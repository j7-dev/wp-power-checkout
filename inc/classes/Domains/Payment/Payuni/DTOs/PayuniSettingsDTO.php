<?php
/**
 * PAYUNi UPP V2（統一金流整合支付頁，導轉式）設定，單例
 *
 * 從 woocommerce_payuni_upp_settings（WC option）取得資料，憑證一律存 DB（禁寫死 prod）。
 *
 * Trade-off：屬性命名統一採 snake_case（對齊 WC settings form 欄位慣例與既有
 * RedirectSettingsDTO 的 min_amount / max_amount / expire_min）。PAYUNi 憑證亦用 snake_case
 * （merchant_id / hash_key / hash_iv），與 admin form 一致。
 *
 * ⚠️ extends J7\WpUtils\Classes\DTO（非 BaseSettingsDTO）：
 *   BaseSettingsDTO 的 before_init 會把 mode 字串轉為 Mode enum，
 *   但本 DTO 對外暴露 $mode 為 string（'test'|'prod'），與 MpgSettingsDTO / RedirectSettingsDTO
 *   的設計一致，故直接繼承底層 DTO 並自行做 trim 與 endpoint 切換。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md §端點 / §加解密
 * @see .claude/skills/payuni-upp-v2/references/sandbox-resources.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\DTOs;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGatewaySettings;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * PAYUNi UPP V2 設定 DTO
 */
final class PayuniSettingsDTO extends DTO implements IGatewaySettings {
	use EnableTrait;

	/** @var string PAYUNi UPP Provider ID（對應 woocommerce_payuni_upp_settings option） */
	public const ID = 'payuni_upp';

	/** @var string sandbox 主機 */
	private const SANDBOX_HOST = 'https://sandbox-api.payuni.com.tw';

	/** @var string production 主機 */
	private const PROD_HOST = 'https://api.payuni.com.tw';

	/** @var string UPP 路徑 */
	private const UPP_PATH = '/api/upp';

	/** @var string 官方公開測試向量 HashKey（payuni-upp-v2 encryption.md §官方測試向量） */
	private const TEST_HASH_KEY = '12345678901234567890123456789012';

	/** @var string 官方公開測試向量 HashIV（payuni-upp-v2 encryption.md §官方測試向量） */
	private const TEST_HASH_IV = '1234567890123456';

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = 'PAYUNi 統一金流';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '支援信用卡、信用卡分期、ATM 虛擬帳號、超商代碼、LINE Pay、街口支付、Apple Pay、Google Pay 等多元付款方式';

	/** @var string 前台顯示付款方式按鈕文字 */
	public string $order_button_text = '前往 PAYUNi 付款';

	/** @var int 付款方式最小金額（0 表示不限制） */
	public int $min_amount = 0;

	/** @var int 付款方式最大金額（0 表示不限制） */
	public int $max_amount = 0;

	/** @var int 付款期限（分鐘），ATM / CVS 取號後的繳費期限 */
	public int $expire_min = 10;

	/** @var string 'test'|'prod' 模式（對齊 Mode enum value，但本 DTO 保持 string） */
	public string $mode = 'test';

	// endregion

	// region PAYUNi API 憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string PAYUNi 商店代號 MerID */
	public string $merchant_id = '';

	/** @var string PAYUNi HashKey（32 bytes） */
	public string $hash_key = '';

	/** @var string PAYUNi HashIV（16 bytes） */
	public string $hash_iv = '';

	// endregion

	// region 付款方式與分期設定

	/**
	 * @var array<string> 允許的付款方式（對齊 PayuniPaymentMethod::value）
	 *
	 * 預設僅 Credit（信用卡一次付清）；Phase 3 起可勾選 ATM / CVS / LinePay / JKoPay 等。
	 */
	public array $allowed_payments = [ 'Credit' ];

	/**
	 * @var string 信用卡分期期數（逗號分隔字串，對齊 admin form 輸入；如 '3,6,12'）
	 *
	 * PAYUNi InstFlag 支援 3/6/9/12/18/24/30。以字串保存避免 admin form 陣列序列化差異。
	 */
	public string $installment_periods = '3,6,12,18,24,30';

	// endregion

	/** @var string PAYUNi UPP 端點（依 mode 於 after_init 設定，不存入 DB） */
	public string $api_url = self::SANDBOX_HOST . self::UPP_PATH;

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
	 * 實例化前型別轉換與 trim
	 *
	 * 先以 trim_invisible_deep 清除所有 string（含陣列內元素）前後的不可見字元
	 * （半形 / 全形空白、零寬字元、BOM、Tab / LF / CR），避免 wp_options 殘留污染憑證。
	 * trim 僅作用於記憶體中的 dto_data，不寫回 wp_options（DTO 為唯讀）。
	 *
	 * @return void
	 */
	protected function before_init(): void {
		if ( \is_array( $this->dto_data ) ) {
			$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );
		}

		$int_keys = [ 'min_amount', 'max_amount', 'expire_min' ];
		foreach ( $int_keys as $key ) {
			if ( ! isset( $this->dto_data[ $key ] ) ) {
				continue;
			}
			$this->dto_data[ $key ] = (int) $this->dto_data[ $key ];
		}

		// installment_periods 強制為字串（admin form 可能送來陣列或 int）
		if ( isset( $this->dto_data['installment_periods'] ) && \is_array( $this->dto_data['installment_periods'] ) ) {
			$this->dto_data['installment_periods'] = \implode(
				',',
				\array_map( static fn( $period ): string => (string) $period, $this->dto_data['installment_periods'] )
			);
		} elseif ( isset( $this->dto_data['installment_periods'] ) ) {
			$this->dto_data['installment_periods'] = (string) $this->dto_data['installment_periods'];
		}

		// mode 強制為字串（保持 'test'|'prod'，不轉 enum）
		if ( isset( $this->dto_data['mode'] ) ) {
			$this->dto_data['mode'] = (string) $this->dto_data['mode'];
		}
	}

	/**
	 * 實例化後：依 mode 設定 api_url 與 test 環境預設憑證
	 *
	 * Test 環境若未填憑證，套用 PAYUNi 官方公開測試向量金鑰（payuni-upp-v2 encryption.md）。
	 * Prod 環境不提供任何預設憑證，必須由 DB 取得。
	 *
	 * @return void
	 */
	protected function after_init(): void {
		if ( Mode::TEST->value === $this->mode ) {
			$this->api_url = self::SANDBOX_HOST . self::UPP_PATH;

			// 官方公開測試向量金鑰（payuni-upp-v2 encryption.md §官方測試向量）
			if ( '' === $this->hash_key ) {
				$this->hash_key = self::TEST_HASH_KEY;
			}
			if ( '' === $this->hash_iv ) {
				$this->hash_iv = self::TEST_HASH_IV;
			}
		} else {
			$this->api_url = self::PROD_HOST . self::UPP_PATH;
		}
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

		// 驗證白名單內每個付款方式皆為 PAYUNi 允許值
		foreach ( $this->allowed_payments as $payment_method ) {
			PayuniPaymentMethod::from( $payment_method );
		}
	}
}
