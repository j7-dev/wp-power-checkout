<?php
/**
 * PAYUNi UNi Embed V3 前端 SDK 串接後端 REST 端點（create-payment）
 *
 * UNi Embed 為「前端 SDK 收卡 → 後端幕後授權」流程，後端缺的最後一段（前端 getTradeResult
 * 取得綁定 TOKEN 結果 → 觸發 merchant_trade 授權）由本類補齊。資料流（承接 Gateway 註解步驟 3）：
 *
 *   1) before_process_payment：後端 token_get 取 SDK_TOKEN，存 meta，回 order-received URL。
 *   2) 前端內嵌元件（uni-payment.js，容器 put_card_no/exp/cvc）以 SDK_TOKEN 渲染收單 UI，
 *      顧客輸入卡片，SDK getTradeResult 取得「綁定 TOKEN 結果」（V3：僅綁定，不授權）。
 *   3)（本類）前端把綁定結果 POST 回 `power-checkout/payuni/uni-embed/create-payment` →
 *      MerchantTradeClient::execute()（原 SDK_TOKEN + 後端算 TradeAmt）→ 解析 3D / 非 3D →
 *      回 { code, message, data:{ three_d_url, need_3ds } }，前端據此導向 3D 或等 NotifyURL。
 *
 * ⚠️ 驗證機制決策：order_key（非 nonce），與 ECPG EcpgFrontendApi 完全一致。
 *   SDK 回呼情境由顧客瀏覽器在 order-received 頁觸發，且支援訪客結帳（未登入）。
 *   WP REST nonce 綁定登入 session，訪客 / 下單後 session 重建時不穩定；而 order_key 是 WC 原生
 *   「持有訂單即授權」憑證，不依賴登入態。以 hash_equals 比對 $order->get_order_key() 防越權
 *   （timing-safe）。permission 一律 __return_true，授權在 callback 內以 order_key 比對把關（回 403）。
 *
 * 分流（比照 EcpgFrontendApi）：訂單不存在 → 404；order_key 不符 → 403；
 *   付款方式非本 gateway / 缺 SDK_TOKEN / trade_result 空 → 400；授權失敗 → 通用訊息（不外洩細節）。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §API 2 merchant_trade §回傳（API3D=1）
 * @see \J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgFrontendApi order_key auth 範本
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** PAYUNi UNi Embed V3 前端 SDK 串接後端 REST 端點 */
final class PayuniUniEmbedFrontendApi extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace power-checkout/payuni（與 Cycle 3 NotifyURL callback 同 domain） */
	protected $namespace = 'power-checkout/payuni';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			// 前端 SDK 取得綁定結果後送回，觸發 merchant_trade（驗證在 callback 內以 order_key 把關）
			'endpoint'            => 'uni-embed/create-payment',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 建立付款（前端內嵌元件取得綁定結果後觸發 merchant_trade 幕後授權）
	 *
	 * 接收 { order_id, order_key, trade_result }：
	 *  1. 以 order_id 取訂單；不存在 → 404。
	 *  2. 比對 order_key 與 $order->get_order_key()（hash_equals，timing-safe）；不符 → 403。
	 *  3. 驗證訂單付款方式為 payuni_uni_embed；否則 → 400。
	 *  4. 驗證 trade_result 非空（前端 SDK 綁卡結果）；空 → 400。
	 *  5. 驗證訂單有 _pc_payuni_uni_sdk_token（未走過 token_get → 流程異常）；缺 → 400。
	 *  6. 呼叫 MerchantTradeClient::execute( $order )（TradeAmt 後端算，原 SDK_TOKEN 授權）。
	 *  7. 解析回應 URL → 回 { code:'success', message, data:{ three_d_url?, need_3ds } }。
	 *
	 * 例外（含 PAYUNi 傳輸層 / 業務層失敗，如 SDK_TOKEN 逾期 IFTRADE04001）一律 catch，
	 * 回通用訊息 + HTTP 400，不外洩內部細節（細節寫 order note / log）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_uni_embed_create_payment_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id     = (int) $request->get_param( 'order_id' );
		$order_key    = (string) $request->get_param( 'order_key' );
		$trade_result = (string) $request->get_param( 'trade_result' );

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return self::error_response( '找不到訂單', 404 );
		}

		// 越權防護：order_key 不符即拒絕（hash_equals timing-safe，不依賴登入態）
		if ( '' === $order_key || ! \hash_equals( $order->get_order_key(), $order_key ) ) {
			return self::error_response( '訂單驗證失敗', 403 );
		}

		if ( PayuniUniEmbedGateway::ID !== $order->get_payment_method() ) {
			return self::error_response( '此訂單非 PAYUNi 站內付', 400 );
		}

		if ( '' === \trim( $trade_result ) ) {
			return self::error_response( 'trade_result 必填', 400 );
		}

		// 缺 SDK_TOKEN 代表未走過 token_get（前端直接 POST 跳過流程，屬流程攻擊）
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		if ( '' === $meta_keys->get_sdk_token() ) {
			return self::error_response( '訂單缺少 SDK_TOKEN，請重新結帳', 400 );
		}

		try {
			// merchant_trade 幕後授權（TradeAmt 後端從 order total 計算，前端傳值忽略）
			// execute 採 catch-and-return 契約：失敗回失敗標記陣列（不拋例外），細節已寫 order note / log
			$result = ( new MerchantTradeClient() )->execute( $order );

			if ( MerchantTradeClient::is_failed( $result ) ) {
				// 不外洩內部細節（通用訊息對齊 feature 場景；細節已由 client 寫 order note / log）
				return self::error_response( '授權失敗，請稍後再試或聯繫商家', 400 );
			}

			$three_d_url = MerchantTradeClient::extract_three_d_url( $result );
			$need_3ds    = '' !== $three_d_url;

			$data = [ 'need_3ds' => $need_3ds ];
			if ( $need_3ds ) {
				$data['three_d_url'] = $three_d_url;
			}

			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => $need_3ds ? \__( '需完成 3D 驗證', 'power_checkout' ) : \__( '付款建立成功', 'power_checkout' ),
					'data'    => $data,
				],
				200
			);
		} catch ( \Throwable $e ) {
			// 防禦性兜底：execute 理應已 catch-and-return，此處攔任何意外 Throwable
			Plugin::logger(
				'PAYUNi UNi Embed create-payment 例外',
				'error',
				[
					'order_id' => $order_id,
					'error'    => $e->getMessage(),
				]
			);
			$order->add_order_note( "❌ PAYUNi 站內付授權失敗：{$e->getMessage()}" );

			// 不外洩內部細節（通用訊息對齊 feature 場景）
			return self::error_response( '授權失敗，請稍後再試或聯繫商家', 400 );
		}
	}

	/**
	 * 統一錯誤回應（response format：{ code, message, data }）
	 *
	 * @param string $message 對前端的訊息（不含內部細節）
	 * @param int    $status  HTTP 狀態碼
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'error',
				'message' => $message,
				'data'    => null,
			],
			$status
		);
	}

	/** @return string create-payment 端點 URL（前端 SDK 取得綁定結果後 POST 至此） */
	public static function get_create_payment_url(): string {
		return \site_url( 'wp-json/power-checkout/payuni/uni-embed/create-payment', 'https' );
	}
}
