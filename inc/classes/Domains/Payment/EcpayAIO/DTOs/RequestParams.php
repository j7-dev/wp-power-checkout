<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\TradeNo;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\TokenService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\AioCallback;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * 綠界 AIO AioCheckOut/V5 建單請求參數
 *
 * 屬性命名一律 camelCase 對齊綠界 API（MerchantID 等綠界以首字大寫，
 * 為符合 DTO ↔ to_array() 直送綠界的需求，屬性名即綠界欄位名）。
 *
 * ChoosePayment 策略：固定送 ChoosePayment='ALL'，再由 AioSettingsDTO::allowedPayments
 * 白名單「反推」IgnorePayment（未勾選的付款方式以 # 連接）。
 *
 * @see https://developers.ecpay.com.tw/?p=2862
 */
final class RequestParams extends DTO {

	/**
	 * @var array<string> 綠界 AIO 可被 IgnorePayment 排除的付款方式全集
	 *
	 * 來源：developers.ecpay.com.tw/2862.md（ChoosePayment=ALL 時 IgnorePayment 可排除清單）。
	 * 注意：
	 *  - DigitalPayment 雖可作 ChoosePayment 值，但不可用於 IgnorePayment，故不納入全集。
	 *  - 銀聯（UnionPay）非獨立付款方式值（走 Credit + UnionPay 參數），不可排除，故不納入。
	 */
	private const IGNORABLE_PAYMENTS = [ 'Credit', 'WebATM', 'ATM', 'CVS', 'BARCODE', 'ApplePay', 'TWQR', 'BNPL', 'WeiXin' ];

	/** @var array<string> BNPL 無卡分期可指定的貸款業者（Source: developers.ecpay.com.tw/36659.md） */
	private const BNPL_SUB_PAYMENTS = [ 'URICH', 'ZINGALA' ];

	/** @var array<int> 銀聯卡交易選項（Source: developers.ecpay.com.tw/2866.md：0 可選 / 1 強制 / 2 隱藏） */
	private const UNION_PAY_OPTIONS = [ 0, 1, 2 ];

	// region 綠界 AIO 必填參數（屬性名 = 綠界欄位名）

	/** @var string 特店編號 */
	public string $MerchantID = '';

	/** @var string 特店交易編號（≤ 20 碼，英數字） */
	public string $MerchantTradeNo = '';

	/** @var string 交易時間 yyyy/MM/dd HH:mm:ss（UTC+8） */
	public string $MerchantTradeDate = '';

	/** @var string 固定值 aio */
	public string $PaymentType = 'aio';

	/** @var int 交易金額（新台幣整數，ceil 進位） */
	public int $TotalAmount = 0;

	/** @var string 交易描述（≤ 200，禁特殊字元） */
	public string $TradeDesc = '';

	/** @var string 商品名稱（多筆以 # 連接，≤ 400） */
	public string $ItemName = '';

	/** @var string 付款結果通知 URL（Server-to-Server） */
	public string $ReturnURL = '';

	/** @var string 付款方式（固定送 ALL，再以 IgnorePayment 反推） */
	public string $ChoosePayment = 'ALL';

	/** @var int 固定值 1（SHA256） */
	public int $EncryptType = 1;

	/** @var string 檢查碼（於 after_init 計算） */
	public string $CheckMacValue = '';

	// endregion

	// region 綠界 AIO 選填參數

	/** @var string 排除的付款方式（以 # 連接） */
	public string $IgnorePayment = '';

	/** @var string 取號結果通知 URL（ATM/CVS/BARCODE） */
	public string $PaymentInfoURL = '';

	/** @var string 消費者付款完成後導回的網址（前端） */
	public string $ClientBackURL = '';

	/** @var string ATM 繳費天數（範圍 1-60，僅 ATM 使用） */
	public string $ExpireDate = '';

	/** @var string 語言（ENG/KOR/JPN/CHI；繁中送空字串使用綠界預設） */
	public string $Language = '';

