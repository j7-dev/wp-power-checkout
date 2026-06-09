<?php
/**
 * PAYUNi Payment 版 PayuniTradeNo（冪等交易單號）測試
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo
 *
 * 規格依據：
 *   - payuni-upp-v2 SKILL.md §EncryptInfo 內層通用請求參數 MerTradeNo 欄位：
 *     長度 ≤25，字元集 [A-Za-z0-9_-]，10 分鐘內不可重複
 *   - 設計對齊既有 EcpayAIO TradeNo helper（含 order_id 可反查 + 冪等）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use Tests\Integration\TestCase;

/**
 * PayuniTradeNo 測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniTradeNoTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_generate方法存在且可呼叫(): void {
		$order = $this->create_wc_order();
		// 應可產生交易單號（不拋例外）
		$trade_no = PayuniTradeNo::generate( $order->get_id() );
		$this->assertIsString( $trade_no );
		$this->assertNotEmpty( $trade_no );
	}

	// ========== 格式驗證（Happy） ==========

	/**
	 * 產生的交易單號長度不得超過 25 字元
	 * 依 payuni-upp-v2 §MerTradeNo 欄位規範
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_產生的交易單號長度不超過25字元(): void {
		$order    = $this->create_wc_order();
		$trade_no = PayuniTradeNo::generate( $order->get_id() );

		$this->assertLessThanOrEqual(
			25,
			strlen( $trade_no ),
			"交易單號長度 {strlen($trade_no)} 超過 PAYUNi 限制 25 字元"
		);
	}

	/**
	 * 交易單號僅包含 [A-Za-z0-9_-] 字元
	 * 依 payuni-upp-v2 §MerTradeNo 欄位規範
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_產生的交易單號僅含合法字元(): void {
		$order    = $this->create_wc_order();
		$trade_no = PayuniTradeNo::generate( $order->get_id() );

		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9_-]+$/',
			$trade_no,
			"交易單號含有 PAYUNi 不允許的字元：{$trade_no}"
		);
	}

	/**
	 * 交易單號包含 order_id 可反查訂單
	 * 設計：單號格式中嵌入 order_id（對齊 ECPay TradeNo 模式）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_交易單號含有order_id可反查(): void {
		$order    = $this->create_wc_order();
		$order_id = $order->get_id();
		$trade_no = PayuniTradeNo::generate( $order_id );

		// 從 trade_no 可反查出 order_id
		$parsed_order_id = PayuniTradeNo::parse_order_id( $trade_no );

		$this->assertSame(
			$order_id,
			$parsed_order_id,
			'從 trade_no 反查的 order_id 不正確'
		);
	}

	// ========== 冪等性（Happy） ==========

	/**
	 * 同一 order_id 呼叫兩次產生相同的交易單號（冪等）
	 * 設計意圖：防止重複建立交易（10 分鐘內不可重複）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_同一訂單重複呼叫產生穩定冪等值(): void {
		$order    = $this->create_wc_order();
		$order_id = $order->get_id();

		$first  = PayuniTradeNo::generate( $order_id );
		$second = PayuniTradeNo::generate( $order_id );

		$this->assertSame(
			$first,
			$second,
			'同一訂單兩次呼叫 generate() 結果不一致（冪等性驗證失敗）'
		);
	}

	// ========== 邊緣案例（Edge） ==========

	/**
	 * 不同 order_id 產生不同的交易單號
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_不同訂單產生不同交易單號(): void {
		$order_a = $this->create_wc_order();
		$order_b = $this->create_wc_order();

		$trade_no_a = PayuniTradeNo::generate( $order_a->get_id() );
		$trade_no_b = PayuniTradeNo::generate( $order_b->get_id() );

		$this->assertNotSame(
			$trade_no_a,
			$trade_no_b,
			'不同訂單不應產生相同交易單號'
		);
	}

	/**
	 * 非常大的 order_id（假設 DB auto increment 達到 9 位數）仍符合 ≤25 字元限制
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_極大的order_id仍符合25字元限制(): void {
		// 模擬一個極大 order_id（不需實際存在於 DB，只測 helper 的字串邏輯）
		$large_order_id = 999999999;
		$trade_no       = PayuniTradeNo::generate( $large_order_id );

		$this->assertLessThanOrEqual( 25, strlen( $trade_no ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $trade_no );
	}

	/**
	 * parse_order_id 對不合法的 trade_no 回傳 null 或 0，不拋例外
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_parse_order_id_對不合法輸入不拋例外(): void {
		// 不合法的 trade_no
		$invalid = 'INVALID_TRADE_NO_STRING';

		// 不應拋例外；回傳 null 或 0
		$result = PayuniTradeNo::parse_order_id( $invalid );

		$this->assertTrue(
			null === $result || 0 === $result,
			'對不合法 trade_no 應回傳 null 或 0，實際回傳：' . var_export( $result, true )
		);
	}
}
