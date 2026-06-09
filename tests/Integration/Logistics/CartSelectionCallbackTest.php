<?php
/**
 * 綠界全方位物流 cart 級選店回呼（session 綁定）整合測試
 *
 * 對應 cart-bound 路徑的選店回呼：
 *   ClientReplyURL 編入 pc_st（session 權杖，非 pc_oid/pc_key）→ 解 ResultData →
 *   以權杖 timing-safe 驗證後寫入 WC session（非 order meta，因下單前無訂單）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     env API_MODE=mock vendor/bin/phpunit --filter CartSelectionCallbackTest 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * cart 級選店回呼（session 綁定）測試類別
 *
 * @group integration
 * @group logistics
 */
final class CartSelectionCallbackTest extends TestCase {

	private const B2C_MERCHANT_ID = '2000132';
	private const B2C_HASH_KEY    = '5294y06JbISpM5x9';
	private const B2C_HASH_IV     = 'v77hoKGq4kWxNNIS';

	/** @var AesCrypto B2C 加密器 */
	private AesCrypto $b2c_crypto;

	public function set_up(): void {
		parent::set_up();
		$this->boot_wc_session();
		$this->b2c_crypto = new AesCrypto( self::B2C_HASH_KEY, self::B2C_HASH_IV );
		EcpayLogisticsSettingsDTO::reset();
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			[
				'mode'             => 'test',
				'account_type'     => 'b2c',
				'b2c_merchant_id'  => self::B2C_MERCHANT_ID,
				'b2c_hash_key'     => self::B2C_HASH_KEY,
				'b2c_hash_iv'      => self::B2C_HASH_IV,
				'server_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback',
				'client_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback',
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
	 * 建立合法 ResultData（B2C AES 加密）
	 *
	 * @param array<string, mixed> $store_data 門市資訊
	 * @return string
	 */
	private function build_result_data( array $store_data ): string {
		return $this->b2c_crypto->encrypt( $store_data );
	}

	/**
	 * 建立選店回呼請求（query 帶 pc_st，body 帶 ResultData）
	 *
	 * @param string               $result_data ResultData 密文
	 * @param array<string, mixed> $query       query 參數（pc_st）
	 * @return \WP_REST_Request
	 */
	private function make_request( string $result_data, array $query ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/logistics/selection-callback' );
		$request->set_body_params( [ 'ResultData' => $result_data ] );
		$request->set_query_params( $query );
		return $request;
	}

	// ========== happy ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_cart選店回呼_有效權杖寫入session門市(): void {
		// Given: 已發起 cart 級選店，產生 session 權杖
		$token = CartLogisticsSession::issue_token();

		$result_data = $this->build_result_data(
			[
				'TempLogisticsID'  => '5501',
				'CVSStoreID'       => 'F0001',
				'CVSStoreName'     => '全家 cart 測試門市',
				'CVSAddress'       => '台北市信義區 cart 路1號',
				'LogisticsSubType' => 'FAMI',
			]
		);

		$request = $this->make_request( $result_data, [ 'pc_st' => $token ] );

		// When
		$response = LogisticsCallback::instance()->post_logistics_selection_callback_callback( $request );

		// Then: 回 HTTP 200，門市寫入 session（非 order meta）
		$this->assertSame( 200, $response->get_status() );
		$store = CartLogisticsSession::get_selected_store();
		$this->assertNotNull( $store, '有效權杖應寫入 session 門市' );
		$this->assertSame( '5501', $store['temp_id'] );
		$this->assertSame( 'F0001', $store['store_id'] );
		$this->assertSame( '全家 cart 測試門市', $store['store_name'] );
		$this->assertSame( 'FAMI', $store['sub_type'] );
	}

	// ========== security ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_cart選店回呼_偽造權杖拒絕寫入session門市(): void {
		// Given: 已有合法 session 權杖，但攻擊者帶偽造權杖
		CartLogisticsSession::issue_token();

		$result_data = $this->build_result_data(
			[
				'TempLogisticsID'  => '9999',
				'CVSStoreID'       => 'EVIL',
				'CVSStoreName'     => '惡意門市',
				'LogisticsSubType' => 'FAMI',
			]
		);

		$request = $this->make_request(
			$result_data,
			[ 'pc_st' => 'forged_token_xxxxxxxxxxxxxxxxxxxxxxxxxxxx' ]
		);

		// When
		$response = LogisticsCallback::instance()->post_logistics_selection_callback_callback( $request );

		// Then: 回 200（不拋 500），但拒絕寫入門市
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull(
			CartLogisticsSession::get_selected_store(),
			'偽造權杖不應寫入任何門市'
		);
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_cart選店回呼_缺權杖時不寫入(): void {
		CartLogisticsSession::issue_token();

		$result_data = $this->build_result_data(
			[
				'TempLogisticsID'  => '1234',
				'CVSStoreID'       => 'X1',
				'LogisticsSubType' => 'FAMI',
			]
		);

		// 無 pc_st、無 pc_oid → 兩條綁定路徑皆不成立
		$request  = $this->make_request( $result_data, [] );
		$response = LogisticsCallback::instance()->post_logistics_selection_callback_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( CartLogisticsSession::get_selected_store() );
	}

	// ========== edge ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_cart選店回呼_解密失敗時不寫入且不拋500(): void {
		$token = CartLogisticsSession::issue_token();

		// 以錯誤憑證加密 → 正確帳號無法解密
		$wrong       = new AesCrypto( 'WRONGKEY12345678', 'WRONGIV123456789' );
		$result_data = $wrong->encrypt(
			[
				'TempLogisticsID' => '1',
				'CVSStoreID'      => 'X',
			]
			);

		$request = $this->make_request( $result_data, [ 'pc_st' => $token ] );

		$threw    = false;
		$response = null;
		try {
			$response = LogisticsCallback::instance()->post_logistics_selection_callback_callback( $request );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		$this->assertFalse( $threw, '解密失敗不應拋例外' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( CartLogisticsSession::get_selected_store(), '解密失敗不應寫入門市' );
	}
}
