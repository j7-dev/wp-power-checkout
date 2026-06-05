<?php
/**
 * 電子發票 block 結帳發票表單整合測試
 *
 * 對應 block 發票表單路徑：
 *   - handle_update_callback：前端 extensionCartUpdate → sanitize + validate → 暫存 WC session
 *   - cart_extension_data：把 session 暫存發票參數注入 cart.extensions['pc_invoice']
 *   - save_block_checkout_meta：woocommerce_store_api_checkout_order_processed → 把 session
 *     發票參數搬進 order meta（與 classic 同一個 `_pc_issue_invoice_params` key），並清除 session
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     env API_MODE=mock vendor/bin/phpunit --filter BlocksInvoiceIntegrationTest 2>&1; echo "EXIT=$?"
 *
 * @group integration
 * @group invoice
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\CartInvoiceSession;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Services\BlocksInvoiceIntegration;
use Tests\Integration\TestCase;

/** block 發票表單整合測試類別 */
final class BlocksInvoiceIntegrationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->boot_wc_session();
		CartInvoiceSession::clear();
	}

	public function tear_down(): void {
		CartInvoiceSession::clear();
		parent::tear_down();
	}

	/** @return void */
	private function boot_wc_session(): void {
		$wc = \WC();
		if (!isset( $wc->session ) || !$wc->session instanceof \WC_Session) {
			$wc->initialize_session();
		}
		$wc->session->set_customer_session_cookie( true );
	}

	// ========== handle_update_callback：個人 / 公司 / 捐贈 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_update_callback_個人手機條碼_暫存session(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'barcode',
				'carrier'     => '/ABC123+',
			]
		);

		$stored = CartInvoiceSession::get();
		$this->assertNotNull( $stored );
		$this->assertSame( 'amego', $stored['provider'] );
		$this->assertSame( 'individual', $stored['invoiceType'] );
		$this->assertSame( 'barcode', $stored['individual'] );
		$this->assertSame( '/ABC123+', $stored['carrier'] );
		$this->assertSame( '', $stored['companyId'], '非公司類型 companyId 應清空' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_update_callback_公司統編_暫存session(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyId'   => '12345678',
				'companyName' => '測試股份有限公司',
			]
		);

		$stored = CartInvoiceSession::get();
		$this->assertNotNull( $stored );
		$this->assertSame( 'company', $stored['invoiceType'] );
		$this->assertSame( '12345678', $stored['companyId'] );
		$this->assertSame( '測試股份有限公司', $stored['companyName'] );
		$this->assertSame( '', $stored['carrier'], '公司類型 carrier 應清空' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_update_callback_捐贈碼_暫存session(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'donate',
				'donateCode'  => '25885',
			]
		);

		$stored = CartInvoiceSession::get();
		$this->assertNotNull( $stored );
		$this->assertSame( 'donate', $stored['invoiceType'] );
		$this->assertSame( '25885', $stored['donateCode'] );
	}

	// ========== handle_update_callback：驗證失敗不暫存（後端第二道防線）==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_update_callback_統編格式錯誤_不暫存(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyId'   => '123', // 非 8 碼
				'companyName' => 'X',
			]
		);
		$this->assertNull( CartInvoiceSession::get(), '統編格式錯誤不應暫存' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_update_callback_載具格式錯誤_不暫存(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'barcode',
				'carrier'     => 'INVALID',
			]
		);
		$this->assertNull( CartInvoiceSession::get(), '手機條碼載具格式錯誤不應暫存' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_update_callback_發票類型不正確_不暫存(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'hacker',
			]
		);
		$this->assertNull( CartInvoiceSession::get() );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_update_callback_clear旗標_清除暫存(): void {
		// 先暫存
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'donate',
				'donateCode'  => '25885',
			]
		);
		$this->assertNotNull( CartInvoiceSession::get() );

		// clear 旗標
		BlocksInvoiceIntegration::handle_update_callback( [ 'clear' => true ] );
		$this->assertNull( CartInvoiceSession::get(), 'clear 旗標應清除暫存' );
	}

	// ========== cart_extension_data ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_cart_extension_data_有暫存時回發票參數(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyId'   => '12345678',
				'companyName' => 'ABC',
			]
		);

		$data = BlocksInvoiceIntegration::cart_extension_data();
		$this->assertTrue( $data['filled'] );
		$this->assertSame( 'company', $data['invoiceType'] );
		$this->assertSame( '12345678', $data['companyId'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_cart_extension_data_無暫存時回空(): void {
		$data = BlocksInvoiceIntegration::cart_extension_data();
		$this->assertFalse( $data['filled'] );
		$this->assertSame( '', $data['invoiceType'] );
	}

	// ========== save_block_checkout_meta：下單寫 order meta ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_block下單_session發票參數搬進order_meta(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);

		$order = $this->create_wc_order( [ 'status' => 'pending' ] );
		BlocksInvoiceIntegration::save_block_checkout_meta( $order );

		// 與 classic 同一個 key（_pc_issue_invoice_params）
		$fresh  = \wc_get_order( $order->get_id() );
		$params = ( new MetaKeys( $fresh ) )->get_issue_params();
		$this->assertIsArray( $params );
		$this->assertSame( 'amego', $params['provider'] );
		$this->assertSame( 'individual', $params['invoiceType'] );
		$this->assertSame( 'cloud', $params['individual'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_block下單後清除session暫存(): void {
		BlocksInvoiceIntegration::handle_update_callback(
			[
				'provider'    => 'amego',
				'invoiceType' => 'donate',
				'donateCode'  => '25885',
			]
		);

		$order = $this->create_wc_order( [ 'status' => 'pending' ] );
		BlocksInvoiceIntegration::save_block_checkout_meta( $order );

		$this->assertNull( CartInvoiceSession::get(), '下單搬 meta 後應清除 session 暫存' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_block下單_無發票暫存時不寫meta(): void {
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );
		BlocksInvoiceIntegration::save_block_checkout_meta( $order );

		$fresh  = \wc_get_order( $order->get_id() );
		$params = ( new MetaKeys( $fresh ) )->get_issue_params();
		$this->assertNull( $params, '無發票暫存不應寫 meta' );
	}
}
