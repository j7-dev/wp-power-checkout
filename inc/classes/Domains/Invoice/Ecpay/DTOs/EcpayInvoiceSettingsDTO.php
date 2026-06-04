<?php
/**
 * 綠界電子發票設定 DTO
 *
 * 憑證一律存 WC option（woocommerce_ecpay_settings），禁止寫死正式憑證。
 * 測試模式（Mode::TEST）才以綠界官方測試帳號作為預設值。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 綠界電子發票設定 DTO */
final class EcpayInvoiceSettingsDTO extends BaseSettingsDTO {
	// region 基礎通用欄位

	/** @var string $id Id */
	public string $id = EcpayInvoiceProvider::ID;

	/** @var string 付款方式 icon */
	public string $icon = 'https://www.ecpay.com.tw/Content/Images/logo.png';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '綠界電子發票';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '綠界科技電子發票，支援 B2C 個人/雲端/載具/捐贈與 B2B 公司統編發票，自動開立與作廢。';

	/** @var string $method_title 標題 */
	public string $method_title = '綠界電子發票';

	/** @var string $method_description 描述 */
	public string $method_description = '綠界科技電子發票，支援 B2C 個人/雲端/載具/捐贈與 B2B 公司統編發票，自動開立與作廢。';

	// endregion 基礎通用欄位

	/** @var string 特店編號 MerchantID */
	public string $merchant_id = '';

	/** @var string HashKey */
	public string $hash_key = '';

	/** @var string HashIV */
	public string $hash_iv = '';

	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例 */
	public static function instance(): self {
		if (!self::$instance) {
			/** @var array<string, mixed>|null $args */
			$args           = ProviderUtils::get_option( EcpayInvoiceProvider::ID );
			self::$instance = new self( $args );
		}
		return self::$instance;
	}

	/**
	 * 取得 API base URL
	 * 測試環境與正式環境網域不同，憑證亦不同。
	 *
	 * @return string
	 */
	public function get_api_url(): string {
		return Mode::TEST === $this->mode
		? 'https://einvoice-stage.ecpay.com.tw'
		: 'https://einvoice.ecpay.com.tw';
	}

	/**
	 * 實例化後，如果是測試模式就帶入綠界官方測試帳號
	 * （測試帳號為公開資訊，非正式憑證）
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §前置需求
	 *
	 * @return void
	 */
	protected function after_init(): void {
		if (Mode::TEST === $this->mode) {
			$this->merchant_id = $this->merchant_id ?: '2000132';
			$this->hash_key    = $this->hash_key ?: 'ejCk326UnaZWKisg';
			$this->hash_iv     = $this->hash_iv ?: 'q9jcZX8Ib9LM8wYk';
		}
	}
}
