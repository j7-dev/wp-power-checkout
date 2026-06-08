<?php
/**
 * ezPay IssueParams DTO 整合測試
 *
 * 驗證 IssueParams 建構與驗證規則：
 *  - B2C：ItemPrice / ItemAmt 為含稅；Amt = round(TotalAmt / 1.05)
 *  - B2B：ItemPrice / ItemAmt 為未稅；Amt = TotalAmt（或未稅合計）
 *  - Category=B2C 必帶 BuyerEmail 或 BuyerPhone 至少一個
 *  - 載具與捐贈互斥：CarrierType 有值時 LoveCode 必空，反之亦然
 *  - MerchantOrderNo 為必填，空字串時拋 InvalidArgumentException
 *  - INV10004：ItemAmt ≠ ItemCount × ItemPrice → 拋出例外或驗證失敗
 *  - INV10012：TotalAmt ≠ Amt + TaxAmt → 驗證失敗
 *
 * 規格出處：ezpay-invoice skill references/api-reference.md + concepts.md §含稅/未稅
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use Tests\Integration\TestCase;

/**
 * EzpayIssueParams DTO 測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 */
final class EzpayIssueParamsTest extends TestCase {

	/**
	 * 最小有效 B2C 個人雲端發票參數（含稅 100 元）
	 *
	 * @return array<string, mixed>
	 */
	private function minimal_b2c_params(): array {
		return [
			'RespondType'     => 'JSON',
			'Version'         => '1.5',
			'TimeStamp'       => (string) time(),
			'MerchantOrderNo' => 'ORD-' . time(),
			'Category'        => 'B2C',
			'BuyerName'       => '測試買家',
			'BuyerEmail'      => 'buyer@example.com',
			'CarrierType'     => '0',
			'CarrierNum'      => '',
			'LoveCode'        => '',
			'PrintFlag'       => 'N',
			'TaxType'         => '1',
			'TaxRate'         => '5',
			'Amt'             => '95',   // round(100 / 1.05) ≒ 95
			'TaxAmt'          => '5',
			'TotalAmt'        => '100',
			'ItemName'        => '測試商品',
			'ItemCount'       => '1',
			'ItemUnit'        => '個',
			'ItemPrice'       => '100',  // B2C: 含稅單價
			'ItemAmt'         => '100',  // B2C: ItemCount × ItemPrice（含稅）
			'Status'          => '1',
		];
	}

