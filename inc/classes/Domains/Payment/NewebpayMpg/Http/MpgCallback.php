<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgStatus;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 藍新 NewebPay MPG 幕後通知接收（NotifyURL / ReturnURL）
 *
 * 比照 EcpayAIO\Http\AioCallback 採 ApiBase + SingletonTrait，但用獨立 namespace
 * power-checkout/newebpay（避免與綠界 power-checkout/ecpay 混淆）。
 *
 * 協定鐵律：
 *  - 狀態真實來源為 NotifyURL（server-to-server）；ReturnURL（browser）僅 UX + 冪等去重。
 *  - 驗章鏈：先 TradeSha 驗封包 → 解密 TradeInfo → CheckCode 驗交易結果 → StatusManager。
 *  - 冪等：以 MerchantOrderNo 反查訂單，已 processing 則 skip。
 *  - 所有失敗分支（驗章 / 解密 / CheckCode / 查無訂單 / 金額竄改 / 任何 \Throwable）一律回 HTTP 200，
 *    避免藍新重送風暴；不更新狀態時 log warning。
 *
 * @see .claude/skills/newebpay-mpg/references/examples.md §Handle Callback (NotifyURL)
 */
final class MpgCallback extends ApiBase {
	use SingletonTrait;

	/** @var string 純文字成功回應（HTTP 200；藍新只要 200 即不重送） */
	private const RESPONSE_OK = '1|OK';

