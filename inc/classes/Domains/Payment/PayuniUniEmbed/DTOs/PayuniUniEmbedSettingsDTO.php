<?php
/**
 * PAYUNi UNi Embed V3（內嵌式信用卡，站內付不跳轉）設定，多例（每次 instance() 合併最新 WC option）
 *
 * 從 woocommerce_payuni_uni_embed_settings（WC option）取得資料，憑證一律存 DB（禁寫死 prod）。
 *
 * 與 UPP 的 PayuniSettingsDTO 差異：
 *  - option 鍵不同（payuni_uni_embed vs payuni_upp），兩 gateway 設定互相隔離。
 *  - 端點為 /api/iframe/token_get（V3 內嵌式），非 UPP 的 /api/upp。
 *  - 新增 V3 特有欄位 iframe_domain（IFrameDomain，token_get 內層必填，須含 https://）。
 *  - 加密與 UPP 完全共用（AES-256-GCM + SHA256 HashInfo）；憑證（HashKey 32 / HashIV 16）格式相同。
 *
 * ⚠️ extends J7\WpUtils\Classes\DTO（非 BaseSettingsDTO）：對齊 PayuniSettingsDTO，
 *   $mode 對外暴露為 string（'test'|'prod'），自行做 trim 與 endpoint 切換。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §端點 §EncryptInfo 內層
 * @see \J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO UPP 對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs;

use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGatewaySettings;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/** PAYUNi UNi Embed V3 設定 DTO */
final class PayuniUniEmbedSettingsDTO extends DTO implements IGatewaySettings {
	use EnableTrait;

	/** @var string PAYUNi UNi Embed Provider ID（對應 woocommerce_payuni_uni_embed_settings option） */
	public const ID = 'payuni_uni_embed';

	/** @var string sandbox 主機 */
	private const SANDBOX_HOST = 'https://sandbox-api.payuni.com.tw';

	/** @var string production 主機 */
	private const PROD_HOST = 'https://api.payuni.com.tw';

	/** @var string token_get 路徑（V3 內嵌式，非 UPP 的 /api/upp） */
	private const TOKEN_GET_PATH = '/api/iframe/token_get';

	/** @var string 官方公開測試向量 HashKey（與 UPP 共用同一組測試金鑰） */
	private const TEST_HASH_KEY = '12345678901234567890123456789012';

	/** @var string 官方公開測試向量 HashIV（與 UPP 共用同一組測試金鑰） */
	private const TEST_HASH_IV = '1234567890123456';

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = 'PAYUNi 信用卡（站內付）';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '不跳轉，於本站內嵌完成信用卡付款（支援一次付清 / 分期 / 3D 驗證）';

	/** @var string 前台顯示付款方式按鈕文字 */
	public string $order_button_text = '確認付款';

	/** @var int 付款方式最小金額（0 表示不限制） */
	public int $min_amount = 0;

	/** @var int 付款方式最大金額（0 表示不限制） */
	public int $max_amount = 0;

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

	// region V3 特有欄位

	/**
	 * @var string IFrameDomain（V3 token_get 內層必填，須含 https://）
	 *
	 * 未填時於 after_init 由 site_url 衍生 https domain（避免 admin 未設定即無法取號）。
	 */
	public string $iframe_domain = '';

	// endregion

	/**
	 * @var string token_get 端點（依 mode 於 after_init 設定，不存入 DB）
	 *
	 * test = sandbox-api.payuni.com.tw/api/iframe/token_get
	 * prod = api.payuni.com.tw/api/iframe/token_get
	 */
	public string $token_get_url = self::SANDBOX_HOST . self::TOKEN_GET_PATH;

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

		$int_keys = [ 'min_amount', 'max_amount' ];
		foreach ( $int_keys as $key ) {
			if ( ! isset( $this->dto_data[ $key ] ) ) {
				continue;
			}
			$this->dto_data[ $key ] = (int) $this->dto_data[ $key ];
		}

		// mode 強制為字串（保持 'test'|'prod'，不轉 enum）
		if ( isset( $this->dto_data['mode'] ) ) {
			$this->dto_data['mode'] = (string) $this->dto_data['mode'];
		}
	}

	/**
	 * 實例化後：依 mode 設定 token_get_url 與 test 環境預設憑證
	 *
	 * Test 環境若未填憑證，套用 PAYUNi 官方公開測試向量金鑰。
	 * Prod 環境不提供任何預設憑證，必須由 DB 取得。
	 *
	 * ⚠️ IFrameDomain 不在此自動衍生（保持 DB 原值，未填即 ''）；衍生 fallback 由
	 *    Gateway::resolve_iframe_domain() 在 token_get 前處理，避免 DTO 暗藏副作用，
	 *    並使設定頁能如實顯示「未填」狀態。
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$this->icon = Plugin::$url . '/inc/assets/images/icons/payuni.png';

		if ( Mode::TEST->value === $this->mode ) {
			$this->token_get_url = self::SANDBOX_HOST . self::TOKEN_GET_PATH;

			// 官方公開測試向量金鑰（與 UPP 共用）
			if ( '' === $this->hash_key ) {
				$this->hash_key = self::TEST_HASH_KEY;
			}
			if ( '' === $this->hash_iv ) {
				$this->hash_iv = self::TEST_HASH_IV;
			}
		} else {
			$this->token_get_url = self::PROD_HOST . self::TOKEN_GET_PATH;
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
	}
}
