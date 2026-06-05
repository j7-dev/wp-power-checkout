<?php
/**
 * 綠界站內付 2.0（ECPG）ReturnURL 幕後通知接收
 *
 * 比照 AioCallback / ShoplinePayment\Http\WebHook 採 ApiBase + SingletonTrait。
 *
 * 協定鐵律（站內付 2.0 ReturnURL，官方規格 9058.md）：
 *  - ReturnURL 為 Server-to-Server JSON POST（Content-Type: application/json）。
 *  - 外層為三層結構 { MerchantID, RpHeader, TransCode, TransMsg, Data }，Data 為 AES-128-CBC 密文。
 *  - 雙層錯誤檢查：TransCode（整數，傳輸層）=== 1 → 解密 Data → RtnCode（整數，業務層）。
 *  - 冪等：以 MerchantTradeNo 為 key，已 processing 則 skip。
 *  - AES 解密失敗 → 維持 pending，不更新明細，僅 log（仍回 1|OK 避免綠界重送風暴）。
 *  - 回應必須是純文字 `1|OK`，HTTP 200（非 JSON）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md §步驟 3 處理回應
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\Http;

use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 綠界站內付 2.0 ReturnURL 幕後通知接收 */
final class EcpgCallback extends ApiBase {
	use SingletonTrait;

	/** @var string 純文字成功回應（綠界協定） */
	private const RESPONSE_OK = '1|OK';

	/** @var string Namespace power-checkout/ecpay */
	protected $namespace = 'power-checkout/ecpay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'ecpg/return',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region REST callback（回純文字 1|OK）

	/**
	 * 付款結果通知（ReturnURL，JSON POST）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（純文字 1|OK）
	 */
	public function post_ecpg_return_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		try {
			$this->handle_return( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( '綠界站內付 2.0 ReturnURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}
		return self::ok_response();
	}

	// endregion

	// region 處理邏輯（可獨立測試）

	/**
	 * 處理付款結果通知（雙層錯誤檢查 + 冪等）
	 *
	 * 1. 傳輸層 TransCode（整數）=== 1 才繼續；非 1 → 記錄傳輸層失敗，維持狀態。
	 * 2. 解密 Data（AES-128-CBC）。解密失敗 → 維持 pending、不更新明細、log。
	 * 3. 以 MerchantTradeNo 反查訂單；冪等：已 processing 則 skip。
	 * 4. 交由 StatusManager 依 RtnCode（整數）更新狀態。
	 *
	 * @param array<string, mixed> $params ReturnURL 外層 JSON 通知參數
	 * @return void
	 * @throws \Exception 找不到訂單時
	 */
	public function handle_return( array $params ): void {
		// 第一層：傳輸層 TransCode（整數 1=成功）
		$trans_code = (int) ( $params['TransCode'] ?? 0 );
		if ( 1 !== $trans_code ) {
			$trans_msg = (string) ( $params['TransMsg'] ?? 'unknown' );
			Plugin::logger(
				'綠界站內付 2.0 ReturnURL 傳輸層失敗',
				'warning',
				[
					'TransCode' => $trans_code,
					'TransMsg'  => $trans_msg,
				]
			);
			$this->add_trans_fail_note( $params, $trans_code, $trans_msg );
			return;
		}

		// 安全：驗 MerchantID 為本商店（不符 → 安全 log 遮蔽憑證，不處理）。
		// 對齊物流貨態 callback 的 MerchantID 驗證模式；MerchantID 位於外層明文 envelope。
		// 維持原 callback 協定（呼叫端仍回 1|OK），避免綠界重送風暴。
		$settings             = EcpgSettingsDTO::instance();
		$incoming_merchant_id = (string) ( $params['MerchantID'] ?? '' );
		if ( $incoming_merchant_id !== $settings->merchantId ) {
			Plugin::logger(
				'綠界站內付 2.0 ReturnURL MerchantID 不符（疑似偽造）',
				'alert',
				[
					'incoming_merchant_id' => $incoming_merchant_id,
					// ⚠️ 安全：絕不記錄 HashKey / HashIV
				]
			);
			return;
		}

		// 解密 Data（失敗 → 維持 pending、不更新明細）
		$crypto = new AesCrypto( $settings->hashKey, $settings->hashIv );
		try {
			$decrypted = $crypto->decrypt( (string) ( $params['Data'] ?? '' ) );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'綠界站內付 2.0 ReturnURL Data 解密失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
			return;
		}

		$trade_no = $this->extract_trade_no( $decrypted );
		$order    = EcpayMetaKeys::get_order_by_trade_no( $trade_no );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( "找不到訂單，MerchantTradeNo: {$trade_no}" );
		}

		// 冪等：已轉為 processing（已付款）則不重複處理
		if ( $order->has_status( OrderStatus::PROCESSING->value ) ) {
			return;
		}

		( new StatusManager( $decrypted, $order ) )->update_order_status();
	}

	// endregion

	// region helpers

	/**
	 * 由解密後的 Data 取出 MerchantTradeNo（站內付為巢狀 OrderInfo.MerchantTradeNo，亦容錯扁平）
	 *
	 * @param array<string, mixed> $decrypted 解密後的 Data
	 * @return string MerchantTradeNo
	 */
	private function extract_trade_no( array $decrypted ): string {
		$order_info = $decrypted['OrderInfo'] ?? [];
		if ( \is_array( $order_info ) && isset( $order_info['MerchantTradeNo'] ) ) {
			return (string) $order_info['MerchantTradeNo'];
		}
		return (string) ( $decrypted['MerchantTradeNo'] ?? '' );
	}

	/**
	 * 傳輸層失敗時，若外層帶有可識別的 MerchantTradeNo 則記錄 order note
	 *
	 * 傳輸層失敗時 Data 通常無法解密，外層 JSON 可能含明文 MerchantTradeNo（容錯）。
	 *
	 * @param array<string, mixed> $params     外層參數
	 * @param int                  $trans_code TransCode
	 * @param string               $trans_msg  TransMsg
	 * @return void
	 */
	private function add_trans_fail_note( array $params, int $trans_code, string $trans_msg ): void {
		$trade_no = (string) ( $params['MerchantTradeNo'] ?? '' );
		if ( '' === $trade_no ) {
			return;
		}
		$order = EcpayMetaKeys::get_order_by_trade_no( $trade_no );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order->add_order_note(
			\sprintf( '綠界站內付傳輸層失敗，TransCode：%d，TransMsg：%s', $trans_code, $trans_msg )
		);
	}

	/** @return string ReturnURL（付款結果通知，JSON POST） */
	public static function get_return_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/ecpg/return', 'https' );
	}

	/**
	 * 純文字 1|OK 回應（HTTP 200，非 JSON）
	 *
	 * 透過 rest_pre_echo_response 過濾把 body 直接輸出為純文字，避免 WP 序列化為 JSON。
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok_response(): \WP_REST_Response {
		$response = new \WP_REST_Response( self::RESPONSE_OK, 200 );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		\add_filter(
			'rest_pre_echo_response',
			static function ( $result, $server, $request ) {
				$route = $request->get_route();
				if ( \is_string( $route ) && \str_contains( $route, 'power-checkout/ecpay/ecpg/' ) ) {
					echo self::RESPONSE_OK; // phpcs:ignore
					return null;
				}
				return $result;
			},
			10,
			3
		);
		return $response;
	}

	// endregion
}
