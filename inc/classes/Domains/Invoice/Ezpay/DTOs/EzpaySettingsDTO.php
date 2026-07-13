<?php
/**
 * 藍新 ezPay 電子發票設定 DTO
 *
 * 憑證一律存 WC option（woocommerce_ezpay_settings），禁止寫死正式憑證。
 * 測試模式（Mode::TEST）才以 ezPay 官方測試帳號作為預設值（測試帳號為公開資訊，非正式憑證）。
 *
 * 欄位採 snake_case（WC 設定欄位慣例，對齊 Ecpay 發票 DTO 的 merchant_id / hash_key / hash_iv）。
 * mode 由 BaseSettingsDTO::before_init 自字串轉為 Mode enum。
 *
 * ⚠️ 類別名稱為 EzpaySettingsDTO（非 EzpayInvoiceSettingsDTO）—— 與整合測試引用一致，勿改名。
 *
 * @see .claude/skills/ezpay-invoice/references/concepts.md §AES-256-CBC 加密
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 藍新 ezPay 電子發票設定 DTO */
final class EzpaySettingsDTO extends BaseSettingsDTO {

	/**
	 * Provider ID
	 *
	 * 與未來 EzpayInvoiceProvider::ID（Phase C 建立）一致，固定為 'ezpay'。
	 * 此處以字串常數承載，避免 Phase B 依賴尚未建立的 Provider 類。
	 *
	 * @var string
	 */
	public const ID = 'ezpay';

	// region 基礎通用欄位

	/** @var string $id Id */
	public string $id = self::ID;

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '藍新 ezPay 電子發票';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '藍新 ezPay 電子發票，支援 B2C 個人 / 載具 / 捐贈與 B2B 公司統編發票，自動開立與作廢。';

	/** @var string $method_title 標題 */
	public string $method_title = '藍新 ezPay 電子發票';

	/** @var string $method_description 描述 */
	public string $method_description = '藍新 ezPay 電子發票，支援 B2C 個人 / 載具 / 捐贈與 B2B 公司統編發票，自動開立與作廢。';

	// endregion 基礎通用欄位

	// region ezPay API 憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string 商店代號 MerchantID */
	public string $merchant_id = '';

	/** @var string HashKey（32 bytes） */
	public string $hash_key = '';

	/** @var string HashIV（16 bytes） */
	public string $hash_iv = '';

	// endregion ezPay API 憑證

	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例（合併 WC option） */
	public static function instance(): self {
		if ( ! self::$instance ) {
			/** @var array<string, mixed>|null $args */
			$args           = ProviderUtils::get_option( self::ID );
			self::$instance = new self( $args );
		}
		return self::$instance;
	}

	/**
	 * 取得 API base URL
	 *
	 * 測試環境與正式環境網域不同，憑證亦不同。
	 *  - test → https://cinv.ezpay.com.tw
	 *  - prod → https://inv.ezpay.com.tw
	 *
	 * @return string API base URL（不含 endpoint path）.
	 */
	public function get_api_url(): string {
		return Mode::TEST === $this->mode
		? 'https://cinv.ezpay.com.tw'
		: 'https://inv.ezpay.com.tw';
	}

	/**
	 * 實例化後，如果是測試模式就帶入 ezPay 官方測試帳號
	 *
	 * 測試帳號為官方手冊公開資訊（非正式憑證），僅在欄位為空時填入，避免覆蓋使用者輸入。
	 *
	 * @see .claude/skills/ezpay-invoice/references/concepts.md §AES-256-CBC 加密
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$this->icon = Plugin::$url . '/inc/assets/images/icons/ezpay.png';

		if ( Mode::TEST === $this->mode ) {
			$this->merchant_id = $this->merchant_id ?: 'MS12345678';
			$this->hash_key    = $this->hash_key ?: 'abcdefghijklmnopqrstuvwxyzabcdef';
			$this->hash_iv     = $this->hash_iv ?: '1234567891234567';
		}
	}
}
