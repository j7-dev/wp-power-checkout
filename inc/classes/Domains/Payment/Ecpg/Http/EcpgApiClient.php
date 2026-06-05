<?php
/**
 * 綠界站內付 2.0（ECPG）API client
 *
 * 三層請求結構：{ MerchantID, RqHeader{ Timestamp }, Data: AES-128-CBC 加密(JSON) }
 *  - 站內付 2.0 的 RqHeader 只有 Timestamp（Unix 秒），無 Revision（與發票 / 物流不同）。
 *  - MerchantID 外層與 Data 內層各一份，兩處皆須填。
 *  - Data 加密用 aesUrlEncode（urlencode，不做 lowercase / .NET 替換），標準 Base64 alphabet。
 *
 * 雙層錯誤檢查（AES-JSON 回應）：
 *  1. 傳輸層 TransCode（整數，外層）=== 1 才可解密 Data；非 1 → throw + order note。
 *  2. 業務層 RtnCode（整數，解密後 Data 內）=== 1 才視為成功；非 1 → throw + order note。
 *
 * 雙 Domain：
 *  - GetTokenbyTrade / CreatePayment 走 ecpg domain（$settings->tokenEndpoint）。
 *  - 查詢 / 退款走 ecpayment domain（階段四）。混用會 404。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\Http;

use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\CreatePaymentParams;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\GetTokenParams;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\TradeNo;
use J7\PowerCheckout\Plugin;

/** 綠界站內付 2.0 API client */
final class EcpgApiClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string GetTokenbyTrade 端點路徑（ecpg domain） */
	private const PATH_GET_TOKEN = '/Merchant/GetTokenbyTrade';

	/** @var string CreatePayment 端點路徑（ecpg domain） */
	private const PATH_CREATE_PAYMENT = '/Merchant/CreatePayment';

	/** @var string 信用卡請退款 DoAction 端點路徑（ecpayment domain，非 ecpg） */
	private const PATH_DO_ACTION = '/1.0.0/Credit/DoAction';

	/** @var int ATM 預設繳費有效天數（綠界 ATMInfo.ExpireDate，1~60，預設 3） */
	private const ATM_EXPIRE_DAYS = 3;

	/** @var int CVS 預設逾期分鐘數（綠界 CVSInfo.StoreExpireDate，預設 10080=7 天） */
	private const CVS_EXPIRE_MINUTES = 10080;

	/** @var int BARCODE 預設繳費有效天數（綠界 BarcodeInfo.StoreExpireDate，預設 7，最長 30） */
	private const BARCODE_EXPIRE_DAYS = 7;

	/** @var EcpgSettingsDTO 設定 */
	private readonly EcpgSettingsDTO $settings;

	/** @var AesCrypto 加解密器 */
	private readonly AesCrypto $crypto;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order,
	) {
		$this->settings = EcpgSettingsDTO::instance();
		$this->crypto   = new AesCrypto( $this->settings->hashKey, $this->settings->hashIv );
	}

	/**
	 * 呼叫 GetTokenbyTrade 取得交易 token
	 *
	 * 流程：組裝 Data（含 ConsumerInfo Email/Phone）→ 寫入冪等鍵 MerchantTradeNo →
	 * AES 加密送 ecpg domain → 雙層檢查（TransCode → RtnCode）→ 回傳解密後 Data（含 Token）。
	 *
	 * ConsumerInfo 缺 Email/Phone 時 GetTokenParams::validate() 會 throw。
	 *
	 * @return array<string, mixed> 解密後的 Data（含 Token / TokenExpireDate）
	 * @throws \Exception 取 token 失敗（傳輸層 / 業務層 / ConsumerInfo 缺漏）
	 */
	public function get_token(): array {
		$meta_keys = new EcpayMetaKeys( $this->order );

		// 冪等：沿用既有 MerchantTradeNo（重試取 token 不重新編號），首次則生成並寫入
		$trade_no = $meta_keys->get_trade_no();
		if ('' === $trade_no) {
			$trade_no = TradeNo::encode( $this->order->get_id() );
			$meta_keys->update_trade_no( $trade_no );
		}

		$params = $this->build_get_token_params( $trade_no, EcpayPaymentMethod::CREDIT );

		// MOCK 模式：不打真 API，回固定 fixture
		if (self::is_mock()) {
			return $this->mock_get_token_response( $trade_no );
		}

		$url       = $this->settings->tokenEndpoint . self::PATH_GET_TOKEN;
		$decrypted = $this->request( $url, $params->to_array() );

		$token = (string) ( $decrypted['Token'] ?? '' );
		if ('' === $token) {
			$msg = '綠界站內付 GetTokenbyTrade 回應缺少 Token';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return $decrypted;
	}

	/**
	 * 非信用卡幕後取號（ATM / CVS / BARCODE）
	 *
	 * 與信用卡的關鍵差異（官方規格 02b-ecpg-atm-cvs-spa.md）：
	 *  - 不需 JS SDK：CreatePayment 的 PayToken 直接填 GetTokenbyTrade 回傳的 Token（跳過前端收卡）。
	 *  - 一氣呵成在後端完成：GetToken（帶非信用卡 ChoosePaymentList + 對應 Info）→ CreatePayment → 取得取號資訊。
	 *  - CreatePayment 回應無 ThreeDURL，而是巢狀取號資訊：
	 *      ATM：ATMInfo{BankCode, vAccount, ExpireDate}
	 *      CVS：CVSInfo{PaymentNo, ExpireDate}
	 *      BARCODE：BarcodeInfo{Barcode1, Barcode2, Barcode3, ExpireDate}
	 *    RtnCode=1 代表「取號成功」（非付款成功）；訂單應維持待付款，待消費者實際繳費後 ReturnURL 才轉付款完成。
	 *
	 * 冪等：沿用既有 MerchantTradeNo（重試取號不重新編號），首次則生成並寫入。
	 *
	 * @param EcpayPaymentMethod $payment 付款方式（限 ATM / CVS / BARCODE）
	 *
	 * @return array<string, mixed> CreatePayment 解密後的 Data（含 ATMInfo / CVSInfo / BarcodeInfo + OrderInfo）
	 * @throws \Exception 非取號付款方式 / 取 token 失敗 / 取號失敗（傳輸層 / 業務層）
	 * @see .claude/skills/ECPay-API-Skill/guides/02b-ecpg-atm-cvs-spa.md
	 * @see https://developers.ecpay.com.tw/9053.md（CreatePayment 非信用卡取號回應欄位）
	 */
	public function get_code( EcpayPaymentMethod $payment ): array {
		if ( ! $payment->is_get_code_payment() ) {
			throw new \Exception( "綠界站內付幕後取號不支援付款方式：{$payment->value}" );
		}

		$meta_keys = new EcpayMetaKeys( $this->order );

		// 冪等：沿用既有 MerchantTradeNo，首次則生成並寫入
		$trade_no = $meta_keys->get_trade_no();
		if ( '' === $trade_no ) {
			$trade_no = TradeNo::encode( $this->order->get_id() );
			$meta_keys->update_trade_no( $trade_no );
		}

		// 步驟 1：GetTokenbyTrade（帶非信用卡 ChoosePaymentList + 對應 Info）取得 Token
		$params = $this->build_get_token_params( $trade_no, $payment );

		// MOCK 模式：不打真 API，回固定取號 fixture
		if ( self::is_mock() ) {
			return $this->mock_get_code_response( $trade_no, $payment );
		}

		$token_url = $this->settings->tokenEndpoint . self::PATH_GET_TOKEN;
		$token_res = $this->request( $token_url, $params->to_array() );
		$token     = (string) ( $token_res['Token'] ?? '' );
		if ( '' === $token ) {
			$msg = '綠界站內付幕後取號 GetTokenbyTrade 回應缺少 Token';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		// 步驟 2：CreatePayment（PayToken 直接用 GetToken 回傳的 Token，跳過 JS SDK）→ 取得取號資訊
		$create_params = new CreatePaymentParams(
			[
				'MerchantID'      => $this->settings->merchantId,
				'PayToken'        => $token,
				'MerchantTradeNo' => $trade_no,
			]
		);
		$create_url    = $this->settings->tokenEndpoint . self::PATH_CREATE_PAYMENT; // 同屬 ecpg domain
		return $this->request( $create_url, $create_params->to_array() );
	}

	/**
	 * 呼叫 CreatePayment 以 token + 卡片綁定結果建立付款
	 *
	 * 前端站內付元件收集卡片後取得 PayToken，送回後端呼叫此方法。
	 * 解析回應的 ThreeDInfo.ThreeDURL：非空 → 回傳需前端導向 3DS 的結構；空 → 等 ReturnURL。
	 *
	 * @param string $pay_token        前端站內付元件取得的 PayToken
	 * @param string $merchant_trade_no 須與 GetTokenbyTrade 相同的 MerchantTradeNo
	 *
	 * @return array{three_d_url: string, need_3ds: bool, data: array<string, mixed>}
	 * @throws \Exception 建立付款失敗（傳輸層 / 業務層）
	 */
	public function create_payment( string $pay_token, string $merchant_trade_no ): array {
		$params = new CreatePaymentParams(
			[
				'MerchantID'      => $this->settings->merchantId,
				'PayToken'        => $pay_token,
				'MerchantTradeNo' => $merchant_trade_no,
			]
		);

		// MOCK 模式：不打真 API，回固定 fixture（預設帶 ThreeDURL，模擬 3DS 流程）
		if (self::is_mock()) {
			$decrypted = $this->mock_create_payment_response( $merchant_trade_no );
		} else {
			$url       = $this->settings->tokenEndpoint . self::PATH_CREATE_PAYMENT; // CreatePayment 同屬 ecpg domain
			$decrypted = $this->request( $url, $params->to_array() );
		}

		// ThreeDURL 為巢狀結構 data.ThreeDInfo.ThreeDURL（非扁平 data.ThreeDURL）
		$three_d_info = $decrypted['ThreeDInfo'] ?? [];
		$three_d_url  = \is_array( $three_d_info ) ? (string) ( $three_d_info['ThreeDURL'] ?? '' ) : '';

		return [
			'three_d_url' => $three_d_url,
			'need_3ds'    => '' !== $three_d_url,
			'data'        => $decrypted,
		];
	}

	/**
	 * 信用卡退款（DoAction Action=R，走 ecpayment domain）
	 *
	 * ⚠️ 雙 Domain：退款端點在 ecpayment domain（$settings->paymentEndpoint），非 ecpg，混用會 404。
	 * ⚠️ 金額一律來自呼叫端傳入的 WC refund 物件，非前端。TradeNo 為綠界回傳的交易編號，非 MerchantTradeNo。
	 * ⚠️ 官方規格（9073.md）：測試環境因無法實際授權故無法使用此 API，僅正式環境可退款。
	 *
	 * @param string $merchant_trade_no MerchantTradeNo（特店交易編號）
	 * @param string $trade_no          綠界交易編號 TradeNo
	 * @param float  $amount            欲退款金額（來自 WC refund 物件）
	 *
	 * @return array<string, mixed> 解密後的 Data（業務層成功，RtnCode=1）
	 * @throws \Exception 缺 TradeNo / 傳輸層 / 業務層失敗
	 */
	public function refund( string $merchant_trade_no, string $trade_no, float $amount ): array {
		if ( '' === $trade_no ) {
			$msg = '綠界站內付退款缺少 TradeNo（綠界交易編號）';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$data = [
			'PlatformID'      => '', // 一般商店填空字串
			'MerchantID'      => $this->settings->merchantId,
			'MerchantTradeNo' => $merchant_trade_no,
			'TradeNo'         => $trade_no,
			'Action'          => 'R', // R=退款
			// 綠界僅收新台幣整數，無條件進位避免少退
			'TotalAmount'     => (int) \ceil( $amount ),
		];

		// MOCK 模式：不打真 API（測試環境連 Stage 都不可用），回固定 fixture
		if ( self::is_mock() ) {
			return [
				'RtnCode'         => 1,
				'RtnMsg'          => 'Success',
				'MerchantID'      => $this->settings->merchantId,
				'MerchantTradeNo' => $merchant_trade_no,
				'TradeNo'         => $trade_no,
			];
		}

		// 退款走 ecpayment domain（非 ecpg）
		$url = $this->settings->paymentEndpoint . self::PATH_DO_ACTION;
		return $this->request( $url, $data );
	}

	/**
	 * 發送 AES-JSON 請求並做雙層錯誤檢查
	 *
	 * @param string               $url  端點 URL
	 * @param array<string, mixed> $data 內層 Data 明文
	 *
	 * @return array<string, mixed> 解密後的 Data
	 * @throws \Exception 傳輸層（TransCode≠1）或業務層（RtnCode≠1）失敗
	 */
	private function request( string $url, array $data ): array {
		$envelope = $this->build_envelope( $data );

		Plugin::logger(
			"綠界站內付 2.0 請求 #{$this->order->get_id()}",
			'info',
			[ 'url' => $url ]
		);

		$response = \wp_remote_post(
			$url,
			[
				'body'     => (string) \wp_json_encode( $envelope ),
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if (\is_wp_error( $response )) {
			$msg = "綠界站內付 2.0 連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		/** @var array{TransCode?: int|string, TransMsg?: string, Data?: string} $body */
		$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

		return $this->parse_response( $body );
	}

	/**
	 * 解析回應：雙層錯誤檢查（TransCode 傳輸層 → 解密 Data → RtnCode 業務層）
	 *
	 * 拆為獨立方法以利測試（不需真 HTTP）。
	 *
	 * @param array{TransCode?: int|string, TransMsg?: string, Data?: string} $body 外層回應
	 *
	 * @return array<string, mixed> 解密後的 Data
	 * @throws \Exception 傳輸層或業務層失敗
	 */
	public function parse_response( array $body ): array {
		// 第一層：傳輸層 TransCode（整數 1=成功）
		$trans_code = (int) ( $body['TransCode'] ?? 0 );
		if (1 !== $trans_code) {
			$trans_msg = (string) ( $body['TransMsg'] ?? 'unknown' );
			$msg       = "綠界站內付傳輸層失敗 TransCode={$trans_code}：{$trans_msg}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		// 解密 Data
		$decrypted = $this->crypto->decrypt( (string) ( $body['Data'] ?? '' ) );

		// 第二層：業務層 RtnCode（整數 1=成功）
		$rtn_code = (int) ( $decrypted['RtnCode'] ?? 0 );
		if (1 !== $rtn_code) {
			$rtn_msg = (string) ( $decrypted['RtnMsg'] ?? 'unknown' );
			$msg     = "綠界站內付業務層失敗 RtnCode={$rtn_code}：{$rtn_msg}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return $decrypted;
	}

	/**
	 * 組裝 GetTokenbyTrade 內層 Data 參數
	 *
	 * 依付款方式組裝對應的 ChoosePaymentList 與付款方式專屬 Info：
	 *  - 信用卡（Credit）：ChoosePaymentList='1'，帶 CardInfo.OrderResultURL（3DS 完成後導回）。
	 *  - ATM（'3'）：帶 ATMInfo.ExpireDate（繳費天數）。
	 *  - CVS（'4'）：帶 CVSInfo.StoreExpireDate（逾期分鐘數）。
	 *  - BARCODE（'5'）：帶 BarcodeInfo.StoreExpireDate（繳費天數）。
	 *
	 * 非信用卡不帶 CardInfo（綠界規格：ATM/CVS/Barcode 不需 OrderResultURL）。空 Info 由 array_filter 濾除，
	 * 避免送出與付款方式無關的空欄位。
	 *
	 * @param string             $trade_no MerchantTradeNo
	 * @param EcpayPaymentMethod $payment  付款方式
	 *
	 * @return GetTokenParams
	 * @throws \Exception ConsumerInfo 缺 Email/Phone 時
	 * @see .claude/skills/ECPay-API-Skill/guides/02a-ecpg-quickstart.md §GetTokenbyTrade Data 必填欄位速查
	 */
	private function build_get_token_params( string $trade_no, EcpayPaymentMethod $payment ): GetTokenParams {
		$return_url = EcpgCallback::get_return_url();

		$data = [
			'MerchantID'        => $this->settings->merchantId,
			'RememberCard'      => 0,
			'PaymentUIType'     => 2,
			'ChoosePaymentList' => self::choose_payment_list( $payment ),
			'OrderInfo'         => [
				'MerchantTradeDate' => \wp_date( 'Y/m/d H:i:s' ) ?: \gmdate( 'Y/m/d H:i:s', \time() + 8 * 3600 ),
				'MerchantTradeNo'   => $trade_no,
				'TotalAmount'       => (int) \ceil( (float) $this->order->get_total() ),
				'ReturnURL'         => $return_url,
				'TradeDesc'         => "Order #{$this->order->get_id()}",
				'ItemName'          => ItemName::get( $this->order ),
			],
			'ConsumerInfo'      => $this->build_consumer_info(),
		];

		// 付款方式專屬欄位（信用卡 CardInfo；ATM/CVS/BARCODE 各自的取號 Info）
		$data = \array_merge( $data, $this->build_payment_specific_info( $payment ) );

		return new GetTokenParams( $data );
	}

	/**
	 * 由付款方式取得綠界 ChoosePaymentList 值（字串，非整數）
	 *
	 * @param EcpayPaymentMethod $payment 付款方式
	 *
	 * @return string '1'=信用卡 / '3'=ATM / '4'=CVS / '5'=BARCODE
	 * @see .claude/skills/ECPay-API-Skill/guides/02a-ecpg-quickstart.md §ChoosePaymentList
	 */
	private static function choose_payment_list( EcpayPaymentMethod $payment ): string {
		return match ( $payment ) {
			EcpayPaymentMethod::ATM => '3',
			EcpayPaymentMethod::CVS => '4',
			EcpayPaymentMethod::BARCODE => '5',
			default => '1', // 信用卡
		};
	}

	/**
	 * 組裝付款方式專屬欄位（CardInfo / ATMInfo / CVSInfo / BarcodeInfo）
	 *
	 * @param EcpayPaymentMethod $payment 付款方式
	 *
	 * @return array<string, mixed>
	 */
	private function build_payment_specific_info( EcpayPaymentMethod $payment ): array {
		return match ( $payment ) {
			EcpayPaymentMethod::ATM => [
				'ATMInfo' => [ 'ExpireDate' => self::ATM_EXPIRE_DAYS ],
			],
			EcpayPaymentMethod::CVS => [
				'CVSInfo' => [ 'StoreExpireDate' => self::CVS_EXPIRE_MINUTES ],
			],
			EcpayPaymentMethod::BARCODE => [
				'BarcodeInfo' => [ 'StoreExpireDate' => self::BARCODE_EXPIRE_DAYS ],
			],
			// 信用卡：3DS 完成後導回 order-received 頁
			default => [
				'CardInfo' => [ 'OrderResultURL' => $this->order->get_checkout_order_received_url() ],
			],
		};
	}

	/**
	 * 由訂單 billing 組裝 ConsumerInfo（Email / Phone 必填其一）
	 *
	 * @return array<string, mixed>
	 */
	private function build_consumer_info(): array {
		$consumer = [
			'Email'       => $this->order->get_billing_email(),
			'Phone'       => $this->order->get_billing_phone(),
			'Name'        => \trim( $this->order->get_billing_first_name() . $this->order->get_billing_last_name() ),
			'CountryCode' => '158', // 台灣（ISO 3166-1 numeric）
		];

		// 過濾空值（綠界不收空字串欄位；Email/Phone 由 GetTokenParams::validate 把關至少一個非空）
		return \array_filter( $consumer, static fn( $value ) => '' !== (string) $value );
	}

	/**
	 * 組裝三層請求結構
	 *
	 * 站內付 2.0 的 RqHeader 只有 Timestamp（Unix 秒），無 Revision。
	 *
	 * @param array<string, mixed> $data 內層 Data 明文
	 *
	 * @return array<string, mixed>
	 */
	private function build_envelope( array $data ): array {
		return [
			'MerchantID' => $this->settings->merchantId,
			'RqHeader'   => [
				'Timestamp' => \time(),
			],
			'Data'       => $this->crypto->encrypt( $data ),
		];
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	/**
	 * MOCK：GetTokenbyTrade 回應（固定 fixture）
	 *
	 * @param string $trade_no MerchantTradeNo
	 *
	 * @return array<string, mixed>
	 */
	private function mock_get_token_response( string $trade_no ): array {
		return [
			'RtnCode'         => 1,
			'RtnMsg'          => 'Success',
			'MerchantID'      => $this->settings->merchantId,
			'MerchantTradeNo' => $trade_no,
			'Token'           => 'mock_token_' . $trade_no,
			'TokenExpireDate' => \gmdate( 'Y/m/d H:i:s', \time() + 3600 ),
		];
	}

	/**
	 * MOCK：CreatePayment 回應（固定 fixture，預設帶 ThreeDURL 模擬 3DS）
	 *
	 * @param string $trade_no MerchantTradeNo
	 *
	 * @return array<string, mixed>
	 */
	private function mock_create_payment_response( string $trade_no ): array {
		return [
			'RtnCode'    => 1,
			'RtnMsg'     => 'Success',
			'MerchantID' => $this->settings->merchantId,
			'OrderInfo'  => [
				'MerchantTradeNo' => $trade_no,
			],
			'ThreeDInfo' => [
				'ThreeDURL' => "https://ecpayment-stage.ecpay.com.tw/Cashier/3DVerify?tk=mock_{$trade_no}",
			],
		];
	}

	/**
	 * MOCK：非信用卡幕後取號回應（固定 fixture，依付款方式回對應取號資訊）
	 *
	 * 對齊官方規格 9053.md CreatePayment 非信用卡回應的巢狀結構：
	 *  - ATM：ATMInfo{BankCode, vAccount, ExpireDate}
	 *  - CVS：CVSInfo{PaymentNo, ExpireDate}
	 *  - BARCODE：BarcodeInfo{Barcode1, Barcode2, Barcode3, ExpireDate}
	 *
	 * @param string             $trade_no MerchantTradeNo
	 * @param EcpayPaymentMethod $payment  付款方式（ATM / CVS / BARCODE）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_get_code_response( string $trade_no, EcpayPaymentMethod $payment ): array {
		$base = [
			'RtnCode'    => 1, // 取號成功（非付款成功）
			'RtnMsg'     => '取號成功',
			'MerchantID' => $this->settings->merchantId,
			'OrderInfo'  => [
				'MerchantTradeNo' => $trade_no,
				'TradeNo'         => 'mock' . \substr( $trade_no, -10 ),
				'TradeAmt'        => (int) \ceil( (float) $this->order->get_total() ),
				'PaymentType'     => $payment->value,
			],
		];

		$specific = match ( $payment ) {
			EcpayPaymentMethod::ATM => [
				'ATMInfo' => [
					'BankCode'   => '812',
					'vAccount'   => '9103522850',
					'ExpireDate' => \gmdate( 'Y/m/d', \time() + self::ATM_EXPIRE_DAYS * 86400 ),
				],
			],
			EcpayPaymentMethod::CVS => [
				'CVSInfo' => [
					'PaymentNo'  => 'LLL' . \substr( $trade_no, -8 ),
					'ExpireDate' => \gmdate( 'Y/m/d H:i:s', \time() + self::CVS_EXPIRE_MINUTES * 60 ),
				],
			],
			EcpayPaymentMethod::BARCODE => [
				'BarcodeInfo' => [
					'Barcode1'   => '110501',
					'Barcode2'   => '3453010377003472',
					'Barcode3'   => '040000000100000',
					'ExpireDate' => \gmdate( 'Y/m/d H:i:s', \time() + self::BARCODE_EXPIRE_DAYS * 86400 ),
				],
			],
			default => [],
		};

		return \array_merge( $base, $specific );
	}
}
