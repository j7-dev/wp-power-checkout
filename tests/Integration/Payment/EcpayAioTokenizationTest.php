<?php
/**
 * 綠界 AIO Token 綁卡（記憶卡號 + 幕後扣款）整合測試
 *
 * 對應任務：B4a 綠界 AIO 進階金流 — Token 綁卡。
 *
 * API 出處（ECPay-API-Skill，2026-06）：
 *  - 結帳記憶卡號：guides/01-payment-aio.md §分期 / 定期定額專用參數
 *    BindingCard（1=使用 / 0=不使用）、MerchantMemberID（MerchantID + 會員編號，≤30，僅 Visa/MC/JCB）
 *  - 綁卡成功回傳：guides/03-payment-backend.md §11 CardInfo.Card6No / Card4No（末 4 碼記錄用）+ CardID/BindCardID
 *  - 回購幕後扣款（無需 PCI-DSS）：guides/03-payment-backend.md §16 綁卡代扣 CreatePaymentWithCardID
 *    端點 /Merchant/CreatePaymentWithCardID（ecpg domain，AES-JSON，背景授權不跳轉）
 *
 * 資料流：結帳勾選記憶卡號 → RequestParams 帶 BindingCard=1 + MerchantMemberID →
 *         綠界 ReturnURL 回 CardID/Card4No → 存 WC_Payment_Token_CC（綁該 user）→
 *         回購以 CardID 呼叫 CreatePaymentWithCardID 幕後扣款。
 *
 * MOCK 模式攔截幕後扣款 API。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\BackgroundChargeClient;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\TokenService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO Token 綁卡測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group tokenization
 */
final class EcpayAioTokenizationTest extends TestCase {

	/** @var string 綠界測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** @var int 測試用 user id */
	private int $user_id = 0;

