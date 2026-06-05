<?php
/**
 * 綠界電子收據 PII 遮蔽 helper 測試
 *
 * 驗證 log / order note 前對收據明細的 PII 遮蔽：
 *   - Email：a***@b.com
 *   - Phone / CellPhone：中間遮蔽
 *   - Identifier（統編/證號）：部分遮蔽
 *   - Name / 地址：完整遮蔽
 *   - 非 PII（RelateNumber / Amount / RtnCode / ReceiptNo / Items）保留
 */

declare( strict_types=1 );

namespace Tests\Integration\Receipt;

use J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Helpers\ReceiptPiiMasker;
use Tests\Integration\TestCase;

/**
 * ReceiptPiiMasker 測試類別
 *
 * @group integration
 * @group receipt
 * @group ecpay
 * @group security
 */
final class ReceiptPiiMaskerTest extends TestCase {

	/**
	 * @test
	 * @group security
	 */
	public function test_mask_receipt_data完整遮蔽PII欄位並保留非PII(): void {
		$data = [
			'MerchantID'      => '2000132',
			'RelateNumber'    => 'RC12ABCDEF',
			'ReceiptNo'       => 'Sale2026040800000448',
			'Email'           => 'alice@example.com',
			'Phone'           => '0223456789',
			'CellPhone'       => '0912345678',
			'Identifier'      => '12345678',
			'Name'            => '王小明',
			'DeliveryAddress' => '台北市信義區信義路五段7號',
			'Amount'          => 1000,
			'RtnCode'         => 1,
		];

		$masked = ReceiptPiiMasker::mask_receipt_data( $data );
		$flat   = (string) \wp_json_encode( $masked, JSON_UNESCAPED_UNICODE );

		// PII 欄位：不可出現原始明文
		$this->assertStringNotContainsString( 'alice@example.com', $flat, 'Email 應被遮蔽' );
		$this->assertStringNotContainsString( '0223456789', $flat, 'Phone 應被遮蔽' );
		$this->assertStringNotContainsString( '0912345678', $flat, 'CellPhone 應被遮蔽' );
		$this->assertStringNotContainsString( '12345678', $flat, 'Identifier 應被遮蔽' );
		$this->assertStringNotContainsString( '王小明', $flat, '抬頭應被遮蔽' );
		$this->assertStringNotContainsString( '信義路五段7號', $flat, '地址應被遮蔽' );

		// 非 PII 欄位：原值保留
		$this->assertSame( '2000132', $masked['MerchantID'] );
		$this->assertSame( 'RC12ABCDEF', $masked['RelateNumber'] );
		$this->assertSame( 'Sale2026040800000448', $masked['ReceiptNo'] );
		$this->assertSame( 1000, $masked['Amount'] );
		$this->assertSame( 1, $masked['RtnCode'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_mask_receipt_data遞迴遮蔽Items不影響商品名與金額(): void {
		$data = [
			'Email' => 'x@y.com',
			'Items' => [
				[
					'ItemName'   => '捐贈物品一批',
					'ItemAmount' => 500,
				],
			],
		];

		$masked = ReceiptPiiMasker::mask_receipt_data( $data );

		$this->assertSame( '捐贈物品一批', $masked['Items'][0]['ItemName'] );
		$this->assertSame( 500, $masked['Items'][0]['ItemAmount'] );
		$this->assertNotSame( 'x@y.com', $masked['Email'] );
	}
}