	/** @var string Namespace power-checkout/newebpay（與綠界區隔） */
	protected $namespace = 'power-checkout/newebpay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'mpg/notify',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
		[
			'endpoint'            => 'mpg/return',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region REST callbacks（回 HTTP 200 純文字）

	/**
	 * 付款結果背景通知（NotifyURL，source of truth）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（HTTP 200 純文字）
	 */
	public function post_mpg_notify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		try {
			$this->handle_notify( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( '藍新 MPG NotifyURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}
		return self::ok_response();
	}

	/**
	 * 付款完成前景導回（ReturnURL，UX only）
	 *
	 * 與 NotifyURL 共用 handle_notify（冪等保護），但即使這裡先到也只是提早更新狀態；
	 * 真正可靠的來源仍是 NotifyURL。
	 *
	 * 此端點承接「買家瀏覽器」的 form POST 導回——必須 302 回訂單完成頁，
	 * 停留在純文字 1|OK 會讓買家付款後卡在白頁（sandbox 端到端實測發現）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 302 導向訂單完成頁（查無訂單時導向結帳頁）
	 */
	public function post_mpg_return_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		$order  = null;
		try {
			$order = $this->handle_notify( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( '藍新 MPG ReturnURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}

		$redirect = $order instanceof \WC_Order
			? $order->get_checkout_order_received_url()
			: \wc_get_checkout_url();

		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $redirect );
		return $response;
	}

	// endregion

	// region 處理邏輯（可獨立測試）

	/**
	 * 處理付款結果通知（驗章鏈 + 冪等 + StatusManager）
	 *
	 * 步驟：
	 *  1. TradeSha 驗封包（hash_equals timing-safe）→ 不符則 log warning 並 return（不更新）。
	 *  2. 解密 TradeInfo → JSON decode。
	 *  3. 頂層 Status 非 SUCCESS → 交 StatusManager 記錄失敗（維持 pending）。
	 *  4. CheckCode 驗交易結果 → 不符則 log warning 並 return（不更新）。
	 *  5. 以 MerchantOrderNo 反查訂單 → 查無則 throw（呼叫端 catch 後仍回 200）。
	 *  6. 冪等：已 processing 則 skip。
	 *  7. StatusManager::update_order_status()。
	 *
	 * @param array<string, mixed> $params NotifyURL/ReturnURL 通知參數（含 TradeInfo / TradeSha）
	 * @return \WC_Order|null 解析出的訂單（ReturnURL 302 導向用）；驗章 / 解密失敗回 null
	 * @throws \Exception 查無訂單
	 */
	public function handle_notify( array $params ): ?\WC_Order {
		$trade_info = (string) ( $params['TradeInfo'] ?? '' );
		$trade_sha  = (string) ( $params['TradeSha'] ?? '' );

		if ( '' === $trade_info || '' === $trade_sha ) {
			Plugin::logger( '藍新 MPG 通知缺少 TradeInfo / TradeSha', 'warning', [ 'params' => $params ] );
			return null;
		}

		$settings = MpgSettingsDTO::instance();
		if ( '' === $settings->hashKey || '' === $settings->hashIv ) {
			Plugin::logger( '藍新 MPG 通知處理失敗：缺少憑證', 'warning' );
			return null;
		}

		$crypto = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );

		// 1. TradeSha 驗封包（timing-safe）
		$expected_sha = $crypto->generate_trade_sha( $trade_info );
		if ( ! \hash_equals( $expected_sha, \strtoupper( $trade_sha ) ) ) {
			Plugin::logger( '藍新 MPG 通知 TradeSha 驗章失敗', 'warning', [ 'trade_sha' => $trade_sha ] );
			return null;
		}

		// 2. 解密 TradeInfo
		$decoded = $this->decode_trade_info( $crypto, $trade_info );
		if ( null === $decoded ) {
			Plugin::logger( '藍新 MPG 通知 TradeInfo 解密 / 解析失敗', 'warning' );
			return null;
		}

		$status = (string) ( $decoded['Status'] ?? '' );
		/** @var array<string, mixed> $result */
		$result = \is_array( $decoded['Result'] ?? null ) ? $decoded['Result'] : [];

		// 5. 反查訂單（先查訂單，失敗 / 成功都要能找到訂單寫 note）
		$order_no = (string) ( $result['MerchantOrderNo'] ?? '' );
		$order    = MpgMetaKeys::get_order_by_order_no( $order_no );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( "找不到訂單，MerchantOrderNo: {$order_no}" );
		}

		// 3. 頂層 Status 非 SUCCESS → 記錄失敗（StatusManager 維持 pending）
		if ( ! MpgStatus::is_status_success( $status ) ) {
			( new StatusManager( $decoded, $order ) )->update_order_status();
			return $order;
		}

		// 4. CheckCode 驗交易結果（Status=SUCCESS 才有意義）
		$received_check_code = (string) ( $result['CheckCode'] ?? '' );
		if ( '' !== $received_check_code && ! $crypto->verify_check_code( $result, $received_check_code ) ) {
			Plugin::logger( '藍新 MPG 通知 CheckCode 驗章失敗', 'warning', [ 'order_no' => $order_no ] );
			return null;
		}

		// 6. 冪等：已 processing 則 skip
		if ( $order->has_status( OrderStatus::PROCESSING->value ) ) {
			return $order;
		}

		// 7. 更新訂單狀態
		( new StatusManager( $decoded, $order ) )->update_order_status();

		return $order;
	}

	/**
	 * 解密並解析 TradeInfo 為陣列
	 *
	 * @param TradeInfoCrypto $crypto     加解密器
	 * @param string          $trade_info 加密 hex
	 * @return array<string, mixed>|null 解析失敗回 null
	 */
	private function decode_trade_info( TradeInfoCrypto $crypto, string $trade_info ): ?array {
		try {
			$json = $crypto->decrypt( $trade_info );
		} catch ( \Throwable $e ) {
			return null;
		}

		$decoded = \json_decode( $json, true );
		if ( ! \is_array( $decoded ) ) {
			return null;
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	// endregion

	// region URL helpers

	/** @return string NotifyURL（付款結果背景通知，source of truth） */
	public static function get_notify_url(): string {
		return \site_url( 'wp-json/power-checkout/newebpay/mpg/notify', 'https' );
	}

	/** @return string ReturnURL（付款完成前景導回，UX） */
	public static function get_return_url(): string {
		return \site_url( 'wp-json/power-checkout/newebpay/mpg/return', 'https' );
	}

	// endregion

	/**
	 * 純文字 HTTP 200 回應（藍新只要 200 即不重送）
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
				if ( \is_string( $route ) && \str_contains( $route, 'power-checkout/newebpay/mpg/' ) ) {
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
}
