<?php
/**
 * 6 金流 process_refund 正規化錯誤碼映射測試（einvoice 導入第六階段-b，改動 A）
 *
 * 對應規格：specs/open-issue/einvoice-adoption-implementation-plan.md
 *   §第六階段 步驟 12 + §資料流分析 流程 3（退款 process_refund → Payment 領域 WP_Error）。
 *
 * 本檔驗證 6 個金流 Gateway 各自 process_refund 的「回傳值建構方式」已從散裝
 * `new \WP_Error('refund_unsupported', ...)` / `'refund_failed'` 正規化為
 * {@see NormalizedError::from()}，使 get_error_code() 對應到領域中立的 {@see ErrorCode}：
 *   - 退款不支援（ATM/CVS/LINEPay 等非信用卡）→ ErrorCode::UNSUPPORTED
 *   - 有金額但超出可退餘額（Payuni / PayuniUniEmbed / Paynow 既有超額分支）→ ErrorCode::VALIDATION
 *   - ShoplinePayment can_refund 拋例外（catch \Throwable）→ ErrorCode::UNKNOWN
 *
 * ⚠️ amount=null 守衛維持回 false（既有 PaymentProviderContractTest::test_*_無金額退款回false
 *    契約，不得改成 WP_Error）。只有「有金額但超額」才 VALIDATION。本檔亦反覆確認此契約。
 *
 * 範圍邊界（硬約束，對齊計劃「限制條件」）：
 *   - 不改 process_refund 型別簽名（已是 bool|\WP_Error）。
 *   - 不改各金流既有「判定邏輯」（付款方式分流 / 金額邊界），只換回傳值建構方式。
 *   - AUTH / NETWORK / PROVIDER 在這 6 支 process_refund 內「不可達」：
 *     信用卡退款的實際第三方 API 呼叫位於 handle_payment_gateway_refund / process_gateway_refund
 *     （回 void + order note），process_refund 對信用卡僅回 true 後延遲委派，故不在此處映射。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/PaymentRefundNormalizationTest.php --no-coverage'
 *
 * @group integration
 * @group payment
 * @group refund
 * @group error
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 6 金流 process_refund 正規化錯誤碼映射測試類別
 *
 * @group integration
 * @group payment
 * @group refund
 * @group error
 * @group edge
 */
final class PaymentRefundNormalizationTest extends TestCase {

