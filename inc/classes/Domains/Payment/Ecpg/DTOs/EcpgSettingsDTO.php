<?php
/**
 * 綠界站內付 2.0（ECPG）設定 DTO，單例
 *
 * 從 woocommerce_ecpay_ecpg_settings（WC option）取得資料，憑證一律存 DB（禁寫死正式憑證）。
 * 測試模式（Mode::TEST）才以綠界 ECPG 線上金流官方測試帳號作為預設值。
 *
 * Trade-off：屬性命名混用 snake_case（WC 設定欄位 / 對齊 BaseSettingsDTO 的 title / description）
 * 與 camelCase（綠界 API 憑證 merchantId / hashKey / hashIv）。前者由 WC settings form 寫入，
 * 後者由綠界 API 組裝端讀取，刻意分流，與 AioSettingsDTO 一致。
 *
 * 雙 Domain：GetTokenbyTrade / CreatePayment 走 ecpg domain（本 DTO 的 $tokenEndpoint）；
 * 查詢 / 退款走 ecpayment domain（本 DTO 的 $paymentEndpoint），混用會 404。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\DTOs;

use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 綠界站內付 2.0（ECPG）設定 DTO */
final class EcpgSettingsDTO extends BaseSettingsDTO {

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string $id Id */
	public string $id = EcpgGateway::ID;

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '綠界 ECPay 站內付';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '站內付 2.0 內嵌式付款，不跳轉即可完成信用卡付款（含 3D 驗證）';

	/** @var string $method_title 後台標題 */
	public string $method_title = '綠界 ECPay 站內付（不跳轉）';

	/** @var string $method_description 後台描述 */
	public string $method_description = '綠界 ECPay 站內付 2.0，內嵌式信用卡收單，AES-128-CBC 加密，雙 Domain（ecpg / ecpayment）';

	/** @var string 前台顯示付款方式按鈕文字 */
	public string $orderButtonText = '確認付款';

	/** @var int 付款方式最小金額（0 表示不限制） */
	public int $minAmount = 0;

	/** @var int 付款方式最大金額（0 表示不限制） */
	public int $maxAmount = 0;

	// endregion 基礎通用欄位

	// region 綠界 API 憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string 綠界特店編號 MerchantID */
	public string $merchantId = '';

	/** @var string 綠界 HashKey */
	public string $hashKey = '';

	/** @var string 綠界 HashIV */
	public string $hashIv = '';

	// endregion

	// region 付款方式設定

	/**
	 * @var array<string> 允許的付款方式（對齊 EcpayPaymentMethod::value）
	 *
	 * 站內付 2.0 支援的付款方式：
	 *  - Credit（信用卡）：前端站內付元件（JS SDK）收單，CreatePayment 回 ThreeDInfo.ThreeDURL（3DS）。
	 *  - ATM / CVS / BARCODE（非信用卡幕後取號）：不需 JS SDK，後端 GetToken → CreatePayment（PayToken
	 *    直接用 GetToken 回傳的 Token）即取號，回虛擬帳號 / 超商代碼 / 條碼 + 繳費期限，訂單維持待付款，
	 *    消費者實際繳費後 ReturnURL 非同步通知（RtnCode=1）才轉付款完成。
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/02b-ecpg-atm-cvs-spa.md
	 */
	public array $allowedPayments = [ 'Credit', 'ATM', 'CVS', 'BARCODE' ];

	// endregion

	/** @var string 站內付 2.0 Token / 建立交易端點（ecpg domain，依 mode 於 after_init 設定，不存 DB） */
	public string $tokenEndpoint = 'https://ecpg-stage.ecpay.com.tw';

	/** @var string 站內付 2.0 查詢 / 退款端點（ecpayment domain，依 mode 於 after_init 設定，不存 DB） */
	public string $paymentEndpoint = 'https://ecpayment-stage.ecpay.com.tw';

	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例（合併 WC option） */
	public static function instance(): self {
		if (!self::$instance) {
			/** @var array<string, mixed>|null $args */
			$args           = ProviderUtils::get_option( EcpgGateway::ID );
			self::$instance = new self( \is_array( $args ) ? $args : [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（測試 / 設定變更後使用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * 實例化前型別轉換（int 欄位），mode → Mode enum 由父類 before_init 處理
	 *
	 * @return void
	 */
	protected function before_init(): void {
		parent::before_init();

		if (!\is_array( $this->dto_data )) {
			return;
		}

		$int_keys = [ 'minAmount', 'maxAmount' ];
		foreach ( $int_keys as $key ) {
			if (!isset( $this->dto_data[ $key ] )) {
				continue;
			}
			$this->dto_data[ $key ] = (int) $this->dto_data[ $key ];
		}
	}

	/**
	 * 實例化後：依 mode 設定雙 Domain 端點與 test 環境預設憑證
	 *
	 * Test 環境若未填憑證，套用綠界 ECPG 線上金流公開測試帳號（站內付 2.0 / 幕後授權共用）。
	 * Prod 環境不提供任何預設憑證，必須由 DB 取得。
	 *
	 * @see .claude/skills/ECPay-API-Skill/SKILL.md §測試帳號（ECPG 線上金流 3002607）
	 * @see .claude/skills/ECPay-API-Skill/SKILL.md §環境 URL（ecpg / ecpayment domain）
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$this->icon = Plugin::$url . '/inc/assets/images/icons/ecpay.png';

		if (Mode::TEST === $this->mode) {
			// 站內付 2.0 雙 Domain（測試環境）
			$this->tokenEndpoint   = 'https://ecpg-stage.ecpay.com.tw';
			$this->paymentEndpoint = 'https://ecpayment-stage.ecpay.com.tw';
			// 綠界 ECPG 線上金流公開測試帳號（站內付 2.0 / 幕後授權 / 幕後取號共用，AES）
			$this->merchantId = $this->merchantId ?: '3002607';
			$this->hashKey    = $this->hashKey ?: 'pwFHCqoQZGmho4w6';
			$this->hashIv     = $this->hashIv ?: 'EkRm7iFT261dpevs';
		} else {
			// 站內付 2.0 雙 Domain（正式環境）
			$this->tokenEndpoint   = 'https://ecpg.ecpay.com.tw';
			$this->paymentEndpoint = 'https://ecpayment.ecpay.com.tw';
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
		// 驗證白名單內每個付款方式皆為綠界允許值
		foreach ( $this->allowedPayments as $payment_method ) {
			EcpayPaymentMethod::from( $payment_method );
		}
	}
}
