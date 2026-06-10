<?php
/**
 * PayNow StatusManager 骨架整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Managers\StatusManager
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 11（骨架）
 *   - specs/open-issue/paynow-implementation-plan.md §失敗模式登記表（金額竄改）
 *   - specs/features/payment/paynow-callback.feature
 *
 * Cycle 1 骨架測試範圍（僅金額防竄改核心斷言）：
 *   - Webhook Amount 與本地訂單金額不符 → 維持 pending（不呼叫 payment_complete）
 *
 * 完整場景於 Cycle 3（PaynowCallbackTest + 完整 StatusManager）展開：
 *   - Status=Success → payment_complete + processing
 *   - Status=Failed → pending + note
 *   - 冪等（已 processing 跳過）
 *   - 離線付款分支（ATM/ConvenienceStore → payment_info + pending）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowStatusManagerTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Managers\StatusManager;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow StatusManager 骨架測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowStatusManagerTest extends TestCase {

	/** 每次測試前設定 paynow 環境 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			'paynow',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => 'pk_test_dummy',
				'private_key' => 'sk_test_dummy',
			]
		);
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( 'paynow' ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * StatusManager 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_StatusManager可被實例化(): void {
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$payload = [
			'Status'          => 'Success',
			'PaymentIntentId' => 'pp_test_001',
			'Amount'          => '1000',
			'PaymentType'     => 'CreditCard',
		];

		$manager = new StatusManager( $payload, $order );
		$this->assertInstanceOf( StatusManager::class, $manager );
	}

	// ========== 金額防竄改（Smoke） ==========

	/**
	 * Webhook Amount 與本地訂單金額不符時維持 pending
	 * 依 paynow-implementation-plan §失敗模式登記表：Amount 竄改 → 維持 pending + 告警
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_金額不符時維持pending(): void {
		// 訂單金額 1000，Webhook 回報 999（竄改）
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$payload = [
			'Status'          => 'Success',
			'PaymentIntentId' => 'pp_tampered_001',
			'Amount'          => '999',  // 與本地 1000 不符
			'PaymentType'     => 'CreditCard',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 訂單應維持 pending，不轉 processing
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * Amount 為 0 時拒絕（防止零元交易）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_Amount為零時維持pending(): void {
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$payload = [
			'Status'          => 'Success',
			'PaymentIntentId' => 'pp_zero_amount_001',
			'Amount'          => '0',
			'PaymentType'     => 'CreditCard',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$this->assert_order_status( $order, 'pending' );
	}
}