	/**
	 * @var string BNPL 無卡分期貸款業者（URICH 裕富 / ZINGALA 中租）
	 *
	 * 空字串代表不指定（由綠界後台「無卡分期切換設定」決定顯示哪家）。
	 * 僅當 BNPL 在 ALL 流程未被 IgnorePayment 排除時才送出。
	 * Source: developers.ecpay.com.tw/36659.md
	 */
	public string $ChooseSubPayment = '';

	/**
	 * @var int 銀聯卡交易選項（0 可選 / 1 強制 / 2 隱藏）
	 *
	 * 哨兵值 -1 代表「不送 UnionPay 參數」（商家未啟用銀聯，需先向綠界申請）。
	 * 因綠界合法值含 0，無法以 array_filter 的 0 過濾判斷是否送出，故以 -1 區分。
	 * 銀聯走 ChoosePayment=Credit + UnionPay；不支援分期 / 定期定額 / 紅利。
	 * Source: developers.ecpay.com.tw/2866.md
	 */
	public int $UnionPay = -1;

	// endregion

	// region 信用卡分期 / 定期定額（皆屬 ChoosePayment=Credit，互斥）

	/**
	 * @var string 信用卡分期期數（單一值，如 '6'）。空字串代表非分期。
	 *
	 * 分期與定期定額互斥；選分期時 ChoosePayment 固定為 Credit。
	 * 期數須在 AioSettingsDTO::installmentPeriods 白名單內（於 build_credit_variant 驗證）。
	 */
	public string $CreditInstallment = '';

	/** @var int 定期定額每期金額（須等於 TotalAmount）。0 代表非定期定額。 */
	public int $PeriodAmount = 0;

	/** @var string 定期定額週期種類 D=天/M=月/Y=年。空字串代表非定期定額。 */
	public string $PeriodType = '';

	/** @var int 定期定額執行頻率（D:1-365, M:1-12, Y:僅 1）。0 代表非定期定額。 */
	public int $Frequency = 0;

	/** @var int 定期定額執行次數（最少 2 次；D/M 最多 999, Y 最多 99）。0 代表非定期定額。 */
	public int $ExecTimes = 0;

	/** @var string 定期定額每期授權結果通知 URL（第 2 期起通知至此） */
	public string $PeriodReturnURL = '';

	// endregion

	// region 記憶卡號（Token 綁卡）

	/**
	 * @var int 記憶卡號（1=使用 / 0=不使用）。0 代表不綁卡（不送出）。
	 *
	 * 僅信用卡（含分期 / 定期定額）且登入會員勾選時送 1；綠界會回 CardID 供回購幕後扣款。
	 * Source: ECPay-API-Skill guides/01-payment-aio.md §記憶卡號 BindingCard。
	 */
	public int $BindingCard = 0;

	/**
	 * @var string 記憶卡號識別碼（MerchantID + 會員編號，≤30）。空字串代表不綁卡。
	 *
	 * 僅支援 Visa/MasterCard/JCB。Source: developers.ecpay.com.tw §記憶卡號 MerchantMemberID。
	 */
	public string $MerchantMemberID = '';

	// endregion

	/** @var string 計算 CheckMacValue 用的 HashKey（不送往綠界，private 不在 to_array 內） */
	private string $hashKey = '';

	/** @var string 計算 CheckMacValue 用的 HashIV（不送往綠界） */
	private string $hashIv = '';

