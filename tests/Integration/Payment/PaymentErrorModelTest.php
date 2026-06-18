<?php
/**
 * Payment 正規化錯誤模型測試（einvoice 導入第六階段-a，步驟 11 + 13）
 *
 * 對應規格：specs/features/payment/payment-error-model.feature
 *
 * 本輪（P6a）只驗證 Payment 領域錯誤模型的「核心三處」，不碰各金流 Gateway：
 *   A. IPaymentProvider::process_refund() 契約 docblock（never-throw + 正規化 WP_Error）。
 *   B. AbstractPaymentGateway 預設 process_refund → NormalizedError::from(UNSUPPORTED)。
 *   C. PaymentApiService REST /refund 將 process_refund 回傳的正規化 \WP_Error
 *      豐富化為 error_code + raw_code + message + 依 code 映射的 HTTP 狀態碼
 *      （對齊 InvoiceApiService::respond() 的 envelope）。
 *
 * 測試替身：
 *   - DefaultRefundGateway：不覆寫 process_refund → 用以驗證 Abstract 預設行為。
 *   - ControllableRefundGateway：覆寫 process_refund 回傳可控的 \WP_Error → 用以驗證 REST 映射；
 *     透過 woocommerce_payment_gateways 過濾器註冊 + WC()->payment_gateways()->init() 重載，
 *     使 wc_get_payment_gateway_by_order 可解析到本替身。
 *
 * 範圍邊界（硬約束）：本測試「不」涉及 callback always-200（P6b/no-change），
 *   亦不改 process_refund 的型別簽名（已是 bool|\WP_Error）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/PaymentErrorModelTest.php --no-coverage'
 *
 * @group integration
 * @group payment
 * @group error
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Services\PaymentApiService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use Tests\Integration\TestCase;

/**
 * Payment 正規化錯誤模型測試類別
 *
 * @group integration
 * @group payment
 * @group error
 * @group edge
 */
final class PaymentErrorModelTest extends TestCase {

	/** @var string 可控退款替身 gateway 的 ID（須與 fixture const ID 一致） */
	private const CONTROLLABLE_ID = 'pc_test_controllable_refund_gateway';

	/**
	 * 每次測試後：清空替身注入、移除過濾器並重載 WC gateway 清單，避免污染其他測試
	 */
	public function tear_down(): void {
		ControllableRefundGateway::$next_refund_result = null;
		\remove_filter( 'woocommerce_payment_gateways', [ self::class, 'inject_controllable_gateway' ] );
		// 重新載入 WC gateway 清單，移除本替身（與 register_controllable_gateway 對稱）。
		if ( \function_exists( 'WC' ) && WC()->payment_gateways() ) {
			WC()->payment_gateways()->init();
		}
		parent::tear_down();
	}

	/**
	 * 過濾器 callback：把可控退款替身 gateway 注入 WC gateway 清單
	 *
	 * @param array<int|string, string> $gateways 既有 gateway 類別清單
	 * @return array<int|string, string>
	 */
	public static function inject_controllable_gateway( array $gateways ): array {
		$gateways[] = ControllableRefundGateway::class;
		return $gateways;
	}

	/**
	 * 註冊可控退款替身 gateway 到 WC gateway 清單並重載
	 *
	 * 重載後 wc_get_payment_gateway_by_order 即可依 payment_method 解析到本替身。
	 */
	private function register_controllable_gateway(): void {
		\add_filter( 'woocommerce_payment_gateways', [ self::class, 'inject_controllable_gateway' ] );
		WC()->payment_gateways()->init();
	}

