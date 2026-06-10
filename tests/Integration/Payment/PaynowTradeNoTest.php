<?php
/**
 * PayNow TradeNo（冪等交易單號）測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowTradeNo
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 6
 *   - specs/open-issue/paynow-execution-plan.md §生命週期（前綴 PCN{order_id}）
 *   - CLAUDE.md §Order Meta Keys：_pc_paynow_trade_no（格式 PCN{order_id}）
 *
 * generate(order_id) == "PCN{order_id}"
 * parse() 回 order_id
 * 前綴 PCN 不同於 UNi Embed 的 PCE 與 UPP 的 PCU
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowTradeNoTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowTradeNo;
use Tests\Integration\TestCase;

/**
 * PaynowTradeNo 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowTradeNoTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * generate() 方法存在且回傳非空字串
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_generate方法存在且可呼叫(): void {
		$order    = $this->create_wc_order();
		$trade_no = PaynowTradeNo::generate( $order->get_id() );

		$this->assertIsString( $trade_no );
		$this->assertNotEmpty( $trade_no );
	}

	/**
	 * generate(100) 回傳 "PCN100"
	 * 依 paynow-implementation-plan §步驟 6：格式 PCN{order_id}
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_generate_格式為PCN加order_id(): void {
		$order    = $this->create_wc_order();
		$order_id = $order->get_id();
		$trade_no = PaynowTradeNo::generate( $order_id );

		$this->assertSame( "PCN{$order_id}", $trade_no );
	}

	// ========== 前綴驗證（Happy） ==========

	/**
	 * 交易單號前綴必須是 PCN（PayNow Checkout No）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_交易單號前綴為PCN(): void {
		$order    = $this->create_wc_order();
		$trade_no = PaynowTradeNo::generate( $order->get_id() );

		$this->assertStringStartsWith(
			'PCN',
			$trade_no,
			"PayNow 交易單號前綴應為 PCN，實際：{$trade_no}"
		);
	}

	/**
	 * PayNow 前綴 PCN 不同於 UNi Embed 的 PCE
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_前綴PCN不同於UNiEmbed的PCE(): void {
		$order    = $this->create_wc_order();
		$trade_no = PaynowTradeNo::generate( $order->get_id() );

		$this->assertStringStartsNotWith( 'PCE', $trade_no, 'PayNow 交易單號不應使用 UNi Embed 的 PCE 前綴' );
	}

	/**
	 * PayNow 前綴 PCN 不同於 UPP 的 PCU
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_前綴PCN不同於UPP的PCU(): void {
		$order    = $this->create_wc_order();
		$trade_no = PaynowTradeNo::generate( $order->get_id() );

		$this->assertStringStartsNotWith( 'PCU', $trade_no, 'PayNow 交易單號不應使用 UPP 的 PCU 前綴' );
	}

	// ========== parse 反查（Happy） ==========

	/**
	 * parse() 可從交易單號回推 order_id
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_parse_從交易單號回推order_id(): void {
		$order    = $this->create_wc_order();
		$order_id = $order->get_id();
		$trade_no = PaynowTradeNo::generate( $order_id );

		$parsed = PaynowTradeNo::parse( $trade_no );

		$this->assertSame( $order_id, $parsed );
	}

	// ========== 邊緣案例（Edge） ==========

	/**
	 * 不同 order_id 產生不同交易單號
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_不同訂單產生不同交易單號(): void {
		$order_a = $this->create_wc_order();
		$order_b = $this->create_wc_order();

		$this->assertNotSame(
			PaynowTradeNo::generate( $order_a->get_id() ),
			PaynowTradeNo::generate( $order_b->get_id() )
		);
	}

	/**
	 * parse() 對不合法輸入不拋例外，回傳 null 或 0
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_parse_對不合法輸入不拋例外(): void {
		$result = PaynowTradeNo::parse( 'INVALID_TRADE_NO' );

		$this->assertTrue(
			null === $result || 0 === $result,
			'對不合法 trade_no 應回傳 null 或 0，實際：' . var_export( $result, true )
		);
	}
}
