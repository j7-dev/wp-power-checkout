<?php
/**
 * IPaymentProvider 統一介面契約測試（TDD 紅燈階段）
 *
 * 驗證四支既有 Payment Gateway 是否符合即將新增的 IPaymentProvider 介面契約。
 * 本測試在 IPaymentProvider 介面尚未建立時必須全部失敗（紅燈狀態）。
 *
 * 測試執行指令：
 *   composer test -- --filter PaymentProviderContractTest
 *   # 或在 wp-env 容器：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit --filter PaymentProviderContractTest"
 *
 * 設計哲學：
 *   - query_trade / capture / void_auth / get_supported_payment_methods 由 AbstractPaymentGateway 提供安全預設實作
 *   - 不支援能力的 gateway 呼叫後不應 throw，僅回傳空值或 no-op
 *   - process_refund 對不支援退款的付款方式回 \WP_Error 或 false，不 throw
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * Payment Provider 契約測試類別
 *
 * @group integration
 * @group payment
 */
final class PaymentProviderContractTest extends TestCase {

	/**
	 * 待測試的 gateway 清單
	 * 格式：[ '說明文字', Gateway 類別名稱, Gateway ID 常數 ]
	 *
	 * @var array<array{string, class-string, string}>
	 */
	private const GATEWAY_MATRIX = [
		[ 'Shopline Payment 轉址金流', RedirectGateway::class, 'shopline_payment_redirect' ],
		[ '綠界 AIO 轉址金流', AioRedirectGateway::class, 'ecpay_aio' ],
		[ '綠界 ECPG 站內付', EcpgGateway::class, 'ecpay_ecpg' ],
		[ '藍新金流 MPG 轉址', MpgRedirectGateway::class, 'newebpay_mpg' ],
		[ 'PAYUNi UNi Embed V3 內嵌式', PayuniUniEmbedGateway::class, 'payuni_uni_embed' ],
	];

	/**
	 * 每次測試前啟用所有 gateway（最小設定，讓建構子正常執行）
	 */
	protected function configure_dependencies(): void {
		putenv( 'API_MODE=mock' );

		foreach ( self::GATEWAY_MATRIX as [ , , $id ] ) {
			ProviderUtils::update_option(
				$id,
				[
					'enabled' => 'yes',
					'title'   => "測試金流 {$id}",
				]
			);
		}
	}

	/**
	 * 每次測試後清理環境
	 */
	public function tear_down(): void {
		putenv( 'API_MODE' );
		foreach ( self::GATEWAY_MATRIX as [ , , $id ] ) {
			delete_option( ProviderUtils::get_option_name( $id ) );
		}
		parent::tear_down();
	}

	/**
	 * 建立指定 gateway 的實例
	 *
	 * @param class-string $class_name
	 * @return object
	 */
	private function make_gateway( string $class_name ): object {
		return new $class_name();
	}

	// ========== 冒煙測試（Smoke）—— 介面實作驗證 ==========
	// 以下測試在 IPaymentProvider 介面尚未建立時必須全部失敗（紅燈）

	/**
	 * Shopline Payment 必須實作 IPaymentProvider 介面
	 *
	 * @test
	 * @group smoke
	 * @group payment
	 */
	public function test_冒煙_Shopline_Payment_實作_IPaymentProvider_介面(): void {
		// Given: Shopline Payment 轉址金流 gateway
		$gateway = $this->make_gateway( RedirectGateway::class );

		// Then: 必須是 IPaymentProvider 的實例（介面尚未建立 → 紅燈）
		$this->assertInstanceOf(
			IPaymentProvider::class,
			$gateway,
			'RedirectGateway 應實作 IPaymentProvider 介面'
		);
	}

	/**
	 * 綠界 AIO 必須實作 IPaymentProvider 介面
	 *
	 * @test
	 * @group smoke
	 * @group payment
	 */
	public function test_冒煙_綠界_AIO_實作_IPaymentProvider_介面(): void {
		// Given: 綠界 AIO 轉址金流 gateway
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// Then: 必須是 IPaymentProvider 的實例（介面尚未建立 → 紅燈）
		$this->assertInstanceOf(
			IPaymentProvider::class,
			$gateway,
			'AioRedirectGateway 應實作 IPaymentProvider 介面'
		);
	}

