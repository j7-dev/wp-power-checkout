<?php
/**
 * 綠界電子收據設定 DTO
 *
 * 憑證一律存 WC option（woocommerce_ecpay_receipt_settings），禁止寫死正式憑證。
 * 測試模式（Mode::TEST）才以綠界官方測試帳號作為預設值。
 *
 * 殘留決策（合理預設 + 後台可改）：
 *  - default_receipt_type 預設 1（一般收據）。公益(2)/政治(4) 需聯繫綠界開通權限，
 *    且各自有獨立測試帳號，故預設僅啟一般收據，避免未開通直接呼叫被退。
 *  - 公益/政治測試帳號（2000132 一般+公益 / 3002607 政治）為公開測試資訊，
 *    後台切換 default_receipt_type 時需自行填入對應正式憑證。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §前置需求
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs;

use J7\PowerCheckout\Domains\Receipt\Ecpay\Services\EcpayReceiptProvider;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums\EReceiptType;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 綠界電子收據設定 DTO */
final class EcpayReceiptSettingsDTO extends BaseSettingsDTO {
	// region 基礎通用欄位

	/** @var string $id Id */
	public string $id = EcpayReceiptProvider::ID;

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示標題 */
	public string $title = '綠界電子收據';

	/** @var string 前台顯示描述 */
	public string $description = '綠界科技電子收據，支援一般 / 公益 / 政治獻金收據的開立與作廢，與電子發票並存可選。';

	/** @var string $method_title 標題 */
	public string $method_title = '綠界電子收據';

	/** @var string $method_description 描述 */
	public string $method_description = '綠界科技電子收據，支援一般 / 公益 / 政治獻金收據的開立與作廢，與電子發票並存可選。';

	// endregion 基礎通用欄位

	/** @var string 特店編號 MerchantID */
	public string $merchant_id = '';

	/** @var string HashKey */
	public string $hash_key = '';

	/** @var string HashIV */
	public string $hash_iv = '';

	/**
	 * 預設開立的收據類型：1=一般 / 2=公益 / 4=政治
	 *
	 * @var int
	 */
	public int $default_receipt_type = 1;

	/** @var string 領用方式 '1'=紙本 '2'=電子 '3'=自行處理 */
	public string $retrieval_method = '2';

	/** @var string 捐贈人類型（公益/政治收據用）'1'~'5' */
	public string $donor_type = '1';

	/** @var string 受贈/捐贈識別碼（公益受贈統編 / 政黨登記字號等，依 DonorType） */
	public string $identifier = '';

	/** @var string 政治獻金付款方式 '1'=匯款 '2'=票據 '3'=現金 */
	public string $payment_method = '1';

	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例 */
	public static function instance(): self {
		if (!self::$instance) {
			/** @var array<string, mixed>|null $args */
			$args           = ProviderUtils::get_option( EcpayReceiptProvider::ID );
			self::$instance = new self( $args );
		}
		return self::$instance;
	}

	/**
	 * 取得 API base URL（與電子發票共用網域，端點前綴為 /Receipt/）
	 *
	 * @return string
	 */
	public function get_api_url(): string {
		return Mode::TEST === $this->mode
		? 'https://einvoice-stage.ecpay.com.tw'
		: 'https://einvoice.ecpay.com.tw';
	}

	/** @return EReceiptType 預設收據類型 enum（無效值 fallback 一般收據） */
	public function get_default_receipt_type(): EReceiptType {
		return EReceiptType::tryFrom( $this->default_receipt_type ) ?? EReceiptType::GENERAL;
	}

	/**
	 * 實例化後：測試模式帶入綠界官方測試帳號（公開測試資訊，非正式憑證）。
	 * 一般/公益用 2000132；政治獻金用 3002607。
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §前置需求
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$this->icon = Plugin::$url . '/inc/assets/images/icons/ecpay.png';

		if (Mode::TEST !== $this->mode) {
			return;
		}

		if (EReceiptType::POLITICAL === $this->get_default_receipt_type()) {
			$this->merchant_id = $this->merchant_id ?: '3002607';
			$this->hash_key    = $this->hash_key ?: 'pwFHCqoQZGmho4w6';
			$this->hash_iv     = $this->hash_iv ?: 'EkRm7iFT261dpevs';
			return;
		}

		// 一般 / 公益測試帳號
		$this->merchant_id = $this->merchant_id ?: '2000132';
		$this->hash_key    = $this->hash_key ?: 'ejCk326UnaZWKisg';
		$this->hash_iv     = $this->hash_iv ?: 'q9jcZX8Ib9LM8wYk';
	}
}
