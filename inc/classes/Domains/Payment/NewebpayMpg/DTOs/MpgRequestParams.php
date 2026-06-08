<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\MpgCallback;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgPaymentMethod;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgOrderNo;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\UrlEncoder;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * 藍新 NewebPay MPG 建單請求參數（TradeInfo 組裝 + 加密 + TradeSha）
 *
 * 流程：
 *  1. 組 TradeInfo 明文（key=value&...），每個 value 以 UrlEncoder::encode（標準 RFC urlencode）編碼。
 *  2. TradeInfoCrypto::encrypt → hex TradeInfo。
 *  3. TradeInfoCrypto::generate_trade_sha → 大寫 TradeSha。
 *  4. to_form_params() = { MerchantID, TradeInfo, TradeSha, Version, [EncryptType] }。
 *
 * 付款方式白名單：MpgSettingsDTO::allowedPayments 勾選哪些，TradeInfo 就把對應旗標（CREDIT=1 等）設 1。
 *
 * ⚠️ 屬性中 hashKey / hashIv 為 private（不進 to_array），僅供內部加密 / 簽章。
 * ⚠️ Amt 為整數新台幣，一律 ceil 進位。
 * ⚠️ TimeStamp 用 time()（Unix UTC 秒），不做時區加減（與綠界 +8 不同）。
 *
 * @see .claude/skills/newebpay-mpg/references/examples.md §Create Payment Form
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §TradeInfo Request Parameters
 */
final class MpgRequestParams extends DTO {

	// region 藍新 TradeInfo 明文欄位（屬性名 = 藍新欄位名）

	/** @var string 特店編號 */
	public string $MerchantID = '';

	/** @var string 回應格式（固定 JSON） */
	public string $RespondType = 'JSON';

	/** @var string Unix timestamp（秒，±120s 內），time() 不做時區加減 */
	public string $TimeStamp = '';

	/** @var string MPG 版本（2.3 / 2.0） */
	public string $Version = '2.3';

	/** @var string 特店訂單編號（英數 ≤30，冪等鍵） */
	public string $MerchantOrderNo = '';

	/** @var int 交易金額（新台幣整數，ceil 進位） */
	public int $Amt = 0;

	/** @var string 商品描述（≤50，多筆逗號連接） */
	public string $ItemDesc = '';

	/** @var string 付款人 email */
	public string $Email = '';

	/** @var int 是否登入藍新會員（0 不需要） */
	public int $LoginType = 0;

	/** @var string 付款結果背景通知 URL（source of truth） */
	public string $NotifyURL = '';

	/** @var string 付款完成前景導回 URL（UX） */
	public string $ReturnURL = '';

	/** @var string offline 取號繳費資訊顯示導回 URL */
	public string $CustomerURL = '';

	/** @var string 返回商店 URL */
	public string $ClientBackURL = '';

	// endregion

	// region 付款方式旗標（白名單勾選則設 1，否則不送）

	/** @var int 信用卡一次付清 */
	public int $CREDIT = 0;

	/** @var int ATM 虛擬帳號 */
	public int $VACC = 0;

	/** @var int WebATM */
	public int $WEBATM = 0;

	/** @var int 超商代碼 */
	public int $CVS = 0;

	/** @var int 超商條碼 */
	public int $BARCODE = 0;

	/** @var int LINE Pay */
	public int $LINEPAY = 0;

	/** @var int Apple Pay */
	public int $APPLEPAY = 0;

	/** @var int 玉山 Wallet */
	public int $ESUNWALLET = 0;

	/** @var int 台灣 Pay */
	public int $TAIWANPAY = 0;

	/** @var int TWQR 跨機構行動支付（需 Version=2.3） */
	public int $TWQR = 0;

	// endregion

	// region offline / 其他選填

	/** @var string offline 繳費期限 YYYYMMDD（VACC/CVS/BARCODE） */
	public string $ExpireDate = '';

	/** @var int TWQR QR code 有效秒數 */
	public int $TWQR_LifeTime = 0;

	/** @var string 信用卡分期期數（藍新 InstFlag，如 "3,6,12"；空字串不送） */
	public string $InstFlag = '';

	/** @var string 語系（zh-tw / en / jp） */
	public string $LangType = 'zh-tw';

	/** @var int 加密型別（0 CBC 不送 / 1 GCM）；本期固定 0，不送 */
	public int $EncryptType = 0;

	// endregion

	/** @var string 計算用 HashKey（不送藍新，private 不在 to_array 內） */
	private string $hashKey = '';

	/** @var string 計算用 HashIV（不送藍新，private 不在 to_array 內） */
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
		$settings = MpgSettingsDTO::instance();

		$meta_keys = new MpgMetaKeys( $order );
		// 冪等：已有 order_no 沿用（避免重建造成 MPG03002），否則新編
		$order_no = $meta_keys->get_order_no();
		if ( '' === $order_no ) {
			$order_no = MpgOrderNo::encode( $order->get_id() );
		}

		$total_amount = (int) \ceil( (float) $order->get_total() );

