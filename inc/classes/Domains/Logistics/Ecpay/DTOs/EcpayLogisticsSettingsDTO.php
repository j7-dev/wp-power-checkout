<?php
/**
 * 綠界全方位物流 v2（AllInOne）設定 DTO，單例
 *
 * 從 woocommerce_ecpay_logistics_settings（WC option）取得資料，憑證一律存 DB（禁寫死正式憑證）。
 * 測試模式（Mode::TEST）才以綠界全方位物流官方公開測試帳號作為預設值。
 *
 * account_type 切換 B2C / C2C 兩組憑證（計畫 R5 核心）：
 * 兩組 MerchantID / HashKey / HashIV 各異，用錯帳號 AES 解密會直接失敗。
 * 因此 ApiClient / Callback 一律只透過 get_active_merchant_id() / get_active_hash_key()
 * / get_active_hash_iv() 取憑證，不直接讀 b2c_* / c2c_*。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §前置需求 / C2C 操作
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsAccountType;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 綠界全方位物流 v2 設定 DTO */
final class EcpayLogisticsSettingsDTO extends BaseSettingsDTO {

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string $id Id */
	public string $id = EcpayLogisticsProvider::ID;

	/** @var string 物流方式 icon */
	public string $icon = 'https://www.ecpay.com.tw/Content/Images/logo.png';

	/** @var string 前台顯示標題 */
	public string $title = '綠界超商取貨 / 宅配';

	/** @var string 前台顯示描述 */
	public string $description = '綠界 ECPay 全方位物流，支援 7-11 / 全家 / 萊爾富超商取貨與黑貓宅配，可代收貨款（COD）。';

	/** @var string $method_title 後台標題 */
	public string $method_title = '綠界 ECPay 全方位物流';

	/** @var string $method_description 後台描述 */
	public string $method_description = '綠界 ECPay 全方位物流 v2（AllInOne，AES-128-CBC），B2C / C2C 帳號切換，超商取貨 + 宅配。';

	// endregion 基礎通用欄位

	// region 帳號類型與兩組憑證（憑證一律存 DB，禁寫死 prod 憑證）

	/** @var string 帳號類型 b2c / c2c（決定使用哪組憑證；對齊 LogisticsAccountType::value） */
	public string $account_type = 'b2c';

	/** @var string B2C 綠界特店編號 MerchantID */
	public string $b2c_merchant_id = '';

	/** @var string B2C HashKey */
	public string $b2c_hash_key = '';

	/** @var string B2C HashIV */
	public string $b2c_hash_iv = '';

	/** @var string C2C 綠界特店編號 MerchantID */
	public string $c2c_merchant_id = '';

	/** @var string C2C HashKey */
	public string $c2c_hash_key = '';

	/** @var string C2C HashIV */
	public string $c2c_hash_iv = '';

	// endregion

	// region 物流方式與寄件人

	/** @var array<int, string> 啟用的物流子類型（logistics_sub_type 子集，如 ["FAMI","UNIMART","HILIFE","HOME"]） */
	public array $enabled_methods = [];

	/** @var string 寄件人姓名 */
	public string $sender_name = '';

	/** @var string 寄件人電話 */
	public string $sender_phone = '';

	/** @var string 寄件人郵遞區號 */
	public string $sender_zip_code = '';

	/** @var string 寄件人地址 */
	public string $sender_address = '';

	// endregion

	// region Reply URL（公開可訪問，僅 80/443 port）

	/** @var string 貨態 callback URL（ServerReplyURL） */
	public string $server_reply_url = '';

	/** @var string 選店回呼 URL（ClientReplyURL，需公開可訪問） */
	public string $client_reply_url = '';

	// endregion

	/** @var string 全方位物流 v2 API base URL（依 mode 於 after_init 設定，不存 DB） */
	public string $api_url = 'https://logistics-stage.ecpay.com.tw';

	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例（合併 WC option） */
	public static function instance(): self {
		if (!self::$instance) {
			/** @var array<string, mixed>|null $args */
			$args           = ProviderUtils::get_option( EcpayLogisticsProvider::ID );
			self::$instance = new self( \is_array( $args ) ? $args : [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（測試 / 設定變更後使用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * 取得目前啟用帳號類型的 MerchantID（依 account_type 切換）
	 *
	 * @return string
	 */
	public function get_active_merchant_id(): string {
		return LogisticsAccountType::C2C->value === $this->account_type
		? $this->c2c_merchant_id
		: $this->b2c_merchant_id;
	}

	/**
	 * 取得目前啟用帳號類型的 HashKey（依 account_type 切換）
	 *
	 * @return string
	 */
	public function get_active_hash_key(): string {
		return LogisticsAccountType::C2C->value === $this->account_type
		? $this->c2c_hash_key
		: $this->b2c_hash_key;
	}

	/**
	 * 取得目前啟用帳號類型的 HashIV（依 account_type 切換）
	 *
	 * @return string
	 */
	public function get_active_hash_iv(): string {
		return LogisticsAccountType::C2C->value === $this->account_type
		? $this->c2c_hash_iv
		: $this->b2c_hash_iv;
	}

	/**
	 * 實例化後：依 mode 設定端點，並於 test 環境套用綠界全方位物流公開測試帳號
	 *
	 * Test 環境若未填憑證，套用綠界全方位物流公開測試帳號（B2C / C2C 各一組，AES-128-CBC）。
	 * Prod 環境不提供任何預設憑證，必須由 DB 取得。
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §前置需求（B2C 2000132）/ C2C 操作（2000933）
	 *
	 * @return void
	 */
	protected function after_init(): void {
		if (Mode::TEST === $this->mode) {
			$this->api_url = 'https://logistics-stage.ecpay.com.tw';

			// B2C 全方位物流公開測試帳號
			$this->b2c_merchant_id = $this->b2c_merchant_id ?: '2000132';
			$this->b2c_hash_key    = $this->b2c_hash_key ?: '5294y06JbISpM5x9';
			$this->b2c_hash_iv     = $this->b2c_hash_iv ?: 'v77hoKGq4kWxNNIS';

			// C2C 店到店公開測試帳號（HashKey/HashIV 與 B2C 各異）
			$this->c2c_merchant_id = $this->c2c_merchant_id ?: '2000933';
			$this->c2c_hash_key    = $this->c2c_hash_key ?: 'XBERn1YOvpM9nfZc';
			$this->c2c_hash_iv     = $this->c2c_hash_iv ?: 'h1ONHk4P4yqbl5LK';
		} else {
			$this->api_url = 'https://logistics.ecpay.com.tw';
		}
	}
}