	/**
	 * 建立一筆使用可控退款替身付款、有可退餘額的訂單
	 *
	 * @param float $total 訂單金額（= 可退餘額）
	 * @return \WC_Order
	 */
	private function create_controllable_order( float $total = 1000 ): \WC_Order {
		return $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => self::CONTROLLABLE_ID,
				'total'          => $total,
			]
		);
	}

	/**
	 * 對 /refund 端點發出請求並取回回應
	 *
	 * @param \WC_Order $order 訂單
	 * @return \WP_REST_Response
	 */
	private function call_refund( \WC_Order $order ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/refund' );
		$request->set_param( 'order_id', (string) $order->get_id() );
		return PaymentApiService::instance()->post_refund_callback( $request );
	}

	// ========================================================================
	// B. AbstractPaymentGateway 預設 process_refund → UNSUPPORTED
	// ========================================================================

	/**
	 * 未覆寫 process_refund 的 gateway → 預設回正規化的 UNSUPPORTED \WP_Error
	 *
	 * @test
	 * @group error
	 */
	public function test_Abstract預設process_refund回UNSUPPORTED的WP_Error(): void {
		// Given: 一個不覆寫 process_refund 的替身 gateway + 一筆訂單
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => DefaultRefundGateway::ID,
				'total'          => 1000,
			]
		);
		$gateway = new DefaultRefundGateway();

		// When: 呼叫預設 process_refund（落到 AbstractPaymentGateway 預設實作）
		$result = $gateway->process_refund( $order->get_id(), 1000.0, '測試退款' );

		// Then: 回正規化 WP_Error，且 code 為 UNSUPPORTED
		$this->assertInstanceOf( \WP_Error::class, $result, '預設 process_refund 應回 \WP_Error' );
		$this->assertTrue(
			NormalizedError::is_normalized_error( $result ),
			'預設 process_refund 回傳值應為正規化錯誤（is_normalized_error 為 true）'
		);
		$this->assertSame(
			ErrorCode::UNSUPPORTED,
			NormalizedError::get_code( $result ),
			'預設 process_refund 的正規化 code 應為 UNSUPPORTED'
		);
	}

	/**
	 * Abstract 預設 process_refund 的 \WP_Error 帶 provider 上下文且 message 非空
	 *
	 * @test
	 * @group edge
	 */
	public function test_Abstract預設process_refund的WP_Error帶provider與可讀message(): void {
		// Given
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => DefaultRefundGateway::ID,
				'total'          => 1000,
			]
		);
		$gateway = new DefaultRefundGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000.0, '測試退款' );

		// Then: message 非空（供前端顯示）、$data['provider'] 記錄 gateway id
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message(), '預設 process_refund 應帶可讀錯誤訊息' );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame(
			DefaultRefundGateway::ID,
			$data['provider'] ?? null,
			'$data[provider] 應記錄 gateway id 供 debug'
		);
	}

	/**
	 * Abstract 預設 process_refund 為 never-throw（不向 WC 退款流程拋例外）
	 *
	 * @test
	 * @group edge
	 */
	public function test_Abstract預設process_refund不拋例外(): void {
		// Given
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => DefaultRefundGateway::ID,
				'total'          => 1000,
			]
		);
		$gateway = new DefaultRefundGateway();

		// When / Then: 不應 throw
		try {
			$gateway->process_refund( $order->get_id(), 1000.0, '測試退款' );
			$this->assertTrue( true, 'process_refund 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "process_refund 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	// ========================================================================
	// C. REST /refund：process_refund 回正規化 WP_Error → 豐富化映射
	// ========================================================================

	/**
	 * UNSUPPORTED → HTTP 400 + body error_code=UNSUPPORTED + message 非空
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_refund_UNSUPPORTED映射為400且body帶error_code(): void {
		$this->register_controllable_gateway();
		$order = $this->create_controllable_order();

		ControllableRefundGateway::$next_refund_result = NormalizedError::from(
			ErrorCode::UNSUPPORTED,
			'此付款方式不支援 API 退款，請至金流後台手動處理',
			[ 'provider' => self::CONTROLLABLE_ID ]
		);

		$response = $this->call_refund( $order );

		$this->assertSame( 400, $response->get_status(), 'UNSUPPORTED 須映射為 HTTP 400' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $data['error_code'] ?? null );
		$this->assertNotEmpty( $data['message'] ?? '', 'body 須含可讀 message 供前端 RefundDialog 顯示' );
	}

	/**
	 * VALIDATION → HTTP 422 + body error_code=VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_refund_VALIDATION映射為422(): void {
		$this->register_controllable_gateway();
		$order = $this->create_controllable_order();

		ControllableRefundGateway::$next_refund_result = NormalizedError::from(
			ErrorCode::VALIDATION,
			'退款金額超出可退餘額',
			[ 'provider' => self::CONTROLLABLE_ID ]
		);

		$response = $this->call_refund( $order );

		$this->assertSame( 422, $response->get_status(), 'VALIDATION 須映射為 HTTP 422' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::VALIDATION->value, $data['error_code'] ?? null );
	}

	/**
	 * AUTH → HTTP 401 + body error_code=AUTH + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_refund_AUTH映射為401且保留raw_code(): void {
		$this->register_controllable_gateway();
		$order = $this->create_controllable_order();

		ControllableRefundGateway::$next_refund_result = NormalizedError::from(
			ErrorCode::AUTH,
			'商店憑證 / 簽章金鑰錯誤',
			[
				'provider' => self::CONTROLLABLE_ID,
				'raw_code' => 'MERCHANT_AUTH_FAILED',
			]
		);

		$response = $this->call_refund( $order );

		$this->assertSame( 401, $response->get_status(), 'AUTH 須映射為 HTTP 401' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::AUTH->value, $data['error_code'] ?? null );
		$this->assertSame( 'MERCHANT_AUTH_FAILED', $data['raw_code'] ?? null, '須保留 raw_code 供 debug' );
	}

	/**
	 * NETWORK → HTTP 502 + body error_code=NETWORK
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_refund_NETWORK映射為502(): void {
		$this->register_controllable_gateway();
		$order = $this->create_controllable_order();

		ControllableRefundGateway::$next_refund_result = NormalizedError::from(
			ErrorCode::NETWORK,
			'退款 API 連線逾時',
			[ 'provider' => self::CONTROLLABLE_ID ]
		);

		$response = $this->call_refund( $order );

		$this->assertSame( 502, $response->get_status(), 'NETWORK 須映射為 HTTP 502' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::NETWORK->value, $data['error_code'] ?? null );
	}

	/**
	 * 退款成功（process_refund 回 true）→ 維持既有 200 回應（不退化）
	 *
	 * @test
	 * @group happy
	 */
	public function test_rest_refund_成功維持200回應(): void {
		$this->register_controllable_gateway();
		$order = $this->create_controllable_order();

		// 替身回 true → 端點視為退款成功
		ControllableRefundGateway::$next_refund_result = null; // null 表示回 true

		$response = $this->call_refund( $order );

		$this->assertSame( 200, $response->get_status(), '退款成功須維持 HTTP 200（既有回應不變）' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'success', $data['code'] ?? null, '成功回應 code 須維持 success' );
		$this->assertArrayNotHasKey( 'error_code', $data, '成功回應不應帶 error_code' );
	}

	/**
	 * never-throw：REST 收到 process_refund 的 \WP_Error 不再 throw（改回應體）
	 *
	 * @test
	 * @group edge
	 */
	public function test_rest_refund_收到WP_Error不再拋例外而是回應體(): void {
		$this->register_controllable_gateway();
		$order = $this->create_controllable_order();

		ControllableRefundGateway::$next_refund_result = NormalizedError::from(
			ErrorCode::UNSUPPORTED,
			'不支援 API 退款',
			[ 'provider' => self::CONTROLLABLE_ID ]
		);

		// When / Then: 不應 throw，而是回 WP_REST_Response
		try {
			$response = $this->call_refund( $order );
			$this->assertInstanceOf( \WP_REST_Response::class, $response );
			$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		} catch ( \Throwable $e ) {
			$this->fail( "REST /refund 收到 WP_Error 不應拋例外，但拋出：{$e->getMessage()}" );
		}
	}

	// ========================================================================
	// 面 A（既有，保留不退化）：訂單 / gateway 找不到 → 仍 throw
	// ========================================================================

	/**
	 * 面 A：訂單不存在 → callback 仍 throw \Exception（既有路徑不退化）
	 *
	 * @test
	 * @group error
	 */
	public function test_面A_refund_訂單不存在仍拋例外(): void {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/refund' );
		$request->set_param( 'order_id', '9999999' );

		$this->expectException( \Exception::class );
		PaymentApiService::instance()->post_refund_callback( $request );
	}

	// ========================================================================
	// 跨領域一致：金流與發票共用同一份正規化 code 值域
	// ========================================================================

	/**
	 * 金流退款的 UNSUPPORTED 與發票折讓不支援使用同一 enum case
	 *
	 * @test
	 * @group edge
	 */
	public function test_跨領域_金流與發票UNSUPPORTED為同一enum(): void {
		$payment_error = NormalizedError::from( ErrorCode::UNSUPPORTED, '金流退款不支援', [] );
		$invoice_error = NormalizedError::from( ErrorCode::UNSUPPORTED, '發票折讓不支援', [] );

		$this->assertSame(
			NormalizedError::get_code( $payment_error ),
			NormalizedError::get_code( $invoice_error ),
			'金流與發票的 UNSUPPORTED 須為同一領域中立 enum case'
		);
		$this->assertSame( ErrorCode::UNSUPPORTED, NormalizedError::get_code( $payment_error ) );
	}
}

/**
 * 測試替身：不覆寫 process_refund 的最小 gateway
 *
 * 用以驗證 AbstractPaymentGateway 的預設 process_refund 行為（落到父類預設實作）。
 */
final class DefaultRefundGateway extends AbstractPaymentGateway {

	/** @var string gateway ID */
	const ID = 'pc_test_default_refund_gateway';

	/** @var string gateway ID（WC_Payment_Gateway 屬性） */
	public $id = self::ID;

	/** @var string 後台方法標題 */
	public $method_title = '測試預設退款替身';

	/**
	 * 取得設定（最小實作，滿足 abstract 契約與建構子需求）
	 *
	 * @param bool $with_default 是否包含預設值
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [
			'id'      => self::ID,
			'enabled' => 'yes',
			'title'   => '測試預設退款替身',
		];
	}
}

/**
 * 測試替身：覆寫 process_refund 回傳可控結果的 gateway
 *
 * $next_refund_result 為 null → 回 true（模擬退款成功）；
 * 為 \WP_Error → 原樣回傳（模擬各正規化 code 失敗，供 REST 映射驗證）。
 */
final class ControllableRefundGateway extends AbstractPaymentGateway {

	/** @var string gateway ID */
	const ID = 'pc_test_controllable_refund_gateway';

	/** @var \WP_Error|null 下一次 process_refund 的回傳值；null 表示回 true */
	public static ?\WP_Error $next_refund_result = null;

	/** @var string gateway ID（WC_Payment_Gateway 屬性） */
	public $id = self::ID;

	/** @var string 後台方法標題 */
	public $method_title = '測試可控退款替身';

	/**
	 * 取得設定（最小實作）
	 *
	 * @param bool $with_default 是否包含預設值
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [
			'id'      => self::ID,
			'enabled' => 'yes',
			'title'   => '測試可控退款替身',
		];
	}

	/**
	 * 覆寫 process_refund 回傳可控結果
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error 預設 true；若已注入 $next_refund_result 則回該 \WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		if ( self::$next_refund_result instanceof \WP_Error ) {
			return self::$next_refund_result;
		}
		return true;
	}
}
