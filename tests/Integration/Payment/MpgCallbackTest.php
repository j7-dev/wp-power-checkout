<?php
/**
 * 藍新 NewebPay MPG 付款結果通知（NotifyURL）FM-06 always-200 護欄測試
 *
 * einvoice 正規化錯誤模型 parity 補列（第 7 個金流）：MpgCallback 程式「已 always-200」，
 * 本檔不改 callback 任何一行，只補上 FM-06（防重送風暴）的斷言護欄——仿 P6b 為其他
 * callback（EcpayAioCallbackTest）補的同型測試：
 *
 *   偽造驗簽（壞 TradeSha / 壞 TradeInfo）的 notify callback
 *     → HTTP 200 + 純文字 1|OK（不可回 500，否則藍新無限重送）
 *     → 訂單狀態維持 pending、不寫入付款明細（驗簽失敗不得污染訂單）。
 *
 * 另含一條「正確驗簽→processing」的對照基線，確保上述「維持 pending」非 vacuous
 * （證明驗簽通過時訂單「確實會」轉態，反襯偽造案例的擋阻有效）。MPG callback 全流程
 * 於 mock 模式 in-process 完成（StatusManager 不打外部 API），故此基線穩定可靠。
 *
 * 驗簽鏈（MpgCallback::handle_notify）：
 *   1. TradeSha 驗封包（hash_equals timing-safe）→ 不符即 return（不更新）。
 *   2. 解密 TradeInfo（AES-256-CBC）→ 失敗即 return（不更新）。
 *   3~7. Status / CheckCode / 反查訂單 / 冪等 / StatusManager。
 * 任一失敗分支（含 \Throwable）一律回 HTTP 200。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/MpgCallbackTest.php --no-coverage'
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\MpgCallback;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 藍新 MPG NotifyURL FM-06 護欄測試類別
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 * @group edge
 */
final class MpgCallbackTest extends TestCase {

	/** @var string 藍新 MPG 公開測試帳號 HashKey */
	private const HASH_KEY = 'Vh2Br0kFGSGHA9zXFDJuf9KIVgVxX1pn';

	/** @var string 藍新 MPG 公開測試帳號 HashIV */
	private const HASH_IV = 'IZGViXjMd2gWMtsR';

	/** @var string 藍新 MPG 公開測試帳號 MerchantID */
	private const MERCHANT_ID = 'MS154450763';

