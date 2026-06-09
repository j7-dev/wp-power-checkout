<?php
/**
 * PAYUNi UNi Embed V3 PayuniUniEmbedSettingsDTO 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO
 *
 * 設計依據：
 *   - 選項鍵：woocommerce_payuni_uni_embed_settings（與 UPP 的 woocommerce_payuni_upp_settings 區隔）
 *   - 端點切換：test = "https://sandbox-api.payuni.com.tw/api/iframe/token_get"
 *               prod = "https://api.payuni.com.tw/api/iframe/token_get"
 *   - IFrameDomain 欄位為 UNi Embed V3 特有（UPP 無此欄位）
 *   - Trim 行為對齊既有 PayuniSettingsDTOTest 風格
 *   - 加密與 UPP 完全共用（AES-256-GCM + SHA256 HashInfo）；憑證（HashKey/HashIV）格式相同
 *
 * 規格來源：
 *   - specs/features/payment/payuni-uni-embed-checkout.feature
 *   - specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 05-08
 *   - payuni-uni-embed-v3 SKILL.md §API 1：取得 SDK_TOKEN §EncryptInfo 內層
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni_uni_embed"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayuniUniEmbedSettingsDTO 測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedSettingsDTOTest extends TestCase {

	private const SANDBOX_HOST   = 'https://sandbox-api.payuni.com.tw';
	private const PROD_HOST      = 'https://api.payuni.com.tw';
	private const TOKEN_GET_PATH = '/api/iframe/token_get';
	private const PROVIDER_ID    = 'payuni_uni_embed';
	private const OPTION_NAME    = 'woocommerce_payuni_uni_embed_settings';

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\delete_option( self::OPTION_NAME );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 寫入 wp_options 模擬現有設定
	 *
	 * @param array<string, mixed> $value
	 */
	private function seed_option( array $value ): void {
		\update_option( self::OPTION_NAME, $value );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PayuniUniEmbedSettingsDTO 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedSettingsDTO可被實例化(): void {
		$dto = PayuniUniEmbedSettingsDTO::instance();
		$this->assertInstanceOf( PayuniUniEmbedSettingsDTO::class, $dto );
	}

	/**
	 * PayuniUniEmbedSettingsDTO::ID 常數等於 'payuni_uni_embed'
	 * 確認 Provider ID 不與 UPP 的 'payuni_upp' 相撞
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_ID常數等於payuni_uni_embed(): void {
		$this->assertSame( 'payuni_uni_embed', PayuniUniEmbedSettingsDTO::ID );
	}

	/**
	 * ProviderUtils::get_option_name('payuni_uni_embed') 回傳正確選項名稱
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_option_name正確(): void {
		$this->assertSame(
			self::OPTION_NAME,
			ProviderUtils::get_option_name( self::PROVIDER_ID )
		);
	}

	// ========== 欄位映射（Happy） ==========

	/**
	 * 核心憑證欄位（merchant_id / hash_key / hash_iv / mode）正確映射
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_欄位映射_核心憑證欄位讀取正確(): void {
		$this->seed_option(
			[
				'merchant_id' => 'UNI_TEST_MERID',
				'hash_key'    => 'testabc12345678901234567890ab123',
				'hash_iv'     => 'testiv1234567890',
				'mode'        => 'test',
			]
		);

		$dto = PayuniUniEmbedSettingsDTO::instance();

		$this->assertSame( 'UNI_TEST_MERID', $dto->merchant_id );
		$this->assertSame( 'testabc12345678901234567890ab123', $dto->hash_key );
		$this->assertSame( 'testiv1234567890', $dto->hash_iv );
		$this->assertSame( 'test', $dto->mode );
	}

	/**
	 * IFrameDomain 欄位正確映射（V3 UNi Embed 特有欄位，UPP 無此欄位）
	 * 依 payuni-uni-embed-v3 SKILL.md §EncryptInfo 內層：IFrameDomain 必填且含 https://
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_欄位映射_IFrameDomain欄位讀取正確(): void {
		$this->seed_option(
			[
				'iframe_domain' => 'https://www.example.com',
				'mode'          => 'test',
			]
		);

		$dto = PayuniUniEmbedSettingsDTO::instance();

		$this->assertSame( 'https://www.example.com', $dto->iframe_domain );
	}

	/**
	 * mode=test 時 token_get_url 應包含 sandbox 主機
	 * 依 payuni-uni-embed-v3 §端點：sandbox-api.payuni.com.tw
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_端點切換_test模式使用sandbox主機(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PayuniUniEmbedSettingsDTO::instance();

		$this->assertStringStartsWith( self::SANDBOX_HOST, $dto->token_get_url );
	}

	/**
	 * mode=prod 時 token_get_url 應使用正式主機
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_端點切換_prod模式使用正式主機(): void {
		$this->seed_option(
			[
				'mode'        => 'prod',
				'merchant_id' => 'PROD_MERID_001',
				'hash_key'    => '12345678901234567890123456789012',
				'hash_iv'     => '1234567890123456',
			]
		);

		$dto = PayuniUniEmbedSettingsDTO::instance();

		$this->assertStringStartsWith( self::PROD_HOST, $dto->token_get_url );
		$this->assertStringNotContainsString( 'sandbox', $dto->token_get_url );
	}

	/**
	 * token_get_url 應包含 /api/iframe/token_get 路徑（V3 特有路徑）
	 * 斷言不同於 UPP 的 /api/upp
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_端點切換_token_get_url包含正確路徑(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PayuniUniEmbedSettingsDTO::instance();

		$this->assertStringContainsString( self::TOKEN_GET_PATH, $dto->token_get_url );
		// V3 路徑不含 UPP 路徑
		$this->assertStringNotContainsString( '/api/upp', $dto->token_get_url );
	}

	/**
	 * test 模式未填憑證時套用官方公開測試向量金鑰
	 * 對齊 PayuniSettingsDTOTest 的設計（sandbox 先行）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_test模式未填憑證套用官方測試向量(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PayuniUniEmbedSettingsDTO::instance();

		$this->assertSame( '12345678901234567890123456789012', $dto->hash_key );
		$this->assertSame( '1234567890123456', $dto->hash_iv );
	}

	// ========== Trim（Happy） ==========

	/**
	 * merchant_id 前後空白被 trim
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_trim_merchant_id前後空白被移除(): void {
		$this->seed_option(
			[
				'merchant_id' => '  UNI_MERID_001  ',
				'mode'        => 'test',
			]
		);

		$dto = PayuniUniEmbedSettingsDTO::instance();
		$this->assertSame( 'UNI_MERID_001', $dto->merchant_id );
	}

	/**
	 * hash_key / hash_iv 前後空白被 trim
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
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

		$dto = PayuniUniEmbedSettingsDTO::instance();
		$this->assertSame( '12345678901234567890123456789012', $dto->hash_key );
		$this->assertSame( '1234567890123456', $dto->hash_iv );
	}

	/**
	 * iframe_domain 前後空白被 trim（避免 IFrameDomain 驗證失敗）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_trim_iframe_domain前後空白被移除(): void {
		$this->seed_option(
			[
				'mode'          => 'test',
				'iframe_domain' => '  https://www.example.com  ',
			]
		);

		$dto = PayuniUniEmbedSettingsDTO::instance();
		$this->assertSame( 'https://www.example.com', $dto->iframe_domain );
	}

	/**
	 * 全形空白與零寬字元被 trim
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_trim_全形空白與零寬字元被移除(): void {
		$this->seed_option(
			[
				'mode'        => 'test',
				'merchant_id' => "\u{3000}\u{200B}UNI_MERID_001\u{FEFF}",
				'hash_key'    => "\u{3000}12345678901234567890123456789012\u{200B}",
			]
		);

		$dto = PayuniUniEmbedSettingsDTO::instance();
		$this->assertSame( 'UNI_MERID_001', $dto->merchant_id );
		$this->assertSame( '12345678901234567890123456789012', $dto->hash_key );
	}

	// ========== Edge Cases ==========

	/**
	 * Trim 不應寫回 wp_options（無副作用）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
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

		PayuniUniEmbedSettingsDTO::instance();

		$raw = \get_option( self::OPTION_NAME );
		$this->assertIsArray( $raw );
		$this->assertSame( $dirty_key, $raw['hash_key'] );
	}

	/**
	 * UNi Embed 選項名稱與 UPP 不同（不可相撞）
	 * 確保兩個 gateway 的設定互相隔離
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_選項名稱與UPP不相撞(): void {
		$uni_embed_option = ProviderUtils::get_option_name( 'payuni_uni_embed' );
		$upp_option       = ProviderUtils::get_option_name( 'payuni_upp' );

		$this->assertNotSame(
			$uni_embed_option,
			$upp_option,
			'payuni_uni_embed 與 payuni_upp 的 option name 不應相同'
		);
		$this->assertSame( 'woocommerce_payuni_uni_embed_settings', $uni_embed_option );
		$this->assertSame( 'woocommerce_payuni_upp_settings', $upp_option );
	}

	/**
	 * IFrameDomain 欄位缺失時回傳空字串（非 null，確保型別安全）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_IFrameDomain_未設定時回傳空字串(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PayuniUniEmbedSettingsDTO::instance();

		$this->assertSame( '', $dto->iframe_domain );
	}
}