	/** 每次測試前啟用 AIO（test 模式 + 測試帳號），開啟 MOCK，建立 user */
	protected function configure_dependencies(): void {
		putenv( 'API_MODE=mock' );
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
		$this->user_id = self::factory()->user->create( [ 'role' => 'customer' ] );
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		// 清理該 user 的所有 payment token
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $this->user_id, AioRedirectGateway::ID ) as $token ) {
			\WC_Payment_Tokens::delete( $token->get_id() );
		}
		putenv( 'API_MODE' );
		delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立綠界信用卡訂單（綁該 user）
	 *
	 * @param bool  $bind_card 是否勾選記憶卡號
	 * @param float $total     訂單金額
	 * @return \WC_Order
	 */
	private function create_order( bool $bind_card, float $total = 1000 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => $total,
			]
		);
		$order->set_customer_id( $this->user_id );
		if ( $bind_card ) {
			$order->update_meta_data( '_pc_ecpay_bind_card', 'yes' );
		}
		$order->save();
		return $order;
	}

	// ========== gateway 支援 tokenization ==========

	/**
	 * AIO gateway $supports 含 tokenization
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_gateway支援tokenization(): void {
		$gateway = new AioRedirectGateway();
		$this->assertContains( 'tokenization', $gateway->supports );
	}

	// ========== 結帳記憶卡號（BindingCard + MerchantMemberID） ==========

	/**
	 * 勾選記憶卡號 + 登入會員時，建單帶 BindingCard=1 + MerchantMemberID
	 *
	 * @test
	 * @group happy
	 */
	public function test_勾選記憶卡號時建單帶BindingCard與MerchantMemberID(): void {
		// Given: 勾選記憶卡號的信用卡訂單
		$order = $this->create_order( true );
		( new EcpayMetaKeys( $order ) )->update_credit_variant( '' ); // 一般信用卡
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: BindingCard=1，MerchantMemberID = MerchantID + user_id
		$this->assertSame( 1, $form['BindingCard'] );
		$this->assertSame( '3002607' . $this->user_id, $form['MerchantMemberID'] );
	}

	/**
	 * 未勾選記憶卡號時不帶 BindingCard / MerchantMemberID
	 *
	 * @test
	 * @group happy
	 */
	public function test_未勾選記憶卡號時不帶BindingCard(): void {
		// Given: 未勾選記憶卡號
		$order   = $this->create_order( false );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 不送 BindingCard / MerchantMemberID
		$this->assertArrayNotHasKey( 'BindingCard', $form );
		$this->assertArrayNotHasKey( 'MerchantMemberID', $form );
	}

	/**
	 * 未登入（訪客）即使勾選也不綁卡（記憶卡號需會員系統）
	 *
	 * @test
	 * @group edge
	 */
	public function test_訪客勾選記憶卡號也不綁卡(): void {
		// Given: 訪客訂單（customer_id=0）但勾選記憶卡號
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => 1000,
			]
		);
		$order->set_customer_id( 0 );
		$order->update_meta_data( '_pc_ecpay_bind_card', 'yes' );
		$order->save();
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 訪客不綁卡
		$this->assertArrayNotHasKey( 'BindingCard', $form );
	}

	// ========== 綁卡成功回傳 → 存 WC_Payment_Token_CC ==========

	/**
	 * ReturnURL 回傳含 CardID / Card4No 時存為 WC_Payment_Token_CC
	 *
	 * @test
	 * @group happy
	 */
	public function test_綁卡成功回傳存為WC_Payment_Token_CC(): void {
		// Given: 勾選記憶卡號的訂單 + 綠界回傳綁卡資訊
		$order   = $this->create_order( true );
		$payload = [
			'RtnCode'     => '1',
			'PaymentType' => 'Credit_CreditCard',
			'CardID'      => 'CARD-TOKEN-0001',
			'Card6No'     => '431195',
			'Card4No'     => '2222',
		];

		// When: 由 payload 儲存 token
		TokenService::save_token_from_payload( $order, $payload );

		// Then: 該 user 取得一張 WC_Payment_Token_CC，末 4 碼 2222
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->user_id, AioRedirectGateway::ID );
		$this->assertCount( 1, $tokens );
		$token = reset( $tokens );
		$this->assertInstanceOf( \WC_Payment_Token_CC::class, $token );
		$this->assertSame( '2222', $token->get_last4() );
		$this->assertSame( 'CARD-TOKEN-0001', $token->get_token() );
	}

	/**
	 * 同一張卡重複綁定不產生重複 token（冪等）
	 *
	 * @test
	 * @group edge
	 */
	public function test_同卡重複綁定不產生重複token(): void {
		// Given
		$order   = $this->create_order( true );
		$payload = [
			'RtnCode'     => '1',
			'PaymentType' => 'Credit_CreditCard',
			'CardID'      => 'CARD-TOKEN-0001',
			'Card6No'     => '431195',
			'Card4No'     => '2222',
		];

		// When: 同一張卡綁兩次
		TokenService::save_token_from_payload( $order, $payload );
		TokenService::save_token_from_payload( $order, $payload );

		// Then: 僅一張 token
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->user_id, AioRedirectGateway::ID );
		$this->assertCount( 1, $tokens );
	}

	/**
	 * 付款失敗（RtnCode 非 1）或無 CardID 時不存 token
	 *
	 * @test
	 * @group error
	 */
	public function test_付款失敗或無CardID時不存token(): void {
		// Given: 付款失敗
		$order = $this->create_order( true );

		// When: RtnCode 非 1
		TokenService::save_token_from_payload(
			$order,
			[
				'RtnCode'     => '10100050',
				'PaymentType' => 'Credit_CreditCard',
				'CardID'      => 'CARD-TOKEN-0001',
				'Card4No'     => '2222',
			]
		);
		// And: 成功但無 CardID
		TokenService::save_token_from_payload(
			$order,
			[
				'RtnCode'     => '1',
				'PaymentType' => 'Credit_CreditCard',
				'Card4No'     => '2222',
			]
		);

		// Then: 無 token
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->user_id, AioRedirectGateway::ID );
		$this->assertCount( 0, $tokens );
	}

	// ========== 回購：以 CardID 幕後扣款（CreatePaymentWithCardID） ==========

	/**
	 * 以 CardID 幕後扣款（MOCK）→ 回 RtnCode=1 + 記錄 order note
	 *
	 * @test
	 * @group happy
	 */
	public function test_以CardID幕後扣款成功(): void {
		// Given: 回購訂單 + 已綁卡 CardID
		$order  = $this->create_order( false );
		$client = new BackgroundChargeClient( $order );

		// When: 以 CardID 幕後扣款
		$result = $client->charge_with_card_id( 'CARD-TOKEN-0001', 'MEMBER0001' );

		// Then: 成功，order note 記錄幕後扣款
		$this->assertSame( '1', $result['RtnCode'] );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '幕後扣款' );
	}

	/**
	 * 幕後扣款缺 CardID 時拋例外
	 *
	 * @test
	 * @group error
	 */
	public function test_幕後扣款缺CardID時拋例外(): void {
		// Given
		$order  = $this->create_order( false );
		$client = new BackgroundChargeClient( $order );

		// When / Then
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'CardID' );

		$client->charge_with_card_id( '', 'MEMBER0001' );
	}

	/**
	 * 幕後扣款回應解析：外層 TransCode=1 + 內層 RtnCode=1 為成功
	 *
	 * @test
	 * @group happy
	 */
	public function test_幕後扣款回應雙層檢查(): void {
		// Given
		$order  = $this->create_order( false );
		$client = new BackgroundChargeClient( $order );

		// When: 內層 Data 已解密的成功結構
		$result = $client->parse_decrypted_data(
			[
				'RtnCode'   => 1,
				'RtnMsg'    => 'Success',
				'OrderInfo' => [
					'TradeNo'     => '2026060512345678',
					'TradeStatus' => '1',
				],
			]
		);

		// Then
		$this->assertSame( '1', $result['RtnCode'] );
		$this->assertSame( '2026060512345678', $result['TradeNo'] );
	}

	/**
	 * 幕後扣款內層 RtnCode 非 1 → 拋例外
	 *
	 * @test
	 * @group error
	 */
	public function test_幕後扣款內層RtnCode非1拋例外(): void {
		// Given
		$order  = $this->create_order( false );
		$client = new BackgroundChargeClient( $order );

		// When / Then
		$this->expectException( \Exception::class );

		$client->parse_decrypted_data(
			[
				'RtnCode' => 10100050,
				'RtnMsg'  => '授權失敗',
			]
		);
	}
}
