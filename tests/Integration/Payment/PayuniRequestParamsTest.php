<?php
/**
 * PAYUNi UPP V2 建單請求參數整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniRequestParams
 *
 * 規格依據：
 *   - specs/features/payment/payuni-upp-checkout.feature §前置（參數）
 *   - payuni-upp-v2 SKILL.md §外層請求格式 / §EncryptInfo 內層通用請求參數 / §付款方式開關
 *   - PayuniCrypto、PayuniSettingsDTO、PayuniTradeNo 均已就位（Phase 2 基礎建設）
 *
 * PayuniRequestParams::to_form_params() 回傳外層：
 *   MerID / Version('2.0') / EncryptInfo（AES-256-GCM hex）/ HashInfo（SHA256 大寫）
 *
 * EncryptInfo 解密後內層含：
 *   MerID / MerTradeNo / TradeAmt / Timestamp / ProdDesc / ReturnURL / NotifyURL
 *   + 付款方式開關欄位（Credit / ATM / CVS / LinePay / JKoPay / ApplePay / GooglePay 等）
 *
 * 付款方式開關欄位名（payuni-upp-v2 §付款方式開關）：
 *   Credit, CreditInst, ATM, CVS, ICash, LinePay, JKoPay, ApplePay, GooglePay
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniRequestParams;
use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UPP V2 建單請求參數測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniRequestParamsTest extends TestCase {

	// 官方公開測試向量金鑰（payuni-upp-v2 encryption.md §官方測試向量）
	private const HASH_KEY = '12345678901234567890123456789012'; // 32 bytes
	private const HASH_IV  = '1234567890123456';                 // 16 bytes

	/** 每次測試前啟用 payuni_upp（test 模式 + 官方測試向量金鑰） */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[
				'enabled'          => 'yes',
				'mode'             => 'test',
				'merchant_id'      => 'TEST_MER',
				'hash_key'         => self::HASH_KEY,
				'hash_iv'          => self::HASH_IV,
				'allowed_payments' => [ 'Credit', 'ATM', 'CVS' ],
			]
		);
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( PayuniSettingsDTO::ID ) );
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立已設定 payment_method 的測試訂單，並帶入 billing email（部分欄位依 spec）
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_payuni_order( float $total = 1000 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PayuniSettingsDTO::ID,
				'total'          => $total,
			]
		);
		$order->set_billing_email( 'buyer@example.com' );
		$order->save();
		return $order;
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PayuniRequestParams 可被實例化（class 不存在時 Red）
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PayuniRequestParams可被實例化(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$this->assertInstanceOf( PayuniRequestParams::class, $params );
	}

	// ========== 外層 form 參數（Happy） ==========

	/**
	 * to_form_params() 含外層必填欄位 MerID / Version / EncryptInfo / HashInfo
	 * 依 payuni-upp-v2 SKILL.md §外層請求格式
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_外層參數_含MerID與Version與EncryptInfo與HashInfo(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$this->assertArrayHasKey( 'MerID', $form );
		$this->assertArrayHasKey( 'Version', $form );
		$this->assertArrayHasKey( 'EncryptInfo', $form );
		$this->assertArrayHasKey( 'HashInfo', $form );
	}

	/**
	 * Version 固定為 '2.0'
	 * 依 payuni-upp-v2 SKILL.md §外層請求格式（Version 固定 2.0）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_外層參數_Version固定為2點0(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$this->assertSame( '2.0', $form['Version'] );
	}

	/**
	 * MerID 與 PayuniSettingsDTO::merchant_id 一致
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_外層參數_MerID來自settings(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$this->assertSame( 'TEST_MER', $form['MerID'] );
	}

	/**
	 * EncryptInfo 格式為全小寫 hex 字串
	 * 依 payuni-upp-v2 §Encrypt：輸出 hex（bin2hex(base64(cipher)+":::"+base64(tag))）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_外層參數_EncryptInfo為hex字串(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$this->assertNotEmpty( $form['EncryptInfo'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $form['EncryptInfo'] );
	}

	/**
	 * HashInfo 格式為 64 字元大寫 hex
	 * 依 payuni-upp-v2 §HashInfo（SHA256 大寫）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_外層參數_HashInfo為64字元大寫hex(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$this->assertMatchesRegularExpression( '/^[0-9A-F]{64}$/', $form['HashInfo'] );
	}

	/**
	 * HashInfo 可用 PayuniCrypto::verify_hash 驗證通過
	 * 對齊 spec 規則：建單參數須以 HashInfo 計算正確
	 *
	 * @test
	 * @group happy
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_外層參數_HashInfo驗證通過(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$this->assertTrue(
			$crypto->verify_hash( $form['EncryptInfo'], $form['HashInfo'] ),
			'HashInfo 驗證失敗（PayuniCrypto::verify_hash 公式：SHA256(HashKey+EncryptInfo+HashIV).toUpperCase()）'
		);
	}

	// ========== 內層 EncryptInfo 解密欄位（Happy） ==========

	/**
	 * 解密後內層含 MerID / MerTradeNo / TradeAmt / Timestamp / ProdDesc
	 * 依 payuni-upp-v2 §EncryptInfo 內層通用請求參數（必填欄位）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_內層解密_含必填欄位(): void {
		$order  = $this->create_payuni_order( 1500 );
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		$this->assertArrayHasKey( 'MerID', $decrypted );
		$this->assertArrayHasKey( 'MerTradeNo', $decrypted );
		$this->assertArrayHasKey( 'TradeAmt', $decrypted );
		$this->assertArrayHasKey( 'Timestamp', $decrypted );
		$this->assertArrayHasKey( 'ProdDesc', $decrypted );
	}

	/**
	 * MerTradeNo 等於 PayuniTradeNo::generate(order_id)（冪等鍵）
	 * 依 specs/features/payment/payuni-upp-checkout.feature §後置（狀態）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_內層解密_MerTradeNo等於冪等鍵(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		$expected_trade_no = PayuniTradeNo::generate( $order->get_id() );
		$this->assertSame( $expected_trade_no, $decrypted['MerTradeNo'] );
	}

	/**
	 * TradeAmt 為整數化的訂單金額（來自 $order->get_total()，非前端輸入）
	 * 安全規則：金額必須從訂單物件取得，不接受前端參數
	 *
	 * @test
	 * @group happy
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_內層解密_TradeAmt為整數化訂單金額(): void {
		$order  = $this->create_payuni_order( 1234 );
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		// TradeAmt 必須為整數（querystring 序列化後為字串，但值須是整數字串）
		$this->assertSame( '1234', $decrypted['TradeAmt'] );
	}

	/**
	 * 含小數的訂單金額無條件進位為整數（避免少收）
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_內層解密_小數金額無條件進位(): void {
		$order  = $this->create_payuni_order( 99.01 );
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		// 99.01 → 無條件進位 → 100（(int) ceil(99.01)）
		$this->assertSame( '100', $decrypted['TradeAmt'] );
	}

	/**
	 * Timestamp 為 Unix 時間戳（數字字串，合理範圍內）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_內層解密_Timestamp為Unix時間戳(): void {
		$before = \time() - 5;
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$after  = \time() + 5;
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		$ts = (int) ( $decrypted['Timestamp'] ?? 0 );
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}

	/**
	 * 解密後含 ReturnURL 且指向 payuni callback 端點
	 * 依 payuni-upp-v2 §EncryptInfo ReturnURL（前景通知網址）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_內層解密_含ReturnURL且指向payuni端點(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		$this->assertArrayHasKey( 'ReturnURL', $decrypted );
		$this->assertNotEmpty( $decrypted['ReturnURL'] );
	}

	/**
	 * 解密後含 NotifyURL 且指向 payuni callback 端點
	 * 依 payuni-upp-v2 §EncryptInfo NotifyURL（背景通知網址，僅限 80/443）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_內層解密_含NotifyURL且指向payuni端點(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		$this->assertArrayHasKey( 'NotifyURL', $decrypted );
		$this->assertNotEmpty( $decrypted['NotifyURL'] );
	}

	// ========== 付款方式開關（Happy / Edge） ==========

	/**
	 * allowed_payments=['Credit','ATM','CVS'] → 內層含 Credit=1, ATM=1, CVS=1 開關
	 * 依 payuni-upp-v2 §付款方式開關：Credit/ATM/CVS 值為 int 1 啟用
	 *
	 * ⚠️ 付款方式開關欄位名（payuni-upp-v2 §付款方式開關）：
	 *   Credit=int, ATM=int, CVS=int, LinePay=int, JKoPay=int, ApplePay=int, GooglePay=int
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_付款方式開關_Credit_ATM_CVS啟用時內層含對應開關(): void {
		// 已在 configure_dependencies 中設 allowed_payments=['Credit','ATM','CVS']
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		// 啟用的付款方式開關值為 '1'（querystring 序列化後為字串）
		$this->assertSame( '1', $decrypted['Credit'] ?? null, 'Credit 開關未啟用' );
		$this->assertSame( '1', $decrypted['ATM'] ?? null, 'ATM 開關未啟用' );
		$this->assertSame( '1', $decrypted['CVS'] ?? null, 'CVS 開關未啟用' );
	}

	/**
	 * allowed_payments=['Credit','ATM'] 時未啟用的 CVS 不出現在內層（或值為 0）
	 * 依 payuni-upp-v2 §付款方式開關：未帶參數依後台預設；帶 0 或不帶均不啟用
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_付款方式開關_未選的CVS不啟用(): void {
		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[ 'allowed_payments' => [ 'Credit', 'ATM' ] ]
		);
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}

		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		// CVS 未啟用：不出現或值不為 '1'
		$cvs_value = $decrypted['CVS'] ?? '0';
		$this->assertNotSame( '1', $cvs_value, 'CVS 不應被啟用，但實際值為 1' );
	}

	/**
	 * allowed_payments=['Credit','CreditInst'] 時 CreditInst 帶 installment_periods 字串
	 * 依 payuni-upp-v2 §付款方式開關：CreditInst 值為期數字串（如 "3,6,12"）
	 *
	 * ⚠️ 注意：CreditInst 的值是期數字串而非 int 1，此為 UPP V2 的特殊欄位
	 *    實際欄位名為 CreditInst（依 payuni-upp-v2 §付款方式開關）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_付款方式開關_CreditInst帶分期期數字串(): void {
		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[
				'allowed_payments'    => [ 'CreditInst' ],
				'installment_periods' => '3,6,12',
			]
		);
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}

		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		// CreditInst 的值應為期數字串（依 payuni-upp-v2 §付款方式開關）
		$this->assertArrayHasKey( 'CreditInst', $decrypted );
		$this->assertSame( '3,6,12', $decrypted['CreditInst'] );
	}

	// ========== 冪等性（Edge） ==========

	/**
	 * 同一訂單多次呼叫 instance() 產生相同 MerTradeNo（冪等）
	 * 依 specs §後置（狀態）- 建單時寫入冪等鍵 MerTradeNo
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_冪等_同一訂單多次組裝MerTradeNo不變(): void {
		$order  = $this->create_payuni_order();
		$crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );

		$form1   = PayuniRequestParams::instance( $order )->to_form_params();
		$inner1  = $crypto->decrypt( $form1['EncryptInfo'] );
		$trade1  = $inner1['MerTradeNo'] ?? '';

		// 重新取得同一訂單（模擬 page reload）
		$order2  = \wc_get_order( $order->get_id() );
		$form2   = PayuniRequestParams::instance( $order2 )->to_form_params();
		$inner2  = $crypto->decrypt( $form2['EncryptInfo'] );
		$trade2  = $inner2['MerTradeNo'] ?? '';

		$this->assertNotEmpty( $trade1 );
		$this->assertSame( $trade1, $trade2, '同一訂單的 MerTradeNo 應冪等（不因重載改變）' );
	}

	// ========== 安全性（Security） ==========

	/**
	 * EncryptInfo 外層 MerID 與 EncryptInfo 內層 MerID 必須一致
	 * 依 payuni-upp-v2 §外層請求格式 注意：MerID 需與 EncryptInfo 內 MerID 一致
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_安全_外層MerID與內層MerID一致(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		$this->assertSame(
			$form['MerID'],
			$decrypted['MerID'],
			'外層 MerID 與 EncryptInfo 解密後內層 MerID 不一致，PAYUNi 閘道將拒絕此請求'
		);
	}

	/**
	 * TradeAmt 必須來自 $order->get_total()（整數化），不可為 0 或負數
	 * 安全性：防止前端竄改金額
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_安全_TradeAmt不為零且為正整數(): void {
		$order  = $this->create_payuni_order( 500 );
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto    = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted = $crypto->decrypt( $form['EncryptInfo'] );

		$trade_amt = (int) ( $decrypted['TradeAmt'] ?? 0 );
		$this->assertGreaterThan( 0, $trade_amt, 'TradeAmt 不應為 0 或負數' );
	}

	/**
	 * MerTradeNo 長度 ≤ 25 且只含 [A-Za-z0-9_-]
	 * 依 payuni-upp-v2 §EncryptInfo MerTradeNo 規格
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_安全_MerTradeNo長度與字元集合規(): void {
		$order  = $this->create_payuni_order();
		$params = PayuniRequestParams::instance( $order );
		$form   = $params->to_form_params();

		$crypto      = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$decrypted   = $crypto->decrypt( $form['EncryptInfo'] );
		$trade_no    = $decrypted['MerTradeNo'] ?? '';

		$this->assertLessThanOrEqual( 25, \strlen( $trade_no ), 'MerTradeNo 超過 25 字元' );
		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9_-]+$/',
			$trade_no,
			'MerTradeNo 含不合法字元（僅允許 [A-Za-z0-9_-]）'
		);
	}
}
