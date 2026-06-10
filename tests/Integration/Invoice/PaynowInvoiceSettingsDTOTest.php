<?php
/**
 * PayNow 電子發票設定 DTO 整合測試
 *
 * 驗證 PaynowInvoiceSettingsDTO 的欄位讀寫、api_url dev/prod 切換，
 * 以及最關鍵的 R5 裁決：option key = woocommerce_paynow_invoice_settings，
 * 不得撞金流 woocommerce_paynow_settings。
 *
 * 規格出處：
 *  - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §B-Cycle 0（R5）
 *  - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §B-Cycle 0 裁決
 *  - paynow skill references/invoice-api.md §1（認證與環境）
 *
 * ⚠️ 本測試為 Red 階段：引用的 class 尚未實作，執行結果應為「class not found」失敗。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\PaynowInvoiceSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PaynowInvoiceSettingsDTO 測試類別
 *
 * @group happy
 * @group invoice
 * @group paynow
 */
final class PaynowInvoiceSettingsDTOTest extends TestCase {

	/** @var string PayNow 發票 Provider ID（R5 裁決） */
	private const PROVIDER_ID = 'paynow_invoice';

	/** @var string 期望的 WC option key（R5 裁決：不撞金流） */
	private const EXPECTED_OPTION_KEY = 'woocommerce_paynow_invoice_settings';

	/** @var string 金流的 WC option key（不得相同） */
	private const PAYMENT_OPTION_KEY = 'woocommerce_paynow_settings';

	/**
	 * 每次測試前：重置設定單例 + 啟用 paynow_invoice（測試模式）
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_instance();
		$this->enable_provider(
			self::PROVIDER_ID,
			[
				'mode'      => 'dev',
				'jwt_token' => 'test-jwt-token-value',
			]
		);
	}

	/**
	 * 每次測試後：清理設定與單例快取
	 */
	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 PaynowInvoiceSettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( PaynowInvoiceSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ID常數為paynow_invoice(): void {
		// R5 裁決：provider id = 'paynow_invoice'（非 'paynow'）
		$this->assertSame( 'paynow_invoice', PaynowInvoiceSettingsDTO::ID );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_option_key不撞金流paynow_settings(): void {
		// R5 最關鍵裁決：發票 option key 必須與金流分開，避免覆蓋金流設定
		$invoice_key = ProviderUtils::get_option_name( self::PROVIDER_ID );
		$payment_key = ProviderUtils::get_option_name( 'paynow' );

		$this->assertSame( self::EXPECTED_OPTION_KEY, $invoice_key, '發票 option key 應為 woocommerce_paynow_invoice_settings' );
		$this->assertSame( self::PAYMENT_OPTION_KEY, $payment_key, '金流 option key 應為 woocommerce_paynow_settings' );
		$this->assertNotSame( $invoice_key, $payment_key, '發票與金流的 option key 必須不同（R5 裁決）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_jwt_token欄位可存取(): void {
		$dto = PaynowInvoiceSettingsDTO::instance();
		$this->assertSame( 'test-jwt-token-value', $dto->jwt_token );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mode欄位dev模式(): void {
		$dto = PaynowInvoiceSettingsDTO::instance();
		// dev 模式（對應 Mode::TEST 或類似結構）
		$this->assertNotEmpty( $dto->mode );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_api_url_dev模式回測試端點(): void {
		// dev mode → https://invoiceapi-dev.paynow.com.tw/
		$dto = PaynowInvoiceSettingsDTO::instance();
		$url = $dto->api_url();
		$this->assertStringContainsString(
			'invoiceapi-dev.paynow.com.tw',
			$url,
			'dev 模式的 api_url 應指向 invoiceapi-dev.paynow.com.tw'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_api_url_prod模式回正式端點(): void {
		// 切換到 prod 模式
		$this->reset_settings_instance();
		$this->enable_provider(
			self::PROVIDER_ID,
			[
				'mode'      => 'prod',
				'jwt_token' => 'prod-jwt-token-value',
			]
		);

		$dto = PaynowInvoiceSettingsDTO::instance();
		$url = $dto->api_url();
		$this->assertStringContainsString(
			'invoiceapi-prod.paynow.com.tw',
			$url,
			'prod 模式的 api_url 應指向 invoiceapi-prod.paynow.com.tw'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_auto_issue_order_statuses欄位存在且為陣列(): void {
		$dto = PaynowInvoiceSettingsDTO::instance();
		$this->assertIsArray( $dto->auto_issue_order_statuses, 'auto_issue_order_statuses 應為陣列' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_auto_allowance_on_refund欄位存在(): void {
		$dto = PaynowInvoiceSettingsDTO::instance();
		// 欄位存在且可存取（無論預設值為何）
		$this->assertTrue(
			\property_exists( $dto, 'auto_allowance_on_refund' ),
			'PaynowInvoiceSettingsDTO 應有 auto_allowance_on_refund 欄位'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_instance_singleton單例回相同實例(): void {
		$dto1 = PaynowInvoiceSettingsDTO::instance();
		$dto2 = PaynowInvoiceSettingsDTO::instance();
		$this->assertSame( $dto1, $dto2, 'instance() 應回傳同一個單例' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_get_settings靜態方法回陣列含必要欄位(): void {
		$settings = PaynowInvoiceSettingsDTO::get_settings();
		$this->assertIsArray( $settings, 'get_settings() 應回傳陣列' );
		$this->assertNotEmpty( $settings, 'get_settings() 不應為空陣列' );
	}
}
