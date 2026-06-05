<?php
/**
 * 購物車（cart / session）級物流選店暫存與權杖綁定整合測試
 *
 * 對應 cart-bound 選店的安全核心：
 *   1. issue_token：產生不可猜測權杖，存入 session + 建立 token→customer_id 索引
 *   2. resolve_customer_id：以權杖 timing-safe 反查 session（不可猜測 / 偽造 → null）
 *   3. store_by_token：權杖驗證通過才寫門市；偽造權杖拒絕寫入
 *   4. get_selected_store / clear：暫存讀取與清除
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     vendor/bin/phpunit --filter CartLogisticsSessionTest 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession;
use Tests\Integration\TestCase;

/**
 * cart 級選店 session 測試類別
 *
 * @group integration
 * @group logistics
 */
final class CartLogisticsSessionTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->boot_wc_session();
	}

	public function tear_down(): void {
		CartLogisticsSession::clear();
		parent::tear_down();
	}

	/**
	 * 初始化 WC session（測試環境預設不啟動 session）
	 *
	 * @return void
	 */
	private function boot_wc_session(): void {
		if (!\function_exists( 'WC' )) {
			$this->markTestSkipped( 'WooCommerce 未載入' );
		}
		$wc = \WC();
		if (!isset( $wc->session ) || !$wc->session instanceof \WC_Session) {
			$wc->initialize_session();
		}
		// 確保有 session（has_session）以便 save_data 生效
		$wc->session->set_customer_session_cookie( true );
	}

	// ========== happy ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_產生權杖後可反查出對應customer_id(): void {
		$token = CartLogisticsSession::issue_token();

		$this->assertNotEmpty( $token, '應產生非空權杖' );
		$this->assertGreaterThanOrEqual( 32, \strlen( $token ), '權杖應夠長（不可猜測）' );

		$customer_id = CartLogisticsSession::resolve_customer_id( $token );
		$this->assertNotNull( $customer_id, '正確權杖應可反查出 customer_id' );
		$this->assertSame(
			(string) \WC()->session->get_customer_id(),
			$customer_id,
			'反查出的 customer_id 應為當前 session'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_權杖驗證通過後可寫入並讀回門市(): void {
		$token = CartLogisticsSession::issue_token();

		$ok = CartLogisticsSession::store_by_token(
			$token,
			[
				'temp_id'    => '2264',
				'store_id'   => '991182',
				'store_name' => '全家測試門市',
				'store_addr' => '台北市中山區測試路1號',
				'sub_type'   => 'FAMI',
			]
		);
		$this->assertTrue( $ok, '正確權杖應成功寫入門市' );

		$store = CartLogisticsSession::get_selected_store();
		$this->assertNotNull( $store, '應讀回暫存門市' );
		$this->assertSame( '2264', $store['temp_id'] );
		$this->assertSame( '991182', $store['store_id'] );
		$this->assertSame( '全家測試門市', $store['store_name'] );
		$this->assertSame( 'FAMI', $store['sub_type'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_clear清除暫存門市與權杖(): void {
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token( $token, [ 'store_id' => 'X1', 'temp_id' => '1' ] );
		$this->assertNotNull( CartLogisticsSession::get_selected_store() );

		CartLogisticsSession::clear();

		$this->assertNull( CartLogisticsSession::get_selected_store(), 'clear 後不應有暫存門市' );
		$this->assertNull(
			CartLogisticsSession::resolve_customer_id( $token ),
			'clear 後權杖索引應失效'
		);
	}

	// ========== security ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_偽造權杖無法反查出customer_id(): void {
		CartLogisticsSession::issue_token(); // 產生合法權杖（但不使用）

		$forged = 'forged_token_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		$this->assertNull(
			CartLogisticsSession::resolve_customer_id( $forged ),
			'偽造權杖不應反查出任何 customer_id'
		);
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_空權杖反查回null(): void {
		$this->assertNull( CartLogisticsSession::resolve_customer_id( '' ) );
		$this->assertNull( CartLogisticsSession::resolve_customer_id( '   ' ) );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_偽造權杖無法寫入門市(): void {
		CartLogisticsSession::issue_token();

		$ok = CartLogisticsSession::store_by_token(
			'forged_token_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
			[ 'store_id' => 'EVIL', 'temp_id' => '999' ]
		);

		$this->assertFalse( $ok, '偽造權杖不應成功寫入' );
		$this->assertNull(
			CartLogisticsSession::get_selected_store(),
			'偽造權杖寫入後 session 不應有門市'
		);
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_重新產生權杖後舊權杖失效(): void {
		$old_token = CartLogisticsSession::issue_token();
		$new_token = CartLogisticsSession::issue_token();

		$this->assertNotSame( $old_token, $new_token, '每次應產生不同權杖' );

		// 舊權杖對應的 session 內權杖已被新權杖取代 → timing-safe 比對失敗
		$this->assertNull(
			CartLogisticsSession::resolve_customer_id( $old_token ),
			'舊權杖應因 session 內權杖已更新而失效'
		);
		$this->assertNotNull(
			CartLogisticsSession::resolve_customer_id( $new_token ),
			'新權杖應有效'
		);
	}

	// ========== edge ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_未選店時get_selected_store回null(): void {
		CartLogisticsSession::issue_token();
		$this->assertNull(
			CartLogisticsSession::get_selected_store(),
			'僅產生權杖未選店時不應有門市'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_空store_id的門市視為未選店(): void {
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token( $token, [ 'store_id' => '', 'temp_id' => '5' ] );
		$this->assertNull(
			CartLogisticsSession::get_selected_store(),
			'store_id 為空應視為未選店'
		);
	}
}
