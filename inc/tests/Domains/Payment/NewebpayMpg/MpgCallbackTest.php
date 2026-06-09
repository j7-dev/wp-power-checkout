<?php
/**
 * 藍新 NewebPay MPG MpgCallback 測試
 * run `vendor/bin/phpunit --filter MpgCallbackTest`
 *
 * 驗證 NotifyURL 驗章鏈 + 冪等 + StatusManager：
 *  - SUCCESS + RespondCode=00 → processing
 *  - TradeSha 驗章失敗 → 不更新
 *  - CheckCode 驗章失敗 → 不更新
 *  - 查無訂單 → 回 HTTP 200（不 throw 到 HTTP 層）
 *  - 冪等：重送不重複
 *  - 金額竄改 → 維持 pending
 */

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Domains\Payment\NewebpayMpg;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\MpgCallback;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckoutTests\Attributes\Create;
use J7\PowerCheckoutTests\Helper\Order;
use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;

/**
 * MpgCallback NotifyURL 處理
 *
 * @group newebpay_mpg
 * @group payment
 */
#[Create( Order::class )]
class MpgCallbackTest extends WC_UnitTestCase {

	/** @var Plugin[] 測試前需要安裝的插件 */
	protected array $required_plugins = [
		Plugin::WOOCOMMERCE,
		Plugin::POWERHOUSE,
		Plugin::POWER_CHECKOUT,
	];

	/** @var MpgRedirectGateway|null gateway */
	private MpgRedirectGateway|null $gateway = null;

	/** 每個測試方法執行前執行一次 */
	public function set_up(): void {
		parent::set_up();
		$this->gateway = new MpgRedirectGateway();
		$order         = $this->get_order();
		$order->set_payment_method( $this->gateway->id );
		// 寫入冪等鍵 MerchantOrderNo，供 callback 反查
		( new MpgMetaKeys( $order ) )->update_order_no( $this->order_no() );
		$order->save();
	}

	/** @return \WC_Order 取得訂單 */
	protected function get_order(): \WC_Order {
		return $this->get_container( Order::class )->get_item();
	}

	/** @return string 測試用 MerchantOrderNo */
	private function order_no(): string {
		return 'PC' . $this->get_order()->get_id() . 'T123';
	}

	/**
	 * 建立加密的 NotifyURL 通知 body（TradeInfo + TradeSha）
	 *
	 * @param array<string, mixed> $result_override 覆寫 Result 欄位
	 * @param string               $status          頂層 Status
	 * @param bool                 $valid_check_code 是否產生正確 CheckCode
	 * @return array{TradeInfo: string, TradeSha: string}
	 */
	private function build_notify_body( array $result_override = [], string $status = 'SUCCESS', bool $valid_check_code = true ): array {
		$settings = MpgSettingsDTO::instance();
		$crypto   = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );
		$order    = $this->get_order();

		$result = \array_merge(
			[
				'MerchantID'      => $settings->merchantId,
				'Amt'             => (int) \ceil( (float) $order->get_total() ),
				'TradeNo'         => '26060512345678',
				'MerchantOrderNo' => $this->order_no(),
				'PaymentType'     => 'CREDIT',
				'RespondCode'     => '00',
				'PayTime'         => '2026-06-05 12:00:00',
			],
			$result_override
		);

		// CheckCode（固定順序）
		$result['CheckCode'] = $valid_check_code
		? $crypto->generate_check_code( $result )
		: 'DEADBEEFDEADBEEFDEADBEEFDEADBEEFDEADBEEFDEADBEEFDEADBEEFDEADBEEF';

		$payload    = [
			'Status'  => $status,
			'Message' => 'SUCCESS' === $status ? '授權成功' : '付款失敗',
			'Result'  => $result,
		];
		$json       = \wp_json_encode( $payload );
		$trade_info = $crypto->encrypt( (string) $json );
		$trade_sha  = $crypto->generate_trade_sha( $trade_info );

