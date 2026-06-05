<?php
/**
 * 綠界全方位物流 v2（AllInOne）服務提供者
 *
 * 統一抽象 ILogisticsProvider 的 ECPay 實作（與 PayuniLogisticsProvider 並存可切換）。
 * account_type 切換 B2C / C2C 兩組憑證。
 *
 * 已實作完整流程：選店（get_store_selection → parse_store_selection）、
 * 建單（create_shipment / CreateByTempTrade）、查詢、列印、取消（C2C）、
 * 逆物流退貨（create_return）與貨態 callback（handle_status_callback）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\Services;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateShipmentParams;
use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\StoreSelectionParams;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsAccountType;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsStatus;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsSubType;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsTemperature;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 綠界全方位物流 v2 服務提供者 */
final class EcpayLogisticsProvider extends BaseService implements ILogisticsProvider {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const ID = 'ecpay_logistics';

	/**
	 * 記錄 log
	 * info, error, warning 會同步記錄到 order note
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級 info | error | alert | critical | debug | emergency | warning | notice
	 * @param array<string, mixed> $args        附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 * @param \WC_Order|null       $order       是否紀錄在 order note
	 */
	public static function logger( string $message, string $level = 'debug', array $args = [], int $trace_limit = 0, \WC_Order|null $order = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, 'power_checkout_' . self::ID, $trace_limit );
		if (!$order) {
			return;
		}

		if ($args) {
			$message .= "<p style='margin-bottom: 0;'>&nbsp;</p>";
		}

