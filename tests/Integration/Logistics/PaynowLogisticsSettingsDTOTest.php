<?php
/**
 * PayNow 物流 SettingsDTO 整合測試（TDD Red 階段 — A-Cycle 1）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO
 *
 * 規格依據：
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 1
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 1 步驟 5 + §R8
 *
 * 涵蓋範疇：
 *   - 欄位讀取（user_account / apicode / mode / enabled_methods / sender_* 全部）
 *   - api_url() 依 mode 切換（test=testlogistic.paynow.com.tw / prod=logistic.paynow.com.tw）（R8）
 *   - option key 為 `woocommerce_paynow_logistics_settings`（不撞金流 `woocommerce_paynow_settings`）
 *   - instance / reset 單例行為
 *   - trim 讀取（空白字元不污染欄位值）
 *
 * ⚠️ 幣別踩雷：本類無金額計算，但呼應全域慣例仍設定 TWD。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowLogisticsSettingsDTOTest tests/Integration/Logistics/"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 物流 SettingsDTO 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsSettingsDTOTest extends TestCase {

	/** PayNow 物流 provider ID */
	private const PROVIDER_ID = 'paynow_logistics';

	/** WC option key（不得與金流 paynow 衝突） */
	private const OPTION_KEY = 'woocommerce_paynow_logistics_settings';

	/** test 模式 API URL（R8） */
	private const TEST_API_URL = 'https://testlogistic.paynow.com.tw';

	/** prod 模式 API URL（R8） */
	private const PROD_API_URL = 'https://logistic.paynow.com.tw';

	/** 每次測試前：重置單例、清理 option、設定幣別 */
	public function set_up(): void {
		parent::set_up();
		PaynowLogisticsSettingsDTO::reset();
		\delete_option( self::OPTION_KEY );
		\update_option( 'woocommerce_currency', 'TWD' );
	}

	/** 每次測試後：清理 */
	public function tear_down(): void {
		PaynowLogisticsSettingsDTO::reset();
		\delete_option( self::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 直接寫入 wp_options（模擬已儲存設定）
	 *
	 * @param array<string, mixed> $value 設定資料
	 */
	private function seed_option( array $value ): void {
		\update_option( self::OPTION_KEY, $value );
	}

	// ========== Happy Path：欄位讀取 ==========

	/**
	 * user_account 欄位正確讀取
	 *
	 * @test
	 * @group happy
	 */
	public function test_user_account欄位正確讀取(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'user_account' => 'TEST_USER_001',
			]
		);

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( 'TEST_USER_001', $dto->user_account, 'user_account 應與存入值相符' );
	}

	/**
	 * apicode 欄位正確讀取
	 *
	 * @test
	 * @group happy
	 */
	public function test_apicode欄位正確讀取(): void {
		$this->seed_option(
			[
				'mode'    => 'test',
				'apicode' => 'MY_API_CODE_999',
			]
		);

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( 'MY_API_CODE_999', $dto->apicode, 'apicode 應與存入值相符' );
	}

	/**
	 * mode 欄位正確讀取
	 *
	 * @test
	 * @group happy
	 */
	public function test_mode欄位正確讀取(): void {
		$this->seed_option( [ 'mode' => 'prod' ] );

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( 'prod', $dto->mode->value ?? $dto->mode, 'mode 應為 prod' );
	}

	/**
	 * enabled_methods 陣列正確讀取
	 *
	 * @test
	 * @group happy
	 */
	public function test_enabled_methods陣列正確讀取(): void {
		$this->seed_option(
			[
				'mode'            => 'test',
				'enabled_methods' => [ 'Seven', 'Fami', 'Tcat' ],
			]
		);

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertIsArray( $dto->enabled_methods, 'enabled_methods 應為陣列' );
		$this->assertContains( 'Seven', $dto->enabled_methods, 'enabled_methods 應含 Seven' );
		$this->assertContains( 'Fami', $dto->enabled_methods, 'enabled_methods 應含 Fami' );
	}

	/**
	 * sender_name 欄位正確讀取
	 *
	 * @test
	 * @group happy
	 */
	public function test_sender_name欄位正確讀取(): void {
		$this->seed_option(
			[
				'mode'        => 'test',
				'sender_name' => '寄件人姓名',
			]
		);

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( '寄件人姓名', $dto->sender_name, 'sender_name 應與存入值相符' );
	}

	/**
	 * sender_phone 欄位正確讀取
	 *
	 * @test
	 * @group happy
	 */
	public function test_sender_phone欄位正確讀取(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'sender_phone' => '0912345678',
			]
		);

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( '0912345678', $dto->sender_phone, 'sender_phone 應與存入值相符' );
	}

	/**
	 * 多個 sender_* 欄位同時讀取（sender_address、sender_email 等）
	 *
	 * @test
	 * @group happy
	 */
	public function test_多個sender欄位同時讀取(): void {
		$this->seed_option(
			[
				'mode'           => 'test',
				'sender_name'    => '王大明',
				'sender_phone'   => '0911222333',
				'sender_address' => '台北市中正區重慶南路一段122號',
				'sender_email'   => 'sender@example.com',
			]
		);

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( '王大明', $dto->sender_name, 'sender_name 應正確' );
		$this->assertSame( '0911222333', $dto->sender_phone, 'sender_phone 應正確' );
		$this->assertSame( '台北市中正區重慶南路一段122號', $dto->sender_address, 'sender_address 應正確' );
		$this->assertSame( 'sender@example.com', $dto->sender_email, 'sender_email 應正確' );
	}

	// ========== Happy Path：api_url() test/prod 切換（R8） ==========

	/**
	 * test 模式時 api_url() 回傳 testlogistic.paynow.com.tw（R8）
	 *
	 * 依 woomp L154 實證：test=https://testlogistic.paynow.com.tw
	 *
	 * @test
	 * @group happy
	 */
	public function test_test模式時api_url回傳testlogistic網域(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame(
			self::TEST_API_URL,
			$dto->api_url(),
			'test 模式 api_url() 應為 https://testlogistic.paynow.com.tw'
		);
	}

	/**
	 * prod 模式時 api_url() 回傳 logistic.paynow.com.tw（R8）
	 *
	 * 依 woomp 實證：prod=https://logistic.paynow.com.tw
	 *
	 * @test
	 * @group happy
	 */
	public function test_prod模式時api_url回傳prod網域(): void {
		$this->seed_option( [ 'mode' => 'prod' ] );

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame(
			self::PROD_API_URL,
			$dto->api_url(),
			'prod 模式 api_url() 應為 https://logistic.paynow.com.tw'
		);
	}

	/**
	 * test 與 prod 兩個網域完全不同（與金流/發票的 sandboxapi 也不同）
	 *
	 * 確保 R8 網域分離：testlogistic / logistic（物流）vs sandboxapi（金流）vs invoiceapi（發票）
	 *
	 * @test
	 * @group happy
	 */
	public function test_物流網域與金流網域完全不同(): void {
		$this->seed_option( [ 'mode' => 'test' ] );
		$dto = PaynowLogisticsSettingsDTO::instance();

		$test_url = $dto->api_url();

		$this->assertStringNotContainsString(
			'sandboxapi',
			$test_url,
			'物流 test 網域不得含 sandboxapi（那是金流網域）'
		);
		$this->assertStringContainsString(
			'testlogistic',
			$test_url,
			'物流 test 網域應含 testlogistic'
		);
	}

	// ========== Happy Path：option key 正確（不撞金流） ==========

	/**
	 * option key 為 woocommerce_paynow_logistics_settings（不撞金流 woocommerce_paynow_settings）
	 *
	 * @test
	 * @group happy
	 */
	public function test_option_key為woocommerce_paynow_logistics_settings(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'user_account' => 'LOGISTICS_ACCT',
			]
			);

		// 同時確認金流 option 是空的
		$logistics_raw = \get_option( 'woocommerce_paynow_logistics_settings' );
		$payment_raw   = \get_option( 'woocommerce_paynow_settings' );

		$this->assertIsArray( $logistics_raw, 'woocommerce_paynow_logistics_settings 應有資料' );
		$this->assertFalse( $payment_raw, '金流 woocommerce_paynow_settings 在此測試中應為空' );

		// DTO 讀到的 user_account 應來自物流 option，非金流 option
		$dto = PaynowLogisticsSettingsDTO::instance();
		$this->assertSame( 'LOGISTICS_ACCT', $dto->user_account, 'DTO 應讀取物流 option 的 user_account' );
	}

	/**
	 * ProviderUtils::get_option_name 對 paynow_logistics 回傳正確 option key
	 *
	 * 確認 paynow_logistics ID 不與 paynow（金流）衝突
	 *
	 * @test
	 * @group happy
	 */
	public function test_ProviderUtils的option_name對paynow_logistics正確(): void {
		$option_name = ProviderUtils::get_option_name( self::PROVIDER_ID );

		$this->assertSame(
			self::OPTION_KEY,
			$option_name,
			'paynow_logistics 的 option name 應為 woocommerce_paynow_logistics_settings'
		);
	}

	// ========== Edge：trim 欄位值 ==========

	/**
	 * 欄位讀取時自動 trim 前後空白（對齊既有 BaseSettingsDTO 行為）
	 *
	 * @test
	 * @group edge
	 */
	public function test_欄位讀取時自動trim空白(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'user_account' => '  USER_WITH_SPACES  ',
				'apicode'      => "\tCODE_WITH_TAB\t",
			]
		);

		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( 'USER_WITH_SPACES', $dto->user_account, 'user_account 讀取後應 trim' );
		$this->assertSame( 'CODE_WITH_TAB', $dto->apicode, 'apicode 讀取後應 trim' );
	}

	// ========== Happy Path：instance / reset 單例行為 ==========

	/**
	 * instance() 回傳同一個單例物件
	 *
	 * @test
	 * @group happy
	 */
	public function test_instance回傳同一個單例物件(): void {
		$first  = PaynowLogisticsSettingsDTO::instance();
		$second = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( $first, $second, 'instance() 應回傳同一個單例' );
	}

	/**
	 * reset() 後 instance() 重新讀取最新設定
	 *
	 * @test
	 * @group happy
	 */
	public function test_reset後instance重新讀取最新設定(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'user_account' => 'OLD_ACCOUNT',
			]
			);
		$before = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( 'OLD_ACCOUNT', $before->user_account );

		// 變更設定後 reset，應讀到新值
		$this->seed_option(
			[
				'mode'         => 'test',
				'user_account' => 'NEW_ACCOUNT',
			]
			);
		PaynowLogisticsSettingsDTO::reset();

		$after = PaynowLogisticsSettingsDTO::instance();
		$this->assertNotSame( $before, $after, 'reset 後應為新實例' );
		$this->assertSame( 'NEW_ACCOUNT', $after->user_account, 'reset 後應讀到新設定' );
	}

	/**
	 * reset() 後 api_url() 切換正確（test→prod）
	 *
	 * @test
	 * @group happy
	 */
	public function test_reset後api_url切換正確(): void {
		$this->seed_option( [ 'mode' => 'test' ] );
		$test_dto = PaynowLogisticsSettingsDTO::instance();
		$this->assertSame( self::TEST_API_URL, $test_dto->api_url() );

		// reset 並切換為 prod
		PaynowLogisticsSettingsDTO::reset();
		$this->seed_option( [ 'mode' => 'prod' ] );
		$prod_dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( self::PROD_API_URL, $prod_dto->api_url(), 'reset 後 prod 模式應回傳 prod URL' );
	}

	// ========== Happy Path：option 空值時預設值 ==========

	/**
	 * option 不存在時 DTO 屬性為空字串預設值
	 *
	 * @test
	 * @group happy
	 */
	public function test_option不存在時屬性為預設空字串(): void {
		// 不 seed_option，讓 option 為空
		$dto = PaynowLogisticsSettingsDTO::instance();

		$this->assertSame( '', $dto->user_account, 'option 不存在時 user_account 應為空字串' );
		$this->assertSame( '', $dto->apicode, 'option 不存在時 apicode 應為空字串' );
	}
}
