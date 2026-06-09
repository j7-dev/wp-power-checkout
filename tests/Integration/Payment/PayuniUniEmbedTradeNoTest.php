<?php
/**
 * PAYUNi UNi Embed V3 TradeNo（冪等交易單號）測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo
 *
 * 規格依據：
 *   - specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 05-08（MetaKeys TradeNo）
 *   - payuni-uni-embed-v3 SKILL.md §EncryptInfo 內層（merchant_trade）：
 *     MerTradeNo ≤25 字元，格式 [A-Za-z0-9_-]，10 分鐘內不可重複
 *   - V3 特性：MerTradeNo 在 merchant_trade 階段才送（token_get 階段不送）
 *   - 前綴 PCE（PAYUNi Checkout Embed）與 UPP 的 PCU 不同
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni_uni_embed"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo;
use Tests\Integration\TestCase;

/**
 * PayuniUniEmbedTradeNo 測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedTradeNoTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * generate 方法存在且可呼叫，回傳非空字串
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_generate方法存在且可呼叫(): void {
		$order    = $this->create_wc_order();
		$trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );

		$this->assertIsString( $trade_no );
		$this->assertNotEmpty( $trade_no );
	}

	// ========== 前綴驗證（Happy） ==========

	/**
	 * 交易單號前綴必須是 PCE（PAYUNi Checkout Embed）
	 * 斷言不是 UPP 的 PCU 前綴
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_交易單號前綴為PCE(): void {
		$order    = $this->create_wc_order();
		$trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );

		$this->assertStringStartsWith(
			'PCE',
			$trade_no,
			"UNi Embed 交易單號前綴應為 PCE，實際：{$trade_no}"
		);
	}

	/**
	 * UNi Embed 交易單號前綴 PCE 不同於 UPP 的 PCU
	 * 確保兩個 gateway 的交易單號不會混淆
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_前綴PCE不同於UPP的PCU(): void {
		$order    = $this->create_wc_order();
		$trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );

		$this->assertStringStartsNotWith(
			'PCU',
			$trade_no,
			'UNi Embed 交易單號不應使用 UPP 的 PCU 前綴'
		);
	}

	// ========== 格式驗證（Happy） ==========

	/**
	 * 交易單號長度不超過 25 字元
	 * 依 payuni-uni-embed-v3 §MerTradeNo：≤25 字元
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_交易單號長度不超過25字元(): void {
		$order    = $this->create_wc_order();
		$trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );

		$this->assertLessThanOrEqual(
			25,
			strlen( $trade_no ),
			'UNi Embed 交易單號長度超過 PAYUNi 限制 25 字元，實際：' . strlen( $trade_no )
		);
	}

	/**
	 * 交易單號僅包含 [A-Za-z0-9_-] 字元
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_交易單號僅含合法字元(): void {
		$order    = $this->create_wc_order();
		$trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );

		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9_-]+$/',
			$trade_no,
			"交易單號含有 PAYUNi 不允許的字元：{$trade_no}"
		);
	}

	/**
	 * 交易單號包含 order_id 可反查訂單
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_交易單號含有order_id可反查(): void {
		$order    = $this->create_wc_order();
		$order_id = $order->get_id();
		$trade_no = PayuniUniEmbedTradeNo::generate( $order_id );

		$parsed_order_id = PayuniUniEmbedTradeNo::parse_order_id( $trade_no );

		$this->assertSame(
			$order_id,
			$parsed_order_id,
			'從 UNi Embed trade_no 反查的 order_id 不正確'
		);
	}

	// ========== 冪等性（Happy） ==========

	/**
	 * 同一 order_id 呼叫兩次產生相同交易單號（冪等）
	 * V3 設計：merchant_trade 階段才送 MerTradeNo，10 分鐘內不可重複
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_同一訂單重複呼叫產生穩定冪等值(): void {
		$order    = $this->create_wc_order();
		$order_id = $order->get_id();

		$first  = PayuniUniEmbedTradeNo::generate( $order_id );
		$second = PayuniUniEmbedTradeNo::generate( $order_id );

		$this->assertSame( $first, $second, '同一訂單兩次呼叫 generate() 結果不一致（冪等性失敗）' );
	}

	// ========== 邊緣案例（Edge） ==========

	/**
	 * 不同 order_id 產生不同交易單號
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_不同訂單產生不同交易單號(): void {
		$order_a = $this->create_wc_order();
		$order_b = $this->create_wc_order();

		$trade_no_a = PayuniUniEmbedTradeNo::generate( $order_a->get_id() );
		$trade_no_b = PayuniUniEmbedTradeNo::generate( $order_b->get_id() );

		$this->assertNotSame( $trade_no_a, $trade_no_b, '不同訂單不應產生相同交易單號' );
	}

	/**
	 * 極大的 order_id（9 位數）仍符合 ≤25 字元限制
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_極大的order_id仍符合25字元限制(): void {
		$large_order_id = 999999999;
		$trade_no       = PayuniUniEmbedTradeNo::generate( $large_order_id );

		$this->assertLessThanOrEqual( 25, strlen( $trade_no ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $trade_no );
	}

	/**
	 * parse_order_id 對不合法輸入不拋例外，回傳 null 或 0
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_parse_order_id_對不合法輸入不拋例外(): void {
		$result = PayuniUniEmbedTradeNo::parse_order_id( 'INVALID_UNI_EMBED_TRADE' );

		$this->assertTrue(
			null === $result || 0 === $result,
			'對不合法 trade_no 應回傳 null 或 0，實際：' . var_export( $result, true )
		);
	}

	/**
	 * UNi Embed generate() 結果與 UPP 的 PCU 格式不相容（不可互換）
	 * 在同一 order_id 下，兩者產生的前綴不同
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_generate結果與UPP格式不相容(): void {
		$order    = $this->create_wc_order();
		$order_id = $order->get_id();

		$uni_trade_no = PayuniUniEmbedTradeNo::generate( $order_id );

		// 驗證前綴是 PCE 而不是 PCU
		$this->assertStringStartsWith( 'PCE', $uni_trade_no );
		$this->assertStringStartsNotWith( 'PCU', $uni_trade_no );
	}
}
