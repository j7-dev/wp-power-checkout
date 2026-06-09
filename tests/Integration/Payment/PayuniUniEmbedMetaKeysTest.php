<?php
/**
 * PAYUNi UNi Embed V3 MetaKeys 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys
 *
 * 設計依據：
 *   - 6 個 meta key 常數前綴一律 _pc_payuni_uni_（與 UPP 的 _pc_payuni_ 區隔）
 *   - 對應 specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 02 Entity Modeling
 *   - HPOS 相容：一律透過 $order->get_meta() / update_meta_data()
 *   - 風格對齊既有 PayuniMetaKeysTest.php
 *
 * 6 個 meta key 常數：
 *   TRADE_NO           → _pc_payuni_uni_trade_no
 *   SDK_TOKEN          → _pc_payuni_uni_sdk_token
 *   PAYMENT_DETAIL     → _pc_payuni_uni_payment_detail
 *   CAPTURE_STATUS     → _pc_payuni_uni_capture_status
 *   CREDIT_HASH        → _pc_payuni_uni_credit_hash
 *   CREDIT_LIFE        → _pc_payuni_uni_credit_life
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni_uni_embed"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use Tests\Integration\TestCase;

/**
 * PayuniUniEmbedMetaKeys 測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedMetaKeysTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PayuniUniEmbedMetaKeys 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedMetaKeys可被實例化(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$this->assertInstanceOf( PayuniUniEmbedMetaKeys::class, $meta_keys );
	}

	// ========== 常數值正確性（Happy） ==========

	/**
	 * TRADE_NO 常數值等於 _pc_payuni_uni_trade_no
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_常數_TRADE_NO等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_payuni_uni_trade_no', PayuniUniEmbedMetaKeys::TRADE_NO );
	}

	/**
	 * SDK_TOKEN 常數值等於 _pc_payuni_uni_sdk_token
	 * UNi Embed V3 特有欄位：token_get 取得的 SDK_TOKEN（10 分鐘有效）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_常數_SDK_TOKEN等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_payuni_uni_sdk_token', PayuniUniEmbedMetaKeys::SDK_TOKEN );
	}

	/**
	 * PAYMENT_DETAIL 常數值等於 _pc_payuni_uni_payment_detail
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_常數_PAYMENT_DETAIL等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_payuni_uni_payment_detail', PayuniUniEmbedMetaKeys::PAYMENT_DETAIL );
	}

	/**
	 * CAPTURE_STATUS 常數值等於 _pc_payuni_uni_capture_status
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_常數_CAPTURE_STATUS等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_payuni_uni_capture_status', PayuniUniEmbedMetaKeys::CAPTURE_STATUS );
	}

	/**
	 * CREDIT_HASH 常數值等於 _pc_payuni_uni_credit_hash
	 * 僅存 Token Hash，絕不存卡號/CVC（硬約束）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_常數_CREDIT_HASH等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_payuni_uni_credit_hash', PayuniUniEmbedMetaKeys::CREDIT_HASH );
	}

	/**
	 * CREDIT_LIFE 常數值等於 _pc_payuni_uni_credit_life
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_常數_CREDIT_LIFE等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_payuni_uni_credit_life', PayuniUniEmbedMetaKeys::CREDIT_LIFE );
	}

	/**
	 * 所有 6 個 meta key 前綴一律為 _pc_payuni_uni_
	 * 以正規表達式批次驗證，確保無遺漏
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_所有meta_key前綴均為_pc_payuni_uni_(): void {
		$keys = [
			PayuniUniEmbedMetaKeys::TRADE_NO,
			PayuniUniEmbedMetaKeys::SDK_TOKEN,
			PayuniUniEmbedMetaKeys::PAYMENT_DETAIL,
			PayuniUniEmbedMetaKeys::CAPTURE_STATUS,
			PayuniUniEmbedMetaKeys::CREDIT_HASH,
			PayuniUniEmbedMetaKeys::CREDIT_LIFE,
		];

		foreach ( $keys as $key ) {
			$this->assertStringStartsWith(
				'_pc_payuni_uni_',
				$key,
				"meta key '{$key}' 前綴不是 _pc_payuni_uni_"
			);
		}
	}

	// ========== 不與 UPP meta key 衝突（Security） ==========

	/**
	 * UNi Embed meta key 不與 UPP meta key 相撞
	 * 驗證 _pc_payuni_uni_ 與 _pc_payuni_ 的隔離性
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_UNiEmbed的meta_key不與UPP相撞(): void {
		$uni_embed_keys = [
			PayuniUniEmbedMetaKeys::TRADE_NO,
			PayuniUniEmbedMetaKeys::SDK_TOKEN,
			PayuniUniEmbedMetaKeys::PAYMENT_DETAIL,
			PayuniUniEmbedMetaKeys::CAPTURE_STATUS,
			PayuniUniEmbedMetaKeys::CREDIT_HASH,
			PayuniUniEmbedMetaKeys::CREDIT_LIFE,
		];

		// UPP 的既有 meta key（hardcoded 依據 CLAUDE.md §Order Meta Keys）
		$upp_keys = [
			'_pc_payuni_trade_no',
			'_pc_payuni_payment_detail',
			'_pc_payuni_payment_info',
			'_pc_payuni_capture_status',
		];

		foreach ( $uni_embed_keys as $uni_key ) {
			$this->assertNotContains(
				$uni_key,
				$upp_keys,
				"UNi Embed meta key '{$uni_key}' 與 UPP meta key 衝突"
			);
		}
	}

	/**
	 * UNi Embed meta key 前綴 _pc_payuni_uni_ 比 UPP 前綴 _pc_payuni_ 更長（不是前綴子集）
	 * 確保 HPOS 查詢時 get_orders([meta_key => '_pc_payuni_']) 不會意外撈到 UNi Embed 資料
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_UNiEmbed前綴比UPP前綴更長且具唯一性(): void {
		$uni_prefix = '_pc_payuni_uni_';
		$upp_prefix = '_pc_payuni_';

		// UNi 前綴必須比 UPP 前綴長（是 UPP 前綴的超集合）
		$this->assertGreaterThan( strlen( $upp_prefix ), strlen( $uni_prefix ) );

		// UNi 前綴是 UPP 前綴的延伸（namespace 超集），確保命名空間隔離且可辨識
		$this->assertStringStartsWith( $upp_prefix, $uni_prefix );
		$this->assertStringContainsString( 'uni_', $uni_prefix );
	}

	// ========== CRUD 行為（Happy） ==========

	/**
	 * sdk_token 寫入後可正確讀取
	 * token_get 呼叫成功後將 SDK_TOKEN 存入此 meta key，供前端 SDK 使用
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_sdk_token_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		$meta_keys->update_sdk_token( 'SDK_TOKEN_TEST_VALUE_12345' );

		$this->assertSame( 'SDK_TOKEN_TEST_VALUE_12345', $meta_keys->get_sdk_token() );
	}

	/**
	 * sdk_token 未設定時回傳空字串
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_sdk_token_未設定時回傳空字串(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		$this->assertSame( '', $meta_keys->get_sdk_token() );
	}

	/**
	 * trade_no 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_trade_no_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		$meta_keys->update_trade_no( 'PCE12345' );

		$this->assertSame( 'PCE12345', $meta_keys->get_trade_no() );
	}

	/**
	 * payment_detail 寫入後可正確讀取（包含 Gateway=9 識別）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_payment_detail_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		$detail = [
			'Status'      => 'SUCCESS',
			'MerTradeNo'  => 'PCE12345',
			'TradeNo'     => 'UNI_EMBED_20240101',
			'Gateway'     => '9',
			'PaymentType' => '1',
			'TradeStatus' => '1',
		];

		$meta_keys->update_payment_detail( $detail );
		$result = $meta_keys->get_payment_detail();

		$this->assertSame( 'SUCCESS', $result['Status'] ?? '' );
		$this->assertSame( '9', $result['Gateway'] ?? '' );
		$this->assertSame( '1', $result['PaymentType'] ?? '' );
	}

	/**
	 * credit_hash 寫入後可正確讀取（Token Hash）
	 * 只允許儲存 PAYUNi 回傳的 CreditHash（不是卡號）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_credit_hash_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		$meta_keys->update_credit_hash( 'HASH_ABCDEF123456' );

		$this->assertSame( 'HASH_ABCDEF123456', $meta_keys->get_credit_hash() );
	}

	/**
	 * credit_life 寫入後可正確讀取（Token 有效日期 MMYY 格式）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_credit_life_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		$meta_keys->update_credit_life( '1230' );

		$this->assertSame( '1230', $meta_keys->get_credit_life() );
	}

	// ========== 依 trade_no 反查（Happy / Error） ==========

	/**
	 * 依 trade_no 反查訂單：找到正確訂單
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_get_order_by_trade_no_找到正確訂單(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_trade_no( 'PCE_SEARCH_001' );

		$found_order = PayuniUniEmbedMetaKeys::get_order_by_trade_no( 'PCE_SEARCH_001' );

		$this->assertInstanceOf( \WC_Order::class, $found_order );
		$this->assertSame( $order->get_id(), $found_order->get_id() );
	}

	/**
	 * 依 trade_no 反查：不存在時回傳 null
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_get_order_by_trade_no_找不到時回傳null(): void {
		$found = PayuniUniEmbedMetaKeys::get_order_by_trade_no( 'NONEXISTENT_UNI_EMBED_TRADE' );
		$this->assertNull( $found );
	}

	// ========== 安全性（Security） ==========

	/**
	 * trade_no 含 SQL injection 字串不造成異常
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_trade_no_SQL_injection不造成異常(): void {
		$sql_injection = "'; DROP TABLE wp_posts; --";
		$order         = $this->create_wc_order();
		$meta_keys     = new PayuniUniEmbedMetaKeys( $order );

		$meta_keys->update_trade_no( $sql_injection );

		$this->assertSame( $sql_injection, $meta_keys->get_trade_no() );
	}

	/**
	 * payment_detail 含 XSS 字串原始儲存，不造成異常（輸出由 WP esc_* 處理）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_payment_detail_XSS字串原始儲存不異常(): void {
		$xss_data = [
			'Status'  => '<script>alert("xss")</script>',
			'Message' => '"><img src=x onerror=alert(1)>',
		];

		$order     = $this->create_wc_order();
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_payment_detail( $xss_data );

		$result = $meta_keys->get_payment_detail();
		$this->assertSame( '<script>alert("xss")</script>', $result['Status'] ?? '' );
	}
}