	/**
	 * 由訂單與 gateway 組裝建單參數
	 *
	 * @param \WC_Order              $order   訂單
	 * @param AbstractPaymentGateway $gateway 付款閘道
	 *
	 * @return self
	 */
	public static function instance( \WC_Order $order, AbstractPaymentGateway $gateway ): self {
		$settings = AioSettingsDTO::instance();

		$language     = ItemName::get_language();
		$return_url   = AioCallback::get_return_url();
		$paymentinfo  = AioCallback::get_payment_info_url();
		$total_amount = (int) \ceil( (float) $order->get_total() );

		$data = [
			'MerchantID'        => $settings->merchantId,
			'MerchantTradeNo'   => TradeNo::encode( $order->get_id() ),
			'MerchantTradeDate' => \wp_date( 'Y/m/d H:i:s' ) ?: \gmdate( 'Y/m/d H:i:s', \time() + 8 * 3600 ),
			'PaymentType'       => 'aio',
			// 綠界僅收新台幣整數，無條件進位避免少收
			'TotalAmount'       => $total_amount,
			'TradeDesc'         => "Order #{$order->get_id()}",
			'ItemName'          => ItemName::get( $order ),
			'ReturnURL'         => $return_url,
			'ChoosePayment'     => 'ALL',
			'EncryptType'       => 1,
			'IgnorePayment'     => self::build_ignore_payment( $settings->allowedPayments ),
			'PaymentInfoURL'    => $paymentinfo,
			'ClientBackURL'     => $order->get_checkout_order_received_url(),
			'ExpireDate'        => (string) $settings->expireDate,
			'Language'          => $language ?? '',
			// BNPL 貸款業者：僅當 BNPL 未被 IgnorePayment 排除時才有意義（避免送了卻被排除）
			'ChooseSubPayment'  => self::resolve_bnpl_sub_payment( $settings ),
			// 銀聯：啟用時送 0/1/2，否則哨兵 -1（不送）
			'UnionPay'          => $settings->unionPayEnabled ? $settings->unionPay : -1,
			// 內部用，不送綠界（private 屬性不會進 to_array）
			'hashKey'           => $settings->hashKey,
			'hashIv'            => $settings->hashIv,
		];

		// 信用卡分期 / 定期定額（皆屬 Credit，互斥）：依 checkout 顧客選擇（order meta）組裝
		$data = self::build_credit_variant( $data, $order, $settings, $return_url, $total_amount );

		// 記憶卡號（Token 綁卡）：登入會員勾選時帶 BindingCard=1 + MerchantMemberID
		$data = TokenService::apply_binding( $data, $order, $settings->merchantId );

		return new self( $data );
	}

	/**
	 * 依顧客於 checkout 的選擇（order meta）組裝信用卡分期 / 定期定額參數
	 *
	 * 變體決策機制（非前端直接控制送往綠界的 raw 參數，避免竄改）：
	 *  - 顧客選擇存於 order meta `_pc_ecpay_credit_variant`（''｜'installment'｜'period'），
	 *    分期期數存於 `_pc_ecpay_installment`，定期定額週期由後台 `AioSettingsDTO::periodConfig` 決定。
	 *  - 選分期或定期定額時，ChoosePayment 固定為 'Credit'（兩者皆 Credit-only，不再用 ALL+IgnorePayment）。
	 *  - 分期與定期定額互斥；變體為空字串則維持原 ALL 流程。
	 *
	 * @param array<string, mixed> $data         已組裝的基礎建單資料
	 * @param \WC_Order            $order        訂單
	 * @param AioSettingsDTO       $settings     設定
	 * @param string               $return_url   ReturnURL（定期定額 PeriodReturnURL 沿用）
	 * @param int                  $total_amount 訂單總額（整數，定期定額 PeriodAmount = 此值）
	 *
	 * @return array<string, mixed>
	 * @throws \Exception 分期期數不在允許範圍 / 定期定額參數不完整
	 */
	private static function build_credit_variant(
		array $data,
		\WC_Order $order,
		AioSettingsDTO $settings,
		string $return_url,
		int $total_amount
	): array {
		$meta_keys = new EcpayMetaKeys( $order );
		$variant   = $meta_keys->get_credit_variant();

		if ( 'installment' === $variant ) {
			$installment = $meta_keys->get_installment();
			// 期數須在後台白名單內，否則拒絕建單
			if ( '' === $installment || ! \in_array( (int) $installment, $settings->installmentPeriods, true ) ) {
				throw new \Exception( '分期期數不在允許範圍' );
			}
			$data['ChoosePayment']     = 'Credit'; // 分期屬 Credit
			$data['IgnorePayment']     = '';       // Credit-only 不再用 IgnorePayment
			$data['CreditInstallment'] = $installment;
			// Credit-only：BNPL 不存在、銀聯不支援分期，清除兩者避免衝突
			$data['ChooseSubPayment'] = '';
			$data['UnionPay']         = -1;
			return $data;
		}

		if ( 'period' === $variant ) {
			$config      = $settings->periodConfig;
			$period_type = isset( $config['PeriodType'] ) ? (string) $config['PeriodType'] : '';
			$frequency   = isset( $config['Frequency'] ) ? (int) $config['Frequency'] : 0;
			$exec_times  = isset( $config['ExecTimes'] ) ? (int) $config['ExecTimes'] : 0;

			// 必填參數缺一不可（PeriodType / Frequency / ExecTimes）
			if ( '' === $period_type || $frequency <= 0 || $exec_times <= 0 ) {
				throw new \Exception( '定期定額參數不完整' );
			}

			$data['ChoosePayment']   = 'Credit'; // 定期定額屬 Credit
			$data['IgnorePayment']   = '';       // Credit-only 不再用 IgnorePayment
			$data['PeriodAmount']    = $total_amount; // 每期授權金額須等於 TotalAmount
			$data['PeriodType']      = $period_type;
			$data['Frequency']       = $frequency;
			$data['ExecTimes']       = $exec_times;
			$data['PeriodReturnURL'] = $return_url;
			// Credit-only：BNPL 不存在、銀聯不支援定期定額，清除兩者避免衝突
			$data['ChooseSubPayment'] = '';
			$data['UnionPay']         = -1;
			return $data;
		}

		return $data;
	}