	/** 每次測試前：mock 模式 + 啟用 6 金流（最小設定，讓建構子正常） */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option( PayuniUppGateway::ID, [ 'enabled' => 'yes', 'title' => 'PAYUNi UPP' ] );
		ProviderUtils::update_option( PayuniUniEmbedGateway::ID, [ 'enabled' => 'yes', 'title' => 'PAYUNi UNi Embed' ] );
		ProviderUtils::update_option( PaynowGateway::ID, [ 'enabled' => 'yes', 'title' => 'PayNow' ] );
		ProviderUtils::update_option( EcpgGateway::ID, [ 'enabled' => 'yes', 'title' => '綠界 ECPG' ] );
		ProviderUtils::update_option( AioRedirectGateway::ID, [ 'enabled' => 'yes', 'title' => '綠界 AIO' ] );
		ProviderUtils::update_option( 'shopline_payment_redirect', [ 'enabled' => 'yes', 'title' => 'Shopline' ] );
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		parent::tear_down();
	}

	// ====================================================================
	// 退款不支援（非信用卡）→ ErrorCode::UNSUPPORTED
	// ====================================================================

	/**
	 * PAYUNi UPP：ATM（PaymentType=2）process_refund → 正規化 UNSUPPORTED
	 *
	 * @test
	 * @group error
	 * @group payuni
	 */
	public function test_PayuniUpp_ATM退款回正規化UNSUPPORTED(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);
		( new PayuniMetaKeys( $order ) )->update_payment_detail(
			[
				'TradeNo'     => 'UNI20260618001',
				'PaymentType' => '2', // ATM（非信用卡）
			]
		);

		$result = ( new PayuniUppGateway() )->process_refund( $order->get_id(), 1000.0, '測試退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ), '應為正規化錯誤' );
		$this->assertSame( ErrorCode::UNSUPPORTED, NormalizedError::get_code( $result ) );
		$this->assertSame(
			ErrorCode::UNSUPPORTED->value,
			$result->get_error_code(),
			'get_error_code() 應為正規化 UNSUPPORTED（取代舊 refund_unsupported）'
		);
		$this->assertStringContainsString( '人工處理', $result->get_error_message() );
	}

	/**
	 * PAYUNi UPP：UNSUPPORTED 的 \WP_Error $data 帶 provider 供 debug
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 */
	public function test_PayuniUpp_UNSUPPORTED帶provider上下文(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);
		( new PayuniMetaKeys( $order ) )->update_payment_detail(
			[
				'TradeNo'     => 'UNI20260618002',
				'PaymentType' => '3', // CVS
			]
		);

		$result = ( new PayuniUppGateway() )->process_refund( $order->get_id(), 1000.0, '測試退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( PayuniUppGateway::ID, $data['provider'] ?? null, '$data[provider] 應記錄 gateway id' );
	}

	/**
	 * 綠界 AIO：非信用卡（ATM 明細）process_refund → 正規化 UNSUPPORTED
	 *
	 * @test
	 * @group error
	 * @group ecpay
	 */
	public function test_EcpayAio_非信用卡退款回正規化UNSUPPORTED(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => 1000,
			]
		);
		// 帶 ATM 付款明細（EcpayPaymentType::order_is_credit 判定為非信用卡）
		( new EcpayMetaKeys( $order ) )->update_payment_detail(
			[
				'PaymentType' => 'ATM_TAISHIN',
				'TradeNo'     => '2026061811112222',
			]
		);

		$result = ( new AioRedirectGateway() )->process_refund( $order->get_id(), 1000.0, '測試退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED, NormalizedError::get_code( $result ) );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * 綠界 ECPG：非信用卡（無明細）process_refund → 正規化 UNSUPPORTED
	 *
	 * @test
	 * @group error
	 * @group ecpay
	 */
	public function test_Ecpg_非信用卡退款回正規化UNSUPPORTED(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => EcpgGateway::ID,
				'total'          => 1000,
			]
		);

		$result = ( new EcpgGateway() )->process_refund( $order->get_id(), 500.0, '測試退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED, NormalizedError::get_code( $result ) );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * PayNow：超商代碼（ConvenienceStore）process_refund → 正規化 UNSUPPORTED
	 *
	 * @test
	 * @group error
	 * @group paynow
	 */
	public function test_Paynow_超商代碼退款回正規化UNSUPPORTED(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PaynowGateway::ID,
				'total'          => 800,
			]
		);
		( new PaynowMetaKeys( $order ) )->update_payment_detail(
			[
				'PaymentType' => 'ConvenienceStore', // 非信用卡 / 非 ATM
			]
		);

		$result = ( new PaynowGateway() )->process_refund( $order->get_id(), 800.0, '測試退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED, NormalizedError::get_code( $result ) );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * PAYUNi UNi Embed：無付款明細（非信用卡）process_refund → 正規化 UNSUPPORTED
	 *
	 * UNi Embed 僅信用卡；當 _pc_payuni_uni_payment_detail 缺 PaymentType=1 時
	 * is_credit_order 回 false → UNSUPPORTED。
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 */
	public function test_PayuniUniEmbed_無付款明細退款回正規化UNSUPPORTED(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => 1000,
			]
		);
		// 刻意寫入非信用卡明細（PaymentType != 1），使 is_credit_order 回 false
		( new PayuniUniEmbedMetaKeys( $order ) )->update_payment_detail(
			[
				'TradeNo'     => 'UNI20260618009',
				'PaymentType' => '2',
			]
		);

		$result = ( new PayuniUniEmbedGateway() )->process_refund( $order->get_id(), 1000.0, '測試退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED, NormalizedError::get_code( $result ) );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	// ====================================================================
	// 有金額但超出可退餘額 → ErrorCode::VALIDATION（不打 API）
	// ====================================================================

	/**
	 * PAYUNi UPP：超額退款（2000 > total 1000）→ 正規化 VALIDATION
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 */
	public function test_PayuniUpp_超額退款回正規化VALIDATION(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);
		// 信用卡明細：若非超額會回 true；超額守衛須先攔截
		( new PayuniMetaKeys( $order ) )->update_payment_detail(
			[
				'TradeNo'     => 'UNI20260618011',
				'PaymentType' => '1',
			]
		);

		$result = ( new PayuniUppGateway() )->process_refund( $order->get_id(), 2000.0, '超額退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$this->assertSame( ErrorCode::VALIDATION->value, $result->get_error_code() );
	}

	/**
	 * PAYUNi UNi Embed：超額退款 → 正規化 VALIDATION
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 */
	public function test_PayuniUniEmbed_超額退款回正規化VALIDATION(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => 1000,
			]
		);
		( new PayuniUniEmbedMetaKeys( $order ) )->update_payment_detail(
			[
				'TradeNo'     => 'UNI20260618012',
				'PaymentType' => '1',
				'Gateway'     => '9',
			]
		);

		$result = ( new PayuniUniEmbedGateway() )->process_refund( $order->get_id(), 2000.0, '超額退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
	}

	/**
	 * PayNow：超額退款 → 正規化 VALIDATION
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 */
	public function test_Paynow_超額退款回正規化VALIDATION(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PaynowGateway::ID,
				'total'          => 1000,
			]
		);
		( new PaynowMetaKeys( $order ) )->update_payment_detail(
			[
				'PaymentType' => 'Credit',
			]
		);

		$result = ( new PaynowGateway() )->process_refund( $order->get_id(), 2000.0, '超額退款' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
	}

	// ====================================================================
	// amount=null 守衛維持回 false（既有契約，不得改成 WP_Error）
	// ====================================================================

	/**
	 * 6 金流 amount=null process_refund 一律維持回 false（不退化既有契約）
	 *
	 * @test
	 * @group edge
	 */
	public function test_六金流amount_null退款一律回false(): void {
		$cases = [
			[ PayuniUppGateway::ID, new PayuniUppGateway() ],
			[ PayuniUniEmbedGateway::ID, new PayuniUniEmbedGateway() ],
			[ PaynowGateway::ID, new PaynowGateway() ],
			[ EcpgGateway::ID, new EcpgGateway() ],
			[ AioRedirectGateway::ID, new AioRedirectGateway() ],
			[ 'shopline_payment_redirect', new RedirectGateway() ],
		];

		foreach ( $cases as [ $payment_method, $gateway ] ) {
			$order  = $this->create_wc_order(
				[
					'status'         => 'processing',
					'payment_method' => $payment_method,
					'total'          => 1000,
				]
			);
			$result = $gateway->process_refund( $order->get_id(), null, '' );
			$this->assertFalse(
				$result,
				"{$payment_method} amount=null 應維持回 false（既有 PaymentProviderContractTest 契約）"
			);
		}
	}

	/**
	 * 6 金流 amount=0 process_refund 維持回 false（≤0 守衛不改）
	 *
	 * @test
	 * @group edge
	 */
	public function test_六金流amount_零退款一律回false(): void {
		$cases = [
			[ PayuniUppGateway::ID, new PayuniUppGateway() ],
			[ PayuniUniEmbedGateway::ID, new PayuniUniEmbedGateway() ],
			[ PaynowGateway::ID, new PaynowGateway() ],
			[ EcpgGateway::ID, new EcpgGateway() ],
			[ AioRedirectGateway::ID, new AioRedirectGateway() ],
			[ 'shopline_payment_redirect', new RedirectGateway() ],
		];

		foreach ( $cases as [ $payment_method, $gateway ] ) {
			$order  = $this->create_wc_order(
				[
					'status'         => 'processing',
					'payment_method' => $payment_method,
					'total'          => 1000,
				]
			);
			$result = $gateway->process_refund( $order->get_id(), 0.0, '' );
			$this->assertFalse( $result, "{$payment_method} amount=0 應維持回 false（≤0 守衛不改）" );
		}
	}

	// ====================================================================
	// ShoplinePayment：can_refund 拋例外（catch \Throwable）→ ErrorCode::UNKNOWN
	// ====================================================================

	/**
	 * ShoplinePayment：退款流程拋例外時 process_refund → 正規化 UNKNOWN（never-throw）
	 *
	 * Shopline process_refund 內 PaymentDTO::from_order / can_refund 可能拋例外；
	 * 既有 catch \Throwable 回散裝 WP_Error('refund_failed') → 改正規化 UNKNOWN，
	 * raw_message 保留原始例外訊息供 debug，且不向 WC 退款流程拋（never-throw）。
	 *
	 * 觸發例外：訂單缺 SLP 付款資料（_pc_payment_detail / identity）→ from_order 解析失敗。
	 *
	 * @test
	 * @group error
	 * @group shopline
	 */
	public function test_Shopline_退款例外回正規化UNKNOWN且never_throw(): void {
		// Given: Shopline 訂單但缺付款明細（使 PaymentDTO::from_order 拋例外）
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000,
			]
		);
		$gateway = new RedirectGateway();

		// When / Then: never-throw — 不應拋例外，且回正規化 UNKNOWN \WP_Error
		try {
			$result = $gateway->process_refund( $order->get_id(), 1000.0, '測試退款' );
		} catch ( \Throwable $e ) {
			$this->fail( "Shopline process_refund 不應拋例外（never-throw），但拋出：{$e->getMessage()}" );
			return;
		}

		$this->assertInstanceOf( \WP_Error::class, $result, 'Shopline 退款例外應回 \WP_Error' );
		$this->assertSame(
			ErrorCode::UNKNOWN,
			NormalizedError::get_code( $result ),
			'Shopline 退款例外應正規化為 UNKNOWN（取代舊 refund_failed）'
		);
		$this->assertSame( ErrorCode::UNKNOWN->value, $result->get_error_code() );
	}
}
