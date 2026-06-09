<?php
/**
 * 綠界全方位物流 block 結帳整合（下單寫 meta + cart extensions）整合測試
 *
 * 對應 block 下單路徑：
 *   woocommerce_store_api_checkout_order_processed → save_block_checkout_meta：
 *   把 session 暫存門市 + sub_type + payment_scenario 搬進 order meta，並清除 session。
 *   cart_extension_data：把 session 暫存門市注入 cart.extensions['ecpay_logistics']。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     env API_MODE=mock vendor/bin/phpunit --filter BlocksLogisticsIntegrationTest 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\BlocksLogisticsIntegration;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\WC_EcpayLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * block 結帳整合測試類別
 *
 * @group integration
 * @group logistics
 */
final class BlocksLogisticsIntegrationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->boot_wc_session();
		EcpayLogisticsSettingsDTO::reset();
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			[
				'mode'            => 'test',
				'account_type'    => 'b2c',
				'enabled_methods' => [ 'FAMI', 'UNIMART', 'HILIFE', 'HOME' ],
			]
		);
		EcpayLogisticsSettingsDTO::reset();
	}

	public function tear_down(): void {
		CartLogisticsSession::clear();
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * @return void
	 */
	private function boot_wc_session(): void {
		$wc = \WC();
		if (!isset( $wc->session ) || !$wc->session instanceof \WC_Session) {
			$wc->initialize_session();
		}
		$wc->session->set_customer_session_cookie( true );
	}

	/**
	 * 建立「已選用綠界物流運送方式」的訂單
	 *
	 * @param string $payment_method 付款方式（cod / 其他）
	 * @return \WC_Order
	 */
	private function create_order_with_logistics_shipping( string $payment_method = 'bacs' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => $payment_method,
			]
			);

		$item = new \WC_Order_Item_Shipping();
		$item->set_method_title( '綠界超商取貨 / 宅配' );
		$item->set_method_id( WC_EcpayLogisticsShipping::METHOD_ID );
		$order->add_item( $item );
		$order->save();

		return $order;
	}

	// ========== happy：下單寫 meta ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_block下單_session門市搬進order_meta(): void {
		// Given: session 已有選店暫存（超商取貨）
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token(
			$token,
			[
				'temp_id'    => '7701',
				'store_id'   => 'B0001',
				'store_name' => '萊爾富 block 門市',
				'store_addr' => '台中市西區 block 路1號',
				'sub_type'   => 'HILIFE',
			]
		);

		$order = $this->create_order_with_logistics_shipping( 'bacs' );

		// When: block 下單 hook
		BlocksLogisticsIntegration::save_block_checkout_meta( $order );

		// Then: 門市 + sub_type + payment_scenario 寫入 order meta
		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '7701', $fresh->get_temp_id() );
		$this->assertSame( 'B0001', $fresh->get_store_id() );
		$this->assertSame( '萊爾富 block 門市', $fresh->get_store_name() );
		$this->assertSame( 'HILIFE', $fresh->get_sub_type() );
		$this->assertSame( 'online', $fresh->get_payment_scenario() );
		$this->assertSame( EcpayLogisticsProvider::ID, $fresh->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_block下單_COD付款寫入cod情境(): void {
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token(
			$token,
			[
				'temp_id'  => '8',
				'store_id' => 'C1',
				'sub_type' => 'FAMI',
			]
		);

		$order = $this->create_order_with_logistics_shipping( 'cod' );
		BlocksLogisticsIntegration::save_block_checkout_meta( $order );

		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'cod', $fresh->get_payment_scenario() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_block下單後清除session暫存(): void {
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token(
			$token,
			[
				'temp_id'  => '9',
				'store_id' => 'D1',
				'sub_type' => 'FAMI',
			]
		);

		$order = $this->create_order_with_logistics_shipping();
		BlocksLogisticsIntegration::save_block_checkout_meta( $order );

		$this->assertNull(
			CartLogisticsSession::get_selected_store(),
			'下單搬 meta 後應清除 session 暫存'
		);
	}

	// ========== edge ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_block下單_未選綠界物流時不寫門市(): void {
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token(
			$token,
			[
				'temp_id'  => '10',
				'store_id' => 'E1',
				'sub_type' => 'FAMI',
			]
		);

		// 訂單未加入綠界物流運送方式
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'bacs',
			]
			);

		BlocksLogisticsIntegration::save_block_checkout_meta( $order );

		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $fresh->get_store_id(), '未選綠界物流不應寫門市' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_block下單_宅配無選店暫存仍寫sub_type情境(): void {
		// 宅配（HOME）不需選店，故 session 無門市暫存；但仍須寫 payment_scenario
		$order = $this->create_order_with_logistics_shipping( 'cod' );

		BlocksLogisticsIntegration::save_block_checkout_meta( $order );

		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'cod', $fresh->get_payment_scenario(), '宅配仍應寫 payment_scenario' );
		$this->assertSame( '', $fresh->get_store_id(), '宅配無門市暫存' );
	}

	// ========== cart extensions ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_cart_extension_data_有選店時回門市(): void {
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token(
			$token,
			[
				'temp_id'    => '11',
				'store_id'   => 'EXT001',
				'store_name' => 'extension 門市',
				'store_addr' => 'extension 地址',
				'sub_type'   => 'UNIMART',
			]
		);

		$data = BlocksLogisticsIntegration::cart_extension_data();
		$this->assertSame( 'EXT001', $data['store_id'] );
		$this->assertSame( 'extension 門市', $data['store_name'] );
		$this->assertSame( 'UNIMART', $data['sub_type'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_cart_extension_data_無選店時回空門市(): void {
		$data = BlocksLogisticsIntegration::cart_extension_data();
		$this->assertSame( '', $data['store_id'] );
		$this->assertSame( '', $data['store_name'] );
	}
}
