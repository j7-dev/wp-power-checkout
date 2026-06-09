<?php
/**
 * PAYUNi UPP V2 StatusManager 整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Managers\StatusManager
 *
 * 設計說明：
 *   StatusManager 建構子接收「解密後的內層通知陣列 + WC_Order」。
 *   簽章：new StatusManager( array $inner_payload, \WC_Order $order )
 *   此設計對齊 NewebpayMpg\Managers\StatusManager 模式（payload 陣列 + 訂單注入）。
 *
 *   核心業務規則（依 specs/features/payment/payuni-upp-callback.feature + payuni-upp-payment-info.feature）：
 *   - TradeStatus=1 + Status=SUCCESS → payment_complete() + 寫 _pc_payuni_payment_detail
 *   - TradeStatus=1 + TradeAmt ≠ ceil(訂單應收) → 維持 pending + order note 告警（資安最敏感）
 *   - TradeStatus=0 → 維持 pending + 寫 _pc_payuni_payment_info（ATM: BankType/PayNo/ExpireDate；CVS: Store/PayNo/ExpireDate）
 *   - TradeStatus=2 / 3 / 8 → 維持 pending + order note 記錄 Status/Message
 *   - 冪等：已 processing 不重複 payment_complete
 *
 * 取號欄位名稱（依 payuni-upp-v2 skill §虛擬帳號 PaymentType=2 / §超商代碼 PaymentType=3）：
 *   ATM：BankType（銀行代碼）、PayNo（繳費虛擬帳號）、PaySet、ExpireDate（YYYY-MM-DD HH:II:SS）
 *   CVS：Store（超商代碼，如 7-ELEVEN）、PayNo（繳費代碼）、ExpireDate（YYYY-MM-DD HH:II:SS）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UPP V2 StatusManager 測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniStatusManagerTest extends TestCase {

	// PAYUNi 官方公開測試向量金鑰（payuni-upp-v2 encryption.md §官方測試向量）
	private const HASH_KEY   = '12345678901234567890123456789012';
	private const HASH_IV    = '1234567890123456';
	private const MERCHANT_ID = 'TEST_MER';

	/** 每次測試前啟用 payuni_upp（test 模式） */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			'payuni_upp',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'merchant_id' => self::MERCHANT_ID,
				'hash_key'    => self::HASH_KEY,
				'hash_iv'     => self::HASH_IV,
			]
		);
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( 'payuni_upp' ) );
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立已綁 MerTradeNo 的 pending 訂單（指定 total）
	 *
	 * @param string $mer_trade_no PAYUNi MerTradeNo（對應訂單的冪等鍵，儲存於 _pc_payuni_trade_no）
	 * @param int    $total        訂單應收金額（整數，單位：元）
	 * @return \WC_Order
	 */
	private function create_payuni_order( string $mer_trade_no, int $total = 1000 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_upp',
				'total'          => $total,
			]
		);
		( new PayuniMetaKeys( $order ) )->update_trade_no( $mer_trade_no );
		return $order;
	}

	/**
	 * 建立標準的已付款內層 payload（TradeStatus=1）
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 * @param string $trade_amt    交易金額（字串，PAYUNi 回傳字串形式）
	 * @return array<string, string>
	 */
	private function paid_payload( string $mer_trade_no, string $trade_amt = '1000' ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '授權成功',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNI20260101001',
			'TradeAmt'    => $trade_amt,
			'TradeStatus' => '1',
			'PaymentType' => '1',
			'Gateway'     => '2',
		];
	}

	/**
	 * 建立 ATM 取號內層 payload（TradeStatus=0）
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 * @return array<string, string>
	 */
	private function atm_get_code_payload( string $mer_trade_no ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '(ATM)建立成功',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNI20260101ATM',
			'TradeAmt'    => '1000',
			'TradeStatus' => '0',
			'PaymentType' => '2',
			'Gateway'     => '2',
			// ATM 取號專屬欄位（payuni-upp-v2 §虛擬帳號 PaymentType=2）
			'BankType'    => '822',
			'PayNo'       => '00000000001234',
			'PaySet'      => '1',
			'ExpireDate'  => '2026-12-31 23:59:59',
		];
	}

	/**
	 * 建立 CVS 取號內層 payload（TradeStatus=0）
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 * @return array<string, string>
	 */
	private function cvs_get_code_payload( string $mer_trade_no ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '(CVS)建立成功',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNI20260101CVS',
			'TradeAmt'    => '1000',
			'TradeStatus' => '0',
			'PaymentType' => '3',
			'Gateway'     => '2',
			// CVS 取號專屬欄位（payuni-upp-v2 §超商代碼 PaymentType=3）
			'Store'       => '7-ELEVEN',
			'PayNo'       => 'CVS12345678',
			'ExpireDate'  => '2026-12-07 23:59:59',
		];
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * StatusManager 可被實例化（建構子接收 inner_payload 陣列 + WC_Order）
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_StatusManager可被實例化(): void {
		$order   = $this->create_payuni_order( 'PCU_SMOKE_001' );
		$manager = new StatusManager( $this->paid_payload( 'PCU_SMOKE_001' ), $order );

		$this->assertInstanceOf( StatusManager::class, $manager );
	}

	// ========== 快樂路徑：TradeStatus=1 已付款（Happy） ==========

	/**
	 * TradeStatus=1（已付款）→ 訂單轉 processing（payment_complete）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus1已付款_訂單轉processing(): void {
		// Given: pending 訂單（應收 1000）
		$order = $this->create_payuni_order( 'PCU_PAID_001', 1000 );

		// When: 收到 TradeStatus=1 + TradeAmt=1000（相符）
		$manager = new StatusManager( $this->paid_payload( 'PCU_PAID_001', '1000' ), $order );
		$manager->update_order_status();

		// Then: 訂單轉 processing
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * TradeStatus=1（已付款）→ 寫入 _pc_payuni_payment_detail
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus1已付款_寫入payment_detail(): void {
		$order   = $this->create_payuni_order( 'PCU_PAID_002', 1000 );
		$payload = $this->paid_payload( 'PCU_PAID_002', '1000' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// Then: _pc_payuni_payment_detail 有值，含 TradeStatus=1
		$detail = ( new PayuniMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertNotEmpty( $detail, '付款明細不應為空' );
		$this->assertSame( '1', $detail['TradeStatus'] ?? '' );
		$this->assertSame( 'SUCCESS', $detail['Status'] ?? '' );
	}

	/**
	 * TradeStatus=1（已付款）→ 新增 order note 包含付款成功字樣
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus1已付款_新增order_note(): void {
		$order = $this->create_payuni_order( 'PCU_PAID_003', 1000 );

		$manager = new StatusManager( $this->paid_payload( 'PCU_PAID_003', '1000' ), $order );
		$manager->update_order_status();

		// 任何包含「成功」或「付款」字樣的 order note 均視為合格
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, '成功' ) || str_contains( $note->content, '付款' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含付款成功相關字樣' );
	}

	// ========== 安全性：金額防竄改（Security — 最重要） ==========

	/**
	 * TradeStatus=1 但 TradeAmt ≠ 訂單應收 → 維持 pending，不轉 processing
	 * 此為資安最關鍵案例：惡意竄改通知金額以低價換取商品
	 *
	 * 驗算：訂單 total=1000，ceil(1000)=1000；PAYUNi 回傳 TradeAmt=1 → 竄改
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_金額竄改_TradeAmt與訂單不符時維持pending(): void {
		// Given: pending 訂單（應收 1000）
		$order = $this->create_payuni_order( 'PCU_AMT_TAMPER', 1000 );

		// When: 回傳 TradeStatus=1 但 TradeAmt=1（惡意竄改）
		$payload = $this->paid_payload( 'PCU_AMT_TAMPER', '1' );
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// Then: 維持 pending（絕不轉 processing）
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * TradeStatus=1 但 TradeAmt ≠ 訂單應收 → 新增告警 order note 含「金額」字樣
	 * 確保管理員能察覺異常通知
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_金額竄改_新增告警order_note含金額字樣(): void {
		$order   = $this->create_payuni_order( 'PCU_AMT_WARN', 1000 );
		$payload = $this->paid_payload( 'PCU_AMT_WARN', '1' ); // 竄改金額

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 告警 note 必須包含「金額」字樣（繁體中文錯誤警報）
		$this->assert_order_note_contains( $order, '金額' );
	}

	/**
	 * TradeStatus=1 且 TradeAmt 相符 → 轉 processing（確認金額驗證不過度嚴格）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_金額相符_TradeAmt相符時正常轉processing(): void {
		// Given: 訂單 total=1500
		$order = $this->create_payuni_order( 'PCU_AMT_MATCH', 1500 );

		// When: TradeAmt=1500（ceil(1500)=1500，完全相符）
		$payload = $this->paid_payload( 'PCU_AMT_MATCH', '1500' );
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// Then: 正常轉 processing
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * TradeStatus=1 且 TradeAmt=ceil(訂單小數總額)→ 轉 processing（ceil 語意確認）
	 *
	 * PAYUNi TradeAmt 必須為整數；WC 訂單 total 可能有小數（如 1000.5 → ceil=1001）。
	 * 驗證：ceil() 正確對齊 PAYUNi 的整數金額。
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_金額ceil_訂單小數total與ceil後PAYUNi金額相符(): void {
		// create_wc_order 接受 float total
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_upp',
				'total'          => 1000.5,
			]
		);
		( new PayuniMetaKeys( $order ) )->update_trade_no( 'PCU_CEIL_001' );

		// ceil(1000.5) = 1001
		$payload = $this->paid_payload( 'PCU_CEIL_001', '1001' );
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'processing' );
	}

	// ========== TradeStatus=0：取號成功（Happy） ==========

	/**
	 * TradeStatus=0（ATM 取號成功）→ 維持 pending + 寫入 _pc_payuni_payment_info（ATM 欄位）
	 *
	 * ATM 欄位依 payuni-upp-v2 §虛擬帳號（PaymentType=2）：BankType / PayNo / PaySet / ExpireDate
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus0_ATM取號_維持pending並寫入payment_info(): void {
		$order   = $this->create_payuni_order( 'PCU_ATM_001' );
		$payload = $this->atm_get_code_payload( 'PCU_ATM_001' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 維持 pending
		$this->assert_order_status( $order, 'pending' );

		// payment_info 寫入正確的 ATM 欄位（BankType / PayNo / ExpireDate）
		$info = ( new PayuniMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertNotEmpty( $info, 'ATM 取號 payment_info 不應為空' );
		$this->assertSame( '822', $info['BankType'] ?? '', 'BankType（銀行代碼）不符' );
		$this->assertSame( '00000000001234', $info['PayNo'] ?? '', 'PayNo（虛擬帳號）不符' );
		$this->assertNotEmpty( $info['ExpireDate'] ?? '', 'ExpireDate 不應為空' );
	}

	/**
	 * TradeStatus=0（CVS 取號成功）→ 維持 pending + 寫入 _pc_payuni_payment_info（CVS 欄位）
	 *
	 * CVS 欄位依 payuni-upp-v2 §超商代碼（PaymentType=3）：Store / PayNo / ExpireDate
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus0_CVS取號_維持pending並寫入payment_info(): void {
		$order   = $this->create_payuni_order( 'PCU_CVS_001' );
		$payload = $this->cvs_get_code_payload( 'PCU_CVS_001' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 維持 pending
		$this->assert_order_status( $order, 'pending' );

		// payment_info 寫入正確的 CVS 欄位（Store / PayNo / ExpireDate）
		$info = ( new PayuniMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertNotEmpty( $info, 'CVS 取號 payment_info 不應為空' );
		$this->assertSame( '7-ELEVEN', $info['Store'] ?? '', 'Store（超商代碼）不符' );
		$this->assertSame( 'CVS12345678', $info['PayNo'] ?? '', 'PayNo（繳費代碼）不符' );
		$this->assertNotEmpty( $info['ExpireDate'] ?? '', 'ExpireDate 不應為空' );
	}

	/**
	 * TradeStatus=0 取號成功 → 不轉 processing，_pc_payuni_payment_detail 不被填入已付款資料
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus0取號_不寫入payment_detail(): void {
		$order   = $this->create_payuni_order( 'PCU_ATM_002' );
		$payload = $this->atm_get_code_payload( 'PCU_ATM_002' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// payment_detail 不應被設為已付款狀態（TradeStatus=1）
		$detail = ( new PayuniMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		// 不應有 TradeStatus=1 的付款成功記錄
		$this->assertNotSame( '1', $detail['TradeStatus'] ?? '' );
	}

	// ========== TradeStatus=2/3/8：非成功狀態（Error） ==========

	/**
	 * TradeStatus=2（付款失敗）→ 維持 pending + order note 記錄 Status/Message
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus2付款失敗_維持pending並記錄note(): void {
		$order   = $this->create_payuni_order( 'PCU_FAIL_001' );
		$payload = [
			'Status'      => 'E001',
			'Message'     => '付款失敗',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCU_FAIL_001',
			'TradeNo'     => 'UNI20260101FAIL',
			'TradeAmt'    => '1000',
			'TradeStatus' => '2',
			'PaymentType' => '1',
			'Gateway'     => '2',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 維持 pending
		$this->assert_order_status( $order, 'pending' );

		// order note 包含失敗相關資訊（Status 或 Message 或「失敗」字樣）
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if (
				str_contains( $note->content, 'E001' )
				|| str_contains( $note->content, '失敗' )
				|| str_contains( $note->content, '2' )
			) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含付款失敗相關資訊' );
	}

	/**
	 * TradeStatus=3（付款取消）→ 維持 pending + order note 記錄資訊
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus3付款取消_維持pending並記錄note(): void {
		$order   = $this->create_payuni_order( 'PCU_CANCEL_001' );
		$payload = [
			'Status'      => 'E002',
			'Message'     => '付款取消',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCU_CANCEL_001',
			'TradeNo'     => 'UNI20260101CAN',
			'TradeAmt'    => '1000',
			'TradeStatus' => '3',
			'PaymentType' => '1',
			'Gateway'     => '2',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, '取消' ) || str_contains( $note->content, 'E002' ) || str_contains( $note->content, '3' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含付款取消相關資訊' );
	}

	/**
	 * TradeStatus=8（訂單待確認 / UNKNOWN）→ 維持 pending + order note 記錄資訊
	 *
	 * 依 payuni-upp-v2 §常見注意事項：UNKNOWN = 60 秒未收到銀行回應，後續再由 NotifyURL 通知最終結果
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus8待確認_維持pending並記錄note(): void {
		$order   = $this->create_payuni_order( 'PCU_UNKNOWN_001' );
		$payload = [
			'Status'      => 'UNKNOWN',
			'Message'     => 'UNKNOWN',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCU_UNKNOWN_001',
			'TradeNo'     => 'UNI20260101UNK',
			'TradeAmt'    => '1000',
			'TradeStatus' => '8',
			'PaymentType' => '1',
			'Gateway'     => '2',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, 'UNKNOWN' ) || str_contains( $note->content, '待確認' ) || str_contains( $note->content, '8' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含待確認相關資訊' );
	}

	// ========== 冪等：重複處理（Edge） ==========

	/**
	 * 冪等：訂單已 processing，重複呼叫 update_order_status 不重複 payment_complete
	 *
	 * PAYUNi 可能因未收到 HTTP 200 而重送通知；已處理過的訂單不應被重複更新。
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_冪等_已processing訂單重複通知不重複處理(): void {
		// Given: 訂單已因付款成功轉為 processing
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'payuni_upp',
				'total'          => 1000,
			]
		);
		( new PayuniMetaKeys( $order ) )->update_trade_no( 'PCU_IDEM_001' );

		// When: 重複收到 4 次 TradeStatus=1 通知
		$payload = $this->paid_payload( 'PCU_IDEM_001', '1000' );
		for ( $i = 0; $i < 4; $i++ ) {
			$manager = new StatusManager( $payload, wc_get_order( $order->get_id() ) );
			$manager->update_order_status();
		}

		// Then: 狀態維持 processing（冪等，不出錯、不改狀態）
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 冪等：同一訂單連續兩次取號通知，payment_info 以最後一次為準（覆寫）
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_冪等_ATM取號通知重複時payment_info以最後一次為準(): void {
		$order = $this->create_payuni_order( 'PCU_ATM_IDEM' );

		// 第一次取號
		$payload_1            = $this->atm_get_code_payload( 'PCU_ATM_IDEM' );
		$payload_1['PayNo']   = '00000000000001';
		$manager              = new StatusManager( $payload_1, $order );
		$manager->update_order_status();

		// 第二次取號（PAYUNi 重送，PayNo 相同但模擬覆寫驗證）
		$payload_2          = $this->atm_get_code_payload( 'PCU_ATM_IDEM' );
		$payload_2['PayNo'] = '00000000000002';
		$manager_2          = new StatusManager( $payload_2, wc_get_order( $order->get_id() ) );
		$manager_2->update_order_status();

		// 維持 pending
		$this->assert_order_status( $order, 'pending' );

		// payment_info 以最後一次為準
		$info = ( new PayuniMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertSame( '00000000000002', $info['PayNo'] ?? '' );
	}

	// ========== 安全性：額外情境（Security） ==========

	/**
	 * 外層 Status 非 SUCCESS（即直接拒絕，無 EncryptInfo）→ 維持 pending
	 *
	 * 依 payuni-upp-v2 §常見注意事項：外層 Status=ERROR 時無 EncryptInfo，直接拒絕。
	 * StatusManager 收到 Status 非 SUCCESS 的 payload 時，不應更新訂單狀態。
	 *
	 * 注意：此判斷理應在 Callback 層完成（驗章失敗時不進入 StatusManager）；
	 * 此測試確保 StatusManager 作為最後防線，對 Status≠SUCCESS 的 payload 也維持 pending。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_外層Status非SUCCESS_維持pending(): void {
		$order   = $this->create_payuni_order( 'PCU_STATUS_ERR' );
		$payload = [
			// Status=ERROR 代表外層驗章已失敗，不應信任此 payload
			'Status'      => 'ERROR',
			'Message'     => '商店設定錯誤',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCU_STATUS_ERR',
			'TradeAmt'    => '1000',
			'TradeStatus' => '1', // 即使 TradeStatus=1 也不應更新
			'PaymentType' => '1',
			'Gateway'     => '2',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * TradeStatus 為未知值（規格外）→ 維持 pending，不拋例外
	 *
	 * 防禦未來 PAYUNi 新增 TradeStatus 值導致 match exhaustion
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus未知值_維持pending不拋例外(): void {
		$order   = $this->create_payuni_order( 'PCU_UNKNOWN_STATUS' );
		$payload = [
			'Status'      => 'SUCCESS',
			'Message'     => '未知狀態',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCU_UNKNOWN_STATUS',
			'TradeNo'     => 'UNI99999',
			'TradeAmt'    => '1000',
			'TradeStatus' => '99', // 規格外的未知值
			'PaymentType' => '1',
			'Gateway'     => '2',
		];

		// 不應拋例外
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 維持 pending（match default fallback）
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * MerID 不符商店設定 → 維持 pending（防跨商店污染）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_MerID不符設定_維持pending(): void {
		$order   = $this->create_payuni_order( 'PCU_MERID_ERR', 1000 );
		$payload = $this->paid_payload( 'PCU_MERID_ERR', '1000' );

		// 竄改 MerID 為其他商店
		$payload['MerID'] = 'EVIL_MERCHANT';

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}
}
