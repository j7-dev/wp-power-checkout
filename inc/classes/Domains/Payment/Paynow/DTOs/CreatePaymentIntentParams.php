<?php
/**
 * PayNow 建立付款意圖請求參數（POST /api/v1/payment-intents）
 *
 * 對齊 payment-rest-api.md §4.1 Request Body。本 Cycle（Foundation）僅組裝 + 守衛，
 * 實際 HTTP 串接於 Cycle 2（PaynowRestClient）。
 *
 * 守衛（於 ::create 靜態工廠同步執行，不依賴 DTO validate 生命週期 —— 底層 DTO 在
 * 非 local 環境會吞驗證例外，故守衛必須在建構前主動 throw）：
 *  - currency 一律 TWD（未指定則補 TWD）。
 *  - allowedPaymentMethods 含 ApplePayDeferred → 拒絕（Q1 排除，不可與其他併用）。
 *  - allowInstallments 僅允許白名單 3/6/9/12/18/24，含非白名單值 → 拒絕。
 *
 * ⚠️ 安全鐵律：amount 一律由後端依訂單計算（Cycle 2 接上），禁前端輸入（防金額竄改）。
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 10
 * @see .claude/skills/paynow/references/payment-rest-api.md §4.1 建立付款意圖
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\DTOs;

use J7\WpUtils\Classes\DTO;

/** PayNow 建立付款意圖請求參數 */
final class CreatePaymentIntentParams extends DTO {

	/** @var string ApplePayDeferred（Q1 排除，不可與其他付款方式併用） */
	private const APPLE_PAY_DEFERRED = 'ApplePayDeferred';

	/** @var array<int> 合法分期期數白名單（payment-rest-api.md §4.1 allowInstallments） */
	private const VALID_INSTALLMENTS = [ 3, 6, 9, 12, 18, 24 ];

	/** @var string|null 付款單號 paymentNo（不指定則 PayNow 自動產生） */
	public ?string $paymentNo = null;

	/** @var int 付款金額（新台幣整數；< 1000000000000） */
	public int $amount = 0;

	/** @var string 幣別（固定 TWD） */
	public string $currency = 'TWD';

	/** @var string|null 描述（<= 255 字） */
	public ?string $description = null;

	/** @var string|null 付款完成轉跳網址 */
	public ?string $resultUrl = null;

	/** @var string|null Webhook 網址（填了即訂閱 payment_result） */
	public ?string $webhookUrl = null;

	/** @var array<string> 允許付款方式（對齊 PaynowPaymentMethod::value） */
	public array $allowedPaymentMethods = [];

	/** @var array<int> 限制分期數（白名單 3/6/9/12/18/24） */
	public array $allowInstallments = [];

	/** @var int|null 繳款天數（含當天）；ATM / ConvenienceStore 有效 */
	public ?int $expireDays = null;

	/**
	 * 由陣列建立付款意圖參數（含守衛）
	 *
	 * @param array<string, mixed> $data 請求資料
	 * @return self
	 * @throws \InvalidArgumentException 含 ApplePayDeferred / 非白名單分期期數時
	 */
	public static function create( array $data ): self {
		// currency 一律 TWD（未指定則補；指定非 TWD 亦覆寫為 TWD 以符合 PayNow 規範）
		$data['currency'] = 'TWD';

		self::assert_no_apple_pay_deferred( $data['allowedPaymentMethods'] ?? [] );
		self::assert_valid_installments( $data['allowInstallments'] ?? [] );

		return new self( $data );
	}

	/**
	 * 守衛：allowedPaymentMethods 不得含 ApplePayDeferred（Q1 排除）
	 *
	 * @param mixed $methods 允許付款方式
	 * @return void
	 * @throws \InvalidArgumentException 含 ApplePayDeferred 時
	 */
	private static function assert_no_apple_pay_deferred( mixed $methods ): void {
		if ( ! \is_array( $methods ) ) {
			return;
		}
		foreach ( $methods as $method ) {
			if ( self::APPLE_PAY_DEFERRED === (string) $method ) {
				throw new \InvalidArgumentException(
					'ApplePayDeferred 不可與其他付款方式併用，已依 Q1 裁決排除'
				);
			}
		}
	}

	/**
	 * 守衛：allowInstallments 僅允許白名單 3/6/9/12/18/24
	 *
	 * @param mixed $installments 分期期數
	 * @return void
	 * @throws \InvalidArgumentException 含非白名單期數時
	 */
	private static function assert_valid_installments( mixed $installments ): void {
		if ( ! \is_array( $installments ) ) {
			return;
		}
		foreach ( $installments as $installment ) {
			if ( ! \in_array( (int) $installment, self::VALID_INSTALLMENTS, true ) ) {
				throw new \InvalidArgumentException(
					\sprintf( '分期期數 %s 不在白名單（3/6/9/12/18/24）內', (string) $installment )
				);
			}
		}
	}
}