	/**
	 * 最小有效 B2B 統編發票參數（未稅 100 元）
	 *
	 * @return array<string, mixed>
	 */
	private function minimal_b2b_params(): array {
		return [
			'RespondType'     => 'JSON',
			'Version'         => '1.5',
			'TimeStamp'       => (string) time(),
			'MerchantOrderNo' => 'B2B-' . time(),
			'Category'        => 'B2B',
			'BuyerName'       => '測試公司',
			'BuyerUBN'        => '87654321',
			'PrintFlag'       => 'Y',
			'TaxType'         => '1',
			'TaxRate'         => '5',
			'Amt'             => '100',  // B2B: 未稅金額
			'TaxAmt'          => '5',
			'TotalAmt'        => '105',  // B2B: 含稅總額 = Amt + TaxAmt
			'ItemName'        => '測試商品',
			'ItemCount'       => '1',
			'ItemUnit'        => '個',
			'ItemPrice'       => '100',  // B2B: 未稅單價
			'ItemAmt'         => '100',  // B2B: ItemCount × ItemPrice（未稅）
			'Status'          => '1',
		];
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_IssueParams_B2C有效參數可建立(): void {
		$params = IssueParams::create( $this->minimal_b2c_params() );
		$this->assertInstanceOf( IssueParams::class, $params );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_IssueParams_B2B有效參數可建立(): void {
		$params = IssueParams::create( $this->minimal_b2b_params() );
		$this->assertInstanceOf( IssueParams::class, $params );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_B2C雲端載具含稅金額計算正確(): void {
		$data                = $this->minimal_b2c_params();
		$data['CarrierType'] = '2'; // ezPay 電子發票載具
		$data['BuyerEmail']  = 'buyer@example.com';

		$params = IssueParams::create( $data );
		$arr    = $params->to_array();

		// B2C: Amt = TotalAmt - TaxAmt（或 round(TotalAmt / 1.05)）
		$this->assertSame( '100', $arr['TotalAmt'] ?? '' );
		$this->assertSame( '95', $arr['Amt'] ?? '' );
		$this->assertSame( '5', $arr['TaxAmt'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_B2C捐贈發票LoveCode存在(): void {
		$data                = $this->minimal_b2c_params();
		$data['CarrierType'] = '';
		$data['LoveCode']    = '168001';
		$data['PrintFlag']   = 'N';

		$params = IssueParams::create( $data );
		$arr    = $params->to_array();

		$this->assertSame( '168001', $arr['LoveCode'] ?? '' );
		$this->assertSame( '', $arr['CarrierType'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_B2B統編發票未稅ItemAmt(): void {
		$params = IssueParams::create( $this->minimal_b2b_params() );
		$arr    = $params->to_array();

		// B2B: ItemPrice = 未稅單價，ItemAmt = ItemCount × ItemPrice（未稅）
		$this->assertSame( '100', $arr['ItemPrice'] ?? '' );
		$this->assertSame( '100', $arr['ItemAmt'] ?? '' );
		$this->assertSame( 'B2B', $arr['Category'] ?? '' );
	}

	// ========== 錯誤處理（Error） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_error_CarrierType有值且LoveCode有值時拋例外(): void {
		$data                = $this->minimal_b2c_params();
		$data['CarrierType'] = '1'; // 手機條碼
		$data['LoveCode']    = '168001'; // 捐贈碼

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/載具與捐贈不可同時指定/' );

		IssueParams::create( $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_MerchantOrderNo空字串時拋例外(): void {
		$data                    = $this->minimal_b2c_params();
		$data['MerchantOrderNo'] = '';

		$this->expectException( \InvalidArgumentException::class );

		IssueParams::create( $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_INV10004_ItemAmt不等於ItemCount乘ItemPrice時拋例外(): void {
		$data              = $this->minimal_b2c_params();
		$data['ItemCount'] = '2';
		$data['ItemPrice'] = '100';
		$data['ItemAmt']   = '150'; // 應為 200，但填 150

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/ItemAmt|金額/' );

		IssueParams::create( $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_INV10012_TotalAmt不等於Amt加TaxAmt時拋例外(): void {
		$data             = $this->minimal_b2c_params();
		$data['Amt']      = '90';
		$data['TaxAmt']   = '5';
		$data['TotalAmt'] = '100'; // 應為 95，但填 100

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/TotalAmt|金額/' );

		IssueParams::create( $data );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_Status1立即開立預設值(): void {
		$params = IssueParams::create( $this->minimal_b2c_params() );
		$arr    = $params->to_array();

		$this->assertSame( '1', $arr['Status'] ?? '', 'Status=1 表示即時開立' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_BuyerName超長截斷不拋例外(): void {
		$data              = $this->minimal_b2c_params();
		$data['BuyerName'] = str_repeat( '測', 100 ); // 超長

		// 應截斷而非拋例外（API 有長度限制，DTO 應自行處理）
		$params = IssueParams::create( $data );
		$arr    = $params->to_array();

		$this->assertNotEmpty( $arr['BuyerName'] ?? '' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_多件商品ItemAmt等於各件小計(): void {
		$data              = $this->minimal_b2c_params();
		$data['ItemCount'] = '3';
		$data['ItemPrice'] = '100';
		$data['ItemAmt']   = '300';
		$data['Amt']       = '286'; // round(300 / 1.05)
		$data['TaxAmt']    = '14';
		$data['TotalAmt']  = '300';

		$params = IssueParams::create( $data );
		$arr    = $params->to_array();

		$this->assertSame( '300', $arr['ItemAmt'] ?? '' );
		$this->assertSame( '3', $arr['ItemCount'] ?? '' );
	}

	// ========== from_order()：金額錨定 $order->get_total()（回歸鎖死 BUG A / BUG B） ==========

	/**
	 * 建立一筆含單一商品的訂單（無稅環境，total 直接設定）
	 *
	 * @param float                $product_price 商品單價.
	 * @param int                  $quantity      數量.
	 * @param float                $order_total   訂單實付總額（含稅實付，唯一錨點）.
	 * @param array<string, mixed> $issue_params  結帳填寫的發票資訊（B2B / 捐贈用）.
	 *
	 * @return \WC_Order
	 */
	private function create_order_with_product(
		float $product_price,
		int $quantity,
		float $order_total,
		array $issue_params = []
	): \WC_Order {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( (string) $product_price );
		$product->save();

		$order->add_product( $product, $quantity );
		$order->set_total( $order_total );
		$order->set_billing_email( 'buyer@example.com' );
		$order->save();

		if ( $issue_params ) {
			( new MetaKeys( $order ) )->update_issue_params( $issue_params );
		}

		return $order;
	}

	/**
	 * 將 to_array() 的 ItemAmt（`|` 串接）拆成整數陣列
	 *
	 * @param array<string, string> $arr to_array() 結果.
	 *
	 * @return array<int, int>
	 */
	private function item_amounts( array $arr ): array {
		$raw = (string) ( $arr['ItemAmt'] ?? '' );
		if ( '' === $raw ) {
			return [];
		}
		return \array_map( 'intval', \explode( '|', $raw ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_from_order_B2C含稅單品TotalAmt等於訂單實付(): void {
		// Given: 含稅實付 100 的個人發票訂單（無結帳發票資訊 → 預設 B2C 雲端載具）
		$order = $this->create_order_with_product( 100, 1, 100 );

		// When
		$params = IssueParams::from_order( $order );
		$arr    = $params->to_array();

		// Then: TotalAmt 鎖定訂單含稅實付（BUG B：不可少 5% 稅額）
		$grand = (int) \round( (float) $order->get_total() );
		$this->assertSame( (string) $grand, $arr['TotalAmt'] ?? '', 'TotalAmt 必須等於 $order->get_total()（含稅實付）' );
		$this->assertSame( '100', $arr['TotalAmt'] ?? '' );

		// 三式自洽：TotalAmt = Amt + TaxAmt
		$this->assertSame(
			(int) $arr['TotalAmt'],
			(int) $arr['Amt'] + (int) $arr['TaxAmt'],
			'TotalAmt 必須等於 Amt + TaxAmt'
		);
		$this->assertSame( 'B2C', $arr['Category'] ?? '' );

		// 含稅明細加總 === TotalAmt（錨定）
		$this->assertSame( $grand, \array_sum( $this->item_amounts( $arr ) ), 'B2C 各項 ItemAmt 加總須等於含稅 TotalAmt' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_from_order_折價券不被雙重扣(): void {
		// Given: 商品原價 200（折扣前小計）、折價券 -50、訂單實付 150
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '200' );
		$product->save();
		$order->add_product( $product, 1 ); // 商品折扣前小計為兩百元.

		// 加一張折 50 的固定金額折價券
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'EZPAY_TEST_50' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 50 );
		$coupon->save();
		$order->apply_coupon( $coupon );

		$order->calculate_totals();
		$order->set_total( 150 ); // 含稅實付（無稅環境）= 200 - 50
		$order->set_billing_email( 'buyer@example.com' );
		$order->save();

		// When
		$params = IssueParams::from_order( $order );
		$arr    = $params->to_array();

		// Then: TotalAmt 鎖定 150（= 訂單實付），未被雙重扣成 100
		$this->assertSame( '150', $arr['TotalAmt'] ?? '', 'TotalAmt 必須等於訂單實付 150（折價券不可雙重扣）' );
		$this->assertNotSame( '100', $arr['TotalAmt'] ?? '', '若 TotalAmt=100 代表折扣被扣兩次（BUG A 復發）' );

		// 商品行採折扣前小計 200（非折扣後 150），折價券為獨立負項 -50；加總 === 150
		$amounts = $this->item_amounts( $arr );
		$this->assertContains( 200, $amounts, '商品行 ItemAmt 應為折扣前小計 200（證明用 get_subtotal 而非 get_total）' );
		$this->assertContains( -50, $amounts, '折價券應為獨立負項 -50' );
		$this->assertSame( 150, \array_sum( $amounts ), '各項 ItemAmt 加總（含折價券負項）須等於訂單實付 150' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_from_order_B2B統編未稅明細加總等於Amt(): void {
		// Given: 統編（B2B）發票訂單，含稅實付 105
		$order = $this->create_order_with_product(
			105,
			1,
			105,
			[
				'provider'    => 'ezpay',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '87654321',
			]
		);

		// When
		$params = IssueParams::from_order( $order );
		$arr    = $params->to_array();

		// Then: B2B 分類，TotalAmt = 訂單含稅實付，三式自洽
		$this->assertSame( 'B2B', $arr['Category'] ?? '' );
		$grand = (int) \round( (float) $order->get_total() );
		$this->assertSame( (string) $grand, $arr['TotalAmt'] ?? '', 'TotalAmt 必須等於 $order->get_total()' );
		$this->assertSame(
			(int) $arr['TotalAmt'],
			(int) $arr['Amt'] + (int) $arr['TaxAmt'],
			'TotalAmt 必須等於 Amt + TaxAmt'
		);

		// B2B 明細為未稅 → Σ ItemAmt 須等於 Amt（未稅銷售額），而非含稅 TotalAmt
		$this->assertSame(
			(int) $arr['Amt'],
			\array_sum( $this->item_amounts( $arr ) ),
			'B2B 各項 ItemAmt（未稅）加總須等於 Amt（未稅銷售額）'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_from_order_逐項ItemAmt等於ItemCount乘ItemPrice(): void {
		// Given: 多件商品（單價回推可能不整除）的 B2C 訂單
		$order = $this->create_order_with_product( 33.33, 3, 100 );

		// When
		$params = IssueParams::from_order( $order );
		$arr    = $params->to_array();

		// Then: 每一項皆滿足 ezPay 平台檢核 ItemAmt = ItemCount × ItemPrice
		$counts = \array_map( 'intval', \explode( '|', (string) $arr['ItemCount'] ) );
		$prices = \array_map( 'intval', \explode( '|', (string) $arr['ItemPrice'] ) );
		$amts   = $this->item_amounts( $arr );

		$n = \count( $amts );
		for ( $i = 0; $i < $n; $i++ ) {
			$this->assertSame(
				$amts[ $i ],
				$counts[ $i ] * $prices[ $i ],
				'第 ' . ( $i + 1 ) . ' 項 ItemAmt 須等於 ItemCount × ItemPrice'
			);
		}

		// 且 TotalAmt 仍鎖定訂單實付
		$this->assertSame( (string) (int) \round( (float) $order->get_total() ), $arr['TotalAmt'] ?? '' );
	}
}
