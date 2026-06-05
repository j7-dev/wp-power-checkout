<?php
/**
 * 綠界發票 PII 遮蔽 helper 測試
 *
 * 驗證 log / order note 前對發票明細的 PII 遮蔽：
 *   - Email：a***@b.com
 *   - Phone：中間遮蔽
 *   - 統編 / 載具：部分遮蔽
 *   - 地址：遮蔽
 *   - 非 PII（RelateNumber / SalesAmount / RtnCode）保留
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\PiiMasker;
use Tests\Integration\TestCase;

/**
 * PiiMasker 測試類別
 *
 * @group integration
 * @group invoice
 * @group ecpay
 * @group security
 */
final class PiiMaskerTest extends TestCase {

	/**
	 * @test
	 * @group security
	 */
	public function test_mask_email遮蔽中間字元保留首字與網域(): void {
		$this->assertSame( 'a***@example.com', PiiMasker::mask_email( 'alice@example.com' ) );
		$this->assertSame( 'b***@b.com', PiiMasker::mask_email( 'bob@b.com' ) );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_mask_phone遮蔽中間數字(): void {
		$masked = PiiMasker::mask_phone( '0912345678' );
		// 不可含完整號碼
		$this->assertStringNotContainsString( '0912345678', $masked );
		// 保留頭尾辨識度，中間以 * 遮蔽
		$this->assertStringContainsString( '*', $masked );
		$this->assertStringStartsWith( '0912', substr( $masked, 0, 4 ) === '0912' ? '0912' : substr( $masked, 0, 2 ) );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_mask_partial統編部分遮蔽(): void {
		$masked = PiiMasker::mask_partial( '12345678' );
		$this->assertStringNotContainsString( '12345678', $masked );
		$this->assertStringContainsString( '*', $masked );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_mask_invoice_data完整遮蔽PII欄位並保留非PII(): void {
		$data = [
			'MerchantID'         => '2000132',
			'RelateNumber'       => 'PC12abcdef',
			'CustomerEmail'      => 'alice@example.com',
			'CustomerPhone'      => '0912345678',
			'CustomerIdentifier' => '12345678',
			'CustomerName'       => '王小明',
			'CustomerAddr'       => '台北市信義區信義路五段7號',
			'CarrierNum'         => '/ABC1234',
			'SalesAmount'        => 1000,
			'RtnCode'            => 1,
		];

		$masked = PiiMasker::mask_invoice_data( $data );

		// PII 欄位：不可出現原始明文
		$flat = (string) \wp_json_encode( $masked, JSON_UNESCAPED_UNICODE );
		$this->assertStringNotContainsString( 'alice@example.com', $flat, 'Email 應被遮蔽' );
		$this->assertStringNotContainsString( '0912345678', $flat, 'Phone 應被遮蔽' );
		$this->assertStringNotContainsString( '12345678', $flat, '統編應被遮蔽' );
		$this->assertStringNotContainsString( '王小明', $flat, '客戶姓名應被遮蔽' );
		$this->assertStringNotContainsString( '信義路五段7號', $flat, '地址應被遮蔽' );
		$this->assertStringNotContainsString( '/ABC1234', $flat, '載具號碼應被遮蔽' );

		// 非 PII 欄位：原值保留
		$this->assertSame( '2000132', $masked['MerchantID'] );
		$this->assertSame( 'PC12abcdef', $masked['RelateNumber'] );
		$this->assertSame( 1000, $masked['SalesAmount'] );
		$this->assertSame( 1, $masked['RtnCode'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_mask_invoice_data遞迴遮蔽巢狀Items中的PII不影響商品名(): void {
		$data = [
			'CustomerEmail' => 'x@y.com',
			'Items'         => [
				[
					'ItemName'   => '測試商品A',
					'ItemAmount' => 500,
				],
			],
		];

		$masked = PiiMasker::mask_invoice_data( $data );

		// 商品明細為非 PII，金額 / 名稱保留
		$this->assertSame( '測試商品A', $masked['Items'][0]['ItemName'] );
		$this->assertSame( 500, $masked['Items'][0]['ItemAmount'] );
		// 頂層 Email 被遮蔽
		$this->assertNotSame( 'x@y.com', $masked['CustomerEmail'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_mask_email非法格式不外洩原值(): void {
		// 無 @ 的字串仍應被遮蔽，不可原樣外洩
		$masked = PiiMasker::mask_email( 'notanemail' );
		$this->assertNotSame( 'notanemail', $masked );
	}
}