	/**
	 * 綠界 ECPG 必須實作 IPaymentProvider 介面
	 *
	 * @test
	 * @group smoke
	 * @group payment
	 */
	public function test_冒煙_綠界_ECPG_實作_IPaymentProvider_介面(): void {
		// Given: 綠界 ECPG 站內付 gateway
		$gateway = $this->make_gateway( EcpgGateway::class );

		// Then: 必須是 IPaymentProvider 的實例（介面尚未建立 → 紅燈）
		$this->assertInstanceOf(
			IPaymentProvider::class,
			$gateway,
			'EcpgGateway 應實作 IPaymentProvider 介面'
		);
	}

	/**
	 * 藍新金流 MPG 必須實作 IPaymentProvider 介面
	 *
	 * @test
	 * @group smoke
	 * @group payment
	 */
	public function test_冒煙_藍新金流_MPG_實作_IPaymentProvider_介面(): void {
		// Given: 藍新金流 MPG 轉址 gateway
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// Then: 必須是 IPaymentProvider 的實例（介面尚未建立 → 紅燈）
		$this->assertInstanceOf(
			IPaymentProvider::class,
			$gateway,
			'MpgRedirectGateway 應實作 IPaymentProvider 介面'
		);
	}

	// ========== 快樂路徑（Happy）—— 新方法存在性驗證 ==========

