<?php
/**
 * PayNow 物流（立吉富體系 1）設定 DTO，單例
 *
 * 從 woocommerce_paynow_logistics_settings（WC option）取得資料。
 *
 * ⚠️ option key 為 `woocommerce_paynow_logistics_settings`（R5）：
 *   刻意與金流 `woocommerce_paynow_settings`、發票 `woocommerce_paynow_invoice_settings`
 *   完全分離，避免三個 PayNow 體系（物流 / 金流 / 發票）共用 option 造成設定汙染。
 *
 * 環境網域（R8，woomp 實證）：
 *   - test → https://testlogistic.paynow.com.tw
 *   - prod → https://logistic.paynow.com.tw
 *   ⚠️ 與金流 sandboxapi.paynow.com.tw / 發票 invoiceapi-*.paynow.com.tw 三者網域完全不同。
 *
 * extends BaseSettingsDTO：沿用 before_init() 的 trim_invisible_deep（清前後不可見字元）
 * 與 mode 字串轉 Mode enum；憑證一律存 DB（user_account / apicode；R3 key/IV 屬 TripleDesCrypto）。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 1 步驟 5 / §R8
 * @see inc/classes/Domains/Logistics/Ecpay/DTOs/EcpayLogisticsSettingsDTO.php（鏡像）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\DTOs;

use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** PayNow 物流設定 DTO */
final class PaynowLogisticsSettingsDTO extends BaseSettingsDTO {

	/** @var string PayNow 物流 Provider ID（對應 woocommerce_paynow_logistics_settings option） */
	public const ID = 'paynow_logistics';

	/** @var string test 環境 API base URL（R8，woomp 實證） */
	private const TEST_API_URL = 'https://testlogistic.paynow.com.tw';

	/** @var string prod 環境 API base URL（R8，woomp 實證） */
	private const PROD_API_URL = 'https://logistic.paynow.com.tw';

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string $id Id */
	public string $id = self::ID;

	/** @var string 物流方式 icon */
	public string $icon = '';

	/** @var string 前台顯示標題 */
	public string $title = 'PayNow 超商取貨 / 宅配';

	/** @var string 前台顯示描述 */
	public string $description = 'PayNow 立吉富物流，支援 7-11 / 全家 / 萊爾富超商取貨與黑貓宅配，可代收貨款（COD）。';

	/** @var string $method_title 後台標題 */
	public string $method_title = 'PayNow 立吉富物流';

	/** @var string $method_description 後台描述 */
	public string $method_description = 'PayNow 立吉富物流（TripleDES），超商取貨 + 黑貓宅配，後台手動建單。';

	// endregion 基礎通用欄位

	// region PayNow 物流商家憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string PayNow 商家帳號（user_account） */
	public string $user_account = '';

	/** @var string PayNow 商家 API 密碼（apicode） */
	public string $apicode = '';

	// endregion

	// region 物流方式與寄件人

	/** @var array<int, string> 啟用的物流服務（PaynowLogisticService::value 子集，如 ["Seven","Fami","Tcat"]） */
	public array $enabled_methods = [];

	/** @var string 寄件人姓名 */
	public string $sender_name = '';

	/** @var string 寄件人電話 */
	public string $sender_phone = '';

	/** @var string 寄件人地址 */
	public string $sender_address = '';

	/** @var string 寄件人 email */
	public string $sender_email = '';

	// endregion

	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例（合併 WC option） */
	public static function instance(): self {
		if (!self::$instance) {
			/** @var array<string, mixed>|null $args */
			$args           = ProviderUtils::get_option( self::ID );
			self::$instance = new self( \is_array( $args ) ? $args : [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（測試 / 設定變更後使用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * 取得 PayNow 物流 API base URL（依 mode 切換，R8）
	 *
	 * Test → https://testlogistic.paynow.com.tw；
	 * prod → https://logistic.paynow.com.tw。
	 *
	 * @return string API base URL（不含尾端斜線）
	 */
	public function api_url(): string {
		return Mode::PROD === $this->mode ? self::PROD_API_URL : self::TEST_API_URL;
	}
}
