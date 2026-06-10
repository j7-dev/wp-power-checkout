<?php
/**
 * PayNow 物流 CreateShipmentParams DTO 整合測試（TDD Red 階段 — A-Cycle 1）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\CreateShipmentParams
 *
 * 規格依據：
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 1
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 1 步驟 6
 *   - woomp class-paynow-shipping-request.php build_add_order_args()（欄位對齊）
 *
 * 涵蓋範疇：
 *   - DTO::parse() 從訂單 + 設定組 Add_Order 完整欄位
 *   - 超商（CVS / Seven / Fami / Hilife）路徑：包含 receiver_storeid / receiver_storename
 *   - 黑貓宅配（TCAT）路徑：包含 DeliveryType / Weight / Length / Width / Height
 *   - PassCode 整合（確認組裝後存在且非空）
 *   - 金額欄位 TotalAmount 使用 $order->get_total() 原值（格式敏感，對齊 PassCodeService）
 *   - EC 欄位固定為 'EC 平台'（woomp 對齊）
 *   - DeliverMode 分流：COD 付款方式 → '01'；非 COD → '02'
 *
 * ⚠️ 幣別踩雷：涉及 get_total()，須顯式設定 TWD。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowCreateShipmentParamsTest tests/Integration/Logistics/"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\CreateShipmentParams;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PassCodeService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use Tests\Integration\TestCase;

/**
 * PayNow 物流 CreateShipmentParams DTO 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowCreateShipmentParamsTest extends TestCase {

	/** 測試用商家帳號 */
	private const TEST_USER_ACCOUNT = 'TEST_ACCOUNT_001';

	/** 測試用 apicode */
	private const TEST_APICODE = 'TEST_APICODE_999';

	/** 測試用寄件人姓名 */
	private const TEST_SENDER_NAME = '王大明';

	/** 測試用寄件人電話 */
	private const TEST_SENDER_PHONE = '0911222333';

	/** 測試用寄件人地址 */
	private const TEST_SENDER_ADDRESS = '台北市中正區重慶南路一段122號';

	/** 每次測試前設定幣別 */
	public function set_up(): void {
		parent::set_up();
		\update_option( 'woocommerce_currency', 'TWD' );
	}

	/**
	 * 建立超商取貨的測試訂單
	 *
	 * @param string $service_id 物流服務代碼（01=Seven, 03=Fami, 05=Hilife）
	 * @param string $deliver_mode 取貨模式（01=COD, 02=非COD）
	 * @param float  $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_cvs_order(
		string $service_id = '01',
		string $deliver_mode = '02',
		float $total = 1000.0
	): \WC_Order {
		$order = $this->create_wc_order( [ 'total' => $total ] );

		// 設定物流服務 meta
		$order->update_meta_data( PaynowLogisticsMetaKeys::SERVICE_ID, $service_id );
		$order->update_meta_data( PaynowLogisticsMetaKeys::ORDER_NO, 'PCN' . $order->get_id() );

		// 設定門市 meta（超商需要）
		$order->update_meta_data( PaynowLogisticsMetaKeys::STORE_ID, 'STORE_CODE_001' );
		$order->update_meta_data( PaynowLogisticsMetaKeys::STORE_NAME, '7-11 測試門市' );
		$order->update_meta_data( PaynowLogisticsMetaKeys::STORE_ADDR, '台北市大安區測試路1號' );

		// COD 判斷靠付款方式
		if ( '01' === $deliver_mode ) {
			$order->set_payment_method( 'cod' );
		} else {
			$order->set_payment_method( 'paynow' );
		}

		// 設定收件人資料
		$order->set_shipping_last_name( '李' );
		$order->set_shipping_first_name( '小明' );
		$order->set_billing_email( 'receiver@example.com' );

		$order->save();

		return $order;
	}

	/**
	 * 建立黑貓宅配的測試訂單
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_tcat_order( float $total = 2000.0 ): \WC_Order {
		$order = $this->create_wc_order( [ 'total' => $total ] );

		$order->update_meta_data( PaynowLogisticsMetaKeys::SERVICE_ID, '06' ); // Tcat
		$order->update_meta_data( PaynowLogisticsMetaKeys::ORDER_NO, 'PCN' . $order->get_id() );
		$order->update_meta_data( PaynowLogisticsMetaKeys::DELIVERY_TYPE, '0003' );
		$order->set_payment_method( 'paynow' );

		// 設定宅配地址
		$order->set_shipping_city( '台北市' );
		$order->set_shipping_state( '大安區' );
		$order->set_shipping_address_1( '測試路123號' );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_last_name( '張' );
		$order->set_shipping_first_name( '三' );
		$order->set_billing_email( 'tcat@example.com' );

		$order->save();

		return $order;
	}

	/**
	 * 建立 DTO parse 所需的設定陣列
	 *
	 * @return array<string, mixed>
	 */
	private function make_settings(): array {
		return [
			'user_account'   => self::TEST_USER_ACCOUNT,
			'apicode'        => self::TEST_APICODE,
			'sender_name'    => self::TEST_SENDER_NAME,
			'sender_phone'   => self::TEST_SENDER_PHONE,
			'sender_address' => self::TEST_SENDER_ADDRESS,
			'sender_email'   => 'sender@example.com',
		];
	}

	// ========== Happy Path：超商取貨（CVS）完整欄位 ==========

	/**
	 * 超商 Seven（01）取貨不付款 — 組 Add_Order 完整欄位
	 *
	 * 依 woomp build_add_order_args()：Description / DeliverMode / Logistic_service /
	 * user_account / apicode / OrderNo / Receiver_* / Sender_* / receiver_storeid /
	 * receiver_storename / PassCode / TotalAmount / EC
	 *
	 * @test
	 * @group happy
	 */
	public function test_超商Seven取貨不付款組Add_Order完整欄位(): void {
		$order    = $this->create_cvs_order( service_id: '01', deliver_mode: '02', total: 1000.0 );
		$settings = $this->make_settings();

		$params = CreateShipmentParams::parse( $order, $settings );

		// 基礎欄位存在
		$this->assertArrayHasKey( 'Description', $params, '應含 Description' );
		$this->assertArrayHasKey( 'DeliverMode', $params, '應含 DeliverMode' );
		$this->assertArrayHasKey( 'Logistic_service', $params, '應含 Logistic_service' );
		$this->assertArrayHasKey( 'user_account', $params, '應含 user_account' );
		$this->assertArrayHasKey( 'apicode', $params, '應含 apicode' );
		$this->assertArrayHasKey( 'OrderNo', $params, '應含 OrderNo' );
		$this->assertArrayHasKey( 'PassCode', $params, '應含 PassCode' );
		$this->assertArrayHasKey( 'TotalAmount', $params, '應含 TotalAmount' );
		$this->assertArrayHasKey( 'EC', $params, '應含 EC' );

		// 收件人欄位
		$this->assertArrayHasKey( 'Receiver_Name', $params, '應含 Receiver_Name' );
		$this->assertArrayHasKey( 'Receiver_Phone', $params, '應含 Receiver_Phone' );
		$this->assertArrayHasKey( 'Receiver_Email', $params, '應含 Receiver_Email' );
		$this->assertArrayHasKey( 'Receiver_address', $params, '應含 Receiver_address' );

		// 寄件人欄位
		$this->assertArrayHasKey( 'Sender_Name', $params, '應含 Sender_Name' );
		$this->assertArrayHasKey( 'Sender_Phone', $params, '應含 Sender_Phone' );
		$this->assertArrayHasKey( 'Sender_address', $params, '應含 Sender_address' );

		// 超商門市欄位
		$this->assertArrayHasKey( 'receiver_storeid', $params, '超商應含 receiver_storeid' );
		$this->assertArrayHasKey( 'receiver_storename', $params, '超商應含 receiver_storename' );
	}

	/**
	 * 超商取貨不付款 DeliverMode 應為 '02'
	 *
	 * @test
	 * @group happy
	 */
	public function test_取貨不付款DeliverMode為02(): void {
		$order = $this->create_cvs_order( service_id: '01', deliver_mode: '02', total: 500.0 );

		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( '02', $params['DeliverMode'], '取貨不付款 DeliverMode 應為 02' );
	}

	/**
	 * 超商 COD 取貨付款 DeliverMode 應為 '01'
	 *
	 * @test
	 * @group happy
	 */
	public function test_COD取貨付款DeliverMode為01(): void {
		$order = $this->create_cvs_order( service_id: '01', deliver_mode: '01', total: 800.0 );

		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( '01', $params['DeliverMode'], 'COD 取貨付款 DeliverMode 應為 01' );
	}

	/**
	 * Logistic_service 值對應訂單 meta 的 service_id（01=Seven）
	 *
	 * @test
	 * @group happy
	 */
	public function test_Logistic_service對應訂單meta的service_id(): void {
		$order = $this->create_cvs_order( service_id: '01', deliver_mode: '02' );

		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( '01', $params['Logistic_service'], 'Logistic_service 應為 01（Seven）' );
	}

	/**
	 * user_account 與 apicode 來自設定
	 *
	 * @test
	 * @group happy
	 */
	public function test_user_account與apicode來自設定(): void {
		$order = $this->create_cvs_order();

		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( self::TEST_USER_ACCOUNT, $params['user_account'], 'user_account 應來自設定' );
		$this->assertSame( self::TEST_APICODE, $params['apicode'], 'apicode 應來自設定' );
	}

	/**
	 * EC 欄位固定為 'EC 平台'（woomp 對齊）
	 *
	 * @test
	 * @group happy
	 */
	public function test_EC欄位固定為EC平台(): void {
		$order  = $this->create_cvs_order();
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( 'EC 平台', $params['EC'], 'EC 欄位應固定為 EC 平台' );
	}

	/**
	 * TotalAmount 使用 $order->get_total() 原值
	 *
	 * ⚠️ PassCodeService::build() 對 total 字串格式敏感（R6）
	 * TotalAmount 與 PassCode 計算所用的 total 必須完全一致
	 *
	 * @test
	 * @group happy
	 */
	public function test_TotalAmount使用訂單get_total原值(): void {
		$order  = $this->create_cvs_order( total: 1500.0 );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		// TotalAmount 應與 get_total() 同值
		$this->assertSame(
			$order->get_total(),
			$params['TotalAmount'],
			'TotalAmount 應等於 $order->get_total() 原值'
		);
	}

	/**
	 * Sender_* 來自設定
	 *
	 * @test
	 * @group happy
	 */
	public function test_Sender欄位來自設定(): void {
		$order  = $this->create_cvs_order();
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( self::TEST_SENDER_NAME, $params['Sender_Name'], 'Sender_Name 應來自設定' );
		$this->assertSame( self::TEST_SENDER_PHONE, $params['Sender_Phone'], 'Sender_Phone 應來自設定' );
		$this->assertSame( self::TEST_SENDER_ADDRESS, $params['Sender_address'], 'Sender_address 應來自設定' );
	}

	/**
	 * receiver_storeid 與 receiver_storename 來自訂單 meta（超商路徑）
	 *
	 * @test
	 * @group happy
	 */
	public function test_超商門市欄位來自訂單meta(): void {
		$order  = $this->create_cvs_order( service_id: '01' );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( 'STORE_CODE_001', $params['receiver_storeid'], 'receiver_storeid 應來自 meta' );
		$this->assertSame( '7-11 測試門市', $params['receiver_storename'], 'receiver_storename 應來自 meta' );
	}

	/**
	 * PassCode 整合：組裝後為非空的大寫 40 字元 SHA1 hex
	 *
	 * PassCode = strtoupper(sha1(user_account + OrderNo + TotalAmount + apicode))
	 * 確認組裝後 PassCode 與 PassCodeService::build() 計算值一致
	 *
	 * @test
	 * @group happy
	 */
	public function test_PassCode整合驗證格式與計算值(): void {
		$order  = $this->create_cvs_order( total: 1000.0 );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$pass_code = $params['PassCode'] ?? '';

		// 格式：40 字元大寫 hex
		$this->assertNotSame( '', $pass_code, 'PassCode 不得為空字串' );
		$this->assertSame( 40, \strlen( $pass_code ), 'PassCode 應為 40 字元 SHA1 hex' );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{40}$/', $pass_code, 'PassCode 應為大寫 hex' );

		// 驗算：應等於 PassCodeService::build() 的輸出
		$expected = PassCodeService::build(
			self::TEST_USER_ACCOUNT,
			$params['OrderNo'],
			(string) $order->get_total(),
			self::TEST_APICODE
		);
		$this->assertSame( $expected, $pass_code, 'PassCode 應與 PassCodeService::build() 計算值一致' );
	}

	/**
	 * OrderNo 格式為 PCN{order_id}（或訂單 meta 中的 order_no）
	 *
	 * @test
	 * @group happy
	 */
	public function test_OrderNo格式正確(): void {
		$order  = $this->create_cvs_order();
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$order_no = $params['OrderNo'] ?? '';
		$this->assertNotSame( '', $order_no, 'OrderNo 不得為空字串' );
		// OrderNo 應含訂單 ID 或符合 PCN 前綴慣例
		$this->assertStringContainsString(
			(string) $order->get_id(),
			$order_no,
			'OrderNo 應包含 order_id'
		);
	}

	// ========== Happy Path：黑貓宅配（TCAT）欄位 ==========

	/**
	 * 黑貓宅配路徑需含 DeliveryType / Weight / Length / Width / Height
	 *
	 * @test
	 * @group happy
	 */
	public function test_黑貓宅配路徑含必要欄位(): void {
		$order  = $this->create_tcat_order( 2000.0 );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertArrayHasKey( 'DeliveryType', $params, '黑貓宅配應含 DeliveryType' );
		$this->assertArrayHasKey( 'Weight', $params, '黑貓宅配應含 Weight' );
		$this->assertArrayHasKey( 'Length', $params, '黑貓宅配應含 Length' );
		$this->assertArrayHasKey( 'Width', $params, '黑貓宅配應含 Width' );
		$this->assertArrayHasKey( 'Height', $params, '黑貓宅配應含 Height' );
	}

	/**
	 * 黑貓宅配 DeliveryType 從訂單 meta 讀取（0003 = 常溫）
	 *
	 * @test
	 * @group happy
	 */
	public function test_黑貓宅配DeliveryType從訂單meta讀取(): void {
		$order  = $this->create_tcat_order();
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( '0003', $params['DeliveryType'], 'DeliveryType 應為訂單 meta 的 0003' );
	}

	/**
	 * 黑貓宅配 Logistic_service 為 '06'（Tcat）
	 *
	 * @test
	 * @group happy
	 */
	public function test_黑貓宅配Logistic_service為06(): void {
		$order  = $this->create_tcat_order();
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertSame( '06', $params['Logistic_service'], '黑貓宅配 Logistic_service 應為 06' );
	}

	/**
	 * 黑貓宅配路徑 receiver_storeid 應為空或不存在（使用宅配地址而非門市）
	 *
	 * @test
	 * @group happy
	 */
	public function test_黑貓宅配路徑門市欄位為空(): void {
		$order  = $this->create_tcat_order();
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		// 黑貓宅配不需要門市資訊，應為空或不存在
		$store_id = $params['receiver_storeid'] ?? '';
		$this->assertSame( '', $store_id, '黑貓宅配 receiver_storeid 應為空' );
	}

	/**
	 * 超商路徑不含 TCAT 專屬欄位（DeliveryType / Weight 等）
	 *
	 * @test
	 * @group happy
	 */
	public function test_超商路徑不含TCAT專屬欄位(): void {
		$order  = $this->create_cvs_order( service_id: '01' );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		// 超商路徑不應包含 TCAT 專屬欄位
		$this->assertArrayNotHasKey( 'DeliveryType', $params, '超商路徑不應含 DeliveryType' );
		$this->assertArrayNotHasKey( 'Weight', $params, '超商路徑不應含 Weight' );
		$this->assertArrayNotHasKey( 'Length', $params, '超商路徑不應含 Length' );
		$this->assertArrayNotHasKey( 'Width', $params, '超商路徑不應含 Width' );
		$this->assertArrayNotHasKey( 'Height', $params, '超商路徑不應含 Height' );
	}

	// ========== Edge：TotalAmount 格式敏感 ==========

	/**
	 * TotalAmount 字串格式與 PassCode 計算完全一致（格式敏感 R6）
	 *
	 * WooCommerce get_total() 可能回傳 "1000" 或 "1000.00"，
	 * TotalAmount 與 PassCode 計算的 total 必須使用完全相同字串
	 *
	 * @test
	 * @group edge
	 */
	public function test_TotalAmount與PassCode計算用的total字串完全一致(): void {
		$order  = $this->create_cvs_order( total: 1000.0 );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$total_amount = $params['TotalAmount'];
		$order_no     = $params['OrderNo'];
		$pass_code    = $params['PassCode'];

		// 以 TotalAmount 欄位值重新計算 PassCode，應與 params['PassCode'] 完全一致
		$recalculated = PassCodeService::build(
			self::TEST_USER_ACCOUNT,
			$order_no,
			(string) $total_amount,
			self::TEST_APICODE
		);

		$this->assertSame(
			$pass_code,
			$recalculated,
			'TotalAmount 字串格式必須與 PassCode 計算用的 total 完全一致'
		);
	}

	/**
	 * 整數金額（1000）與浮點格式（1000.00）的 total 字串不同，PassCode 也不同
	 *
	 * 此測試驗證字串格式敏感性（R6 warning），不是驗證實作應回哪種格式，
	 * 而是確認 DTO 的 TotalAmount 與 PassCode 使用同一個 total 字串來源。
	 *
	 * @test
	 * @group edge
	 */
	public function test_字串格式敏感性_不同格式sha1不同(): void {
		$pass_code_int   = PassCodeService::build( 'acct', 'NO001', '1000', 'code' );
		$pass_code_float = PassCodeService::build( 'acct', 'NO001', '1000.00', 'code' );

		$this->assertNotSame(
			$pass_code_int,
			$pass_code_float,
			'"1000" 與 "1000.00" 的 PassCode 應不同（R6 格式敏感性確認）'
		);
	}

	// ========== Edge：邊界金額 ==========

	/**
	 * 超商金額上限邊界值（≤20000 不拋例外）
	 *
	 * @test
	 * @group edge
	 */
	public function test_超商金額恰好20000時parse不拋例外(): void {
		$order  = $this->create_cvs_order( service_id: '01', total: 20000.0 );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertArrayHasKey( 'TotalAmount', $params, '金額 20000 時應成功組裝 TotalAmount' );
	}

	/**
	 * 黑貓宅配金額上限邊界值（≤100000 不拋例外）
	 *
	 * @test
	 * @group edge
	 */
	public function test_黑貓宅配金額恰好100000時parse不拋例外(): void {
		$order  = $this->create_tcat_order( 100000.0 );
		$params = CreateShipmentParams::parse( $order, $this->make_settings() );

		$this->assertArrayHasKey( 'TotalAmount', $params, '金額 100000 時應成功組裝 TotalAmount' );
	}
}
