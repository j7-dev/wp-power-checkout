<?php
/**
 * 藍新 NewebPay MPG sandbox 端到端實測修復的行為鎖定測試
 *
 * 2026-07-03 以真實藍新 sandbox（MS158975086）端到端驗證時揪出四個 mock 測不到的缺陷，
 * 本檔為修復後的行為鎖定（regression guard）：
 *
 *  1. ReturnURL 買家白頁：post_mpg_return_callback 原回純文字 1|OK，買家付款完卡白頁
 *     → 修為 302 導向訂單完成頁（查無訂單 / 驗章失敗 fallback 結帳頁）。
 *  2. before_order_received 冪等缺失：已付款（processing）訂單重訪 order-received
 *     仍渲染 auto-form 把買家彈回藍新（藍新以重複 MerchantOrderNo 拒單）
 *     → 修為 needs_payment() guard。
 *  3. REST /refund 不發金流 API 卻回成功：wc_create_refund 未帶 refund_payment=true，
 *     refund 被 process_gateway_refund 判為「手動退款」early return，API 從未發送
 *     →（WC 訂單 refunded、藍新端仍已付款——後台對賬鐵證）修為帶旗標 +
 *     wc_refund_payment 壓平 WP_Error 後重呼叫 process_refund 取回正規化錯誤。
 *  4. 退款 API 失敗透出：process_gateway_refund 於 hook 內吞例外，REST 無從得知
 *     → record_refund_error / consume_refund_error（read-once meta）。
 *
 * （另兩個 sandbox 修復——DoAction TimeStamp 必填參數與 Akamai WAF UA 阻擋——屬
 *  對外 API 參數 / 傳輸層，mock 模式無法斷言，已由 sandbox 實測 TRA10035 與
 *  取消授權成功 note 驗證。）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/MpgSandboxFixesTest.php --no-coverage'
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 * @group error
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\MpgCallback;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Domains\Payment\Shared\Services\PaymentApiService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 藍新 MPG sandbox 修復行為鎖定測試類別
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 * @group error
 */
final class MpgSandboxFixesTest extends TestCase {

	/** @var string 藍新 MPG 公開測試帳號 HashKey */
	private const HASH_KEY = 'Vh2Br0kFGSGHA9zXFDJuf9KIVgVxX1pn';

	/** @var string 藍新 MPG 公開測試帳號 HashIV */
	private const HASH_IV = 'IZGViXjMd2gWMtsR';

	/** @var string 藍新 MPG 公開測試帳號 MerchantID */
	private const MERCHANT_ID = 'MS154450763';

	/** 每次測試前啟用 newebpay_mpg（test 模式 + 公開測試憑證） */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		// auto-form 模板位於 templates/pages/，此註冊平時由 Plugin::__construct 設定；
		// 測試 bootstrap 不一定觸發 Plugin singleton，缺少時 load_template 會 throw「模板不存在」
		\J7\PowerCheckout\Plugin::$template_page_names = [ 'auto-form' ];

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
	 * 建立綁定 MerchantOrderNo 的藍新訂單
	 *
	 * @param string $order_no MerchantOrderNo（冪等鍵，反查主鍵）
	 * @param string $status   訂單狀態
	 * @return \WC_Order
	 */
	private function create_mpg_order( string $order_no, string $status = 'pending' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
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
		$result['CheckCode'] = $crypto->generate_check_code( $result );

		$payload = [
			'Status'  => 'SUCCESS',
			'Message' => '授權成功',
			'Result'  => $result,
		];

		return $crypto->encrypt( (string) \wp_json_encode( $payload ) );
	}

	// ========== 修復 1：ReturnURL 302 導向（買家白頁） ==========