	/** 每次測試前啟用 newebpay_mpg（test 模式 + 公開測試憑證） */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			MpgRedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => self::MERCHANT_ID,
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		parent::tear_down();
	}

	/**
	 * 建立綁定 MerchantOrderNo 的 pending 藍新訂單
	 *
	 * @param string $order_no MerchantOrderNo（冪等鍵，反查主鍵）
	 * @return \WC_Order
	 */
	private function create_mpg_order( string $order_no ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => MpgRedirectGateway::ID,
				'total'          => 1000,
			]
		);
		( new MpgMetaKeys( $order ) )->update_order_no( $order_no );
		return $order;
	}

	/**
	 * 以公開測試憑證加密一個成功付款的 TradeInfo（hex）
	 *
	 * @param string $order_no MerchantOrderNo
	 * @param int    $amt      金額（須等於訂單應收，否則金額防竄改會擋下）
	 * @return string AES-256-CBC hex 密文
	 */
	private function build_success_trade_info( string $order_no, int $amt ): string {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		$result = [
			'MerchantID'      => self::MERCHANT_ID,
			'MerchantOrderNo' => $order_no,
			'TradeNo'         => '24061812345678',
			'Amt'             => $amt,
			'PaymentType'     => 'CREDIT',
			'RespondCode'     => '00',
		];
		// CheckCode 內層驗章（Status=SUCCESS 才會被驗）
		$result['CheckCode'] = $crypto->generate_check_code( $result );

		$payload = [
			'Status'  => 'SUCCESS',
			'Message' => '授權成功',
			'Result'  => $result,
		];

		return $crypto->encrypt( (string) \wp_json_encode( $payload ) );
	}

	// ========== 對照基線：正確驗簽 → processing（證明偽造案例的「維持 pending」非 vacuous） ==========

	/**
	 * 正確 TradeInfo + 正確 TradeSha 透過 NotifyURL REST callback → HTTP 200 + 訂單轉 processing
	 *
	 * @test
	 * @group happy
	 */
	public function test_正確驗簽透過REST_callback回200且訂單轉processing(): void {
		// Given: pending 訂單 + 正確簽章的成功通知
		$order_no   = 'PCM_OK_' . \uniqid();
		$order      = $this->create_mpg_order( $order_no );
		$trade_info = $this->build_success_trade_info( $order_no, 1000 );
		$crypto     = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/newebpay/mpg/notify' );
		$request->set_body_params(
			[
				'TradeInfo' => $trade_info,
				'TradeSha'  => $crypto->generate_trade_sha( $trade_info ),
			]
		);

		// When
		$response = MpgCallback::instance()->post_mpg_notify_callback( $request );

		// Then: HTTP 200 + 1|OK，且訂單轉 processing（驗簽通過確實會轉態）
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1|OK', $response->get_data() );
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== FM-06 護欄：偽造 TradeSha → HTTP 200 + 訂單維持 pending + 不寫明細 ==========

	/**
	 * 偽造 TradeSha（封包驗章失敗）透過 NotifyURL REST callback → HTTP 200 + 維持 pending + 不寫明細
	 *
	 * @test
	 * @group security
	 * @group edge
	 */
	public function test_FM06_偽造TradeSha透過REST_callback仍回200且訂單未變(): void {
		// Given: pending 訂單 + 正確 TradeInfo 但「壞 TradeSha」
		$order_no   = 'PCM_BADSHA_' . \uniqid();
		$order      = $this->create_mpg_order( $order_no );
		$trade_info = $this->build_success_trade_info( $order_no, 1000 );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/newebpay/mpg/notify' );
		$request->set_body_params(
			[
				'TradeInfo' => $trade_info,
				'TradeSha'  => 'FORGED_TRADE_SHA_0000000000000000000000000000000000000000000000', // 偽造
			]
		);

		// When: 偽造封包驗章請求進 REST callback
		$response = MpgCallback::instance()->post_mpg_notify_callback( $request );

		// Then: 仍回 HTTP 200 + 1|OK（避免藍新重送風暴），且訂單維持 pending、不寫付款明細
		$this->assertSame( 200, $response->get_status(), '偽造 TradeSha 仍須回 HTTP 200（防重送風暴）' );
		$this->assertSame( '1|OK', $response->get_data() );
		$this->assert_order_status( $order, 'pending' );
		$detail = ( new MpgMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertEmpty( $detail, '偽造 TradeSha 不得寫入付款明細' );
	}

	/**
	 * 偽造 TradeInfo（解密失敗）透過 NotifyURL REST callback → HTTP 200 + 維持 pending + 不寫明細
	 *
	 * 攻擊者用「過得了 TradeSha 但解不開」的 TradeInfo：TradeSha 以該偽造 TradeInfo 計算
	 *（故封包驗章 hash_equals 會通過），但其非合法 AES 密文 → 解密 throw → handle_notify 提前 return。
	 * 證明驗簽鏈第 2 關（解密）失敗時 callback 仍 always-200 且不污染訂單。
	 *
	 * @test
	 * @group security
	 * @group edge
	 */
	public function test_FM06_偽造TradeInfo解密失敗透過REST_callback仍回200且訂單未變(): void {
		// Given: pending 訂單 + 非法 TradeInfo（非合法 AES hex 密文）
		$order_no   = 'PCM_BADINFO_' . \uniqid();
		$order      = $this->create_mpg_order( $order_no );
		$bad_info   = 'deadbeefdeadbeefdeadbeefdeadbeef'; // 16 bytes hex，hex2bin 過得了但解不出合法明文
		$crypto     = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		$forged_sha = $crypto->generate_trade_sha( $bad_info ); // 讓封包驗章通過，逼進解密關

		$request = new \WP_REST_Request( 'POST', '/power-checkout/newebpay/mpg/notify' );
		$request->set_body_params(
			[
				'TradeInfo' => $bad_info,
				'TradeSha'  => $forged_sha,
			]
		);

		// When
		$response = MpgCallback::instance()->post_mpg_notify_callback( $request );

		// Then: 仍回 HTTP 200 + 1|OK，且訂單維持 pending、不寫付款明細
		$this->assertSame( 200, $response->get_status(), '解密失敗仍須回 HTTP 200（防重送風暴）' );
		$this->assertSame( '1|OK', $response->get_data() );
		$this->assert_order_status( $order, 'pending' );
		$detail = ( new MpgMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertEmpty( $detail, '解密失敗不得寫入付款明細' );
	}
}
