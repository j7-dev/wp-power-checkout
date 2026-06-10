<?php
/**
 * PayNow 電子發票 IssueParams DTO 整合測試
 *
 * 驗證 IssueParams 建構與驗證規則（R10）：
 *  - 非統編（B2C）→ tax_amount = 0（國稅局算稅，不帶稅額）
 *  - 統編（B2B）  → tax_amount = 實際稅額（自行計算）
 *  - 零稅率必填 zero_tax_rate_reason，缺者拋 \InvalidArgumentException
 *  - 載具與捐贈互斥：同時帶 carrier_type（非 None）與 npoban → 拋 \InvalidArgumentException
 *  - build_merchant_order_no()：格式 PCN{order_id} 或類似（對齊計劃 §IssueParams）
 *
 * 規格出處：
 *  - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §B-Cycle 0（R10）
 *  - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §B-Cycle 0（R10）
 *  - specs/features/invoice/paynow-invoice-issue.feature
 *  - paynow skill references/invoice-api.md §3（開立發票核心欄位）/ §10 / §11
 *
 * ⚠️ 本測試為 Red 階段：引用的 class 尚未實作，執行結果應為「class not found」失敗。
 * ⚠️ 幣別踩雷：WooCommerce 預設幣別為 USD，金額相關測試須顯式設 TWD。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\ECarrierType;
use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\ETaxType;
use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\EZeroTaxReason;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use Tests\Integration\TestCase;

/**
 * PayNow IssueParams DTO 測試類別
 *
 * @group happy
 * @group edge
 * @group error
 * @group invoice
 * @group paynow
 */
final class PaynowIssueParamsTest extends TestCase {

	/**
	 * 每次測試前：設定台幣（避免 USD 幣別污染金額計算）
	 */
	public function set_up(): void {
		parent::set_up();
		\update_option( 'woocommerce_currency', 'TWD' );
	}

	/**
	 * 建立最小有效的 B2C 個人手機條碼發票參數陣列
	 *
	 * @return array<string, mixed>
	 */
	private function minimal_b2c_barcode_params(): array {
		return [
			'order_no'             => 'PCN100',
			'total_amount'         => 1050,
			'tax_amount'           => 0,                              // B2C：非統編帶 0（R10）
			'tax_type'             => ETaxType::SaleTax->value,
			'carrier_type'         => ECarrierType::PhoneBarCodeCarrier->value,
			'carrier_id1'          => '/ABC1234',
			'carrier_id2'          => '/ABC1234',
			'npoban'               => null,
			'is_pass_customs'      => null,
			'zero_tax_rate_reason' => EZeroTaxReason::None->value,
			'buyer'                => [
				'name'       => '測試買家',
				'identifier' => '',
				'address'    => '',
				'phone'      => '0912345678',
				'email'      => 'buyer@example.com',
			],
			'items'                => [
				[
					'quantity'    => 1,
					'unit_price'  => 1050,
					'amount'      => 1050,
					'tax_type'    => ETaxType::SaleTax->value,
					'tax_amount'  => 0,
					'description' => '測試商品',
				],
			],
		];
	}