		$data = [
			'MerchantID'      => $settings->merchantId,
			'RespondType'     => 'JSON',
			'TimeStamp'       => (string) \time(), // Unix UTC 秒，不做時區加減
			'Version'         => $settings->version,
			'MerchantOrderNo' => $order_no,
			'Amt'             => $total_amount,
			'ItemDesc'        => ItemName::get( $order ),
			'Email'           => (string) $order->get_billing_email(),
			'LoginType'       => 0,
			'NotifyURL'       => MpgCallback::get_notify_url(),
			'ReturnURL'       => MpgCallback::get_return_url(),
			'CustomerURL'     => MpgCallback::get_return_url(),
			'ClientBackURL'   => $order->get_checkout_order_received_url(),
			'LangType'        => ItemName::get_language(),
			'ExpireDate'      => self::resolve_expire_date( $settings ),
			'InstFlag'        => self::resolve_inst_flag( $order, $settings ),
			// 內部用（private，不送藍新）
			'hashKey'         => $settings->hashKey,
			'hashIv'          => $settings->hashIv,
		];

		// 付款方式白名單 → 旗標
		$data = self::apply_payment_flags( $data, $settings );

		return new self( $data );
	}

	/**
	 * 依白名單設定付款方式旗標（勾選設 1）
	 *
	 * @param array<string, mixed> $data     已組裝資料
	 * @param MpgSettingsDTO       $settings 設定
	 *
	 * @return array<string, mixed>
	 */
	private static function apply_payment_flags( array $data, MpgSettingsDTO $settings ): array {
		foreach ( $settings->allowedPayments as $payment ) {
			$method = MpgPaymentMethod::tryFrom( $payment );
			if ( null === $method ) {
				continue;
			}
			$data[ $method->value ] = 1;

			// TWQR 需附 TWQR_LifeTime
			if ( MpgPaymentMethod::TWQR === $method ) {
				$data['TWQR_LifeTime'] = $settings->twqrLifeTime;
			}
		}

		return $data;
	}

	/**
	 * 解析 offline 繳費期限（YYYYMMDD）
	 *
	 * 僅當白名單含 offline 付款方式（VACC/CVS/BARCODE）時才需要；
	 * 為簡化，一律帶上（藍新對非 offline 付款方式會忽略 ExpireDate）。
	 *
	 * @param MpgSettingsDTO $settings 設定
	 *
	 * @return string YYYYMMDD
	 */
	private static function resolve_expire_date( MpgSettingsDTO $settings ): string {
		$has_offline = false;
		foreach ( $settings->allowedPayments as $payment ) {
			$method = MpgPaymentMethod::tryFrom( $payment );
			if ( null !== $method && $method->is_offline() ) {
				$has_offline = true;
				break;
			}
		}

		if ( ! $has_offline ) {
			return '';
		}

		$timestamp = \time() + ( $settings->expireDate * 86400 ); // 86400 = 1 天秒數
		return \wp_date( 'Ymd', $timestamp ) ?: \gmdate( 'Ymd', $timestamp );
	}

	/**
	 * 解析信用卡分期 InstFlag
	 *
	 * 顧客於 checkout 選擇「分期」時（order meta _pc_newebpay_credit_variant='installment'），
	 * 帶上該期數；否則不送（空字串）。分期期數須在後台白名單內。
	 *
	 * @param \WC_Order      $order    訂單
	 * @param MpgSettingsDTO $settings 設定
	 *
	 * @return string InstFlag（如 "6"；空字串不送）
	 * @throws \Exception 分期期數不在允許範圍
	 */
	private static function resolve_inst_flag( \WC_Order $order, MpgSettingsDTO $settings ): string {
		$meta_keys = new MpgMetaKeys( $order );
		if ( 'installment' !== $meta_keys->get_credit_variant() ) {
			return '';
		}

		$installment = $meta_keys->get_installment();
		if ( '' === $installment || ! \in_array( (int) $installment, $settings->installmentPeriods, true ) ) {
			throw new \Exception( '分期期數不在允許範圍' );
		}

		return $installment;
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
	 * 組裝 TradeInfo 明文（key=value&...，每個 value URL-encode）
	 *
	 * 順序：固定欄位 + 已啟用付款方式旗標 + 選填欄位（非空才納入）。
	 * value 以 UrlEncoder::encode（標準 RFC urlencode），避免值內含 & / = 破壞格式。
	 *
	 * @return string TradeInfo 明文
	 */
	public function to_trade_string(): string {
		$pairs = [];
		foreach ( $this->collect_trade_params() as $key => $value ) {
			$pairs[] = $key . '=' . UrlEncoder::encode( (string) $value );
		}
		return \implode( '&', $pairs );
	}

	/**
	 * 收集要進 TradeInfo 的參數（過濾空值 / 0 旗標）
	 *
	 * @return array<string, string|int>
	 */
	private function collect_trade_params(): array {
		/** @var array<string, string|int> $all */
		$all = [
			'MerchantID'      => $this->MerchantID,
			'RespondType'     => $this->RespondType,
			'TimeStamp'       => $this->TimeStamp,
			'Version'         => $this->Version,
			'MerchantOrderNo' => $this->MerchantOrderNo,
			'Amt'             => $this->Amt,
			'ItemDesc'        => $this->ItemDesc,
			'Email'           => $this->Email,
			'LoginType'       => $this->LoginType,
			'NotifyURL'       => $this->NotifyURL,
			'ReturnURL'       => $this->ReturnURL,
			'CustomerURL'     => $this->CustomerURL,
			'ClientBackURL'   => $this->ClientBackURL,
			'LangType'        => $this->LangType,
			'ExpireDate'      => $this->ExpireDate,
			'InstFlag'        => $this->InstFlag,
			'TWQR_LifeTime'   => $this->TWQR_LifeTime,
			// 付款方式旗標（0 會於下方過濾不送）
			'CREDIT'          => $this->CREDIT,
			'VACC'            => $this->VACC,
			'WEBATM'          => $this->WEBATM,
			'CVS'             => $this->CVS,
			'BARCODE'         => $this->BARCODE,
			'LINEPAY'         => $this->LINEPAY,
			'APPLEPAY'        => $this->APPLEPAY,
			'ESUNWALLET'      => $this->ESUNWALLET,
			'TAIWANPAY'       => $this->TAIWANPAY,
			'TWQR'            => $this->TWQR,
		];

		// 過濾空字串與 0（藍新不送的欄位不納入 TradeInfo）
		// 注意 Amt 不可能為 0（validate 已擋）；LoginType=0 為合法值需保留
		$filtered = [];
		foreach ( $all as $key => $value ) {
			if ( 'LoginType' === $key ) {
				$filtered[ $key ] = $value;
				continue;
			}
			if ( '' === $value || 0 === $value ) {
				continue;
			}
			$filtered[ $key ] = $value;
		}

		return $filtered;
	}

	/**
	 * 取得實際送往藍新 form 的參數（外層信封）
	 *
	 * { MerchantID, TradeInfo（加密 hex）, TradeSha（大寫）, Version, [EncryptType=1] }。
	 * 本期 encryptType 固定 0（CBC），不送 EncryptType（藍新預設即 CBC）。
	 *
	 * @return array<string, string|int>
	 */
	public function to_form_params(): array {
		$crypto     = new TradeInfoCrypto( $this->hashKey, $this->hashIv );
		$trade_info = $crypto->encrypt( $this->to_trade_string() );
		$trade_sha  = $crypto->generate_trade_sha( $trade_info );

		$form = [
			'MerchantID' => $this->MerchantID,
			'TradeInfo'  => $trade_info,
			'TradeSha'   => $trade_sha,
			'Version'    => $this->Version,
		];

		// GCM 時才送 EncryptType=1（本期固定 0，不送）
		if ( 1 === $this->EncryptType ) {
			$form['EncryptType'] = 1;
		}

		return $form;
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		// MerchantOrderNo：英數 ≤30
		if ( '' !== $this->MerchantOrderNo ) {
			if ( \strlen( $this->MerchantOrderNo ) > 30 ) {
				throw new \Exception( 'MerchantOrderNo 長度不可超過 30' );
			}
			if ( 1 !== \preg_match( '/^[0-9a-zA-Z]+$/', $this->MerchantOrderNo ) ) {
				throw new \Exception( 'MerchantOrderNo 僅允許英數字' );
			}
		}

		// ItemDesc ≤50
		if ( '' !== $this->ItemDesc && \mb_strlen( $this->ItemDesc, 'UTF-8' ) > 50 ) {
			throw new \Exception( 'ItemDesc 長度不可超過 50' );
		}

		// Version 僅 2.0 / 2.3
		if ( ! \in_array( $this->Version, [ '2.0', '2.3' ], true ) ) {
			throw new \Exception( "Version 必須為 2.0 或 2.3，收到：{$this->Version}" );
		}

		// Amt 必須 > 0
		if ( $this->Amt <= 0 ) {
			throw new \Exception( 'Amt 必須大於 0' );
		}

		// TWQR 需 Version 2.3
		if ( 1 === $this->TWQR && '2.3' !== $this->Version ) {
			throw new \Exception( 'TWQR 需 Version 為 2.3' );
		}

		// CVS / BARCODE 金額限制 30-20000
		if ( ( 1 === $this->CVS || 1 === $this->BARCODE ) && ( $this->Amt < 30 || $this->Amt > 20000 ) ) {
			throw new \Exception( 'CVS / BARCODE 金額須介於 30 至 20000' );
		}

		// 至少要有一種付款方式
		$has_payment = $this->CREDIT || $this->VACC || $this->WEBATM || $this->CVS || $this->BARCODE
		|| $this->LINEPAY || $this->APPLEPAY || $this->ESUNWALLET || $this->TAIWANPAY || $this->TWQR;
		if ( ! $has_payment ) {
			throw new \Exception( '至少需啟用一種付款方式' );
		}
	}
}