	/**
	 * Shopline Payment 具備四個新能力方法
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_Shopline_Payment_具備四個新能力方法(): void {
		// Given: Shopline Payment 轉址金流 gateway 實例
		$gateway = $this->make_gateway( RedirectGateway::class );

		// Then: 四個能力方法必須存在（方法尚未加入 → 紅燈）
		$this->assertTrue( method_exists( $gateway, 'query_trade' ), 'RedirectGateway 缺少 query_trade 方法' );
		$this->assertTrue( method_exists( $gateway, 'capture' ), 'RedirectGateway 缺少 capture 方法' );
		$this->assertTrue( method_exists( $gateway, 'void_auth' ), 'RedirectGateway 缺少 void_auth 方法' );
		$this->assertTrue( method_exists( $gateway, 'get_supported_payment_methods' ), 'RedirectGateway 缺少 get_supported_payment_methods 方法' );
	}

	/**
	 * 綠界 AIO 具備四個新能力方法
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_AIO_具備四個新能力方法(): void {
		// Given: 綠界 AIO 轉址金流 gateway 實例
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// Then: 四個能力方法必須存在
		$this->assertTrue( method_exists( $gateway, 'query_trade' ), 'AioRedirectGateway 缺少 query_trade 方法' );
		$this->assertTrue( method_exists( $gateway, 'capture' ), 'AioRedirectGateway 缺少 capture 方法' );
		$this->assertTrue( method_exists( $gateway, 'void_auth' ), 'AioRedirectGateway 缺少 void_auth 方法' );
		$this->assertTrue( method_exists( $gateway, 'get_supported_payment_methods' ), 'AioRedirectGateway 缺少 get_supported_payment_methods 方法' );
	}

	/**
	 * 綠界 ECPG 具備四個新能力方法
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_ECPG_具備四個新能力方法(): void {
		// Given: 綠界 ECPG 站內付 gateway 實例
		$gateway = $this->make_gateway( EcpgGateway::class );

		// Then: 四個能力方法必須存在
		$this->assertTrue( method_exists( $gateway, 'query_trade' ), 'EcpgGateway 缺少 query_trade 方法' );
		$this->assertTrue( method_exists( $gateway, 'capture' ), 'EcpgGateway 缺少 capture 方法' );
		$this->assertTrue( method_exists( $gateway, 'void_auth' ), 'EcpgGateway 缺少 void_auth 方法' );
		$this->assertTrue( method_exists( $gateway, 'get_supported_payment_methods' ), 'EcpgGateway 缺少 get_supported_payment_methods 方法' );
	}

	/**
	 * 藍新金流 MPG 具備四個新能力方法
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_藍新金流_MPG_具備四個新能力方法(): void {
		// Given: 藍新金流 MPG 轉址 gateway 實例
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// Then: 四個能力方法必須存在
		$this->assertTrue( method_exists( $gateway, 'query_trade' ), 'MpgRedirectGateway 缺少 query_trade 方法' );
		$this->assertTrue( method_exists( $gateway, 'capture' ), 'MpgRedirectGateway 缺少 capture 方法' );
		$this->assertTrue( method_exists( $gateway, 'void_auth' ), 'MpgRedirectGateway 缺少 void_auth 方法' );
		$this->assertTrue( method_exists( $gateway, 'get_supported_payment_methods' ), 'MpgRedirectGateway 缺少 get_supported_payment_methods 方法' );
	}

	// ========== 快樂路徑（Happy）—— 預設能力安全呼叫驗證 ==========
	// 對「不支援該能力」的 gateway 呼叫後，應為 no-op 或回安全預設值，不應 throw

	/**
	 * Shopline Payment 呼叫 query_trade 回傳陣列（預設安全值）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_Shopline_Payment_query_trade_回傳陣列(): void {
		// Given: 一筆測試訂單 + Shopline Payment gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
			]
			);
		$gateway = $this->make_gateway( RedirectGateway::class );

		// When: 呼叫 query_trade（AbstractPaymentGateway 安全預設 → 回 []）
		$result = $gateway->query_trade( $order );

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $result, 'query_trade 應回傳 array，預設為空陣列' );
	}

	/**
	 * 綠界 AIO 呼叫 query_trade 回傳陣列（預設安全值）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_AIO_query_trade_回傳陣列(): void {
		// Given: 一筆測試訂單 + 綠界 AIO gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// When: 呼叫 query_trade
		$result = $gateway->query_trade( $order );

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $result, 'query_trade 應回傳 array，預設為空陣列' );
	}

	/**
	 * 綠界 ECPG 呼叫 query_trade 回傳陣列（預設安全值）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_ECPG_query_trade_回傳陣列(): void {
		// Given: 一筆測試訂單 + 綠界 ECPG gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => EcpgGateway::ID,
			]
			);
		$gateway = $this->make_gateway( EcpgGateway::class );

		// When: 呼叫 query_trade
		$result = $gateway->query_trade( $order );

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $result, 'query_trade 應回傳 array，預設為空陣列' );
	}

	/**
	 * 藍新金流 MPG 呼叫 query_trade 回傳陣列（預設安全值）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_藍新金流_MPG_query_trade_回傳陣列(): void {
		// Given: 一筆測試訂單 + 藍新金流 MPG gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => MpgRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// When: 呼叫 query_trade
		$result = $gateway->query_trade( $order );

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $result, 'query_trade 應回傳 array，預設為空陣列' );
	}

	/**
	 * Shopline Payment 呼叫 capture 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_Shopline_Payment_capture_為安全no_op(): void {
		// Given: 一筆測試訂單 + Shopline Payment gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
			]
			);
		$gateway = $this->make_gateway( RedirectGateway::class );

		// When / Then: 呼叫 capture 不應 throw（AbstractPaymentGateway 預設 no-op + order note）
		try {
			$gateway->capture( $order );
			$this->assertTrue( true, 'capture 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "capture 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 綠界 AIO 呼叫 capture 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_AIO_capture_為安全no_op(): void {
		// Given: 一筆測試訂單 + 綠界 AIO gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// When / Then: 呼叫 capture 不應 throw
		try {
			$gateway->capture( $order );
			$this->assertTrue( true, 'capture 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "capture 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 綠界 ECPG 呼叫 capture 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_ECPG_capture_為安全no_op(): void {
		// Given: 一筆測試訂單 + 綠界 ECPG gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => EcpgGateway::ID,
			]
			);
		$gateway = $this->make_gateway( EcpgGateway::class );

		// When / Then: 呼叫 capture 不應 throw
		try {
			$gateway->capture( $order );
			$this->assertTrue( true, 'capture 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "capture 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 藍新金流 MPG 呼叫 capture 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_藍新金流_MPG_capture_為安全no_op(): void {
		// Given: 一筆測試訂單 + 藍新金流 MPG gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => MpgRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// When / Then: 呼叫 capture 不應 throw
		try {
			$gateway->capture( $order );
			$this->assertTrue( true, 'capture 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "capture 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * Shopline Payment 呼叫 void_auth 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_Shopline_Payment_void_auth_為安全no_op(): void {
		// Given: 一筆測試訂單 + Shopline Payment gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
			]
			);
		$gateway = $this->make_gateway( RedirectGateway::class );

		// When / Then: 呼叫 void_auth 不應 throw（AbstractPaymentGateway 預設 no-op + order note）
		try {
			$gateway->void_auth( $order );
			$this->assertTrue( true, 'void_auth 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "void_auth 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 綠界 AIO 呼叫 void_auth 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_AIO_void_auth_為安全no_op(): void {
		// Given: 一筆測試訂單 + 綠界 AIO gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// When / Then: 呼叫 void_auth 不應 throw
		try {
			$gateway->void_auth( $order );
			$this->assertTrue( true, 'void_auth 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "void_auth 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 綠界 ECPG 呼叫 void_auth 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_ECPG_void_auth_為安全no_op(): void {
		// Given: 一筆測試訂單 + 綠界 ECPG gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => EcpgGateway::ID,
			]
			);
		$gateway = $this->make_gateway( EcpgGateway::class );

		// When / Then: 呼叫 void_auth 不應 throw
		try {
			$gateway->void_auth( $order );
			$this->assertTrue( true, 'void_auth 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "void_auth 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 藍新金流 MPG 呼叫 void_auth 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_藍新金流_MPG_void_auth_為安全no_op(): void {
		// Given: 一筆測試訂單 + 藍新金流 MPG gateway 實例
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => MpgRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// When / Then: 呼叫 void_auth 不應 throw
		try {
			$gateway->void_auth( $order );
			$this->assertTrue( true, 'void_auth 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "void_auth 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * Shopline Payment 呼叫 get_supported_payment_methods 回傳陣列
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_Shopline_Payment_get_supported_payment_methods_回傳陣列(): void {
		// Given: Shopline Payment gateway 實例
		$gateway = $this->make_gateway( RedirectGateway::class );

		// When: 呼叫 get_supported_payment_methods（AbstractPaymentGateway 預設 → 回 []）
		$methods = $gateway->get_supported_payment_methods();

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $methods, 'get_supported_payment_methods 應回傳 array' );
	}

	/**
	 * 綠界 AIO 呼叫 get_supported_payment_methods 回傳陣列
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_AIO_get_supported_payment_methods_回傳陣列(): void {
		// Given: 綠界 AIO gateway 實例
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// When: 呼叫 get_supported_payment_methods
		$methods = $gateway->get_supported_payment_methods();

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $methods, 'get_supported_payment_methods 應回傳 array' );
	}

	/**
	 * 綠界 ECPG 呼叫 get_supported_payment_methods 回傳陣列
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_ECPG_get_supported_payment_methods_回傳陣列(): void {
		// Given: 綠界 ECPG gateway 實例
		$gateway = $this->make_gateway( EcpgGateway::class );

		// When: 呼叫 get_supported_payment_methods
		$methods = $gateway->get_supported_payment_methods();

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $methods, 'get_supported_payment_methods 應回傳 array' );
	}

	/**
	 * 藍新金流 MPG 呼叫 get_supported_payment_methods 回傳陣列
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_藍新金流_MPG_get_supported_payment_methods_回傳陣列(): void {
		// Given: 藍新金流 MPG gateway 實例
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// When: 呼叫 get_supported_payment_methods
		$methods = $gateway->get_supported_payment_methods();

		// Then: 不 throw，回傳陣列
		$this->assertIsArray( $methods, 'get_supported_payment_methods 應回傳 array' );
	}

	// ========== 快樂路徑（Happy）—— process_refund 安全降級驗證 ==========
	// 對不支援 API 退款的情境，應回 \WP_Error 或 false，不應 throw

	/**
	 * 綠界 AIO 對無金額退款呼叫 process_refund 回 false（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_AIO_無金額退款回false(): void {
		// Given: 一筆訂單，退款金額為 null（模擬無退款金額情境）
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// When: amount=null → 提前 return false
		$result = $gateway->process_refund( $order->get_id(), null, '' );

		// Then: 回傳 false，不 throw
		$this->assertFalse( $result, '無退款金額時應回傳 false' );
	}

	/**
	 * 綠界 ECPG 對無金額退款呼叫 process_refund 回 false（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_綠界_ECPG_無金額退款回false(): void {
		// Given: 一筆訂單，退款金額為 null
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => EcpgGateway::ID,
			]
			);
		$gateway = $this->make_gateway( EcpgGateway::class );

		// When: amount=null → 提前 return false
		$result = $gateway->process_refund( $order->get_id(), null, '' );

		// Then: 回傳 false，不 throw
		$this->assertFalse( $result, '無退款金額時應回傳 false' );
	}

	/**
	 * 藍新金流 MPG 對無金額退款呼叫 process_refund 回 false（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_藍新金流_MPG_無金額退款回false(): void {
		// Given: 一筆訂單，退款金額為 null
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => MpgRedirectGateway::ID,
			]
			);
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// When: amount=null → 提前 return false
		$result = $gateway->process_refund( $order->get_id(), null, '' );

		// Then: 回傳 false，不 throw
		$this->assertFalse( $result, '無退款金額時應回傳 false' );
	}

	/**
	 * AbstractPaymentGateway 的預設 process_refund 在無覆寫時回傳 false
	 *
	 * @test
	 * @group happy
	 * @group payment
	 */
	public function test_Shopline_Payment_無金額退款回false(): void {
		// Given: 一筆訂單，退款金額為 null
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
			]
			);
		$gateway = $this->make_gateway( RedirectGateway::class );

		// When: amount=null → 提前 return false
		$result = $gateway->process_refund( $order->get_id(), null, '' );

		// Then: 回傳 false，不 throw
		$this->assertFalse( $result, '無退款金額時應回傳 false' );
	}

	// ========== 錯誤處理（Error）—— 不支援退款時的安全降級 ==========

	/**
	 * 綠界 AIO 對不支援退款的付款方式呼叫 process_refund 回 WP_Error 或 false（不 throw）
	 *
	 * @test
	 * @group error
	 * @group payment
	 */
	public function test_綠界_AIO_非信用卡付款退款回WP_Error或false(): void {
		// Given: 一筆未標記信用卡付款的訂單（meta 無 ecpay_credit_variant → ATM/CVS 情境）
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => 500,
			]
		);
		$gateway = $this->make_gateway( AioRedirectGateway::class );

		// When: 嘗試退款
		try {
			$result = $gateway->process_refund( $order->get_id(), 500.0, '測試退款' );
			// Then: 回傳 false 或 \WP_Error，不 throw
			$this->assertTrue(
				false === $result || $result instanceof \WP_Error,
				'process_refund 對不支援退款的付款方式應回傳 false 或 WP_Error'
			);
		} catch ( \Throwable $e ) {
			$this->fail( "process_refund 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 綠界 ECPG 對不支援退款的付款方式呼叫 process_refund 回 WP_Error 或 false（不 throw）
	 *
	 * @test
	 * @group error
	 * @group payment
	 */
	public function test_綠界_ECPG_非信用卡付款退款回WP_Error或false(): void {
		// Given: 一筆未標記信用卡付款的訂單（ATM/CVS 情境）
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => EcpgGateway::ID,
				'total'          => 500,
			]
		);
		$gateway = $this->make_gateway( EcpgGateway::class );

		// When: 嘗試退款
		try {
			$result = $gateway->process_refund( $order->get_id(), 500.0, '測試退款' );
			// Then: 回傳 false 或 \WP_Error，不 throw
			$this->assertTrue(
				false === $result || $result instanceof \WP_Error,
				'process_refund 對不支援退款的付款方式應回傳 false 或 WP_Error'
			);
		} catch ( \Throwable $e ) {
			$this->fail( "process_refund 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * 藍新金流 MPG 對不支援退款的付款方式呼叫 process_refund 回 WP_Error 或 false（不 throw）
	 *
	 * 藍新 ATM / 超商付款不支援 API 退款
	 *
	 * @test
	 * @group error
	 * @group payment
	 */
	public function test_藍新金流_MPG_非信用卡付款退款回WP_Error或false(): void {
		// Given: 一筆未標記付款方式的訂單（ATM/超商情境，無 ewallet 也無 credit meta）
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => MpgRedirectGateway::ID,
				'total'          => 500,
			]
		);
		$gateway = $this->make_gateway( MpgRedirectGateway::class );

		// When: 嘗試退款
		try {
			$result = $gateway->process_refund( $order->get_id(), 500.0, '測試退款' );
			// Then: 回傳 false 或 \WP_Error，不 throw
			$this->assertTrue(
				false === $result || $result instanceof \WP_Error,
				'process_refund 對不支援退款的付款方式應回傳 false 或 WP_Error'
			);
		} catch ( \Throwable $e ) {
			$this->fail( "process_refund 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	// ========== 邊緣案例（Edge）—— get_settings 靜態方法回傳陣列 ==========

	/**
	 * 四支 gateway 的 get_settings 回傳非空陣列（靜態呼叫）
	 *
	 * @test
	 * @group edge
	 * @group payment
	 */
	public function test_Shopline_Payment_get_settings_回傳陣列(): void {
		// When: 靜態呼叫（含預設值）
		$settings = RedirectGateway::get_settings( true );

		// Then: 回傳陣列且不為空
		$this->assertIsArray( $settings );
		$this->assertNotEmpty( $settings, 'RedirectGateway::get_settings() 應回傳非空陣列' );
	}

	/**
	 * 綠界 AIO 的 get_settings 回傳非空陣列（靜態呼叫）
	 *
	 * @test
	 * @group edge
	 * @group payment
	 */
	public function test_綠界_AIO_get_settings_回傳陣列(): void {
		// When: 靜態呼叫（含預設值）
		$settings = AioRedirectGateway::get_settings( true );

		// Then: 回傳陣列且不為空
		$this->assertIsArray( $settings );
		$this->assertNotEmpty( $settings, 'AioRedirectGateway::get_settings() 應回傳非空陣列' );
	}

	/**
	 * 綠界 ECPG 的 get_settings 回傳非空陣列（靜態呼叫）
	 *
	 * @test
	 * @group edge
	 * @group payment
	 */
	public function test_綠界_ECPG_get_settings_回傳陣列(): void {
		// When: 靜態呼叫（含預設值）
		$settings = EcpgGateway::get_settings( true );

		// Then: 回傳陣列且不為空
		$this->assertIsArray( $settings );
		$this->assertNotEmpty( $settings, 'EcpgGateway::get_settings() 應回傳非空陣列' );
	}

	/**
	 * 藍新金流 MPG 的 get_settings 回傳非空陣列（靜態呼叫）
	 *
	 * @test
	 * @group edge
	 * @group payment
	 */
	public function test_藍新金流_MPG_get_settings_回傳陣列(): void {
		// When: 靜態呼叫（含預設值）
		$settings = MpgRedirectGateway::get_settings( true );

		// Then: 回傳陣列且不為空
		$this->assertIsArray( $settings );
		$this->assertNotEmpty( $settings, 'MpgRedirectGateway::get_settings() 應回傳非空陣列' );
	}

	// ========== PAYUNi UNi Embed V3 IPaymentProvider 契約（Smoke / Happy） ==========
	// 以下為新 gateway 擴充；實作類別尚未存在 → Red 狀態

	/**
	 * PAYUNi UNi Embed V3 必須實作 IPaymentProvider 介面
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PAYUNi_UNi_Embed_實作_IPaymentProvider_介面(): void {
		$gateway = $this->make_gateway( PayuniUniEmbedGateway::class );

		$this->assertInstanceOf(
			IPaymentProvider::class,
			$gateway,
			'PayuniUniEmbedGateway 應實作 IPaymentProvider 介面'
		);
	}

	/**
	 * PAYUNi UNi Embed 具備 IPaymentProvider 的 7 個方法
	 * 7 methods：before_process_payment / before_order_received / process_refund /
	 *            query_trade / capture / void_auth / get_supported_payment_methods
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PAYUNi_UNi_Embed_具備IPaymentProvider七個方法(): void {
		$gateway = $this->make_gateway( PayuniUniEmbedGateway::class );

		$this->assertTrue( \method_exists( $gateway, 'before_process_payment' ), 'PayuniUniEmbedGateway 缺少 before_process_payment 方法' );
		$this->assertTrue( \method_exists( $gateway, 'before_order_received' ), 'PayuniUniEmbedGateway 缺少 before_order_received 方法' );
		$this->assertTrue( \method_exists( $gateway, 'process_refund' ), 'PayuniUniEmbedGateway 缺少 process_refund 方法' );
		$this->assertTrue( \method_exists( $gateway, 'query_trade' ), 'PayuniUniEmbedGateway 缺少 query_trade 方法' );
		$this->assertTrue( \method_exists( $gateway, 'capture' ), 'PayuniUniEmbedGateway 缺少 capture 方法' );
		$this->assertTrue( \method_exists( $gateway, 'void_auth' ), 'PayuniUniEmbedGateway 缺少 void_auth 方法' );
		$this->assertTrue( \method_exists( $gateway, 'get_supported_payment_methods' ), 'PayuniUniEmbedGateway 缺少 get_supported_payment_methods 方法' );
	}

	/**
	 * PAYUNi UNi Embed 呼叫 query_trade 回傳陣列（預設安全值）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PAYUNi_UNi_Embed_query_trade_回傳陣列(): void {
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
			]
			);
		$gateway = $this->make_gateway( PayuniUniEmbedGateway::class );

		$result = $gateway->query_trade( $order );

		$this->assertIsArray( $result, 'query_trade 應回傳 array' );
	}

	/**
	 * PAYUNi UNi Embed 呼叫 capture 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PAYUNi_UNi_Embed_capture_為安全no_op(): void {
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
			]
			);
		$gateway = $this->make_gateway( PayuniUniEmbedGateway::class );

		try {
			$gateway->capture( $order );
			$this->assertTrue( true, 'capture 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "capture 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * PAYUNi UNi Embed 呼叫 void_auth 為安全 no-op（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PAYUNi_UNi_Embed_void_auth_為安全no_op(): void {
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
			]
			);
		$gateway = $this->make_gateway( PayuniUniEmbedGateway::class );

		try {
			$gateway->void_auth( $order );
			$this->assertTrue( true, 'void_auth 不應拋出例外' );
		} catch ( \Throwable $e ) {
			$this->fail( "void_auth 不應拋出例外，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * PAYUNi UNi Embed 呼叫 get_supported_payment_methods 回傳陣列
	 * UNi Embed 僅支援信用卡，應包含 'Credit'
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PAYUNi_UNi_Embed_get_supported_payment_methods_回傳陣列(): void {
		$gateway = $this->make_gateway( PayuniUniEmbedGateway::class );
		$methods = $gateway->get_supported_payment_methods();

		$this->assertIsArray( $methods, 'get_supported_payment_methods 應回傳 array' );
		$this->assertContains( 'Credit', $methods, 'UNi Embed 支援信用卡，應包含 Credit' );
	}

	/**
	 * PAYUNi UNi Embed 無金額退款回 false（不 throw）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PAYUNi_UNi_Embed_無金額退款回false(): void {
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
			]
			);
		$gateway = $this->make_gateway( PayuniUniEmbedGateway::class );

		$result = $gateway->process_refund( $order->get_id(), null, '' );

		$this->assertFalse( $result, '無退款金額時應回傳 false' );
	}

	/**
	 * PAYUNi UNi Embed get_settings 回傳非空陣列
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PAYUNi_UNi_Embed_get_settings_回傳陣列(): void {
		$settings = PayuniUniEmbedGateway::get_settings( true );

		$this->assertIsArray( $settings );
		$this->assertNotEmpty( $settings, 'PayuniUniEmbedGateway::get_settings() 應回傳非空陣列' );
	}
}
