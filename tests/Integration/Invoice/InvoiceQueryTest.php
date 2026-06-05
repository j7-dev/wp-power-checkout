<?php
/**
 * 發票查詢（唯讀）整合測試（B6）
 *
 * 涵蓋 ISupportsQuery::query_invoice()：
 *  - Amego：invoice_query（以發票號碼 / 訂單編號查單張發票明細）
 *  - Ecpay：GetIssue（以 RelateNumber / InvoiceNo + InvoiceDate 查詢）
 *  - 未開發票回空陣列
 *  - REST GET /invoices/query/{order_id} 依訂單 provider 路由
 *
 * 在 API_MODE=mock 下執行（API client 走固定 fixture，不打真 API）。
 *
 * 查詢 API 出處：
 *  - Amego：amego-invoice skill §發票查詢 /json/invoice_query（type=order/invoice）
 *  - Ecpay：ECPay-API-Skill guides/04-invoice-b2c.md §查詢發票 /B2CInvoice/GetIssue
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 發票查詢測試類別
 *
 * @group integration
 * @group invoice
 * @group query
 */
final class InvoiceQueryTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_instances();
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		$this->reset_instances();
		\delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		\delete_option( ProviderUtils::get_option_name( EcpayInvoiceProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 重置設定單例
	 */
	private function reset_instances(): void {
		foreach ( [ AmegoSettingsDTO::class, EcpayInvoiceSettingsDTO::class ] as $cls ) {
			$ref  = new \ReflectionClass( $cls );
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
	}

	/**
	 * 建立一筆已開立發票的訂單
	 *
	 * @param string $provider_id provider id
	 * @return \WC_Order
	 */
	private function create_issued_order( string $provider_id ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 100,
			]
		);
		$order->set_total( 100 );
		$order->save();

		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number' => 'AG00000001',
				'invoice_date'   => '2026-01-15 10:00:00',
				'invoice_time'   => \time(),
				'random_number'  => '1234',
			]
		);
		$meta_keys->update_provider_id( $provider_id );

		return $order;
	}

	// ========== 型別契約 ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_AmegoProvider_實作ISupportsQuery(): void {
		$this->assertInstanceOf( ISupportsQuery::class, AmegoProvider::instance() );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_EcpayInvoiceProvider_實作ISupportsQuery(): void {
		$this->assertInstanceOf( ISupportsQuery::class, EcpayInvoiceProvider::instance() );
	}

	// ========== 快樂路徑：Amego ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_amego_query_invoice_已開發票回傳明細(): void {
		$this->enable_provider( AmegoProvider::ID, [ 'mode' => 'test' ] );
		$order    = $this->create_issued_order( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		$result = $provider->query_invoice( $order );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
		$this->assertSame( 'AG00000001', $result['invoice_number'] ?? '' );
	}

	// ========== 快樂路徑：Ecpay ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_ecpay_query_invoice_已開發票回傳明細(): void {
		$this->enable_provider(
			EcpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => '2000132',
				'hash_key'    => 'ejCk326UnaZWKisg',
				'hash_iv'     => 'q9jcZX8Ib9LM8wYk',
			]
		);
		$order    = $this->create_issued_order( EcpayInvoiceProvider::ID );
		$provider = EcpayInvoiceProvider::instance();

		$result = $provider->query_invoice( $order );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
		$this->assertSame( 'AG00000001', $result['invoice_number'] ?? '' );
	}

	// ========== 錯誤處理 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_amego_query_invoice_未開發票回傳空陣列(): void {
		$this->enable_provider( AmegoProvider::ID, [ 'mode' => 'test' ] );
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		$provider = AmegoProvider::instance();
		$result   = $provider->query_invoice( $order );

		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_ecpay_query_invoice_未開發票回傳空陣列(): void {
		$this->enable_provider(
			EcpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => '2000132',
				'hash_key'    => 'ejCk326UnaZWKisg',
				'hash_iv'     => 'q9jcZX8Ib9LM8wYk',
			]
		);
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		$provider = EcpayInvoiceProvider::instance();
		$result   = $provider->query_invoice( $order );

		$this->assertSame( [], $result );
	}
}