	/**
	 * 正確驗簽透過 ReturnURL REST callback → 302 導向該訂單完成頁 + 訂單轉 processing
	 *
	 * @test
	 * @group happy
	 */
	public function test_ReturnURL_正確驗簽回302導向訂單完成頁(): void {
		// Given: pending 訂單 + 正確簽章的成功通知
		$order_no   = 'PCMR302' . \uniqid();
		$order      = $this->create_mpg_order( $order_no );
		$trade_info = $this->build_success_trade_info( $order_no, 1000 );
		$crypto     = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/newebpay/mpg/return' );
		$request->set_body_params(
			[
				'TradeInfo' => $trade_info,
				'TradeSha'  => $crypto->generate_trade_sha( $trade_info ),
			]
		);

		// When: 買家瀏覽器 form POST 導回
		$response = MpgCallback::instance()->post_mpg_return_callback( $request );

		// Then: 302 + Location = 該訂單 order-received URL（不得停留純文字白頁）
		$this->assertSame( 302, $response->get_status(), 'ReturnURL 必須 302 導向，不得停留純文字頁' );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Location', $headers );
		$this->assertStringContainsString( 'order-received', (string) $headers['Location'] );
		$this->assertStringContainsString( (string) $order->get_id(), (string) $headers['Location'] );
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 偽造 TradeSha 透過 ReturnURL → 302 fallback 結帳頁 + 訂單不受污染
	 *
	 * @test
	 * @group security
	 * @group error
	 */
	public function test_ReturnURL_偽造TradeSha回302fallback結帳頁且訂單未變(): void {
		// Given: pending 訂單 + 壞 TradeSha
		$order_no   = 'PCMR302BAD' . \uniqid();
		$order      = $this->create_mpg_order( $order_no );
		$trade_info = $this->build_success_trade_info( $order_no, 1000 );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/newebpay/mpg/return' );
		$request->set_body_params(
			[
				'TradeInfo' => $trade_info,
				'TradeSha'  => 'FORGED_TRADE_SHA_0000000000000000000000000000000000000000000000',
			]
		);

		// When
		$response = MpgCallback::instance()->post_mpg_return_callback( $request );

		// Then: 仍 302（買家不能卡死），但導向結帳頁而非任何訂單頁；訂單維持 pending
		$this->assertSame( 302, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Location', $headers );
		$this->assertStringNotContainsString( 'order-received', (string) $headers['Location'], '驗章失敗不得導向訂單完成頁' );
		$this->assert_order_status( $order, 'pending' );
	}

	// ========== 修復 2：before_order_received 冪等（已付款不再彈藍新） ==========

	/**
	 * 呼叫 protected before_order_received 並擷取輸出
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 渲染輸出（auto-form HTML 或空字串）
	 */
	private function invoke_before_order_received( \WC_Order $order ): string {
		$gateway = new MpgRedirectGateway();
		$method  = new \ReflectionMethod( $gateway, 'before_order_received' );
		$method->setAccessible( true );

		\ob_start();
		$method->invoke( $gateway, $order );
		return (string) \ob_get_clean();
	}

	/**
	 * 已付款（processing）訂單重訪 order-received → 不得再渲染導轉藍新的 auto-form
	 *
	 * sandbox 實測：重複導轉會被藍新以重複 MerchantOrderNo 拒單，買家被甩出完成頁。
	 *
	 * @test
	 * @group error
	 */
	public function test_已付款訂單不再渲染藍新導轉表單(): void {
		// Given: 已付款 processing 訂單
		$order = $this->create_mpg_order( 'PCMIDEM' . \uniqid(), 'processing' );

		// When
		$output = $this->invoke_before_order_received( $order );

		// Then: 無任何導轉輸出
		$this->assertSame( '', \trim( $output ), '已付款訂單不得再渲染導轉藍新的 auto-form' );
	}

	/**
	 * 對照基線：pending（needs_payment）訂單仍正常渲染 auto-form（證明上一條非 vacuous）
	 *
	 * @test
	 * @group happy
	 */
	public function test_pending訂單仍渲染藍新導轉表單(): void {
		// Given: pending 訂單
		$order = $this->create_mpg_order( 'PCMPEND' . \uniqid(), 'pending' );

		// When
		$output = $this->invoke_before_order_received( $order );

		// Then: 含導向藍新 mpg_gateway 的表單
		$this->assertStringContainsString( 'newebpay.com/MPG/mpg_gateway', $output, 'pending 訂單應渲染導轉藍新的 auto-form' );
	}

	// ========== 修復 4：退款 API 失敗透出（record / consume read-once） ==========

	/**
	 * record_refund_error → consume_refund_error round-trip + read-once
	 *
	 * @test
	 * @group error
	 */
	public function test_退款失敗記錄record_consume往返且僅能讀取一次(): void {
		// Given: 訂單 + 一筆正規化退款失敗（sandbox 實測樣板：TRA10035）
		$order   = $this->create_mpg_order( 'PCMRCERR' . \uniqid(), 'processing' );
		$gateway = new MpgRedirectGateway();

		$record = new \ReflectionMethod( $gateway, 'record_refund_error' );
		$record->setAccessible( true );
		$record->invoke(
			null,
			$order,
			NormalizedError::from(
				ErrorCode::PROVIDER,
				'藍新 MPG DoAction 退款失敗 Status=TRA10035：該交易非授權成功或已請款完成狀態，請確認',
				[
					'raw_code' => 'TRA10035',
					'provider' => MpgRedirectGateway::ID,
				]
			)
		);

		// When: 第一次 consume
		$error = $gateway->consume_refund_error( $order->get_id() );

		// Then: 取回正規化錯誤（PROVIDER + raw_code TRA10035）
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $error ) );
		$this->assertSame( 'TRA10035', NormalizedError::get_raw_code( $error ) );
		$this->assertStringContainsString( 'TRA10035', $error->get_error_message() );

		// And: read-once——第二次 consume 回 null（不得重複回報同一筆失敗）
		$this->assertNull( $gateway->consume_refund_error( $order->get_id() ) );
	}