		$order_note = \J7\WpUtils\Classes\WP::array_to_html( $args, [ 'title' => $message ] );
		$order->add_order_note( $order_note );
	}

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否帶預設值（false = 只拿 DB 值）
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = \J7\PowerCheckout\Shared\Utils\ProviderUtils::get_option( self::ID );
			/** @var array<string, mixed> $option */
			$option = \is_array( $option ) ? $option : [];
			return $option;
		}
		return EcpayLogisticsSettingsDTO::instance()->to_array();
	}

	/**
	 * 階段 A：取得門市選擇導轉資訊（RWD 選店頁）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 第三階段實作
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		$settings = EcpayLogisticsSettingsDTO::instance();
		$sub_type = (string) ( $ctx['sub_type'] ?? '' );
		$scenario = (string) ( $ctx['payment_scenario'] ?? LogisticsPaymentScenario::ONLINE->value );

		// 前置驗證 1：provider 必須啟用
		if (!ProviderUtils::is_enabled( self::ID )) {
			throw new \Exception( \__( '綠界全方位物流未啟用', 'power_checkout' ) );
		}

		// 前置驗證 2：訂單必須存在
		$this->assert_order_exists( $order );

		// 前置驗證 3：reply URL 不可為 localhost（R6，僅 80/443 + 公開可訪問）
		$this->assert_public_reply_url( $settings->server_reply_url );
		$this->assert_public_reply_url( $settings->client_reply_url );

		// 前置驗證 4：sub_type 必須為已啟用的綠界物流子類型
		if (!\in_array( $sub_type, $this->get_supported_methods(), true )) {
			throw new \Exception(
				\__( '運送方式必須為已啟用的綠界物流子類型', 'power_checkout' )
			);
		}

		// 組裝 RedirectToLogisticsSelection 內層 Data
		$is_collection = LogisticsPaymentScenario::COD->value === $scenario;
		$total         = (int) \round( (float) $order->get_total() );

		$data = [
			'TempLogisticsID'  => '0',
			'GoodsAmount'      => $total,
			'GoodsName'        => $this->get_goods_name( $order ),
			'SenderName'       => $settings->sender_name ?: '寄件人',
			'SenderZipCode'    => $settings->sender_zip_code,
			'SenderAddress'    => $settings->sender_address,
			'ServerReplyURL'   => $settings->server_reply_url,
			// 防 IDOR：ClientReplyURL 編入 pc_oid + pc_key（order_key），回呼時 timing-safe 驗證
			'ClientReplyURL'   => LogisticsCallback::build_client_reply_url( $settings->client_reply_url, $order ),
			'LogisticsSubType' => $sub_type,
			'IsCollection'     => $is_collection ? 'Y' : 'N',
			'CollectionAmount' => $is_collection ? $total : 0,
			'Temperature'      => $this->get_temperature( $order, $sub_type ),
		];

		$params = StoreSelectionParams::parse( $data );
		$html   = ( new LogisticsApiClient( $order ) )->redirect_to_logistics_selection( $params );

		return [ 'redirect_target' => $html ];
	}

	/**
	 * 購物車（cart）級選店：取得門市選擇導轉資訊（結帳「下單前」，無訂單）
	 *
	 * 第一性原理：超商選店發生在下單前，此時無 WC 訂單，故不能用 order_key 綁定。
	 * 改以 CartLogisticsSession 產生「不可猜測的 session 權杖」綁定當前 cart session，
	 * 編入 ClientReplyURL（pc_st）；選店回呼以權杖 timing-safe 驗證後寫入 session（非 order meta）。
	 *
	 * 商品金額 / 名稱改由 WC()->cart 取得（無訂單）。
	 *
	 * @param array<string, mixed> $ctx 上下文（sub_type / payment_scenario）
	 *
	 * @return array<string, mixed> 含 redirect_target（RWD 選店頁 HTML）
	 * @throws \Exception 前置驗證 / 傳輸層失敗
	 */
	public function get_cart_store_selection( array $ctx = [] ): array {
		$settings = EcpayLogisticsSettingsDTO::instance();
		$sub_type = (string) ( $ctx['sub_type'] ?? '' );
		$scenario = (string) ( $ctx['payment_scenario'] ?? LogisticsPaymentScenario::ONLINE->value );

		// 前置驗證 1：provider 必須啟用
		if (!ProviderUtils::is_enabled( self::ID )) {
			throw new \Exception( \__( '綠界全方位物流未啟用', 'power_checkout' ) );
		}

		// 前置驗證 2：reply URL 不可為 localhost（R6）
		$this->assert_public_reply_url( $settings->server_reply_url );
		$this->assert_public_reply_url( $settings->client_reply_url );

		// 前置驗證 3：sub_type 必須為已啟用的綠界物流子類型
		if (!\in_array( $sub_type, $this->get_supported_methods(), true )) {
			throw new \Exception(
				\__( '運送方式必須為已啟用的綠界物流子類型', 'power_checkout' )
			);
		}

		// 產生並綁定 cart 級選店權杖（存入 WC session + 建立 token→customer_id 索引）
		$token = \J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession::issue_token();

		// 商品金額 / 名稱由購物車取得（下單前無訂單）
		$total      = $this->get_cart_total();
		$goods_name = $this->get_cart_goods_name();

		$is_collection = LogisticsPaymentScenario::COD->value === $scenario;

		$data = [
			'TempLogisticsID'  => '0',
			'GoodsAmount'      => $total,
			'GoodsName'        => $goods_name,
			'SenderName'       => $settings->sender_name ?: '寄件人',
			'SenderZipCode'    => $settings->sender_zip_code,
			'SenderAddress'    => $settings->sender_address,
			'ServerReplyURL'   => $settings->server_reply_url,
			// cart 級綁定：ClientReplyURL 編入 pc_st（session 權杖），回呼時 timing-safe 驗證
			'ClientReplyURL'   => LogisticsCallback::build_cart_reply_url( $settings->client_reply_url, $token ),
			'LogisticsSubType' => $sub_type,
			'IsCollection'     => $is_collection ? 'Y' : 'N',
			'CollectionAmount' => $is_collection ? $total : 0,
		];

		$params = StoreSelectionParams::parse( $data );
		// cart 級無訂單，client 以 null order 建構（HTML 回應路徑不需 order note）
		$html = ( new LogisticsApiClient( null ) )->redirect_to_logistics_selection( $params );

		return [ 'redirect_target' => $html ];
	}

	/**
	 * 取得購物車商品總額（整數新台幣；無購物車回 0）
	 *
	 * @return int
	 */
	private function get_cart_total(): int {
		$cart = self::get_wc_cart();
		if (null === $cart) {
			return 0;
		}
		return (int) \round( (float) $cart->get_total( 'edit' ) );
	}

	/**
	 * 取得購物車商品名稱（合併品項，截斷至 50 字；無購物車回預設）
	 *
	 * @return string
	 */
	private function get_cart_goods_name(): string {
		$cart = self::get_wc_cart();
		if (null === $cart) {
			return '購物車商品';
		}

		$names = [];
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ($product instanceof \WC_Product) {
				$names[] = $product->get_name();
			}
		}
		$goods_name = \implode( '#', $names );
		if ('' === $goods_name) {
			$goods_name = '購物車商品';
		}
		return \mb_substr( $goods_name, 0, 50 );
	}

	/**
	 * 取得 WC 購物車（未初始化 / 無購物車回 null）
	 *
	 * WC()->cart 於 stub 型別為非 nullable，但執行期在 REST / 後台情境可能為 null，
	 * 故經區域變數取得後以 instanceof 收斂，避免 stub 型別誤判。
	 *
	 * @return \WC_Cart|null
	 */
	private static function get_wc_cart(): ?\WC_Cart {
		if (!\function_exists( 'WC' )) {
			return null;
		}
		/** @var \WC_Cart|null $cart */
		$cart = \WC()->cart;
		return $cart instanceof \WC_Cart ? $cart : null;
	}

	/**
	 * 解析選店回呼（ClientReplyURL）
	 *
	 * @param array<string, mixed> $raw 綠界 Form POST 原始資料
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 第三階段實作
	 */
	public function parse_store_selection( array $raw ): array {
		$result_data = (string) ( $raw['ResultData'] ?? '' );
		if ('' === \trim( $result_data )) {
			throw new \Exception( \__( '選店結果為空', 'power_checkout' ) );
		}

		$settings = EcpayLogisticsSettingsDTO::instance();
		$crypto   = new \J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto(
			$settings->get_active_hash_key(),
			$settings->get_active_hash_iv()
		);

		// 解密失敗會 throw RuntimeException，由呼叫端 catch
		$decrypted = $crypto->decrypt( $result_data );

		return [
			'temp_id'    => (string) ( $decrypted['TempLogisticsID'] ?? '' ),
			'store_id'   => (string) ( $decrypted['CVSStoreID'] ?? '' ),
			'store_name' => (string) ( $decrypted['CVSStoreName'] ?? '' ),
			'store_addr' => (string) ( $decrypted['CVSAddress'] ?? '' ),
			'sub_type'   => (string) ( $decrypted['LogisticsSubType'] ?? '' ),
		];
	}

	/**
	 * 階段 B：成立物流單（CreateByTempTrade）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 第三階段實作
	 */
	public function create_shipment( \WC_Order $order ): array {
		$meta    = new LogisticsMetaKeys( $order );
		$temp_id = $meta->get_temp_id();

		// 前置驗證：須先完成選店（有 TempLogisticsID）
		if ('' === \trim( $temp_id )) {
			throw new \Exception( \__( '尚未選店，無暫存物流單', 'power_checkout' ) );
		}

		$params    = CreateShipmentParams::parse( [ 'TempLogisticsID' => $temp_id ] );
		$decrypted = ( new LogisticsApiClient( $order ) )->create_by_temp_trade( $params );

		$logistics_id = (string) ( $decrypted['LogisticsID'] ?? '' );
		if ('' === $logistics_id) {
			throw new \Exception( \__( '綠界未回傳 LogisticsID，成立物流單失敗', 'power_checkout' ) );
		}

		// 寫入統一物流單號（下游主鍵）
		$meta->update_ref( $logistics_id );
		$meta->update_provider_id( self::ID );

		// C2C 帳號：保存寄貨編號 / 驗證碼（取消單需用）
		$settings = EcpayLogisticsSettingsDTO::instance();
		if (LogisticsAccountType::C2C->value === $settings->account_type) {
			$cvs_payment_no    = (string) ( $decrypted['CVSPaymentNo'] ?? '' );
			$cvs_validation_no = (string) ( $decrypted['CVSValidationNo'] ?? '' );
			if ('' !== $cvs_payment_no) {
				$meta->update_cvs_payment_no( $cvs_payment_no );
			}
			if ('' !== $cvs_validation_no) {
				$meta->update_cvs_validation_no( $cvs_validation_no );
			}
		}

		self::logger(
			\sprintf( '✅ 成立物流單成功，LogisticsID：%s', $logistics_id ),
			'info',
			[ 'logistics_id' => $logistics_id ],
			0,
			$order
		);

		return [ 'logistics_id' => $logistics_id ];
	}

	/**
	 * 查詢物流單（QueryLogisticsTradeInfo）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 第三階段實作
	 */
	public function query_shipment( \WC_Order $order ): array {
		$meta = new LogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();

		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚未成立物流單', 'power_checkout' ) );
		}

		$decrypted = ( new LogisticsApiClient( $order ) )->query( $ref );

		return [
			'logistics_id' => (string) ( $decrypted['LogisticsID'] ?? $ref ),
			'status'       => (string) ( $decrypted['LogisticsStatus'] ?? '' ),
			'store_info'   => [
				'store_id'   => (string) ( $decrypted['StoreID'] ?? '' ),
				'store_name' => (string) ( $decrypted['StoreName'] ?? '' ),
			],
		];
	}

	/**
	 * 列印物流單（PrintTradeDocument）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 *
	 * @throws \Exception 第三階段實作
	 */
	public function print_document( \WC_Order $order ): string {
		$meta = new LogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();

		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚未成立物流單', 'power_checkout' ) );
		}

		$sub_type = $meta->get_sub_type() ?: LogisticsSubType::FAMI->value;

		return ( new LogisticsApiClient( $order ) )->print_trade_document( [ $ref ], $sub_type );
	}

	/**
	 * 取消物流單（C2C CancelC2COrder）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 第三階段實作
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		$settings = EcpayLogisticsSettingsDTO::instance();

		// 前置驗證 1：取消物流單僅支援 C2C 帳號
		if (LogisticsAccountType::C2C->value !== $settings->account_type) {
			throw new \Exception( \__( '取消物流單僅支援 C2C 帳號', 'power_checkout' ) );
		}

		$meta = new LogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚未成立物流單', 'power_checkout' ) );
		}

		// 前置驗證 2：須有 C2C 寄貨編號
		$cvs_payment_no = $meta->get_cvs_payment_no();
		if ('' === \trim( $cvs_payment_no )) {
			throw new \Exception( \__( '缺少 C2C 寄貨編號，無法取消', 'power_checkout' ) );
		}

		$cvs_validation_no = $meta->get_cvs_validation_no();

		$decrypted = ( new LogisticsApiClient( $order ) )->cancel_c2c(
			$ref,
			$cvs_payment_no,
			$cvs_validation_no
		);

		// 標記為取消（非綠界碼，本系統標記值）
		$meta->update_status( LogisticsStatus::CANCELLED->value );

		self::logger(
			\sprintf( '✅ 已取消 C2C 物流單，LogisticsID：%s', $ref ),
			'info',
			[ 'logistics_id' => $ref ],
			0,
			$order
		);

		return [
			'logistics_id' => (string) ( $decrypted['LogisticsID'] ?? $ref ),
			'cancelled'    => true,
		];
	}

	/**
	 * 建立退貨單（逆物流）
	 *
	 * 前置驗證：provider 啟用 → 訂單存在 → 已成立正向物流單（有 _pc_logistics_ref）→
	 * ServerReplyURL 公開可訪問。依原物流子類型分派四個逆物流端點：
	 *   FAMI→ReturnCVS、UNIMART→ReturnUniMartCVS、HILIFE→ReturnHilifeCVS、HOME→ReturnHome。
	 * 成功後寫入逆物流單號 _pc_logistics_return_ref 並記 order note；逆物流貨態沿用既有 status-callback。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（保留擴充，如自訂溫層 / 規格）
	 *
	 * @return array<string, mixed> 含 return_logistics_id
	 *
	 * @throws \Exception 前置驗證 / 傳輸層 / 業務層失敗
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		$settings = EcpayLogisticsSettingsDTO::instance();

		// 前置驗證 1：provider 必須啟用
		if (!ProviderUtils::is_enabled( self::ID )) {
			throw new \Exception( \__( '綠界全方位物流未啟用', 'power_checkout' ) );
		}

		// 前置驗證 2：訂單必須存在
		$this->assert_order_exists( $order );

		// 前置驗證 3：須已成立正向物流單（有 LogisticsID 才能退貨）
		$meta = new LogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚未成立物流單，無法退貨', 'power_checkout' ) );
		}

		// 前置驗證 4：ServerReplyURL（逆物流貨態通知）須公開可訪問（R6）
		$this->assert_public_reply_url( $settings->server_reply_url );

		// 依原物流子類型分派退貨端點
		$sub_type = $meta->get_sub_type() ?: LogisticsSubType::FAMI->value;
		$total    = (int) \round( (float) $order->get_total() );

		$params    = $this->build_return_params( $order, $ref, $sub_type, $total, $settings );
		$decrypted = $this->dispatch_return( $order, $sub_type, $params );

		$return_logistics_id = (string) ( $decrypted['ReturnLogisticsID'] ?? $decrypted['LogisticsID'] ?? '' );
		if ('' === $return_logistics_id) {
			throw new \Exception( \__( '綠界未回傳逆物流單號，建立退貨單失敗', 'power_checkout' ) );
		}

		// 寫入逆物流單號（逆物流貨態反查用）
		$meta->update_return_ref( $return_logistics_id );

		self::logger(
			\sprintf( '↩️ 建立退貨單成功，ReturnLogisticsID：%s', $return_logistics_id ),
			'info',
			[
				'logistics_id'        => $ref,
				'return_logistics_id' => $return_logistics_id,
				'sub_type'            => $sub_type,
			],
			0,
			$order
		);

		return [ 'return_logistics_id' => $return_logistics_id ];
	}

	/**
	 * 組裝逆物流 Data 參數（依子類型填超商 / 宅配欄位）
	 *
	 * @param \WC_Order                 $order    訂單
	 * @param string                    $ref      原正向物流單號 LogisticsID
	 * @param string                    $sub_type 物流子類型
	 * @param int                       $total    商品金額
	 * @param EcpayLogisticsSettingsDTO $settings 設定
	 *
	 * @return \J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateReturnParams
	 */
	private function build_return_params(
		\WC_Order $order,
		string $ref,
		string $sub_type,
		int $total,
		EcpayLogisticsSettingsDTO $settings
	): \J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateReturnParams {
		$data = [
			'LogisticsID'    => $ref,
			'GoodsAmount'    => $total,
			'GoodsName'      => $this->get_goods_name( $order ),
			'ServerReplyURL' => $settings->server_reply_url,
		];

		if (LogisticsSubType::HOME->value === $sub_type) {
			// 宅配退貨：Temperature / Distance / Specification
			$data['Temperature'] = $this->get_temperature( $order, $sub_type ) ?: LogisticsTemperature::NORMAL->value;
			// Distance 由綠界依寄收件地址自動驗證修正，預設同縣市 '00'
			$data['Distance'] = '00';
			// Specification 預設 60cm（最小規格），可由 ctx 擴充
			$data['Specification'] = '0001';
		} else {
			// 超商退貨：ServiceType 固定 '4'（退貨不付款）+ 退貨人姓名 / 手機
			$data['ServiceType'] = '4';
			$data['SenderName']  = $settings->sender_name ?: '退貨人';
			if ('' !== $settings->sender_phone) {
				$data['SenderPhone'] = $settings->sender_phone;
			}
		}

		return \J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateReturnParams::parse( $data );
	}

	/**
	 * 依子類型分派至對應的 ApiClient 逆物流方法
	 *
	 * @param \WC_Order                                                         $order    訂單
	 * @param string                                                            $sub_type 物流子類型
	 * @param \J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateReturnParams $params   退貨參數
	 *
	 * @return array<string, mixed> 解密後 Data
	 * @throws \Exception 不支援的子類型 / 傳輸層 / 業務層失敗
	 */
	private function dispatch_return(
		\WC_Order $order,
		string $sub_type,
		\J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateReturnParams $params
	): array {
		$client = new LogisticsApiClient( $order );

		return match ( $sub_type ) {
			LogisticsSubType::FAMI->value    => $client->return_cvs( $params ),
			LogisticsSubType::UNIMART->value => $client->return_unimart_cvs( $params ),
			LogisticsSubType::HILIFE->value  => $client->return_hilife_cvs( $params ),
			LogisticsSubType::HOME->value    => $client->return_home( $params ),
			default                          => throw new \Exception(
				\sprintf(
					/* translators: %s: logistics sub type */
					\__( '不支援的退貨物流子類型：%s', 'power_checkout' ),
					$sub_type
				)
			),
		};
	}

	/**
	 * 處理貨態 callback（ServerReplyURL）
	 *
	 * @param \WP_REST_Request $request 綠界 JSON body POST 請求
	 *
	 * @return \WP_REST_Response
	 *
	 * @throws \Exception 第三階段實作
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return \J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsCallback::instance()
			->post_logistics_status_callback_callback( $request );
	}

	/**
	 * 取得結帳頁可選的物流子類型（enabled_methods 子集）
	 *
	 * @return array<int, string>
	 */
	public function get_supported_methods(): array {
		$settings = self::get_settings();
		$methods  = $settings['enabled_methods'] ?? [];
		if (!\is_array( $methods )) {
			return [];
		}
		$list = \array_map(
			static fn( mixed $method ): string => (string) $method,
			\array_values( $methods )
		);
		return $list;
	}

	// region 前置驗證 / 組裝 helpers

	/**
	 * 驗證訂單存在（ID > 0）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 * @throws \InvalidArgumentException 訂單不存在
	 */
	private function assert_order_exists( \WC_Order $order ): void {
		if ($order->get_id() <= 0) {
			throw new \InvalidArgumentException( \__( '找不到訂單', 'power_checkout' ) );
		}
	}

	/**
	 * 驗證 reply URL 為公開可訪問（非 localhost / 127.0.0.1，R6）
	 *
	 * @param string $url reply URL
	 * @return void
	 * @throws \Exception URL 為 localhost
	 */
	private function assert_public_reply_url( string $url ): void {
		$host = (string) \wp_parse_url( $url, PHP_URL_HOST );
		$host = \strtolower( $host );

		$is_local = \in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true )
		|| \str_ends_with( $host, '.localhost' )
		|| \str_ends_with( $host, '.local' )
		|| \str_ends_with( $host, '.test' );

		if ($is_local) {
			throw new \Exception(
				\__( 'ClientReplyURL / ServerReplyURL 必須為公開可訪問的 URL（不可為 localhost）', 'power_checkout' )
			);
		}
	}

	/**
	 * 取得商品名稱（合併訂單品項，截斷避免過長）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function get_goods_name( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$goods_name = \implode( '#', $names );
		if ('' === $goods_name) {
			$goods_name = \sprintf( '訂單 #%d', $order->get_id() );
		}
		// 綠界商品名稱長度限制，截斷至 50 字
		return \mb_substr( $goods_name, 0, 50 );
	}

	/**
	 * 取得宅配溫層（僅 HOME 宅配；由 order meta _pc_logistics_temperature 讀取）
	 *
	 * @param \WC_Order $order    訂單
	 * @param string    $sub_type 物流子類型
	 * @return string 溫層碼（非宅配回空字串）
	 */
	private function get_temperature( \WC_Order $order, string $sub_type ): string {
		if (LogisticsSubType::HOME->value !== $sub_type) {
			return '';
		}
		return (string) ( $order->get_meta( '_pc_logistics_temperature' ) ?: '' );
	}

	// endregion
}
