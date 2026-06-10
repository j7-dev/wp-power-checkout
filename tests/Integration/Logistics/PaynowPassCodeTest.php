<?php
/**
 * PayNow PassCodeService 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段，class 不存在時預期 class not found）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PassCodeService
 *
 * 規格依據：
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 2
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §R6 PassCode 規則
 *   - woomp grounding：
 *       ../woomp/.../class-paynow-shipping-request.php L780
 *       strtoupper( hash('sha1', user_account . OrderNo . TotalAmount . apicode) )
 *
 * R6 裁決：
 *   PassCode = strtoupper(sha1(user_account + OrderNo + TotalAmount + apicode))
 *   TotalAmount 用 $order->get_total() 原值（字串格式）
 *   ⚠️ "1000" 與 "1000.00" sha1 結果不同 → 測試須鎖定格式
 *
 * 已知向量（本地 PHP 驗算鎖定）：
 *   build('testuser', 'PCN123', '1000',    'testapi') = 'ED49EF7BD40EF7E3C0529930A723EB65E29F57AF'
 *   build('testuser', 'PCN123', '1000.00', 'testapi') = '58E7A1E843D87C0D23D31EB6BEF6B3BF35AE69CA'（不同！）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowPassCodeTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PassCodeService;
use Tests\Integration\TestCase;

/**
 * PayNow PassCodeService 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowPassCodeTest extends TestCase {

	// ========== Happy：固定向量驗證 ==========

	/**
	 * build() 對固定輸入產生正確的 SHA1 大寫字串
	 *
	 * 向量：strtoupper(sha1('testuser' + 'PCN123' + '1000' + 'testapi'))
	 *      = 'ED49EF7BD40EF7E3C0529930A723EB65E29F57AF'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_build_固定向量輸出正確SHA1大寫(): void {
		$result = PassCodeService::build(
			user_account: 'testuser',
			order_no: 'PCN123',
			total: '1000',
			apicode: 'testapi'
		);

		$this->assertSame(
			'ED49EF7BD40EF7E3C0529930A723EB65E29F57AF',
			$result,
			'PassCode 固定向量不符——strtoupper(sha1(user+order+total+api)) 計算結果有誤'
		);
	}

	/**
	 * build() 輸出一律為大寫（strtoupper 確認）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_build_輸出為大寫40字元hex(): void {
		$result = PassCodeService::build(
			user_account: 'anyuser',
			order_no: 'ORDER001',
			total: '500',
			apicode: 'mycode'
		);

		// SHA1 輸出為 40 字元大寫 hex
		$this->assertSame( 40, strlen( $result ), 'PassCode 應為 40 字元 SHA1 hex' );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{40}$/', $result, 'PassCode 應全大寫 hex' );
	}

	// ========== Edge：total 格式敏感性（R6 關鍵） ==========

	/**
	 * total "1000" 與 "1000.00" 產生不同 PassCode（格式敏感）
	 *
	 * R6 ⚠️ 警告：$order->get_total() 回傳的字串格式影響 PassCode 計算。
	 * 若格式與 API 方不一致，會導致 PassCode 驗証失敗（建單被拒）。
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_total格式敏感_1000與1000點00產生不同PassCode(): void {
		$result_int     = PassCodeService::build(
			user_account: 'testuser',
			order_no: 'PCN123',
			total: '1000',
			apicode: 'testapi'
		);
		$result_decimal = PassCodeService::build(
			user_account: 'testuser',
			order_no: 'PCN123',
			total: '1000.00',
			apicode: 'testapi'
		);

		$this->assertNotSame(
			$result_int,
			$result_decimal,
			'R6：total "1000" 與 "1000.00" 必須產生不同 PassCode（字串格式敏感）'
		);
	}

	/**
	 * "1000.00" 格式向量：正確的 SHA1 大寫
	 *
	 * 向量：strtoupper(sha1('testuser' + 'PCN123' + '1000.00' + 'testapi'))
	 *      = '58E7A1E843D87C0D23D31EB6BEF6B3BF35AE69CA'
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_build_1000點00格式向量(): void {
		$result = PassCodeService::build(
			user_account: 'testuser',
			order_no: 'PCN123',
			total: '1000.00',
			apicode: 'testapi'
		);

		$this->assertSame(
			'58E7A1E843D87C0D23D31EB6BEF6B3BF35AE69CA',
			$result,
			'PassCode "1000.00" 向量不符'
		);
	}

	/**
	 * user_account 不同時 PassCode 不同（各欄位影響結果）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_build_user_account不同產生不同PassCode(): void {
		$result_a = PassCodeService::build(
			user_account: 'user_a',
			order_no: 'PCN001',
			total: '100',
			apicode: 'code1'
		);
		$result_b = PassCodeService::build(
			user_account: 'user_b',
			order_no: 'PCN001',
			total: '100',
			apicode: 'code1'
		);

		$this->assertNotSame( $result_a, $result_b, '不同 user_account 必須產生不同 PassCode' );
	}

	/**
	 * order_no 不同時 PassCode 不同
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_build_order_no不同產生不同PassCode(): void {
		$result_a = PassCodeService::build(
			user_account: 'testuser',
			order_no: 'PCN001',
			total: '500',
			apicode: 'code1'
		);
		$result_b = PassCodeService::build(
			user_account: 'testuser',
			order_no: 'PCN002',
			total: '500',
			apicode: 'code1'
		);

		$this->assertNotSame( $result_a, $result_b, '不同 order_no 必須產生不同 PassCode' );
	}

	/**
	 * 同樣輸入多次呼叫結果相同（冪等）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_build_同樣輸入多次結果相同(): void {
		$args = [
			'user_account' => 'stableuser',
			'order_no'     => 'PCN999',
			'total'        => '3500',
			'apicode'      => 'stablecode',
		];

		$result1 = PassCodeService::build( ...$args );
		$result2 = PassCodeService::build( ...$args );

		$this->assertSame( $result1, $result2, 'PassCode 對相同輸入必須冪等（每次結果一致）' );
	}

	/**
	 * 幣別須設為 TWD（確保 get_total() 不帶小數符號變化）
	 * 此測試驗證 update_option 環境設定不影響純字串計算
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_build_純字串計算不受WC幣別環境影響(): void {
		// WC 幣別設 TWD（防禦性設定，確保其他測試幣別設定不干擾）
		\update_option( 'woocommerce_currency', 'TWD' );

		$result = PassCodeService::build(
			user_account: 'testuser',
			order_no: 'PCN123',
			total: '1000',
			apicode: 'testapi'
		);

		// 純字串計算，不受 WC 幣別格式影響
		$this->assertSame(
			'ED49EF7BD40EF7E3C0529930A723EB65E29F57AF',
			$result,
			'PassCode 計算應為純字串 SHA1，不受 WC 幣別環境影響'
		);
	}
}
