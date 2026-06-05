<?php
/**
 * 綠界站內付 2.0（ECPG）ReturnURL 幕後通知整合測試
 * 驗證：AES 解密失敗維持 pending、TransCode=0 傳輸層失敗、TransCode=1+RtnCode=1→processing、
 * TransCode=1+RtnCode≠1 業務層失敗、冪等、回 1|OK。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgCallback;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界站內付 2.0 ReturnURL 通知測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group ecpg
 */
final class EcpgCallbackTest extends TestCase {

	/** @var string 綠界 ECPG 線上金流測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 ECPG 線上金流測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** 每次測試前啟用 ecpay_ecpg */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
		EcpgSettingsDTO::reset();
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( EcpgGateway::ID ) );
		EcpgSettingsDTO::reset();
		parent::tear_down();
	}

	/** @return AesCrypto 測試用加解密器 */
	private function crypto(): AesCrypto {
		return new AesCrypto( self::HASH_KEY, self::HASH_IV );
	}

	/**
	 * 建立綁定 MerchantTradeNo 的 pending 訂單
	 *
	 * @param string $trade_no MerchantTradeNo
	 * @return \WC_Order
	 */
	private function create_ecpg_order( string $trade_no ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => EcpgGateway::ID,
				'total'          => 1000,
			]
		);
		( new EcpayMetaKeys( $order ) )->update_trade_no( $trade_no );
		return $order;
	}

	/**
	 * 組裝 ReturnURL 外層 JSON 通知（巢狀 OrderInfo.MerchantTradeNo）
	 *
	 * @param int    $trans_code 傳輸層碼
	 * @param int    $rtn_code   業務層碼
	 * @param string $trade_no   MerchantTradeNo
	 * @return array<string, mixed>
	 */
	private function build_notify( int $trans_code, int $rtn_code, string $trade_no ): array {
		return [
			'MerchantID' => '3002607',
			'TransCode'  => $trans_code,
			'TransMsg'   => 1 === $trans_code ? 'Success' : 'AES error',
			'Data'       => $this->crypto()->encrypt(
				[
					'RtnCode'   => $rtn_code,
					'RtnMsg'    => 1 === $rtn_code ? '交易成功' : '授權失敗',
					'OrderInfo' => [
						'MerchantTradeNo' => $trade_no,
						'TradeNo'         => '2026031215360001',
						'TradeAmt'        => 1000,
						'PaymentType'     => 'Credit',
					],
				]
			),
		];
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_TransCode1且RtnCode1時訂單轉處理中(): void {
		// Given: pending 訂單 + 雙層皆成功的通知
		$trade_no = 'EG200ABCDEF';
		$order    = $this->create_ecpg_order( $trade_no );

		// When
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, 1, $trade_no ) );

		// Then: 轉 processing，付款明細有值
		$this->assert_order_status( $order, 'processing' );
		$detail = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertNotEmpty( $detail );
		$this->assertSame( 1, $detail['RtnCode'] );
	}

	// ========== MerchantID 驗證（High-2：ReturnURL 未驗 MerchantID） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_MerchantID不符時維持pending不更新明細(): void {
		// Given: pending 訂單 + TransCode/RtnCode 皆 1，但外層 MerchantID 為偽造值
		$trade_no = 'EG200BADMID';
		$order    = $this->create_ecpg_order( $trade_no );
		$payload  = $this->build_notify( 1, 1, $trade_no );
		$payload['MerchantID'] = '9999999'; // 偽造（本商店為 3002607）

		// When
		EcpgCallback::instance()->handle_return( $payload );

		// Then: 維持 pending，不更新付款明細（拒絕處理偽造來源）
		$this->assert_order_status( $order, 'pending' );
		$detail = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertEmpty( $detail );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_MerchantID不符時即使透過REST端點也不洩漏憑證且回1OK(): void {
		// Given: 偽造 MerchantID 的通知
		$trade_no = 'EG200BADMID2';
		$this->create_ecpg_order( $trade_no );
		$payload  = $this->build_notify( 1, 1, $trade_no );
		$payload['MerchantID'] = '9999999';

		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/ecpg/return' );
		$request->set_body_params( $payload );

		// When
		$response = EcpgCallback::instance()->post_ecpg_return_callback( $request );

		// Then: 仍回 1|OK / 200（照原 callback 協定，避免重送風暴）
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1|OK', $response->get_data() );
		// 回應不洩漏憑證
		$body = (string) $response->get_data();
		$this->assertStringNotContainsString( self::HASH_KEY, $body );
		$this->assertStringNotContainsString( self::HASH_IV, $body );
	}

	// ========== 金額驗證（High-2：ReturnURL 未驗金額） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_RtnCode1但金額與訂單總額不符時維持pending(): void {
		// Given: pending 訂單（總額 1000），但回傳 TradeAmt=1（疑似竄改）
		$trade_no = 'EG200AMTMISMATCH';
		$order    = $this->create_ecpg_order( $trade_no );
		$payload  = [
			'MerchantID' => '3002607',
			'TransCode'  => 1,
			'TransMsg'   => 'Success',
			'Data'       => $this->crypto()->encrypt(
				[
					'RtnCode'   => 1,
					'RtnMsg'    => '交易成功',
					'OrderInfo' => [
						'MerchantTradeNo' => $trade_no,
						'TradeNo'         => '2026031215360099',
						'TradeAmt'        => 1, // 與訂單總額 1000 不符
						'PaymentType'     => 'Credit',
					],
				]
			),
		];

		// When
		EcpgCallback::instance()->handle_return( $payload );

		// Then: 維持 pending（不可自動轉處理中），order note 告警
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '金額' );
	}

	// ========== 業務層失敗（RtnCode≠1） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_TransCode1但RtnCode非1時維持pending並記錄RtnCode(): void {
		// Given: 傳輸成功、業務失敗（RtnCode=10100050）
		$trade_no = 'EG200ABCDEF';
		$order    = $this->create_ecpg_order( $trade_no );

		// When
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, 10100050, $trade_no ) );

		// Then: 維持 pending，order note 記錄失敗 RtnCode
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '10100050' );
	}

	// ========== 傳輸層失敗（TransCode≠1） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_TransCode0時維持pending並記錄傳輸層失敗(): void {
		// Given: 傳輸層失敗，外層帶明文 MerchantTradeNo（容錯記 note）
		$trade_no = 'EG200ABCDEF';
		$order    = $this->create_ecpg_order( $trade_no );
		$payload  = [
			'MerchantID'      => '3002607',
			'MerchantTradeNo' => $trade_no,
			'TransCode'       => 0,
			'TransMsg'        => 'AES decrypt error',
			'Data'            => '',
		];

		// When
		EcpgCallback::instance()->handle_return( $payload );

		// Then: 維持 pending，記錄傳輸層失敗
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '傳輸層失敗' );
		// 不更新付款明細
		$detail = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertEmpty( $detail );
	}

	// ========== AES 解密失敗 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_Data無法解密時維持pending不更新明細(): void {
		// Given: TransCode=1 但 Data 為非法密文（解密失敗）
		$trade_no = 'EG200ABCDEF';
		$order    = $this->create_ecpg_order( $trade_no );
		$payload  = [
			'MerchantID' => '3002607',
			'TransCode'  => 1,
			'TransMsg'   => 'Success',
			'Data'       => '###not-a-valid-cipher###',
		];

		// When
		EcpgCallback::instance()->handle_return( $payload );

		// Then: 維持 pending，不更新付款明細
		$this->assert_order_status( $order, 'pending' );
		$detail = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertEmpty( $detail );
	}

	// ========== 冪等（Idempotency） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_重複通知同一MerchantTradeNo時不重複處理(): void {
		// Given: 訂單已因成功通知轉為 processing
		$trade_no = 'EG200ABCDEF';
		$order    = $this->create_ecpg_order( $trade_no );

		// When: 重送 4 次（綠界最多重送 4 次）
		for ( $i = 0; $i < 4; $i++ ) {
			EcpgCallback::instance()->handle_return( $this->build_notify( 1, 1, $trade_no ) );
		}

		// Then: 狀態維持 processing（冪等）
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 回應（Response） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_ReturnURL回應純文字1OK且HTTP200(): void {
		// Given
		$trade_no = 'EG200RESP';
		$this->create_ecpg_order( $trade_no );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/ecpg/return' );
		$request->set_body_params( $this->build_notify( 1, 1, $trade_no ) );

		// When
		$response = EcpgCallback::instance()->post_ecpg_return_callback( $request );

		// Then: HTTP 200 + body 為 1|OK
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1|OK', $response->get_data() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_即使處理拋例外仍回1OK避免綠界重送風暴(): void {
		// Given: 找不到對應訂單（MerchantTradeNo 不存在）會在 handle_return 拋例外
		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/ecpg/return' );
		$request->set_body_params( $this->build_notify( 1, 1, 'NON_EXISTENT_TRADE_NO' ) );

		// When
		$response = EcpgCallback::instance()->post_ecpg_return_callback( $request );

		// Then: 仍回 1|OK / 200（例外被 catch）
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1|OK', $response->get_data() );
	}
}
