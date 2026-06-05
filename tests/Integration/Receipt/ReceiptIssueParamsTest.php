<?php
/**
 * ReceiptIssueParams 整合測試
 *
 * 驗證電子收據開立參數組裝：
 *   - Amount 等於 Items[].ItemAmount 加總（綠界要求）。
 *   - 公益收據僅帶 1 項商品。
 *   - 政治獻金補上 DonationInfo / PaymentMethod，金額上限驗證。
 *   - 必填欄位（MerchantID / Amount / Name / ReceiptType / RetrievalMethod / Items）齊全。
 */

declare( strict_types=1 );

namespace Tests\Integration\Receipt;

use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\EcpayReceiptSettingsDTO;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\ReceiptIssueParams;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Services\EcpayReceiptProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * ReceiptIssueParams 測試類別
 *
 * @group integration
 * @group receipt
 * @group ecpay
 */
final class ReceiptIssueParamsTest extends TestCase {

	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EcpayReceiptProvider::ID ) );
		parent::tear_down();
	}

	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( EcpayReceiptSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * @param int                  $receipt_type 收據類型
	 * @param array<string, mixed> $extra        額外設定
	 */
	private function settings( int $receipt_type = 1, array $extra = [] ): EcpayReceiptSettingsDTO {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EcpayReceiptProvider::ID ) );
		ProviderUtils::update_option(
			EcpayReceiptProvider::ID,
			\array_merge(
				[
					'enabled'              => 'yes',
					'mode'                 => 'test',
					'default_receipt_type' => $receipt_type,
				],
				$extra
			)
		);
		return EcpayReceiptSettingsDTO::instance();
	}

	/**
	 * 建立多商品訂單
	 *
	 * @param array<int, array{name: string, price: float, qty: int}> $lines 商品列
	 * @return \WC_Order
	 */
	private function create_order( array $lines ): \WC_Order {
		$order = wc_create_order();
		$total = 0.0;
		foreach ( $lines as $line ) {
			$product = new \WC_Product_Simple();
			$product->set_name( $line['name'] );
			$product->set_regular_price( (string) $line['price'] );
			$product->save();
			$order->add_product( $product, $line['qty'] );
			$total += $line['price'] * $line['qty'];
		}
		$order->calculate_totals();
		$order->set_total( $total );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->save();
		return $order;
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_一般收據_Amount等於Items加總(): void {
		$settings = $this->settings( 1 );
		$order    = $this->create_order(
			[
				[
					'name'  => '商品A',
					'price' => 120,
					'qty'   => 2,
				],
				[
					'name'  => '商品B',
					'price' => 60,
					'qty'   => 1,
				],
			]
		);

		$params = ReceiptIssueParams::from_order( $order, $settings );

		$items_sum = \array_sum( \array_column( $params->Items, 'ItemAmount' ) );
		$this->assertSame( $params->Amount, (int) $items_sum, 'Amount 必須等於 Items 加總' );
		$this->assertSame( 300, $params->Amount );
		$this->assertSame( 1, $params->ReceiptType );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_公益收據_僅帶一項商品(): void {
		$settings = $this->settings(
			2,
			[ 'donor_type' => '1' ]
		);
		$order    = $this->create_order(
			[
				[
					'name'  => '商品A',
					'price' => 100,
					'qty'   => 1,
				],
				[
					'name'  => '商品B',
					'price' => 200,
					'qty'   => 1,
				],
			]
		);

		$params = ReceiptIssueParams::from_order( $order, $settings );

		$this->assertCount( 1, $params->Items, '公益收據僅可帶 1 項商品' );
		$this->assertSame( 2, $params->ReceiptType );
		$this->assertSame( 1, $params->DonorType );
		// 自然人公益捐贈帶手機
		$this->assertNotEmpty( $params->CellPhone );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_政治獻金_補上DonationInfo與PaymentMethod(): void {
		$settings = $this->settings(
			4,
			[
				'donor_type'     => '1',
				'payment_method' => '1',
			]
		);
		$order    = $this->create_order(
			[
				[
					'name'  => '捐款',
					'price' => 5000,
					'qty'   => 1,
				],
			]
		);

		$params = ReceiptIssueParams::from_order( $order, $settings );

		$this->assertSame( 4, $params->ReceiptType );
		$this->assertSame( 1, $params->PaymentMethod );
		$this->assertNotEmpty( $params->DonationInfo );
		$this->assertArrayHasKey( 'DonationDate', $params->DonationInfo );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_政治獻金_匿名超過一萬validate回傳違規訊息(): void {
		$settings = $this->settings(
			4,
			[
				'donor_type'     => '5', // 匿名
				'payment_method' => '1',
			]
		);
		$order    = $this->create_order(
			[
				[
					'name'  => '捐款',
					'price' => 15000,
					'qty'   => 1,
				],
			]
		);

		$params    = ReceiptIssueParams::from_order( $order, $settings );
		$violation = $params->check_amount_limit();

		$this->assertNotNull( $violation );
		$this->assertStringContainsString( '10,000', $violation );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_政治獻金_現金超過十萬validate回傳違規訊息(): void {
		$settings = $this->settings(
			4,
			[
				'donor_type'     => '1',
				'payment_method' => '3', // 現金
			]
		);
		$order    = $this->create_order(
			[
				[
					'name'  => '捐款',
					'price' => 150000,
					'qty'   => 1,
				],
			]
		);

		$params    = ReceiptIssueParams::from_order( $order, $settings );
		$violation = $params->check_amount_limit();

		$this->assertNotNull( $violation );
		$this->assertStringContainsString( '100,000', $violation );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_必填欄位齊全且RelateNumber為大寫英數(): void {
		$settings = $this->settings( 1 );
		$order    = $this->create_order(
			[
				[
					'name'  => '商品A',
					'price' => 100,
					'qty'   => 1,
				],
			]
		);

		$params = ReceiptIssueParams::from_order( $order, $settings );
		$arr    = $params->to_array();

		foreach ( [ 'MerchantID', 'Amount', 'Name', 'ReceiptType', 'RetrievalMethod', 'ReceiptDate', 'RelateNumber', 'Items' ] as $key ) {
			$this->assertArrayHasKey( $key, $arr );
		}
		// RelateNumber 大寫英數、無特殊符號
		$this->assertMatchesRegularExpression( '/^[A-Z0-9]+$/', $params->RelateNumber );
		$this->assertLessThanOrEqual( 50, \strlen( $params->RelateNumber ) );
	}
}
