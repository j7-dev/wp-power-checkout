<?php
/**
 * PAYUNi 統一金流物流設定 DTO，單例
 *
 * 從 woocommerce_payuni_logistics_settings（WC option）取得資料，憑證一律存 DB（禁寫死正式憑證）。
 *
 * ⚠️ 與綠界（兩組 B2C / C2C 憑證切換）不同：PAYUNi 物流與金流共用**單一組** MerID / HashKey / HashIV，
 *    B2C / C2C 差異只在 trade / ship_map 的 LgsType 參數，不換金鑰。
 *
 * 測試模式（Mode::TEST）才以 PAYUNi 官方公開測試向量金鑰作為預設值（payuni-logistics-v3
 * encryption.md §測試向量：HashKey 32 bytes / HashIV 16 bytes），用以在 MOCK 模式驗證
 * AES-256-GCM 加解密；正式區金鑰須由 PAYUNi 後台取得並存 DB。
 *
 * @see .claude/skills/payuni-logistics-v3/references/encryption.md
 * @see .claude/skills/payuni-upp-v2/references/sandbox-resources.md §串接金鑰
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\DTOs;

use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** PAYUNi 統一金流物流設定 DTO */
final class PayuniLogisticsSettingsDTO extends BaseSettingsDTO {

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string $id Id */
	public string $id = PayuniLogisticsProvider::ID;

	/** @var string 物流方式 icon */
	public string $icon = 'https://www.payuni.com.tw/images/logo.svg';

	/** @var string 前台顯示標題 */
	public string $title = 'PAYUNi 7-11 超商取貨 / 黑貓宅配';

	/** @var string 前台顯示描述 */
	public string $description = 'PAYUNi 統一金流物流，支援 7-ELEVEN 超商取貨與黑貓宅配，可代收貨款（COD）。';

	/** @var string $method_title 後台標題 */
	public string $method_title = 'PAYUNi 統一金流物流';

	/** @var string $method_description 後台描述 */
	public string $method_description = 'PAYUNi 統一金流物流（AES-256-GCM），7-ELEVEN 超商取貨 + 黑貓宅配，B2C / C2C 店到店。';

	// endregion 基礎通用欄位

	// region 憑證（單一組，物流 / 金流共用；憑證一律存 DB，禁寫死 prod 憑證）

	/** @var string PAYUNi 商店代號 MerID */
	public string $mer_id = '';

	/** @var string PAYUNi HashKey（32 bytes，AES-256-GCM key） */
	public string $hash_key = '';

	/** @var string PAYUNi HashIV（16 bytes，AES-256-GCM iv） */
	public string $hash_iv = '';

	// endregion

	// region 物流方式與寄件人

	/** @var array<int, string> 啟用的物流子類型（PayuniSubType 子集，如 ["SEVEN","HOME"]） */
	public array $enabled_methods = [];

	/** @var string 物流型態 B2C / C2C（超商取貨的寄倉模式；對齊 PayuniLgsType::value） */
	public string $cvs_lgs_type = 'B2C';

	/** @var string 寄件人姓名 */
	public string $sender_name = '';

	/** @var string 寄件人手機（09 開頭） */
	public string $sender_mobile = '';

	// endregion

	// region Notify URL（公開可訪問，僅 80/443 port）

	/** @var string 貨態 Notify URL（後台設定於 PAYUNi 後台；用於前置驗證公開可訪問） */
	public string $notify_url = '';

	/** @var string 門市地圖回傳 URL（MapReturnURL，需公開可訪問） */
	public string $map_return_url = '';

	// endregion

	/** @var string PAYUNi API base URL（依 mode 於 after_init 設定，不存 DB） */
	public string $api_url = 'https://sandbox-api.payuni.com.tw';

	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例（合併 WC option） */
	public static function instance(): self {
		if (!self::$instance) {
			/** @var array<string, mixed>|null $args */
			$args           = ProviderUtils::get_option( PayuniLogisticsProvider::ID );
			self::$instance = new self( \is_array( $args ) ? $args : [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（測試 / 設定變更後使用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * 實例化後：依 mode 設定端點，test 環境套用官方公開測試向量金鑰
	 *
	 * Test 環境若未填憑證，套用 PAYUNi 官方文件測試向量金鑰（AES-256-GCM，僅供 MOCK 驗證加解密）。
	 * Prod 環境不提供任何預設憑證，必須由 DB 取得。
	 *
	 * @see .claude/skills/payuni-logistics-v3/references/encryption.md §測試向量
	 *
	 * @return void
	 */
	protected function after_init(): void {
		if (Mode::TEST === $this->mode) {
			$this->api_url = 'https://sandbox-api.payuni.com.tw';

			// PAYUNi 官方文件公開測試向量金鑰（32 / 16 bytes）— 僅供 MOCK 驗證 AES-256-GCM round-trip
			$this->mer_id   = $this->mer_id ?: 'S0000000000';
			$this->hash_key = $this->hash_key ?: '12345678901234567890123456789012';
			$this->hash_iv  = $this->hash_iv ?: '1234567890123456';
		} else {
			$this->api_url = 'https://api.payuni.com.tw';
		}
	}
}
