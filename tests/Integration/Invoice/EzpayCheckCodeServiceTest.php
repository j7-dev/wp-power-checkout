<?php
/**
 * ezPay CheckCodeService 整合測試
 *
 * 驗證回應驗證碼（CheckCode）計算符合 ezPay 官方規格：
 *  - 取五欄位：InvoiceTransNo / MerchantID / MerchantOrderNo / RandomNum / TotalAmt
 *  - 依英文字母 A-Z ksort() 排序
 *  - http_build_query 串聯後，前綴 "HashIV=<IV>&" 後綴 "&HashKey=<Key>"
 *  - SHA256 → 大寫 hex
 *
 * 官方驗證向量出處：ezpay-invoice skill references/concepts.md §CheckCode 回應驗證
 *
 * 注意：此為整合測試層，官方固定向量請同步執行離線 harness：
 *   php tests/offline/ezpay-pure-harness.php
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\CheckCodeService;
use Tests\Integration\TestCase;

/**
 * EzpayCheckCodeService 驗證碼測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 */
final class EzpayCheckCodeServiceTest extends TestCase {

	/**
	 * 官方固定向量（來自 concepts.md §官方範例）
	 * SHA256(HashIV=1234567891234567&InvoiceTransNo=14061313541640927&MerchantID=3622183
	 *        &MerchantOrderNo=201409170000001&RandomNum=0142&TotalAmt=500
	 *        &HashKey=abcdefghijklmnopqrstuvwxyzabcdef)
	 */
	private const OFFICIAL_VECTOR = [
		'HashKey'         => 'abcdefghijklmnopqrstuvwxyzabcdef',
		'HashIV'          => '1234567891234567',
		'InvoiceTransNo'  => '14061313541640927',
		'MerchantID'      => '3622183',
		'MerchantOrderNo' => '201409170000001',
		'RandomNum'       => '0142',
		'TotalAmt'        => '500',
		// 官方期望輸出（SHA256 大寫）
		'expected'        => '303AB800650B724733B5D91CBCE075D9EA09E4CDE9CD33461D45F07D5EC7EECB',
	];

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_CheckCodeService_類別可實例化(): void {
		$service = new CheckCodeService(
			self::OFFICIAL_VECTOR['HashKey'],
			self::OFFICIAL_VECTOR['HashIV']
		);
		$this->assertInstanceOf( CheckCodeService::class, $service );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_verify_對官方固定向量回傳true(): void {
		$v       = self::OFFICIAL_VECTOR;
		$service = new CheckCodeService( $v['HashKey'], $v['HashIV'] );

		$fields = [
			'InvoiceTransNo'  => $v['InvoiceTransNo'],
			'MerchantID'      => $v['MerchantID'],
			'MerchantOrderNo' => $v['MerchantOrderNo'],
			'RandomNum'       => $v['RandomNum'],
			'TotalAmt'        => $v['TotalAmt'],
		];

		$this->assertTrue( $service->verify( $fields, $v['expected'] ) );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_compute_對官方固定向量產出正確大寫SHA256(): void {
		$v       = self::OFFICIAL_VECTOR;
		$service = new CheckCodeService( $v['HashKey'], $v['HashIV'] );

		$fields = [
			'InvoiceTransNo'  => $v['InvoiceTransNo'],
			'MerchantID'      => $v['MerchantID'],
			'MerchantOrderNo' => $v['MerchantOrderNo'],
			'RandomNum'       => $v['RandomNum'],
			'TotalAmt'        => $v['TotalAmt'],
		];

		$checkCode = $service->compute( $fields );
		$this->assertSame( $v['expected'], $checkCode, 'CheckCode 必須與官方向量完全吻合' );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_欄位輸入順序不影響計算結果(): void {
		$v       = self::OFFICIAL_VECTOR;
		$service = new CheckCodeService( $v['HashKey'], $v['HashIV'] );

		// 打亂順序
		$fields_shuffled = [
			'TotalAmt'        => $v['TotalAmt'],
			'RandomNum'       => $v['RandomNum'],
			'InvoiceTransNo'  => $v['InvoiceTransNo'],
			'MerchantOrderNo' => $v['MerchantOrderNo'],
			'MerchantID'      => $v['MerchantID'],
		];

		$checkCode = $service->compute( $fields_shuffled );
		$this->assertSame( $v['expected'], $checkCode, 'ksort 後順序應一致，結果不受輸入順序影響' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_CheckCode為大寫hex(): void {
		$v       = self::OFFICIAL_VECTOR;
		$service = new CheckCodeService( $v['HashKey'], $v['HashIV'] );

		$fields = [
			'InvoiceTransNo'  => $v['InvoiceTransNo'],
			'MerchantID'      => $v['MerchantID'],
			'MerchantOrderNo' => $v['MerchantOrderNo'],
			'RandomNum'       => $v['RandomNum'],
			'TotalAmt'        => $v['TotalAmt'],
		];

		$checkCode = $service->compute( $fields );
		$this->assertSame( strtoupper( $checkCode ), $checkCode, 'CheckCode 必須為大寫' );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{64}$/', $checkCode, 'SHA256 大寫 hex 應為 64 字元' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_TotalAmt為整數字串與整數輸入結果相同(): void {
		$v       = self::OFFICIAL_VECTOR;
		$service = new CheckCodeService( $v['HashKey'], $v['HashIV'] );

		$fields_str = [
			'InvoiceTransNo'  => $v['InvoiceTransNo'],
			'MerchantID'      => $v['MerchantID'],
			'MerchantOrderNo' => $v['MerchantOrderNo'],
			'RandomNum'       => $v['RandomNum'],
			'TotalAmt'        => '100',
		];
		$fields_int = array_merge( $fields_str, [ 'TotalAmt' => 100 ] );

		$this->assertSame(
			$service->compute( $fields_str ),
			$service->compute( $fields_int ),
			'TotalAmt 不論字串或整數，結果應相同'
		);
	}

	// ========== 安全測試（Security） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_security_錯誤CheckCode驗證失敗(): void {
		$v       = self::OFFICIAL_VECTOR;
		$service = new CheckCodeService( $v['HashKey'], $v['HashIV'] );

		$fields = [
			'InvoiceTransNo'  => $v['InvoiceTransNo'],
			'MerchantID'      => $v['MerchantID'],
			'MerchantOrderNo' => $v['MerchantOrderNo'],
			'RandomNum'       => $v['RandomNum'],
			'TotalAmt'        => $v['TotalAmt'],
		];

		$this->assertFalse(
			$service->verify( $fields, 'DEADBEEF' . str_repeat( '0', 56 ) ),
			'偽造的 CheckCode 必須驗證失敗'
		);
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_security_篡改TotalAmt後CheckCode驗證失敗(): void {
		$v       = self::OFFICIAL_VECTOR;
		$service = new CheckCodeService( $v['HashKey'], $v['HashIV'] );

		$tampered_fields = [
			'InvoiceTransNo'  => $v['InvoiceTransNo'],
			'MerchantID'      => $v['MerchantID'],
			'MerchantOrderNo' => $v['MerchantOrderNo'],
			'RandomNum'       => $v['RandomNum'],
			'TotalAmt'        => '9999', // 篡改金額
		];

		$this->assertFalse(
			$service->verify( $tampered_fields, $v['expected'] ),
			'篡改 TotalAmt 後原始 CheckCode 必須驗證失敗'
		);
	}
}