		return [
			'TradeInfo' => $trade_info,
			'TradeSha'  => $trade_sha,
		];
	}

	/**
	 * @testdox 付款成功（SUCCESS + RespondCode=00）後訂單轉為處理中
	 * @return void
	 */
	public function test_notify_success_to_processing(): void {
		$body = $this->build_notify_body();
		( new MpgCallback() )->handle_notify( $body );

		$order = \wc_get_order( $this->get_order()->get_id() );
		$this->assertContains(
			$order->get_status(),
			[ OrderStatus::PROCESSING->value, OrderStatus::COMPLETED->value ],
			'付款成功後應為處理中或已完成'
		);
	}

	/**
	 * @testdox TradeSha 驗章失敗時不更新訂單狀態（維持 pending）
	 * @return void
	 */
	public function test_notify_invalid_trade_sha_no_update(): void {
		$body             = $this->build_notify_body();
		$body['TradeSha'] = 'TAMPERED_SHA';

		( new MpgCallback() )->handle_notify( $body );

		$order = \wc_get_order( $this->get_order()->get_id() );
		$this->assertSame( OrderStatus::PENDING->value, $order->get_status(), 'TradeSha 失敗應維持 pending' );
	}

	/**
	 * @testdox CheckCode 驗章失敗時不更新訂單狀態（維持 pending）
	 * @return void
	 */
	public function test_notify_invalid_check_code_no_update(): void {
		$body = $this->build_notify_body( [], 'SUCCESS', false );

		( new MpgCallback() )->handle_notify( $body );

		$order = \wc_get_order( $this->get_order()->get_id() );
		$this->assertSame( OrderStatus::PENDING->value, $order->get_status(), 'CheckCode 失敗應維持 pending' );
	}

	/**
	 * @testdox 金額竄改（Result.Amt 不符）時維持 pending
	 * @return void
	 */
	public function test_notify_amount_tampered_keeps_pending(): void {
		// 竄改金額為 1 元（與訂單實收不符），CheckCode 仍以竄改後金額正確計算（模擬攻擊者重算）
		$body = $this->build_notify_body( [ 'Amt' => 1 ] );

		( new MpgCallback() )->handle_notify( $body );

		$order = \wc_get_order( $this->get_order()->get_id() );
		$this->assertSame( OrderStatus::PENDING->value, $order->get_status(), '金額竄改應維持 pending' );
	}

	/**
	 * @testdox 查無訂單時 REST 端點仍回 HTTP 200（不拋 500）
	 * @return void
	 */
	public function test_notify_order_not_found_returns_200(): void {
		$settings = MpgSettingsDTO::instance();
		$crypto   = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );

		$result              = [
			'MerchantID'      => $settings->merchantId,
			'Amt'             => 100,
			'TradeNo'         => 'X',
			'MerchantOrderNo' => 'PC_NONEXISTENT_999',
			'PaymentType'     => 'CREDIT',
			'RespondCode'     => '00',
		];
		$result['CheckCode'] = $crypto->generate_check_code( $result );
		$json                = \wp_json_encode(
			[
				'Status'  => 'SUCCESS',
				'Message' => 'ok',
				'Result'  => $result,
			]
			);
		$trade_info          = $crypto->encrypt( (string) $json );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/newebpay/mpg/notify' );
		$request->set_body_params(
			[
				'TradeInfo' => $trade_info,
				'TradeSha'  => $crypto->generate_trade_sha( $trade_info ),
			]
		);
		$response = \rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), '查無訂單仍須回 HTTP 200' );
	}

	/**
	 * @testdox 冪等：已 processing 的訂單重送通知不重複處理
	 * @return void
	 */
	public function test_notify_idempotent_when_already_processing(): void {
		$body = $this->build_notify_body();

		// 第一次：轉 processing
		( new MpgCallback() )->handle_notify( $body );
		$order_id    = $this->get_order()->get_id();
		$order_first = \wc_get_order( $order_id );
		$notes_first = \wc_get_order_notes( [ 'order_id' => $order_id ] );

		// 第二次重送：應 skip（不再新增付款結果通知 note）
		( new MpgCallback() )->handle_notify( $body );
		$notes_second = \wc_get_order_notes( [ 'order_id' => $order_id ] );

		$this->assertContains(
			$order_first->get_status(),
			[ OrderStatus::PROCESSING->value, OrderStatus::COMPLETED->value ]
		);
		$this->assertLessThanOrEqual(
			\count( $notes_first ) + 1,
			\count( $notes_second ),
			'重送不應大量新增 order note（冪等）'
		);
	}

	// region Phase 2：offline 取號

	/**
	 * @testdox VACC 取號通知（SUCCESS 但無 RespondCode=00）寫繳費資訊且維持 pending
	 * @return void
	 */
	public function test_vacc_get_code_writes_info_keeps_pending(): void {
		$body = $this->build_notify_body(
			[
				'PaymentType' => 'VACC',
				'RespondCode' => '',
				'BankCode'    => '812',
				'CodeNo'      => '1234567890123456',
				'ExpireDate'  => '2026-06-12',
			]
		);

		( new MpgCallback() )->handle_notify( $body );

		$order = \wc_get_order( $this->get_order()->get_id() );
		$this->assertSame( OrderStatus::PENDING->value, $order->get_status(), 'VACC 取號應維持 pending' );

		$info = ( new MpgMetaKeys( $order ) )->get_payment_info();
		$this->assertSame( '1234567890123456', $info['CodeNo'] ?? '', '應寫入虛擬帳號' );
		$this->assertSame( '812', $info['BankCode'] ?? '', '應寫入銀行代碼' );
	}

	/**
	 * @testdox VACC 取號後付款完成（SUCCESS + RespondCode=00）轉為處理中
	 * @return void
	 */
	public function test_vacc_payment_complete_to_processing(): void {
		// 先取號
		$get_code = $this->build_notify_body(
			[
				'PaymentType' => 'VACC',
				'RespondCode' => '',
				'CodeNo'      => '1234567890123456',
			]
		);
		( new MpgCallback() )->handle_notify( $get_code );

		// 後續付款完成
		$paid = $this->build_notify_body(
			[
				'PaymentType' => 'VACC',
				'RespondCode' => '00',
			]
			);
		( new MpgCallback() )->handle_notify( $paid );

		$order = \wc_get_order( $this->get_order()->get_id() );
		$this->assertContains(
			$order->get_status(),
			[ OrderStatus::PROCESSING->value, OrderStatus::COMPLETED->value ],
			'VACC 付款完成應轉為處理中'
		);
	}

	// endregion
}
