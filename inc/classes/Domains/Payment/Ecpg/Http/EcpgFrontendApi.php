<?php
/**
 * 綠界站內付 2.0（ECPG）前端 SDK 串接後端 REST 端點
 *
 * 站內付 2.0 為「前端 SDK 收卡」流程，後端缺的最後一段（前端取得 PayToken → 觸發 CreatePayment）
 * 由本類補齊。資料流（承接 EcpgGateway 註解步驟 3）：
 *
 *   1) before_process_payment：後端 GetTokenbyTrade 取交易 Token，存 meta，回 order-received URL。
 *   2) 前端站內付元件（綠界 JS SDK，容器 id ECPayPayment）以 Token 渲染收單 UI，顧客輸入卡片，
 *      SDK 取得 PayToken。
 *   3)（本類）前端把 PayToken POST 回 `power-checkout/ecpay/ecpg/create-payment` →
 *      EcpgApiClient::create_payment()（ecpg domain）→ 解析巢狀 ThreeDInfo.ThreeDURL →
 *      回 { code, message, data:{ three_d_url, need_3ds } }，前端據此導向 3DS 或等 ReturnURL。
 *
 * ⚠️ 驗證機制決策：order_key（非 nonce）。
 *   站內付 SDK 回呼情境由顧客瀏覽器在 order-received 頁觸發，且支援訪客結帳（未登入）。
 *   WP REST nonce 綁定登入 session，訪客 / 下單後 session 重建時不穩定；而 order_key 是 WC 原生
 *   「持有訂單即授權」憑證（與 order-received / pay-for-order 頁同一套機制），不依賴登入態，
 *   為此情境正確的擁有權驗證。比對 $order->get_order_key() 防越權。permission 一律 __return_true，
 *   授權在 callback 內以 order_key 比對把關（回 403 / 拋例外）。
 *
 * 比照 EcpgCallback 採 ApiBase + SingletonTrait，namespace 同為 power-checkout/ecpay。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md §步驟 2 後端建立交易 / §ThreeDURL
 * @see EcpgGateway 資料流決策（步驟 3 觸發點）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\Http;

use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 綠界站內付 2.0 前端 SDK 串接後端 REST 端點 */
final class EcpgFrontendApi extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace power-checkout/ecpay（與 EcpgCallback 同 domain） */
	protected $namespace = 'power-checkout/ecpay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			// 前端 SDK 取得 PayToken 後送回，觸發 CreatePayment（驗證在 callback 內以 order_key 把關）
			'endpoint'            => 'ecpg/create-payment',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 建立付款（前端站內付元件取得 PayToken 後觸發 CreatePayment）
	 *
	 * 接收 { order_id, order_key, pay_token }：
	 *  1. 以 order_id 取訂單；不存在 → 404。
	 *  2. 比對 order_key 與 $order->get_order_key()（防越權）；不符 → 403。
	 *  3. 驗證訂單付款方式為 ecpay_ecpg；否則 → 400。
	 *  4. 取訂單冪等鍵 MerchantTradeNo（GetTokenbyTrade 階段寫入，須與 CreatePayment 相同）。
	 *  5. 呼叫 EcpgApiClient::create_payment( pay_token, merchant_trade_no )。
	 *  6. 回 { code:'success', message, data:{ three_d_url, need_3ds } }。
	 *
	 * 例外（含綠界傳輸層 / 業務層失敗）一律 catch，回 { code:'error' } + HTTP 400，
	 * 不外洩內部細節（細節已由 EcpgApiClient 寫入 order note / log）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_ecpg_create_payment_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id  = (int) $request->get_param( 'order_id' );
		$order_key = (string) $request->get_param( 'order_key' );
		$pay_token = (string) $request->get_param( 'pay_token' );

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return self::error_response( '找不到訂單', 404 );
		}

		// 越權防護：order_key 不符即拒絕（不依賴登入態，WC 原生擁有權憑證）
		if ( '' === $order_key || ! \hash_equals( $order->get_order_key(), $order_key ) ) {
			return self::error_response( '訂單驗證失敗', 403 );
		}

		if ( EcpgGateway::ID !== $order->get_payment_method() ) {
			return self::error_response( '此訂單非綠界站內付', 400 );
		}

		if ( '' === \trim( $pay_token ) ) {
			return self::error_response( 'pay_token 必填', 400 );
		}

		try {
			$merchant_trade_no = ( new EcpayMetaKeys( $order ) )->get_trade_no();
			if ( '' === $merchant_trade_no ) {
				// 無 MerchantTradeNo 代表未走過 GetTokenbyTrade，流程異常
				throw new \Exception( '訂單缺少 MerchantTradeNo，請重新結帳' );
			}

			$result = ( new EcpgApiClient( $order ) )->create_payment( $pay_token, $merchant_trade_no );

			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => $result['need_3ds'] ? '需完成 3D 驗證' : '付款建立成功',
					'data'    => [
						'three_d_url' => $result['three_d_url'],
						'need_3ds'    => $result['need_3ds'],
					],
				],
				200
			);
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'綠界站內付 2.0 CreatePayment 失敗',
				'error',
				[
					'order_id' => $order_id,
					'error'    => $e->getMessage(),
				]
			);
			// 不外洩內部細節（細節已寫 order note / log）
			return self::error_response( '建立付款失敗，請稍後再試或聯繫商家', 400 );
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

	/** @return string create-payment 端點 URL（前端 SDK 取得 PayToken 後 POST 至此） */
	public static function get_create_payment_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/ecpg/create-payment', 'https' );
	}
}