	/**
	 * 建立一筆有商品的訂單（含 issue_params meta）
	 *
	 * @param float                $total        訂單含稅實付（TWD）
	 * @param array<string, mixed> $issue_params 結帳填寫的發票資訊
	 *
	 * @return \WC_Order
	 */
	private function create_order_with_invoice_params( float $total, array $issue_params = [] ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => $total,
			]
		);

		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( (string) $total );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_total( $total );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();

		if ( $issue_params ) {
			( new MetaKeys( $order ) )->update_issue_params( $issue_params );
		}

		return $order;
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_B2C個人手機條碼參數可建立(): void {
		$params = IssueParams::create( $this->minimal_b2c_barcode_params() );
		$this->assertInstanceOf( IssueParams::class, $params );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_B2C非統編tax_amount為零(): void {
		// R10：非統編（B2C）→ tax_amount = 0（國稅局算稅，不帶稅額）
		// 依 feature §「首次開立 B2C 個人手機條碼載具發票成功」+ invoice-api §11.1
		$data = $this->minimal_b2c_barcode_params();
		$this->assertSame( 0, $data['tax_amount'], '非統編 B2C 發票 tax_amount 必須為 0（R10）' );

		$params = IssueParams::create( $data );
		$arr    = $params->to_array();

		$this->assertSame( 0, (int) $arr['tax_amount'], 'B2C 開立後 tax_amount 仍應為 0' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_B2B統編tax_amount為實際稅額(): void {
		// R10：統編（B2B）→ tax_amount = 實際稅額（自行計算）
		// 依 feature §「首次開立 B2B 公司統編發票成功」+ invoice-api §11.2
		// 總額 1050，稅額 50（5% = 1000 * 0.05），未稅 1000
		$data = [
			'order_no'             => 'PCN101',
			'total_amount'         => 1050,
			'tax_amount'           => 50,                              // B2B：帶實際稅額（R10）
			'tax_type'             => ETaxType::SaleTax->value,
			'carrier_type'         => ECarrierType::None->value,
			'carrier_id1'          => null,
			'carrier_id2'          => null,
			'npoban'               => null,
			'is_pass_customs'      => null,
			'zero_tax_rate_reason' => EZeroTaxReason::None->value,
			'buyer'                => [
				'name'       => '某某有限公司',
				'identifier' => '87654321',                            // 統編
				'address'    => '台北市中正區某街1號',
				'phone'      => '',
				'email'      => 'ap@company.com',
			],
			'items'                => [
				[
					'quantity'    => 1,
					'unit_price'  => 1000,
					'amount'      => 1000,
					'tax_type'    => ETaxType::SaleTax->value,
					'tax_amount'  => 50,
					'description' => '測試商品',
				],
			],
		];

		$params = IssueParams::create( $data );
		$arr    = $params->to_array();

		$this->assertSame( 50, (int) $arr['tax_amount'], 'B2B 統編發票 tax_amount 應為實際稅額 50（R10）' );
		$this->assertSame( '87654321', $arr['buyer']['identifier'] ?? '', 'B2B 應帶 buyer.identifier（統編）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_捐贈發票npoban存在且carrier_type為None(): void {
		// 依 feature §「首次開立捐贈發票成功」+ invoice-api §11.3
		$data = [
			'order_no'             => 'PCN102',
			'total_amount'         => 500,
			'tax_amount'           => 0,
			'tax_type'             => ETaxType::SaleTax->value,
			'carrier_type'         => ECarrierType::None->value,      // 捐贈時載具留 None
			'carrier_id1'          => null,
			'carrier_id2'          => null,
			'npoban'               => '919',                           // 愛心碼
			'is_pass_customs'      => null,
			'zero_tax_rate_reason' => EZeroTaxReason::None->value,
			'buyer'                => [
				'name'       => '王小明',
				'identifier' => '',
				'phone'      => '0912345678',
				'email'      => 'buyer@example.com',
			],
			'items'                => [
				[
					'quantity'    => 1,
					'unit_price'  => 500,
					'amount'      => 500,
					'tax_type'    => ETaxType::SaleTax->value,
					'tax_amount'  => 0,
					'description' => '商品 C',
				],
			],
		];

		$params = IssueParams::create( $data );
		$arr    = $params->to_array();

		$this->assertSame( '919', $arr['npoban'] ?? '', '捐贈發票應帶 npoban 愛心碼' );
		$this->assertSame( ECarrierType::None->value, $arr['carrier_type'] ?? '', '捐贈發票 carrier_type 應為 None' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_零稅率發票有reason時可建立(): void {
		// 依 invoice-api §11.4：ZeroTax 需帶 is_pass_customs + zero_tax_rate_reason
		$data = [
			'order_no'             => 'PCN103',
			'total_amount'         => 1000,
			'tax_amount'           => 0,
			'tax_type'             => ETaxType::ZeroTax->value,
			'carrier_type'         => ECarrierType::None->value,
			'carrier_id1'          => null,
			'carrier_id2'          => null,
			'npoban'               => null,
			'is_pass_customs'      => true,
			'zero_tax_rate_reason' => EZeroTaxReason::ExportGoods->value,
			'buyer'                => [
				'name'       => 'Foreign Co',
				'identifier' => '',
				'email'      => 'buyer@example.com',
			],
			'items'                => [
				[
					'quantity'    => 1,
					'unit_price'  => 1000,
					'amount'      => 1000,
					'tax_type'    => ETaxType::ZeroTax->value,
					'tax_amount'  => 0,
					'description' => 'Export goods',
				],
			],
		];

		$params = IssueParams::create( $data );
		$this->assertInstanceOf( IssueParams::class, $params );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_build_merchant_order_no_格式包含order_id(): void {
		// build_merchant_order_no() 必須由 order id 衍生（格式如 PCN{order_id}）
		$order  = $this->create_order_with_invoice_params( 1050 );
		$result = IssueParams::build_merchant_order_no( $order );

		$this->assertIsString( $result, 'build_merchant_order_no() 應回傳字串' );
		$this->assertNotEmpty( $result, 'build_merchant_order_no() 不應為空' );
		$this->assertStringContainsString(
			(string) $order->get_id(),
			$result,
			'build_merchant_order_no() 應包含訂單 ID'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_build_merchant_order_no_長度不超過上限(): void {
		// 訂單號過長時應截斷至合理上限
		$order  = $this->create_order_with_invoice_params( 1050 );
		$result = IssueParams::build_merchant_order_no( $order );

		// PayNow API 通常對 order_no 沒有嚴格長度上限，但合理上限為 64 字元
		$this->assertLessThanOrEqual( 64, \strlen( $result ), 'build_merchant_order_no() 長度應不超過 64 字元' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_from_order_B2C不帶issue_params預設手機條碼或None載具(): void {
		// 無 issue_params 時，由 from_order 建立，tax_amount 應為 0（R10）
		\update_option( 'woocommerce_currency', 'TWD' );
		$order = $this->create_order_with_invoice_params( 1050 );

		$params = IssueParams::from_order( $order );
		$arr    = $params->to_array();

		// B2C 預設 tax_amount = 0
		$this->assertSame( 0, (int) $arr['tax_amount'], 'B2C 預設開立 tax_amount 應為 0（R10）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_from_order_B2B統編帶實際稅額(): void {
		// 有 B2B issue_params 時，from_order 計算實際稅額（非 0）
		\update_option( 'woocommerce_currency', 'TWD' );
		$order = $this->create_order_with_invoice_params(
			1050,
			[
				'provider'    => 'paynow_invoice',
				'invoiceType' => 'company',
				'companyName' => '某某有限公司',
				'companyId'   => '87654321',
			]
		);

		$params = IssueParams::from_order( $order );
		$arr    = $params->to_array();

		// B2B 統編發票：稅額應 > 0
		$this->assertGreaterThan( 0, (int) $arr['tax_amount'], 'B2B 統編發票 tax_amount 應大於 0（R10）' );
		$this->assertSame( '87654321', $arr['buyer']['identifier'] ?? '', 'B2B 應帶 buyer.identifier（統編）' );
	}

	// ========== 錯誤處理（Error） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_error_載具與捐贈同時指定時拋例外(): void {
		// 依 feature §「同時帶載具與捐贈碼時開立失敗」+ R10（互斥）
		$data                 = $this->minimal_b2c_barcode_params();
		$data['carrier_type'] = ECarrierType::PhoneBarCodeCarrier->value;  // 有載具
		$data['npoban']       = '919';                                        // 同時有捐贈碼

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/載具與捐贈不可同時指定/' );

		IssueParams::create( $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_零稅率缺zero_tax_rate_reason時拋例外(): void {
		// 依 feature §「零稅率發票缺 zero_tax_rate_reason 時開立失敗」+ R10 + invoice-api §11.4
		$data                         = $this->minimal_b2c_barcode_params();
		$data['tax_type']             = ETaxType::ZeroTax->value;
		$data['is_pass_customs']      = true;
		$data['zero_tax_rate_reason'] = '';    // 故意留空

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/零稅率發票必填零稅率原因/' );

		IssueParams::create( $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_零稅率缺zero_tax_rate_reason時拋例外_null版(): void {
		// null 與空字串都應觸發驗證
		$data                         = $this->minimal_b2c_barcode_params();
		$data['tax_type']             = ETaxType::ZeroTax->value;
		$data['is_pass_customs']      = true;
		$data['zero_tax_rate_reason'] = null;

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/零稅率發票必填零稅率原因/' );

		IssueParams::create( $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_order_no空字串時拋例外(): void {
		$data             = $this->minimal_b2c_barcode_params();
		$data['order_no'] = '';

		$this->expectException( \InvalidArgumentException::class );

		IssueParams::create( $data );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_carrier_type_None加npoban空不視為互斥(): void {
		// 捐贈發票：carrier_type=None（紙本）且 npoban 有值 → 合法（非互斥）
		$data                 = $this->minimal_b2c_barcode_params();
		$data['carrier_type'] = ECarrierType::None->value;
		$data['carrier_id1']  = null;
		$data['carrier_id2']  = null;
		$data['npoban']       = '919';

		// 不應拋例外
		$params = IssueParams::create( $data );
		$this->assertInstanceOf( IssueParams::class, $params );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_carrier_type有值加npoban空不視為互斥(): void {
		// 手機條碼載具 + 無捐贈 → 合法（不互斥）
		$data                 = $this->minimal_b2c_barcode_params();
		$data['carrier_type'] = ECarrierType::PhoneBarCodeCarrier->value;
		$data['npoban']       = null;   // 無捐贈

		$params = IssueParams::create( $data );
		$this->assertInstanceOf( IssueParams::class, $params );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_SaleTax時zero_tax_rate_reason為None不拋例外(): void {
		// 應稅（SaleTax）不需填 zero_tax_rate_reason → 帶 None 合法
		$data = $this->minimal_b2c_barcode_params();
		$this->assertSame( ETaxType::SaleTax->value, $data['tax_type'] );
		$data['zero_tax_rate_reason'] = EZeroTaxReason::None->value;

		$params = IssueParams::create( $data );
		$this->assertInstanceOf( IssueParams::class, $params );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_from_order_total含小數時tax_amount計算正確(): void {
		// 金額含小數點（如 1050.00）不應影響稅額計算
		\update_option( 'woocommerce_currency', 'TWD' );
		$order = $this->create_order_with_invoice_params( 1050.00 );

		$params = IssueParams::from_order( $order );
		$arr    = $params->to_array();

		// B2C：tax_amount = 0
		$this->assertSame( 0, (int) $arr['tax_amount'], '含小數 total 的 B2C 發票 tax_amount 仍應為 0' );
		// total_amount 應為整數 1050
		$this->assertSame( 1050, (int) $arr['total_amount'], 'total_amount 應為整數 1050' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_build_merchant_order_no_僅含英數底線(): void {
		// 訂單號碼應僅含英數底線（某些 API 不接受特殊符號）
		$order  = $this->create_order_with_invoice_params( 1050 );
		$result = IssueParams::build_merchant_order_no( $order );

		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9_]+$/',
			$result,
			'build_merchant_order_no() 結果應僅含英數底線'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_to_array回傳結果含核心欄位(): void {
		$params = IssueParams::create( $this->minimal_b2c_barcode_params() );
		$arr    = $params->to_array();

		$required_keys = [ 'order_no', 'total_amount', 'tax_amount', 'tax_type', 'carrier_type' ];
		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey( $key, $arr, "to_array() 應包含 '{$key}' 欄位" );
		}
	}
}