	// ========== 修復 3：REST /refund 正規化錯誤 envelope（refund_payment=true 路徑） ==========

	/**
	 * REST /refund 對不支援 API 退款的付款方式（VACC）→ 正規化 UNSUPPORTED envelope + 無殘留 refund
	 *
	 * 覆蓋修復後的完整路徑：wc_create_refund( refund_payment=true ) → WC 核心
	 * wc_refund_payment 呼叫 process_refund 取得正規化 \WP_Error 後「壓平」為
	 * WP_Error('error', msg) → REST 重呼叫 process_refund 取回正規化錯誤 →
	 * error_response（HTTP 400 + error_code=UNSUPPORTED）。refund 由 WC 核心刪除不殘留。
	 *
	 * @test
	 * @group error
	 */
	public function test_REST退款_VACC付款方式回正規化UNSUPPORTED且無殘留refund(): void {
		// Given: 已付款 VACC（ATM 虛擬帳號）訂單——不支援 API 退款
		$order = $this->create_mpg_order( 'PCMRESTVACC' . \uniqid(), 'processing' );
		( new MpgMetaKeys( $order ) )->update_payment_detail(
			[
				'PaymentType' => 'VACC',
				'TradeNo'     => '24061812345678',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/refund' );
		$request->set_param( 'order_id', $order->get_id() );

		// When
		$response = PaymentApiService::instance()->post_refund_callback( $request );

		// Then: HTTP 400 + error_code=UNSUPPORTED（非壓平後的 'error'）
		$this->assertSame( ErrorCode::UNSUPPORTED->to_http_status(), $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $data['error_code'] ?? null, 'WC 壓平後仍須取回正規化 error_code' );

		// And: refund 不殘留（WC 核心 wc_refund_payment 失敗即刪）、訂單狀態不變
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertCount( 0, $fresh->get_refunds(), '退款失敗不得殘留 refund 記錄' );
		$this->assert_order_status( $order, 'processing' );
	}
}