	/**
	 * 由白名單反推 IgnorePayment
	 *
	 * 全集（IGNORABLE_PAYMENTS）扣除白名單（allowedPayments）即為要排除的付款方式，
	 * 以 # 連接。修正舊版以 in_array 單值驗證 IgnorePayment 的 bug。
	 *
	 * @param array<string> $allowed_payments 後台勾選允許的付款方式
	 *
	 * @return string 以 # 連接的 IgnorePayment 字串
	 */
	private static function build_ignore_payment( array $allowed_payments ): string {
		$ignored = \array_values( \array_diff( self::IGNORABLE_PAYMENTS, $allowed_payments ) );
		return \implode( '#', $ignored );
	}

	/**
	 * 解析 BNPL 貸款業者（ChooseSubPayment）
	 *
	 * 僅當 BNPL 在白名單內（不會被 IgnorePayment 排除）時才回傳設定的 lender；
	 * 若 BNPL 未勾選，送出 ChooseSubPayment 無意義（綠界頁面不會出現 BNPL），故回空字串。
	 *
	 * @param AioSettingsDTO $settings 設定
	 *
	 * @return string URICH / ZINGALA / ''（不指定）
	 */
	private static function resolve_bnpl_sub_payment( AioSettingsDTO $settings ): string {
		if ( ! \in_array( EcpayPaymentMethod::BNPL->value, $settings->allowedPayments, true ) ) {
			return '';
		}
		return $settings->bnplSubPayment;
	}

	/** @return void 初始化前處理：搬出 hashKey / hashIv 到 private 屬性 */
	protected function before_init(): void {
		if ( ! \is_array( $this->dto_data ) ) {
			return;
		}
		$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );

