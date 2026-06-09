<?php
/**
 * PAYUNi Payment 版 PayuniSettingsDTO 測試
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO
 *
 * 設計依據：
 *   - 欄位規範：payuni-upp-v2 SKILL.md §端點 / §加解密
 *   - 端點切換：test = "https://sandbox-api.payuni.com.tw/api/upp"
 *               prod = "https://api.payuni.com.tw/api/upp"
 *   - Trim 行為：對齊既有 RedirectSettingsDTOTrimTest 風格（前後空白 / 全形空白 / 零寬字元）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayuniSettingsDTO 測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniSettingsDTOTest extends TestCase {

	// PAYUNi UPP V2 端點（payuni-upp-v2 SKILL.md §端點）
	private const SANDBOX_HOST = 'https://sandbox-api.payuni.com.tw';
	private const PROD_HOST    = 'https://api.payuni.com.tw';
	private const UPP_PATH     = '/api/upp';

	/** PAYUNi UPP Provider ID（與既有金流命名慣例 ecpay_aio / newebpay_mpg 對齊；payuni-uni-embed 內嵌變體未來不撞名） */
	private const PROVIDER_ID = 'payuni_upp';

	/**
	 * 每次測試後清理 PAYUNi 設定
	 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );
		// 重置 DTO 單例（若有靜態 instance）
		if ( method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 寫入 wp_options 模擬現有設定
	 *
	 * @param array<string, mixed> $value
	 */
	private function seed_option( array $value ): void {
		\update_option( ProviderUtils::get_option_name( self::PROVIDER_ID ), $value );
		if ( method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PayuniSettingsDTO可被實例化(): void {
		$dto = PayuniSettingsDTO::instance();
		$this->assertInstanceOf( PayuniSettingsDTO::class, $dto );
	}

	// ========== 欄位映射（Happy） ==========

	/**
	 * 確認核心欄位（merchant_id / hash_key / hash_iv / mode）正確映射
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_欄位映射_核心憑證欄位讀取正確(): void {
		$this->seed_option(
			[
				'merchant_id' => 'S9999999999',
				'hash_key'    => 'testkeyabc12345678901234567890ab',
				'hash_iv'     => 'testiv12345678',
				'mode'        => 'test',
			]
		);

		$dto = PayuniSettingsDTO::instance();

		$this->assertSame( 'S9999999999', $dto->merchant_id );
		$this->assertSame( 'testkeyabc12345678901234567890ab', $dto->hash_key );
		$this->assertSame( 'testiv12345678', $dto->hash_iv );
		$this->assertSame( 'test', $dto->mode );
	}

	/**
	 * 確認付款方式開關欄位（allowed_payments）正確映射
	 * 依 payuni-upp-v2 §付款方式開關 欄位（Credit / ATM / CVS / LinePay / JKoPay / ApplePay / GooglePay）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_欄位映射_allowed_payments陣列讀取正確(): void {
		$this->seed_option(
			[
				'allowed_payments' => [ 'Credit', 'ATM', 'CVS', 'LinePay' ],
				'mode'             => 'test',
			]
		);

		$dto = PayuniSettingsDTO::instance();

		$this->assertIsArray( $dto->allowed_payments );
		$this->assertContains( 'Credit', $dto->allowed_payments );
		$this->assertContains( 'ATM', $dto->allowed_payments );
		$this->assertContains( 'CVS', $dto->allowed_payments );
		$this->assertContains( 'LinePay', $dto->allowed_payments );
	}

	/**
	 * 確認分期期數（installment_periods）欄位正確映射
	 * 依 payuni-upp-v2 §付款方式開關 CreditInst 支援 3/6/9/12/18/24/30
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_欄位映射_installment_periods讀取正確(): void {
		$this->seed_option(
			[
				'installment_periods' => '3,6,12',
				'mode'                => 'test',
			]
		);

		$dto = PayuniSettingsDTO::instance();
		$this->assertSame( '3,6,12', $dto->installment_periods );
	}

	/**
	 * 確認金額限制欄位（min_amount / max_amount）正確映射
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_欄位映射_金額限制欄位讀取正確(): void {
		$this->seed_option(
			[
				'min_amount' => '100',
				'max_amount' => '50000',
				'mode'       => 'test',
			]
		);

		$dto = PayuniSettingsDTO::instance();
		$this->assertSame( '100', (string) $dto->min_amount );
		$this->assertSame( '50000', (string) $dto->max_amount );
	}

	/**
	 * 確認 expire_min（付款截止分鐘）欄位正確映射
	 * 對應 payuni-upp-v2 §TradeLExpireSec（60–600 秒，但 DTO 層轉為分鐘或直接存秒，Green 階段確定）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_欄位映射_expire_min欄位讀取正確(): void {
		$this->seed_option(
			[
				'expire_min' => '10',
				'mode'       => 'test',
			]
		);

		$dto = PayuniSettingsDTO::instance();
		$this->assertSame( '10', (string) $dto->expire_min );
	}

	// ========== mode → endpoint 切換（Happy） ==========

	/**
	 * mode=test → api_url host 應為 sandbox-api.payuni.com.tw
	 * 依 payuni-upp-v2 SKILL.md §端點
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_端點切換_test模式使用sandbox主機(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PayuniSettingsDTO::instance();

		$this->assertStringStartsWith( self::SANDBOX_HOST, $dto->api_url );
	}

	/**
	 * mode=prod → api_url host 應為 api.payuni.com.tw
	 * 依 payuni-upp-v2 SKILL.md §端點
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_端點切換_prod模式使用正式主機(): void {
		$this->seed_option(
			[
				'mode'        => 'prod',
				'merchant_id' => 'S0000000001',
				'hash_key'    => '12345678901234567890123456789012',
				'hash_iv'     => '1234567890123456',
			]
		);

		$dto = PayuniSettingsDTO::instance();

		$this->assertStringStartsWith( self::PROD_HOST, $dto->api_url );
		// api_url 不得包含 sandbox
		$this->assertStringNotContainsString( 'sandbox', $dto->api_url );
	}

	/**
	 * test 模式 api_url 應包含 /api/upp 路徑
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_端點切換_api_url包含upp路徑(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PayuniSettingsDTO::instance();

		$this->assertStringContainsString( self::UPP_PATH, $dto->api_url );
	}

	// ========== Trim（Happy） ==========

	/**
	 * merchant_id 前後空白被 trim
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_trim_merchant_id前後空白被移除(): void {
		$this->seed_option(
			[
				'merchant_id' => '  S9999999999  ',
				'mode'        => 'test',
			]
		);

		$dto = PayuniSettingsDTO::instance();
		$this->assertSame( 'S9999999999', $dto->merchant_id );
	}

	/**
	 * hash_key / hash_iv 前後空白被 trim（PAYUNi 後台複製偶有 trailing 空白）
	 * 依 payuni-upp-v2 encryption.md §金鑰：不可含空白
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_trim_hash_key與hash_iv前後空白被移除(): void {
		$this->seed_option(
			[
				'mode'     => 'test',
				'hash_key' => '  12345678901234567890123456789012  ',
				'hash_iv'  => "\t1234567890123456\n",
			]
		);

		$dto = PayuniSettingsDTO::instance();
		$this->assertSame( '12345678901234567890123456789012', $dto->hash_key );
		$this->assertSame( '1234567890123456', $dto->hash_iv );
	}

	/**
	 * 全形空白與零寬字元在讀取時也被 trim
	 * 對齊 RedirectSettingsDTOTrimTest 測試風格
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_trim_全形空白與零寬字元被移除(): void {
		$this->seed_option(
			[
				'mode'        => 'test',
				'merchant_id' => "\u{3000}\u{200B}S9999999999\u{FEFF}",
				'hash_key'    => "\u{3000}12345678901234567890123456789012\u{200B}",
			]
		);

		$dto = PayuniSettingsDTO::instance();
		$this->assertSame( 'S9999999999', $dto->merchant_id );
		$this->assertSame( '12345678901234567890123456789012', $dto->hash_key );
	}

	// ========== Edge Cases ==========

	/**
	 * Trim 不應寫回 wp_options（無副作用）
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_trim_讀取時不寫回wp_options(): void {
		$dirty_key = '  12345678901234567890123456789012  ';
		$this->seed_option(
			[
				'mode'     => 'test',
				'hash_key' => $dirty_key,
			]
		);

		PayuniSettingsDTO::instance();

		// 原始 option 應未被覆寫
		$raw = \get_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );
		$this->assertIsArray( $raw );
		$this->assertSame( $dirty_key, $raw['hash_key'] );
	}

	/**
	 * 欄位中間空白保留（僅 trim 前後）
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_trim_保留欄位中間空白(): void {
		$this->seed_option(
			[
				'mode'        => 'test',
				'merchant_id' => 'S999 999 9999',
			]
		);

		$dto = PayuniSettingsDTO::instance();
		$this->assertSame( 'S999 999 9999', $dto->merchant_id );
	}

	/**
	 * test 模式若未填憑證應套用官方公開測試向量金鑰（對齊 Logistics DTO 設計）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_test模式未填憑證套用官方公開測試向量(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PayuniSettingsDTO::instance();

		// 官方測試向量金鑰（payuni-upp-v2 encryption.md §官方測試向量）
		$this->assertSame( '12345678901234567890123456789012', $dto->hash_key );
		$this->assertSame( '1234567890123456', $dto->hash_iv );
	}
}
