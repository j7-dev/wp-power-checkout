<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGatewaySettings;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * 綠界 AIO 全方位金流（導轉式）設定，單例
 *
 * 從 woocommerce_ecpay_aio_settings（WC option）取得資料，憑證一律存 DB（禁寫死）。
 *
 * Trade-off：屬性命名混用 snake_case（WC 設定欄位慣例，對齊 SLP RedirectSettingsDTO 的
 * title / description / min_amount 等）與 camelCase（綠界 API 憑證欄位 merchantId / hashKey /
 * hashIv，對齊綠界 API）。前者由 WC settings form 寫入，後者由綠界 API 組裝端讀取，
 * 兩者語意層不同，刻意分流以降低呼叫端的心智負擔。
 *
 * @see https://developers.ecpay.com.tw/?p=2862
 */
final class AioSettingsDTO extends DTO implements IGatewaySettings {
	use EnableTrait;

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '綠界 ECPay 全方位金流';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '支援信用卡、ATM 虛擬帳號、超商代碼、超商條碼、WebATM、Apple Pay 等多元付款方式';

	/** @var string 前台顯示付款方式按鈕文字 */
	public string $orderButtonText = '前往綠界付款';

	/** @var int 付款方式最小金額（0 表示不限制） */
	public int $minAmount = 0;

	/** @var int 付款方式最大金額（0 表示不限制） */
	public int $maxAmount = 0;

	/** @var int ATM 繳費天數（範圍 1-60） */
	public int $expireDate = 3;

	/** @var string 'test'|'prod' 模式（對齊 Mode enum value） */
	public string $mode = 'test';

	// endregion 基礎通用欄位

	// region 綠界 API 憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string 綠界特店編號 MerchantID */
	public string $merchantId = '';

	/** @var string 綠界 HashKey */
	public string $hashKey = '';

	/** @var string 綠界 HashIV */
	public string $hashIv = '';

	// endregion

	// region 付款方式與分期設定

	/**
	 * @var array<string> 允許的付款方式（對齊 EcpayPaymentMethod::value 的 ChoosePayment 全集 D4）
	 *
	 * 預設全集：Credit / ATM / WebATM / CVS / BARCODE / ApplePay。
	 * 組裝請求時固定送 ChoosePayment=ALL，再由此白名單反推 IgnorePayment。
	 */
	public array $allowedPayments = [ 'Credit', 'ATM', 'WebATM', 'CVS', 'BARCODE', 'ApplePay' ];

	/** @var array<int> 信用卡分期期數 */
	public array $installmentPeriods = [ 3, 6, 12, 18, 24, 30 ];

	/**
	 * @var array<string, mixed> 定期定額設定（PeriodAmount / PeriodType / Frequency / ExecTimes）
	 *
	 * 預設空陣列代表不啟用定期定額；本階段僅保留結構，實際組裝於後續階段。
	 */
	public array $periodConfig = [];

	// endregion

	/** @var string 綠界 AIO 端點（依 mode 於 after_init 設定，不存入 DB） */
	public string $endpoint = 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5';

	/** 取得實例（合併 WC option） */
	public static function instance(): self {
		$settings_array = ProviderUtils::get_option( AioRedirectGateway::ID );
		$settings_array = \is_array( $settings_array ) ? $settings_array : [];
		return new self( $settings_array );
	}

	/** @return void 初始化前型別轉換 */
	protected function before_init(): void {
		// Issue #16：先 trim 所有 string 與陣列內 string 元素，避免 wp_options 殘留前後不可見字元污染屬性
		if ( \is_array( $this->dto_data ) ) {
			$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );
		}

		$int_keys = [ 'minAmount', 'maxAmount', 'expireDate' ];
		foreach ( $int_keys as $key ) {
			if ( ! isset( $this->dto_data[ $key ] ) ) {
				continue;
			}
			$this->dto_data[ $key ] = (int) $this->dto_data[ $key ];
		}

		// installmentPeriods 一律正規化為 int（admin form 送來可能是字串，
		// 供 RequestParams 以 strict in_array 比對分期期數白名單）
		if ( isset( $this->dto_data['installmentPeriods'] ) && \is_array( $this->dto_data['installmentPeriods'] ) ) {
			$this->dto_data['installmentPeriods'] = \array_values(
				\array_map( static fn( $period ) => (int) $period, $this->dto_data['installmentPeriods'] )
			);
		}
	}

	/**
	 * 實例化後：依 mode 設定 endpoint 與 test 環境預設憑證
	 *
	 * Test 環境若未填憑證，套用綠界 AIO 公開測試帳號（金流 AIO 測試帳號，
	 * 非物流 2000132）。Prod 環境不提供任何預設憑證，必須由 DB 取得。
	 *
	 * @return void
	 */
	protected function after_init(): void {
		if ( Mode::TEST->value === $this->mode ) {
			$this->endpoint = 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5';
			// 綠界 AIO 公開測試帳號（Source: ECPay-API-Skill SKILL.md §測試帳號 金流 AIO）
			if ( '' === $this->merchantId ) {
				$this->merchantId = '3002607';
			}
			if ( '' === $this->hashKey ) {
				$this->hashKey = 'pwFHCqoQZGmho4w6';
			}
			if ( '' === $this->hashIv ) {
				$this->hashIv = 'EkRm7iFT261dpevs';
			}
		} else {
			$this->endpoint = 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5';
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
		// 驗證白名單內每個付款方式皆為綠界允許值
		foreach ( $this->allowedPayments as $payment_method ) {
			EcpayPaymentMethod::from( $payment_method );
		}
	}
}
