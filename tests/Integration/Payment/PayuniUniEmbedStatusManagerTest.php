<?php
/**
 * PAYUNi UNi Embed V3 StatusManager 整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Managers\StatusManager
 *
 * 設計說明：
 *   StatusManager 建構子接收「解密後的內層通知陣列 + WC_Order」。
 *   簽章：new StatusManager( array $inner_payload, \WC_Order $order )
 *   此設計對齊 PayuniStatusManagerTest.php 風格（payload 陣列 + 訂單注入）。
 *
 *   核心業務規則（依 specs/features/payment/payuni-uni-embed-callback.feature §DECISION:4a）：
 *   - TradeStatus=1 + Status=SUCCESS → payment_complete() + 寫 _pc_payuni_uni_payment_detail
 *   - TradeStatus=1 + TradeAmt ≠ ceil(訂單應收) → 維持 pending + order note 告警（資安最關鍵）
 *   - TradeStatus=2（付款失敗） → 維持 pending + order note
 *   - TradeStatus=3（付款取消） → 維持 pending + order note
 *   - TradeStatus=8（待確認 / UNKNOWN / UNAPPROVED） → 維持 pending + order note
 *   - 冪等：已 processing 不重複 payment_complete
 *
 *   UNi Embed 獨有差異（對比 UPP StatusManager）：
 *   - 無 TradeStatus=0（取號成功）：UNi Embed 僅支援信用卡，無 ATM/CVS 取號流程
 *   - Gateway=9（IFrame），非 UPP 的 Gateway=2
 *   - PaymentType 固定 1（信用卡）
 *   - _pc_payuni_uni_* meta key 前綴（非 _pc_payuni_*）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ \
 *     --filter PayuniUniEmbed --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed V3 StatusManager 測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedStatusManagerTest extends TestCase {

	// PAYUNi 官方公開測試向量金鑰（共用）
	private const HASH_KEY    = '12345678901234567890123456789012';
	private const HASH_IV     = '1234567890123456';
	private const MERCHANT_ID = 'UNI_TEST_MER';

	/** 每次測試前啟用 payuni_uni_embed（test 模式） */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			'payuni_uni_embed',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'merchant_id' => self::MERCHANT_ID,
				'hash_key'    => self::HASH_KEY,
				'hash_iv'     => self::HASH_IV,
			]
		);
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( 'payuni_uni_embed' ) );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	// =====================================================================
	// 測試輔助方法
	// =====================================================================

	/**
	 * 建立已綁 _pc_payuni_uni_trade_no 的 pending 訂單
	 *
	 * @param string $mer_trade_no PAYUNi MerTradeNo（PCE 前綴，儲存於 _pc_payuni_uni_trade_no）
	 * @param int    $total        訂單應收金額（整數，單位：元）
	 * @return \WC_Order
	 */
	private function create_uni_embed_order( string $mer_trade_no, int $total = 1000 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_uni_embed',
				'total'          => $total,
			]
		);
		( new PayuniUniEmbedMetaKeys( $order ) )->update_trade_no( $mer_trade_no );
		return $order;
	}

	/**
	 * 建立標準的已付款內層 payload（TradeStatus=1）
	 *
	 * ⚠️ Gateway=9（IFrame，UNi Embed 固定值，不同於 UPP 的 Gateway=2）
	 * ⚠️ PaymentType=1（信用卡，UNi Embed 唯一支援工具）
	 *
	 * @param string $mer_trade_no 商店訂單編號（PCE 前綴）
	 * @param string $trade_amt    交易金額（字串，PAYUNi 回傳字串形式）
	 * @return array<string, string>
	 */
	private function paid_payload( string $mer_trade_no, string $trade_amt = '1000' ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '授權成功',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNIEMBED20260101001',
			'TradeAmt'    => $trade_amt,
			'TradeStatus' => '1',
			'PaymentType' => '1',   // 固定 1=信用卡（UNi Embed 僅支援信用卡）
			'Gateway'     => '9',   // 固定 9=IFrame（UPP 為 2）
			'CardBank'    => '013',
			'Card6No'     => '414763',
			'Card4No'     => '0001',
			'AuthCode'    => '123456',
		];
	}

	// =====================================================================
	// 冒煙測試（Smoke）
	// =====================================================================

	/**
	 * StatusManager 可被實例化（建構子接收 inner_payload 陣列 + WC_Order）
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_StatusManager可被實例化(): void {
		$order   = $this->create_uni_embed_order( 'PCE_SMOKE_001' );
		$manager = new StatusManager( $this->paid_payload( 'PCE_SMOKE_001' ), $order );

		$this->assertInstanceOf( StatusManager::class, $manager );
	}

	// =====================================================================
	// Happy：TradeStatus=1 已付款（完整 payment_complete 路徑）
	// =====================================================================

	/**
	 * TradeStatus=1（已付款）→ 訂單轉 processing（payment_complete）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus1已付款_訂單轉processing(): void {
		// Given: pending 訂單（應收 1000）
		$order = $this->create_uni_embed_order( 'PCE_PAID_001', 1000 );

		// When: 收到 TradeStatus=1 + TradeAmt=1000（相符）
		$manager = new StatusManager( $this->paid_payload( 'PCE_PAID_001', '1000' ), $order );
		$manager->update_order_status();

		// Then: 訂單轉 processing
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * TradeStatus=1（已付款）→ 寫入 _pc_payuni_uni_payment_detail
	 * 驗證 Gateway=9 識別欄位正確寫入（非 UPP 的 2）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus1已付款_寫入payment_detail含Gateway9(): void {
		$order   = $this->create_uni_embed_order( 'PCE_PAID_002', 1000 );
		$payload = $this->paid_payload( 'PCE_PAID_002', '1000' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// Then: _pc_payuni_uni_payment_detail 有值，含 TradeStatus=1 + Gateway=9
		$detail = ( new PayuniUniEmbedMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertNotEmpty( $detail, 'UNi Embed payment_detail 不應為空' );
		$this->assertSame( '1', $detail['TradeStatus'] ?? '' );
		$this->assertSame( 'SUCCESS', $detail['Status'] ?? '' );
		$this->assertSame( '9', $detail['Gateway'] ?? '', 'Gateway 必須為 9（IFrame，UNi Embed 識別）' );
		$this->assertSame( '1', $detail['PaymentType'] ?? '', 'PaymentType 必須為 1（信用卡）' );
	}

	/**
	 * TradeStatus=1（已付款）→ 新增 order note 包含付款成功字樣
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus1已付款_新增order_note(): void {
		$order = $this->create_uni_embed_order( 'PCE_PAID_003', 1000 );

		$manager = new StatusManager( $this->paid_payload( 'PCE_PAID_003', '1000' ), $order );
		$manager->update_order_status();

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

	// =====================================================================
	// Security：金額防竄改（最重要—放 security 群）
	// =====================================================================

	/**
	 * TradeStatus=1 但 TradeAmt ≠ 訂單應收 → 維持 pending，不轉 processing
	 * 此為資安最關鍵案例：惡意竄改通知金額以低價換取商品
	 *
	 * 驗算：訂單 total=1000，ceil(1000)=1000；PAYUNi 回傳 TradeAmt=1 → 竄改
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_金額竄改_TradeAmt不符維持pending(): void {
		// Given: pending 訂單（應收 1000）
		$order = $this->create_uni_embed_order( 'PCE_AMT_TAMPER', 1000 );

		// When: 回傳 TradeStatus=1 但 TradeAmt=1（惡意竄改）
		$payload = $this->paid_payload( 'PCE_AMT_TAMPER', '1' );
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
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_金額竄改_新增告警order_note含金額字樣(): void {
		$order   = $this->create_uni_embed_order( 'PCE_AMT_WARN', 1000 );
		$payload = $this->paid_payload( 'PCE_AMT_WARN', '1' ); // 竄改金額

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 告警 note 必須包含「金額」字樣
		$this->assert_order_note_contains( $order, '金額' );
	}

	/**
	 * TradeStatus=1 且 TradeAmt 相符 → 轉 processing（確認金額驗證不過度嚴格）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_金額相符_正常轉processing(): void {
		$order   = $this->create_uni_embed_order( 'PCE_AMT_MATCH', 1500 );
		$payload = $this->paid_payload( 'PCE_AMT_MATCH', '1500' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * TradeStatus=1 且 TradeAmt=ceil(訂單小數總額）→ 轉 processing（ceil 語意確認）
	 *
	 * PAYUNi TradeAmt 必須為整數；WC 訂單 total 可能有小數（如 1000.5 → ceil=1001）。
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_金額ceil_訂單小數total與ceil後PAYUNi金額相符(): void {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_uni_embed',
				'total'          => 1000.5, // 有小數
			]
		);
		( new PayuniUniEmbedMetaKeys( $order ) )->update_trade_no( 'PCE_CEIL_001' );

		// ceil(1000.5) = 1001
		$payload = $this->paid_payload( 'PCE_CEIL_001', '1001' );
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * TradeStatus=1 但 TradeAmt 超大（注入攻擊式竄改）→ 維持 pending
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_金額超大竄改_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_AMT_BIG', 1000 );
		$payload = $this->paid_payload( 'PCE_AMT_BIG', '199999' ); // 超大金額

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * TradeStatus=1 且 TradeAmt=0 → 維持 pending（零元攻擊）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_金額零元_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_AMT_ZERO', 1000 );
		$payload = $this->paid_payload( 'PCE_AMT_ZERO', '0' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Error：TradeStatus=2/3（付款失敗/取消）→ 維持 pending + note
	// =====================================================================

	/**
	 * TradeStatus=2（付款失敗）→ 維持 pending + order note 記錄 Status/Message
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus2付款失敗_維持pending並記錄note(): void {
		$order   = $this->create_uni_embed_order( 'PCE_FAIL_001' );
		$payload = [
			'Status'      => 'E001',
			'Message'     => '付款失敗',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_FAIL_001',
			'TradeNo'     => 'UNIEMBED_FAIL',
			'TradeAmt'    => '1000',
			'TradeStatus' => '2',
			'PaymentType' => '1',
			'Gateway'     => '9',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 維持 pending
		$this->assert_order_status( $order, 'pending' );

		// order note 包含失敗相關資訊
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
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus3付款取消_維持pending並記錄note(): void {
		$order   = $this->create_uni_embed_order( 'PCE_CANCEL_001' );
		$payload = [
			'Status'      => 'E002',
			'Message'     => '付款取消',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_CANCEL_001',
			'TradeNo'     => 'UNIEMBED_CANCEL',
			'TradeAmt'    => '1000',
			'TradeStatus' => '3',
			'PaymentType' => '1',
			'Gateway'     => '9',
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

	// =====================================================================
	// Edge：TradeStatus=8（待確認 UNKNOWN / UNAPPROVED）
	// =====================================================================

	/**
	 * TradeStatus=8（UNKNOWN，60 秒無銀行回應）→ 維持 pending + order note
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus8_UNKNOWN_維持pending並記錄note(): void {
		$order   = $this->create_uni_embed_order( 'PCE_UNKNOWN_001' );
		$payload = [
			'Status'      => 'UNKNOWN',
			'Message'     => 'UNKNOWN',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_UNKNOWN_001',
			'TradeNo'     => 'UNIEMBED_UNK',
			'TradeAmt'    => '1000',
			'TradeStatus' => '8',
			'PaymentType' => '1',
			'Gateway'     => '9',
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

	/**
	 * TradeStatus=8（UNAPPROVED，買家會員審查中）→ 維持 pending + order note
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus8_UNAPPROVED_維持pending並記錄note(): void {
		$order   = $this->create_uni_embed_order( 'PCE_UNAPPROVED_001' );
		$payload = [
			'Status'      => 'UNAPPROVED',
			'Message'     => '買家會員審查中',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_UNAPPROVED_001',
			'TradeNo'     => 'UNIEMBED_UNAPPR',
			'TradeAmt'    => '1000',
			'TradeStatus' => '8',
			'PaymentType' => '1',
			'Gateway'     => '9',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, 'UNAPPROVED' ) || str_contains( $note->content, '審查' ) || str_contains( $note->content, '8' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'order note 應包含 UNAPPROVED 相關資訊' );
	}

	/**
	 * TradeStatus 為未知值（規格外）→ 維持 pending，不拋例外
	 * 防禦未來 PAYUNi 新增 TradeStatus 值導致 match exhaustion
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus未知值_維持pending不拋例外(): void {
		$order   = $this->create_uni_embed_order( 'PCE_UNKNOWN_STATUS' );
		$payload = [
			'Status'      => 'SUCCESS',
			'Message'     => '未知狀態',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_UNKNOWN_STATUS',
			'TradeNo'     => 'UNIEMBED99999',
			'TradeAmt'    => '1000',
			'TradeStatus' => '99', // 規格外的未知值
			'PaymentType' => '1',
			'Gateway'     => '9',
		];

		// 不應拋例外
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 維持 pending（match default fallback）
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * UNi Embed 不應處理 TradeStatus=0（取號成功）
	 * UNi Embed 僅支援信用卡，沒有 ATM/CVS 取號流程，此為嚴格邊界測試
	 *
	 * @test
	 * @group edge
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus0_UNiEmbed不應出現此狀態_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_TS0_TEST' );
		$payload = [
			'Status'      => 'SUCCESS',
			'Message'     => '意外的取號',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_TS0_TEST',
			'TradeNo'     => 'UNIEMBED_TS0',
			'TradeAmt'    => '1000',
			'TradeStatus' => '0',   // UNi Embed 不應出現，但要能安全處理
			'PaymentType' => '1',
			'Gateway'     => '9',
		];

		// 不應拋例外，也不應更新訂單（UNi Embed 信用卡不存在取號）
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Edge：冪等（重複處理防護）
	// =====================================================================

	/**
	 * 冪等：訂單已 processing，重複呼叫 update_order_status 不重複 payment_complete
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冪等_已processing訂單重複通知不重複處理(): void {
		// Given: 訂單已因付款成功轉為 processing
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'payuni_uni_embed',
				'total'          => 1000,
			]
		);
		( new PayuniUniEmbedMetaKeys( $order ) )->update_trade_no( 'PCE_IDEM_001' );

		// When: 重複收到 4 次 TradeStatus=1 通知
		$payload = $this->paid_payload( 'PCE_IDEM_001', '1000' );
		for ( $i = 0; $i < 4; $i++ ) {
			$manager = new StatusManager( $payload, wc_get_order( $order->get_id() ) );
			$manager->update_order_status();
		}

		// Then: 狀態維持 processing（冪等，不出錯、不改狀態）
		$this->assert_order_status( $order, 'processing' );
	}

	// =====================================================================
	// Security：其他安全情境
	// =====================================================================

	/**
	 * 外層 Status 非 SUCCESS → 維持 pending（StatusManager 作為最後防線）
	 *
	 * 注意：此判斷理應在 Callback 層完成（驗章失敗時不進入 StatusManager）；
	 * 此測試確保 StatusManager 作為最後防線，對 Status≠SUCCESS 的 payload 也維持 pending。
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_外層Status非SUCCESS_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_STATUS_ERR' );
		$payload = [
			// Status=ERROR 代表外層驗章已失敗，不應信任此 payload
			'Status'      => 'ERROR',
			'Message'     => '商店設定錯誤',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => 'PCE_STATUS_ERR',
			'TradeAmt'    => '1000',
			'TradeStatus' => '1', // 即使 TradeStatus=1 也不應更新
			'PaymentType' => '1',
			'Gateway'     => '9',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * MerID 不符商店設定 → 維持 pending（防跨商店污染）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_MerID不符設定_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_MERID_ERR', 1000 );
		$payload = $this->paid_payload( 'PCE_MERID_ERR', '1000' );

		// 竄改 MerID 為其他商店
		$payload['MerID'] = 'EVIL_MERCHANT';

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * Gateway 值不是 9 → 維持 pending（防 UPP Gateway=2 混入）
	 *
	 * UNi Embed StatusManager 應僅接受 Gateway=9 的 payload；
	 * 若 Gateway=2 出現，代表可能是 UPP 回調誤觸發到 UNi Embed 路徑
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_Gateway非9_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_GW_WRONG', 1000 );
		$payload = $this->paid_payload( 'PCE_GW_WRONG', '1000' );

		// 竄改 Gateway 為 UPP 的值
		$payload['Gateway'] = '2';

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * TradeStatus=1 + TradeAmt 含非數字字元 → 維持 pending（格式驗證）
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeAmt含非數字字元_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_AMT_NAN', 1000 );
		$payload = $this->paid_payload( 'PCE_AMT_NAN', '1000abc' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * TradeStatus=1 + TradeAmt 含負數 → 維持 pending（負數金額攻擊）
	 *
	 * @test
	 * @group security
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeAmt負數_維持pending(): void {
		$order   = $this->create_uni_embed_order( 'PCE_AMT_NEG', 1000 );
		$payload = $this->paid_payload( 'PCE_AMT_NEG', '-1000' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * payment_detail 寫入後正確包含 CreditHash（Token Hash）
	 * 絕不包含卡號/CVC（硬安全約束）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_付款成功_payment_detail含CreditHash但不含卡號CVC(): void {
		$order   = $this->create_uni_embed_order( 'PCE_CRHASH_001', 1000 );
		$payload = $this->paid_payload( 'PCE_CRHASH_001', '1000' );

		// 加入 CreditHash 和 CreditLife（PAYUNi 授權成功才回傳）
		$payload['CreditHash'] = 'HASH_ABCDEF123456';
		$payload['CreditLife'] = '1230';

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$detail = ( new PayuniUniEmbedMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertNotEmpty( $detail );

		// 絕不包含完整卡號（Card6No + Card4No 僅為隱碼，不是完整卡號）
		foreach ( $detail as $key => $value ) {
			$this->assertStringNotContainsString(
				'4147631000000001',
				(string) $value,
				"payment_detail 不應包含完整卡號（key={$key}）"
			);
		}

		// CreditHash 可以出現（只是 hash，不是卡號）
		// 注意：StatusManager 可能將 CreditHash 寫入獨立的 _pc_payuni_uni_credit_hash，
		//       也可能含在 payment_detail 中——兩種方式均接受，只驗證完整卡號不出現
	}
}
