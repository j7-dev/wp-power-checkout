<?php
/**
 * PayNow（REST API 體系 1）設定，多例（每次 instance() 合併最新 WC option）
 *
 * 從 woocommerce_paynow_settings（WC option）取得資料，憑證一律存 DB（禁寫死 prod）。
 *
 * 金鑰體系（concepts.md §3）：
 *  - public_key（PublicKey）：前端 Component SDK 初始化用。
 *  - private_key（PrivateKey）：後端 Bearer Token + Webhook HMAC-SHA256 驗簽金鑰，不可公開。
 *
 * 環境網域（concepts.md §10）：
 *  - test → https://sandboxapi.paynow.com.tw
 *  - prod → https://api.paynow.com.tw
 *
 * ⚠️ extends J7\WpUtils\Classes\DTO（非 BaseSettingsDTO）：對齊 PayuniSettingsDTO，
 *   $mode 對外暴露為 string（'test'|'prod'），自行做 trim 與 host 切換。
 *
 * trim：以 trim_invisible_deep 清除所有 string（含全形空白 / 零寬字元 / BOM）前後不可見字元；
 *   僅作用於記憶體中的 dto_data，不寫回 wp_options（DTO 為唯讀）。
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 9
 * @see .claude/skills/paynow/references/concepts.md §3 金鑰體系 §10 環境網域速查
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\DTOs;

use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGatewaySettings;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/** PayNow 設定 DTO */
final class PaynowSettingsDTO extends DTO implements IGatewaySettings {
	use EnableTrait;

	/** @var string PayNow Provider ID（對應 woocommerce_paynow_settings option） */
	public const ID = 'paynow';

	/** @var string sandbox 主機（concepts.md §10） */
	private const SANDBOX_HOST = 'https://sandboxapi.paynow.com.tw';

	/** @var string production 主機（concepts.md §10） */
	private const PROD_HOST = 'https://api.paynow.com.tw';

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = 'PayNow 立吉富';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '支援信用卡、信用卡分期、ATM、超商代碼、LINE Pay、Apple Pay 等多元付款方式';

	/** @var string 前台顯示付款方式按鈕文字 */
	public string $order_button_text = '確認付款';

	/** @var int 付款方式最小金額（0 表示不限制） */
	public int $min_amount = 0;

	/** @var int 付款方式最大金額（0 表示不限制） */
	public int $max_amount = 0;

	/** @var string 'test'|'prod' 模式（對齊 Mode enum value，但本 DTO 保持 string） */
	public string $mode = 'test';

	// endregion

	// region PayNow API 憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string PayNow PublicKey（前端 Component SDK 用） */
	public string $public_key = '';

	/** @var string PayNow PrivateKey（後端 Bearer + Webhook 驗簽，不可公開） */
	public string $private_key = '';

	// endregion

	// region 付款方式與分期設定

	/**
	 * @var array<string> 允許的付款方式（對齊 PaynowPaymentMethod::value）
	 *
	 * 預設僅 CreditCard；後台可勾選 ATM / ConvenienceStore / LINEPayOnline 等。
	 */
	public array $allowed_payment_methods = [ 'CreditCard' ];

	/** @var bool 是否允許信用卡分期 */
	public bool $allow_installments = false;

	/** @var int 離線付款（ATM / 超商代碼）繳款天數（含當天） */
	public int $expire_days = 3;

	// endregion

	/**
	 * @var string PayNow API base_url（依 mode 於 after_init 設定，不存入 DB）
	 *
	 * test = https://sandboxapi.paynow.com.tw
	 * prod = https://api.paynow.com.tw
	 */
	public string $base_url = self::SANDBOX_HOST;

	/**
	 * 取得實例（合併 WC option）
	 *
	 * @return self
	 */
	public static function instance(): self {
		$settings_array = ProviderUtils::get_option( self::ID );
		$settings_array = \is_array( $settings_array ) ? $settings_array : [];
		return new self( $settings_array );
	}

	/**
	 * 重置（本 DTO 為多例、不快取，故無狀態可清；提供以對齊測試 reset 介面）
	 *
	 * @return void
	 */
	public static function reset(): void {
		// 多例設計：每次 instance() 皆讀最新 option，無快取狀態需重置。
	}

	/**
	 * 實例化前型別轉換與 trim
	 *
	 * 先以 trim_invisible_deep 清除所有 string（含陣列內元素）前後的不可見字元
	 * （半形 / 全形空白、零寬字元、BOM、Tab / LF / CR），避免 wp_options 殘留污染憑證。
	 *
	 * @return void
	 */
	protected function before_init(): void {
		if ( \is_array( $this->dto_data ) ) {
			$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );
		}

		$int_keys = [ 'min_amount', 'max_amount', 'expire_days' ];
		foreach ( $int_keys as $key ) {
			if ( ! isset( $this->dto_data[ $key ] ) ) {
				continue;
			}
			$this->dto_data[ $key ] = (int) $this->dto_data[ $key ];
		}

		// allow_installments 強制為 bool（admin form 可能送 'yes' / '1' / 'on'）
		if ( isset( $this->dto_data['allow_installments'] ) ) {
			$this->dto_data['allow_installments'] = \filter_var(
				$this->dto_data['allow_installments'],
				FILTER_VALIDATE_BOOLEAN
			);
		}

		// mode 強制為字串（保持 'test'|'prod'，不轉 enum）
		if ( isset( $this->dto_data['mode'] ) ) {
			$this->dto_data['mode'] = (string) $this->dto_data['mode'];
		}
	}

	/**
	 * 實例化後：依 mode 設定 base_url
	 *
	 * ⚠️ 不對 prod 提供任何預設憑證，必須由 DB 取得（禁寫死 production key）。
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$this->base_url = Mode::PROD->value === $this->mode
		? self::PROD_HOST
		: self::SANDBOX_HOST;
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		// 驗證 mode 為合法 Mode value（$mode 一律有預設值，不需 isset）
		Mode::from( $this->mode );
	}
}
