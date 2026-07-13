<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGatewaySettings;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * 藍新 NewebPay MPG（多功能支付，導轉式）設定，單例
 *
 * 從 woocommerce_newebpay_mpg_settings（WC option）取得資料，憑證一律存 DB（禁寫死 prod）。
 *
 * Trade-off：屬性命名混用 snake_case（WC 設定欄位慣例，對齊既有 gateway DTO 的 minAmount 等）
 * 與 camelCase（藍新 API 憑證 merchantId / hashKey / hashIv，對齊藍新 API）。
 *
 * @see .claude/skills/newebpay-mpg/SKILL.md
 */
final class MpgSettingsDTO extends DTO implements IGatewaySettings {
	use EnableTrait;

	// region 基礎通用欄位（WC settings form 寫入）

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '藍新金流';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '支援信用卡、ATM 虛擬帳號、超商代碼、超商條碼、WebATM、LINE Pay、Apple Pay 等多元付款方式';

	/** @var string 前台顯示付款方式按鈕文字 */
	public string $orderButtonText = '前往藍新金流付款';

	/** @var int 付款方式最小金額（0 表示不限制） */
	public int $minAmount = 0;

	/** @var int 付款方式最大金額（0 表示不限制） */
	public int $maxAmount = 0;

	/** @var int offline 繳費天數（VACC/CVS/BARCODE ExpireDate，範圍 1-180） */
	public int $expireDate = 3;

	/** @var string 'test'|'prod' 模式（對齊 Mode enum value） */
	public string $mode = 'test';

	// endregion

	// region 藍新 API 憑證（一律存 DB，禁寫死 prod 憑證）

	/** @var string 藍新特店編號 MerchantID */
	public string $merchantId = '';

	/** @var string 藍新 HashKey（32 bytes） */
	public string $hashKey = '';

	/** @var string 藍新 HashIV（16 bytes） */
	public string $hashIv = '';

	// endregion

	// region MPG 版本與加密設定

	/**
	 * @var string MPG API 版本（預設 "2.3"，TWQR / OrderDetail 需 2.3）
	 *
	 * 藍新 Version 為字串（"2.3" 非 2.3）；"2.0" 仍相容但無法用 TWQR。
	 */
	public string $version = '2.3';

	/**
	 * @var int 加密型別（0=AES-256-CBC 預設 / 1=AES-256-GCM）
	 *
	 * ⚠️ 本期僅實作 CBC（GCM wire format 官方未定義）。預留此欄位 + crypto 分派點，
	 * 但目前固定使用 CBC，不送 EncryptType=1。
	 */
	public int $encryptType = 0;

	// endregion

	// region 付款方式與分期設定

	/**
	 * @var array<string> 允許的付款方式（對齊 MpgPaymentMethod::value）
	 *
	 * 預設：僅 CREDIT（信用卡）—— Phase 1 MVP。
	 * Phase 2 起可勾選 VACC / WEBATM / CVS / BARCODE / LINEPAY / APPLEPAY / TWQR 等。
	 * ⚠️ TWQR 需 version='2.3'；部分付款方式需先向藍新申請開通。
	 */
	public array $allowedPayments = [ 'CREDIT' ];

	/** @var array<int> 信用卡分期期數（藍新 InstFlag：3,6,12,18,24,30） */
	public array $installmentPeriods = [ 3, 6, 12, 18, 24, 30 ];

	/** @var int TWQR QR code 有效秒數（預設 300，最大 2678400） */
	public int $twqrLifeTime = 300;

	// endregion

	/** @var string 藍新 MPG 端點（依 mode 於 after_init 設定，不存入 DB） */
	public string $endpoint = 'https://ccore.newebpay.com/MPG/mpg_gateway';

	/** 取得實例（合併 WC option） */
	public static function instance(): self {
		$settings_array = ProviderUtils::get_option( MpgRedirectGateway::ID );
		$settings_array = \is_array( $settings_array ) ? $settings_array : [];
		return new self( $settings_array );
	}

	/** @return void 初始化前型別轉換 */
	protected function before_init(): void {
		// Issue #16：先 trim 所有 string 與陣列內 string 元素，避免 wp_options 殘留前後不可見字元污染屬性
		if ( \is_array( $this->dto_data ) ) {
			$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );
		}

		$int_keys = [ 'minAmount', 'maxAmount', 'expireDate', 'encryptType', 'twqrLifeTime' ];
		foreach ( $int_keys as $key ) {
			if ( ! isset( $this->dto_data[ $key ] ) ) {
				continue;
			}
			$this->dto_data[ $key ] = (int) $this->dto_data[ $key ];
		}

		// installmentPeriods 一律正規化為 int（admin form 送來可能是字串，供 strict in_array 比對）
		if ( isset( $this->dto_data['installmentPeriods'] ) && \is_array( $this->dto_data['installmentPeriods'] ) ) {
			$this->dto_data['installmentPeriods'] = \array_values(
				\array_map( static fn( $period ) => (int) $period, $this->dto_data['installmentPeriods'] )
			);
		}

		// version 強制為字串（避免 admin form 送來 float 2.3）
		if ( isset( $this->dto_data['version'] ) ) {
			$this->dto_data['version'] = (string) $this->dto_data['version'];
		}
	}

	/**
	 * 實例化後：依 mode 設定 endpoint 與 test 環境預設憑證
	 *
	 * Test 環境若未填憑證，套用藍新 MPG 公開測試帳號（ccore）。
	 * Prod 環境不提供任何預設憑證，必須由 DB 取得（core）。
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$this->icon = Plugin::$url . '/inc/assets/images/icons/newebpay.png';

		if ( Mode::TEST->value === $this->mode ) {
			$this->endpoint = 'https://ccore.newebpay.com/MPG/mpg_gateway';
			// 藍新 MPG 公開測試帳號（Source: newebpay-mpg skill §Sandbox Detection / Test Environment）
			if ( '' === $this->merchantId ) {
				$this->merchantId = 'MS154450763';
			}
			if ( '' === $this->hashKey ) {
				$this->hashKey = 'Vh2Br0kFGSGHA9zXFDJuf9KIVgVxX1pn';
			}
			if ( '' === $this->hashIv ) {
				$this->hashIv = 'IZGViXjMd2gWMtsR';
			}
		} else {
			$this->endpoint = 'https://core.newebpay.com/MPG/mpg_gateway';
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

		// 驗證白名單內每個付款方式皆為藍新允許值
		foreach ( $this->allowedPayments as $payment_method ) {
			MpgPaymentMethod::from( $payment_method );
		}

		// version 僅允許 "2.0" / "2.3"
		if ( ! \in_array( $this->version, [ '2.0', '2.3' ], true ) ) {
			throw new \Exception( "version 必須為 2.0 或 2.3，收到：{$this->version}" );
		}

		// encryptType 僅允許 0 / 1（本期僅實作 0）
		if ( ! \in_array( $this->encryptType, [ 0, 1 ], true ) ) {
			throw new \Exception( "encryptType 必須為 0 或 1，收到：{$this->encryptType}" );
		}

		// TWQR 需 version 2.3
		if ( \in_array( MpgPaymentMethod::TWQR->value, $this->allowedPayments, true ) && '2.3' !== $this->version ) {
			throw new \Exception( 'TWQR 付款方式需 version 設為 2.3' );
		}

		// expireDate 1-180（藍新 offline 最長 180 天）
		if ( $this->expireDate < 1 || $this->expireDate > 180 ) {
			throw new \Exception( "expireDate 必須介於 1 至 180，收到：{$this->expireDate}" );
		}
	}
}
