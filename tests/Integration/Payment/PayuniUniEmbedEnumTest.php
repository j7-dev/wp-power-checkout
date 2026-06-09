<?php
/**
 * PAYUNi UNi Embed V3 Enum 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Enums\PayuniUniEmbedTradeStatus
 *
 * 設計說明：
 *   - TradeStatus 值域與 UPP 相同（1/2/3/8，0 不適用—UNi Embed 信用卡無取號流程）
 *   - Gateway 識別值：UNi Embed 固定為 9（IFrame），UPP 為 2；兩者在同一 PAYUNi 平台並列
 *   - UNi Embed 僅支援信用卡，PaymentType 固定為 1
 *
 * 規格依據：
 *   - payuni-uni-embed-v3 SKILL.md §merchant_trade 回傳（Gateway=9）
 *   - payuni-uni-embed-v3 SKILL.md §TradeStatus：1=已付款 / 2=付款失敗 / 3=付款取消 / 8=訂單待確認
 *   - 與 UPP TradeStatus 值域對齊（但 UNi Embed 不存在 TradeStatus=0 取號情境）
 *   - specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 02 Enum 擴充
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni_uni_embed"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Enums\PayuniUniEmbedTradeStatus;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed Enum 測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedEnumTest extends TestCase {

	// ========== TradeStatus 值域（Happy） ==========

	/**
	 * TradeStatus=1 代表已付款（授權成功）
	 * 依 payuni-uni-embed-v3 §merchant_trade 回傳：TradeStatus=1=已付款
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus_1為已付款(): void {
		$this->assertSame( 1, PayuniUniEmbedTradeStatus::Paid->value );
	}

	/**
	 * TradeStatus=2 代表付款失敗
	 * 依 payuni-uni-embed-v3 §TradeStatus：2=付款失敗
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus_2為付款失敗(): void {
		$this->assertSame( 2, PayuniUniEmbedTradeStatus::Failed->value );
	}

	/**
	 * TradeStatus=3 代表付款取消
	 * 依 payuni-uni-embed-v3 §TradeStatus：3=付款取消
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus_3為付款取消(): void {
		$this->assertSame( 3, PayuniUniEmbedTradeStatus::Cancelled->value );
	}

	/**
	 * TradeStatus=8 代表訂單待確認（UNKNOWN，60秒無銀行回應）
	 * 依 payuni-uni-embed-v3 §UNKNOWN 狀態：60秒無銀行回應，後續以 NotifyURL 通知
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus_8為訂單待確認(): void {
		$this->assertSame( 8, PayuniUniEmbedTradeStatus::Pending->value );
	}

	// ========== Gateway 識別值（Happy / Security） ==========

	/**
	 * PayuniUniEmbedTradeStatus::GATEWAY_VALUE 等於 9（IFrame）
	 * 依 payuni-uni-embed-v3 §Gateway 欄位：固定 9=IFrame
	 * 斷言不是 UPP 的 2
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_Gateway識別值為9_IFrame(): void {
		$this->assertSame( 9, PayuniUniEmbedTradeStatus::GATEWAY_VALUE );
	}

	/**
	 * UNi Embed Gateway 識別值（9）不同於 UPP 的 Gateway 識別值（2）
	 * 確保兩個 gateway 的交易結果不會混淆
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_Gateway識別值9不同於UPP的2(): void {
		$this->assertSame( 9, PayuniUniEmbedTradeStatus::GATEWAY_VALUE );
		// UPP Gateway=2（依 payuni-upp-v2 規格），UNi Embed 不應等於 2
		$this->assertNotSame( 2, PayuniUniEmbedTradeStatus::GATEWAY_VALUE );
	}

	// ========== is_paid / is_pending 判定（Happy） ==========

	/**
	 * TradeStatus=1（已付款）→ is_paid() = true
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_is_paid_已付款為true(): void {
		$this->assertTrue( PayuniUniEmbedTradeStatus::Paid->is_paid() );
	}

	/**
	 * TradeStatus=2（付款失敗）→ is_paid() = false
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_is_paid_付款失敗為false(): void {
		$this->assertFalse( PayuniUniEmbedTradeStatus::Failed->is_paid() );
	}

	/**
	 * TradeStatus=3（付款取消）→ is_paid() = false
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_is_paid_付款取消為false(): void {
		$this->assertFalse( PayuniUniEmbedTradeStatus::Cancelled->is_paid() );
	}

	/**
	 * TradeStatus=8（待確認）→ is_paid() = false（不算正式付款）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_is_paid_待確認為false(): void {
		$this->assertFalse( PayuniUniEmbedTradeStatus::Pending->is_paid() );
	}

	// ========== tryFrom 整數值（Edge） ==========

	/**
	 * tryFrom() 可從整數值建立 enum case
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus_tryFrom_整數值建立enum(): void {
		$this->assertSame( PayuniUniEmbedTradeStatus::Paid, PayuniUniEmbedTradeStatus::tryFrom( 1 ) );
		$this->assertSame( PayuniUniEmbedTradeStatus::Failed, PayuniUniEmbedTradeStatus::tryFrom( 2 ) );
		$this->assertSame( PayuniUniEmbedTradeStatus::Cancelled, PayuniUniEmbedTradeStatus::tryFrom( 3 ) );
		$this->assertSame( PayuniUniEmbedTradeStatus::Pending, PayuniUniEmbedTradeStatus::tryFrom( 8 ) );
		$this->assertNull( PayuniUniEmbedTradeStatus::tryFrom( 999 ) );
	}

	/**
	 * UNi Embed TradeStatus 不包含 0（取號成功）
	 * UNi Embed 僅支援信用卡，無 ATM/CVS 取號流程，故無 TradeStatus=0
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_TradeStatus_不包含0取號成功(): void {
		// UNi Embed 信用卡不存在取號流程，tryFrom(0) 應回 null
		$this->assertNull(
			PayuniUniEmbedTradeStatus::tryFrom( 0 ),
			'UNi Embed TradeStatus 不應包含 0（取號成功），只有 UPP ATM/CVS 才有此狀態'
		);
	}

	/**
	 * 從 Gateway 值 9 可識別為 IFrame 類型（不是 UPP 的 2）
	 * 用於 process_refund 時區分要走哪條 API 路徑
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_從Gateway值9識別為IFrame類型(): void {
		$gateway_value = PayuniUniEmbedTradeStatus::GATEWAY_VALUE;

		// Gateway=9 是 IFrame（UNi Embed）
		$this->assertSame( 9, $gateway_value );
		// 不是 UPP 的 Gateway=2
		$this->assertNotSame( 2, $gateway_value );
	}
}
