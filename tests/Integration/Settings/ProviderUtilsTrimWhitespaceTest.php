<?php
/**
 * ProviderUtils::update_option 自動修剪前後不可見字元整合測試
 * 對應 Issue #16，覆蓋 specs/features/settings/trim-key-whitespace.feature 規則 1
 *
 * 測試重點：
 *  1. 寫入時自動修剪 string 欄位前後不可見字元（最終防線）
 *  2. 中間不可見字元不受影響
 *  3. 純不可見字元欄位變空字串
 *  4. 陣列內元素遞迴修剪
 *  5. 非字串型別（數值、布林、enabled）不受影響
 *  6. 未來新 Provider 自動受惠
 */

declare( strict_types=1 );

namespace Tests\Integration\Settings;

use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group settings
 * @group trim
 */
final class ProviderUtilsTrimWhitespaceTest extends TestCase {

	/**
	 * 受測 provider 清單，含 future_provider 用以驗證未來自動受惠
	 *
	 * @var array<string>
	 */
	private array $cleanup_provider_ids = [
		RedirectGateway::ID,
		AmegoProvider::ID,
		'future_provider',
	];

	/**
	 * 每次測試後清理受測 wp_options
	 */
	public function tear_down(): void {
		foreach ( $this->cleanup_provider_ids as $id ) {
			\delete_option( ProviderUtils::get_option_name( $id ) );
		}
		parent::tear_down();
	}

	// ========== 規則 1：批次寫入時自動修剪 string 欄位 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_批次寫入時SLP金鑰類欄位自動修剪前後空白(): void {
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'platformId' => '  platform_001  ',
				'merchantId' => 'merchant_xyz ',
				'apiKey'     => ' sk_live_abc123 ',
				'clientKey'  => 'pk_live_xyz789  ',
				'signKey'    => '  sign_secret_key',
				'apiUrl'     => ' https://a.com/ ',
			]
		);

		$stored = \get_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );

		$this->assertIsArray( $stored );
		$this->assertSame( 'platform_001', $stored['platformId'] );
		$this->assertSame( 'merchant_xyz', $stored['merchantId'] );
		$this->assertSame( 'sk_live_abc123', $stored['apiKey'] );
		$this->assertSame( 'pk_live_xyz789', $stored['clientKey'] );
		$this->assertSame( 'sign_secret_key', $stored['signKey'] );
		$this->assertSame( 'https://a.com/', $stored['apiUrl'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_批次寫入時Amego欄位自動修剪前後空白(): void {
		ProviderUtils::update_option(
			AmegoProvider::ID,
			[
				'invoice' => ' 12345678 ',
				'app_key' => '  amego_key  ',
			]
		);

		$stored = \get_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );

		$this->assertIsArray( $stored );
		$this->assertSame( '12345678', $stored['invoice'] );
		$this->assertSame( 'amego_key', $stored['app_key'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_單一key寫入時也會自動修剪string值(): void {
		ProviderUtils::update_option( RedirectGateway::ID, 'apiKey', '  sk_live_xyz  ' );
		$this->assertSame( 'sk_live_xyz', ProviderUtils::get_option( RedirectGateway::ID, 'apiKey' ) );
	}

	// ========== 規則 1：描述、標題類欄位也適用 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_描述標題類欄位也適用前後修剪(): void {
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'title'             => '  我的金流標題  ',
				'description'       => ' 我的金流描述 ',
				'order_button_text' => '  立即付款 ',
			]
		);

		$stored = \get_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );

		$this->assertIsArray( $stored );
		$this->assertSame( '我的金流標題', $stored['title'] );
		$this->assertSame( '我的金流描述', $stored['description'] );
		$this->assertSame( '立即付款', $stored['order_button_text'] );
	}

	// ========== 規則 1：多種不可見字元 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_全形空白與零寬字元也會被修剪(): void {
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'apiKey'    => "\u{3000}sk_live_abc\u{3000}",
				'clientKey' => "\u{200B}pk_live_xyz\u{FEFF}",
				'signKey'   => "\u{00A0}sign_xyz\u{200D}",
			]
		);

		$stored = \get_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );

		$this->assertIsArray( $stored );
		$this->assertSame( 'sk_live_abc', $stored['apiKey'] );
		$this->assertSame( 'pk_live_xyz', $stored['clientKey'] );
		$this->assertSame( 'sign_xyz', $stored['signKey'] );
	}

	// ========== 規則 1：邊界 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_中間空白被保留(): void {
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[ 'apiKey' => 'sk_live abc 123' ]
		);
		$this->assertSame(
			'sk_live abc 123',
			ProviderUtils::get_option( RedirectGateway::ID, 'apiKey' )
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_純不可見字元的欄位儲存後變成空字串(): void {
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[ 'apiKey' => '    ' ]
		);
		$this->assertSame(
			'',
			ProviderUtils::get_option( RedirectGateway::ID, 'apiKey' )
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_陣列類欄位內元素也會被修剪(): void {
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'allowPaymentMethodList' => [ '  CreditCard  ', ' LinePay ' ],
			]
		);

		$stored = ProviderUtils::get_option( RedirectGateway::ID, 'allowPaymentMethodList' );
		$this->assertSame( [ 'CreditCard', 'LinePay' ], $stored );
	}

	// ========== 規則 4：不影響其他既有行為 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_數值類欄位不受trim影響(): void {
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'min_amount' => 5,
				'max_amount' => 50000,
				'expire_min' => 360,
			]
		);

		$this->assertSame( 5, ProviderUtils::get_option( RedirectGateway::ID, 'min_amount' ) );
		$this->assertSame( 50000, ProviderUtils::get_option( RedirectGateway::ID, 'max_amount' ) );
		$this->assertSame( 360, ProviderUtils::get_option( RedirectGateway::ID, 'expire_min' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_布林類enabled欄位不受trim影響(): void {
		// Given：先以 enabled=yes 寫入
		ProviderUtils::update_option( AmegoProvider::ID, [ 'enabled' => 'yes' ] );
		$this->assertSame( 'yes', ProviderUtils::get_option( AmegoProvider::ID, 'enabled' ) );

		// When：toggle 切換為 no
		ProviderUtils::toggle( AmegoProvider::ID );

		// Then：值正確切換為 'no' ，未受 trim 影響（無前後空白）
		$this->assertSame( 'no', ProviderUtils::get_option( AmegoProvider::ID, 'enabled' ) );
	}

	// ========== 未來 Provider 自動受惠 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_未來新Provider自動受惠trim邏輯(): void {
		ProviderUtils::update_option(
			'future_provider',
			[ 'some_key' => '  value  ' ]
		);

		$stored = \get_option( ProviderUtils::get_option_name( 'future_provider' ) );
		$this->assertIsArray( $stored );
		$this->assertSame( 'value', $stored['some_key'] );
	}
}