		if ( isset( $this->dto_data['hashKey'] ) && \is_string( $this->dto_data['hashKey'] ) ) {
			$this->hashKey = $this->dto_data['hashKey'];
			unset( $this->dto_data['hashKey'] );
		}
		if ( isset( $this->dto_data['hashIv'] ) && \is_string( $this->dto_data['hashIv'] ) ) {
			$this->hashIv = $this->dto_data['hashIv'];
			unset( $this->dto_data['hashIv'] );
		}
	}

	/**
	 * 初始化後：計算 CheckMacValue
	 *
	 * 以送往綠界的參數（to_array 結果，已含 ChoosePayment/IgnorePayment 等）計算，
	 * 演算法委派 CheckMacValueService（ksort → HashKey 前綴 → HashIV 後綴 →
	 * .NET urlencode → 小寫 → SHA256 → 大寫）。
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$args = $this->to_check_value_args();
		if ( '' === $this->hashKey || '' === $this->hashIv ) {
			return;
		}
		$this->CheckMacValue = CheckMacValueService::get_check_value( $args, $this->hashKey, $this->hashIv, 'sha256' );
	}

	/**
	 * 收集送往綠界的參數（明確型別 string|int，過濾空字串選填欄位）
	 *
	 * 直接讀取本 DTO 的綠界欄位屬性（皆為 string|int），不經 to_array() 的 mixed，
	 * 確保回傳型別精確為 array<string, string|int>。
	 *
	 * @param bool $with_check_value 是否包含 CheckMacValue
	 * @return array<string, string|int>
	 */
	private function collect_params( bool $with_check_value ): array {
		/** @var array<string, string|int> $all */
		$all = [
			'MerchantID'        => $this->MerchantID,
			'MerchantTradeNo'   => $this->MerchantTradeNo,
			'MerchantTradeDate' => $this->MerchantTradeDate,
			'PaymentType'       => $this->PaymentType,
			'TotalAmount'       => $this->TotalAmount,
			'TradeDesc'         => $this->TradeDesc,
			'ItemName'          => $this->ItemName,
			'ReturnURL'         => $this->ReturnURL,
			'ChoosePayment'     => $this->ChoosePayment,
			'EncryptType'       => $this->EncryptType,
			'IgnorePayment'     => $this->IgnorePayment,
			'PaymentInfoURL'    => $this->PaymentInfoURL,
			'ClientBackURL'     => $this->ClientBackURL,
			'ExpireDate'        => $this->ExpireDate,
			'Language'          => $this->Language,
			// BNPL 貸款業者（空字串會於下方 array_filter 過濾）
			'ChooseSubPayment'  => $this->ChooseSubPayment,
			// 信用卡分期 / 定期定額（空字串 / 0 會於下方 array_filter 過濾）
			'CreditInstallment' => $this->CreditInstallment,
			'PeriodAmount'      => $this->PeriodAmount,
			'PeriodType'        => $this->PeriodType,
			'Frequency'         => $this->Frequency,
			'ExecTimes'         => $this->ExecTimes,
			'PeriodReturnURL'   => $this->PeriodReturnURL,
			// 記憶卡號（BindingCard=0 與空 MerchantMemberID 會於下方 array_filter 過濾不送）
			'BindingCard'       => $this->BindingCard,
			'MerchantMemberID'  => $this->MerchantMemberID,
		];

		if ( $with_check_value ) {
			$all['CheckMacValue'] = $this->CheckMacValue;
		}

		// 移除空字串 / 0 的選填欄位（綠界不傳的欄位不參與 CMV，亦不送 form）
		$filtered = \array_filter(
			$all,
			static fn( string|int $value ) => '' !== $value && 0 !== $value
		);

		// UnionPay 需特判：綠界合法值含 0（可選銀聯），不可被上方 array_filter 過濾。
		// 哨兵 -1 = 不送；0/1/2 = 送出（含 0）。
		if ( $this->UnionPay >= 0 ) {
			$filtered['UnionPay'] = $this->UnionPay;
		}

		return $filtered;
	}

	/**
	 * 取得參與 CheckMacValue 計算的參數
	 *
	 * 排除 CheckMacValue 自身與空字串選填欄位（綠界不傳的欄位不參與計算）。
	 *
	 * @return array<string, string|int>
	 */
	private function to_check_value_args(): array {
		return $this->collect_params( false );
	}

	/**
	 * 取得實際送往綠界 form 的參數（含 CheckMacValue，去除空值選填欄位）
	 *
	 * @return array<string, string|int>
	 */
	public function to_form_params(): array {
		return $this->collect_params( true );
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		// MerchantTradeNo ≤ 20
		if ( '' !== $this->MerchantTradeNo && \strlen( $this->MerchantTradeNo ) > 20 ) {
			throw new \Exception( 'MerchantTradeNo 長度不可超過 20' );
		}

		// TradeDesc ≤ 200
		if ( '' !== $this->TradeDesc && \mb_strlen( $this->TradeDesc ) > 200 ) {
			throw new \Exception( 'TradeDesc 長度不可超過 200' );
		}

		// EncryptType 必為 1
		if ( 1 !== $this->EncryptType ) {
			throw new \Exception( 'EncryptType 必須為 1' );
		}

		// ChoosePayment 白名單（本策略固定 ALL，但仍驗證以防外部覆寫）
		$allowed_choose = [ 'ALL', ...self::IGNORABLE_PAYMENTS, 'TWQR', 'BNPL', 'WeiXin', 'DigitalPayment' ];
		if ( '' !== $this->ChoosePayment && ! \in_array( $this->ChoosePayment, $allowed_choose, true ) ) {
			throw new \Exception( 'ChoosePayment 必須為綠界允許值' );
		}

		// IgnorePayment 多值驗證（逐段驗證每個都在可排除全集內）
		if ( '' !== $this->IgnorePayment ) {
			$segments = \explode( '#', $this->IgnorePayment );
			foreach ( $segments as $segment ) {
				if ( '' === $segment ) {
					continue;
				}
				if ( ! \in_array( $segment, self::IGNORABLE_PAYMENTS, true ) ) {
					throw new \Exception( "IgnorePayment 含非法值：{$segment}" );
				}
			}
		}

		// ChooseSubPayment（BNPL 貸款業者）：空字串代表不指定，否則須為 URICH / ZINGALA
		if ( '' !== $this->ChooseSubPayment && ! \in_array( $this->ChooseSubPayment, self::BNPL_SUB_PAYMENTS, true ) ) {
			throw new \Exception( "ChooseSubPayment 必須為 URICH 或 ZINGALA，收到：{$this->ChooseSubPayment}" );
		}

		// UnionPay（銀聯）：-1 代表不送，否則須為 0 / 1 / 2
		if ( -1 !== $this->UnionPay && ! \in_array( $this->UnionPay, self::UNION_PAY_OPTIONS, true ) ) {
			throw new \Exception( "UnionPay 必須為 0、1 或 2，收到：{$this->UnionPay}" );
		}

		// ExpireDate 1-60（ATM 繳費天數）
		if ( '' !== $this->ExpireDate ) {
			$expire = (int) $this->ExpireDate;
			if ( $expire < 1 || $expire > 60 ) {
				throw new \Exception( 'ExpireDate 必須介於 1 至 60' );
			}
		}

		// 分期與定期定額互斥（皆屬 Credit，不可同時帶）
		$has_installment = '' !== $this->CreditInstallment;
		$has_period      = 0 !== $this->PeriodAmount || '' !== $this->PeriodType || 0 !== $this->Frequency || 0 !== $this->ExecTimes;
		if ( $has_installment && $has_period ) {
			throw new \Exception( '分期與定期定額不可同時使用' );
		}

		// 帶分期 / 定期定額時 ChoosePayment 必為 Credit
		if ( ( $has_installment || $has_period ) && 'Credit' !== $this->ChoosePayment ) {
			throw new \Exception( '分期 / 定期定額的 ChoosePayment 必須為 Credit' );
		}

		// 定期定額 PeriodType 僅 D/M/Y
		if ( '' !== $this->PeriodType && ! \in_array( $this->PeriodType, [ 'D', 'M', 'Y' ], true ) ) {
			throw new \Exception( 'PeriodType 必須為 D / M / Y' );
		}
	}
}
