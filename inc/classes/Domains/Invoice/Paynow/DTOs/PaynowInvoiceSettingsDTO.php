<?php
/**
 * PayNow 電子發票設定 DTO
 *
 * 憑證一律存 WC option，禁止寫死正式憑證。PayNow 發票（體系 3）以 Bearer JWT-Token 認證，
 * 故僅需 jwt_token 一把憑證（無對稱加密）。
 *
 * ⚠️ R5 最關鍵裁決：const ID = 'paynow_invoice'（非 'paynow'），對應 WC option key
 * `woocommerce_paynow_invoice_settings`，與金流 `woocommerce_paynow_settings` 完全分開，
 * 避免覆蓋金流設定。
 *
 * ⚠️ mode 命名差異：PayNow 文件以 dev / prod 表述環境，而本專案 Mode enum 以 test / prod 為值。
 * before_init 將傳入的 'dev' 正規化為 'test'，再交由 BaseSettingsDTO::before_init 轉為 Mode enum，
 * 故 $this->mode 對外仍為 Mode enum（dev → Mode::TEST、prod → Mode::PROD）。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §1 認證與環境
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\DTOs;

use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** PayNow 電子發票設定 DTO */
final class PaynowInvoiceSettingsDTO extends BaseSettingsDTO {

	/**
	 * Provider ID
	 *
	 * R5 裁決：發票 provider id 固定為 'paynow_invoice'，與金流 gateway 'paynow' 分離，
	 * 確保 WC option key 不互撞（woocommerce_paynow_invoice_settings vs woocommerce_paynow_settings）。
	 *
	 * @var string
	 */
	public const ID = 'paynow_invoice';

	// region 基礎通用欄位

	/** @var string $id Id */
	public string $id = self::ID;

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = 'PayNow 立吉富電子發票';

	/** @var string 前台顯示付款方式描述 */
	public string $description = 'PayNow 立吉富電子發票，支援 B2C 個人 / 載具 / 捐贈與 B2B 公司統編發票，自動開立、作廢與折讓。';

	/** @var string $method_title 標題 */
	public string $method_title = 'PayNow 立吉富電子發票';

	/** @var string $method_description 描述 */
	public string $method_description = 'PayNow 立吉富電子發票，支援 B2C 個人 / 載具 / 捐贈與 B2B 公司統編發票，自動開立、作廢與折讓。';

	// endregion 基礎通用欄位

	// region PayNow 發票 API 憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string 商家 JWT-Token（Bearer 認證） */
	public string $jwt_token = '';

	// endregion PayNow 發票 API 憑證

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
	 * 取得設定欄位陣列（WooCommerce settings form fields）
	 *
	 * 提供 PayNow 發票設定頁的欄位定義骨架；亦供 BaseSettingsDTO::create() 與未來
	 * PaynowInvoiceProvider::get_settings() 委派使用。
	 *
	 * @return array<string, mixed> 設定欄位定義.
	 */
	public static function get_settings(): array {
		return [
			'enabled'                   => [
				'title'   => \__( '啟用 / 停用', 'power_checkout' ),
				'type'    => 'checkbox',
				'label'   => \__( '啟用 PayNow 立吉富電子發票', 'power_checkout' ),
				'default' => 'no',
			],
			'mode'                      => [
				'title'   => \__( '環境模式', 'power_checkout' ),
				'type'    => 'select',
				'options' => [
					'dev'  => \__( '測試（dev）', 'power_checkout' ),
					'prod' => \__( '正式（prod）', 'power_checkout' ),
				],
				'default' => 'dev',
			],
			'jwt_token'                 => [
				'title' => \__( '商家 JWT-Token', 'power_checkout' ),
				'type'  => 'password',
			],
			'auto_issue_order_statuses' => [
				'title'   => \__( '自動開立發票的訂單狀態', 'power_checkout' ),
				'type'    => 'multiselect',
				'options' => [],
				'default' => [],
			],
			'auto_allowance_on_refund'  => [
				'title'   => \__( '部分退款時自動開立折讓', 'power_checkout' ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
		];
	}

	/**
	 * 取得 API base URL
	 *
	 * 測試環境與正式環境網域不同，憑證亦不同。
	 *  - dev（Mode::TEST） → https://invoiceapi-dev.paynow.com.tw
	 *  - prod（Mode::PROD）→ https://invoiceapi-prod.paynow.com.tw
	 *
	 * @return string API base URL（不含 endpoint path）.
	 */
	public function api_url(): string {
		return Mode::TEST === $this->mode
		? 'https://invoiceapi-dev.paynow.com.tw'
		: 'https://invoiceapi-prod.paynow.com.tw';
	}

	/**
	 * 實例化前的處理
	 *
	 * PayNow 文件以 dev / prod 表述環境，先將 'dev' 正規化為 'test'（本專案 Mode enum 的測試值），
	 * 再交由 BaseSettingsDTO::before_init 完成 trim 與 Mode enum 轉換。
	 *
	 * @return void
	 */
	protected function before_init(): void {
		if ( \is_array( $this->dto_data )
			&& isset( $this->dto_data['mode'] )
			&& \is_string( $this->dto_data['mode'] )
			&& 'dev' === $this->dto_data['mode'] ) {
			$this->dto_data['mode'] = Mode::TEST->value;
		}

		parent::before_init();
	}

	/** @return void 實例化後：帶入本地 icon 網址 */
	protected function after_init(): void {
		parent::after_init();
		$this->icon = Plugin::$url . '/inc/assets/images/icons/paynow.png';
	}
}
