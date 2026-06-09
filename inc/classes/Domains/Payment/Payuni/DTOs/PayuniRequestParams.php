<?php
/**
 * PAYUNi UPP V2 建單請求參數（內層明文組裝 + AES-256-GCM 加密 + SHA256 HashInfo）
 *
 * 流程（payuni-upp-v2 SKILL.md §外層請求格式 / §加解密）：
 *  1. 組內層明文陣列（MerID / MerTradeNo / TradeAmt / Timestamp / ProdDesc / ReturnURL /
 *     NotifyURL + 付款方式開關）。
 *  2. PayuniCrypto::encrypt → EncryptInfo（hex 字串，AES-256-GCM）。
 *  3. PayuniCrypto::hash_info → HashInfo（SHA256 大寫）。
 *  4. to_form_params() = { MerID, Version('2.0'), EncryptInfo, HashInfo }。
 *
 * 付款方式開關（payuni-upp-v2 §付款方式開關）：白名單勾選哪些，內層就帶對應開關：
 *  - Credit / ATM / CVS / ICash / LinePay / JKoPay / ApplePay / GooglePay → int 1（啟用）
 *  - CreditInst → 分期期數字串（如 "3,6,12"，來自 settings installment_periods；非 int 1）
 * 開關欄位名 = PayuniPaymentMethod::value（enum value 即 PAYUNi 開關欄位名，一對一對齊 skill）。
 *
 * ⚠️ 安全鐵律：
 *  - TradeAmt 一律 (int) ceil($order->get_total())，禁前端輸入（防金額竄改）。
 *  - MerTradeNo 為冪等鍵 PayuniTradeNo::generate(order_id)（同一訂單恆定）。
 *  - 外層 MerID 與 EncryptInfo 內層 MerID 必須一致（否則 PAYUNi 拒絕）。
 *
 * ⚠️ hash_key / hash_iv 為 private（不進 to_array），僅供內部加密 / 簽章。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md §外層請求格式 / §EncryptInfo 內層通用請求參數 / §付款方式開關
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\DTOs;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * PAYUNi UPP V2 建單請求參數
 */
final class PayuniRequestParams extends DTO {

	/** @var string 外層 / 內層 商店代號 MerID */
	public string $MerID = '';

	/** @var string UPP 版本（固定 2.0） */
	public string $Version = '2.0';

	/** @var string 商店訂單編號 MerTradeNo（≤25, [A-Za-z0-9_-], 冪等鍵） */
	public string $MerTradeNo = '';

	/** @var int 訂單金額（新台幣整數，ceil 進位；禁前端輸入） */
	public int $TradeAmt = 0;

	/** @var string Unix 時間戳（time()） */
	public string $Timestamp = '';

	/** @var string 商品說明（≤550，多項以半形分號分隔） */
	public string $ProdDesc = '';

	/** @var string 前景通知網址（瀏覽器 Form POST 回 order-received） */
	public string $ReturnURL = '';

	/** @var string 背景通知網址（server-to-server，僅限 80/443 port） */
	public string $NotifyURL = '';

	/** @var string 繳費有效日期 YYYY-MM-DD（offline ATM/CVS 才需要；空字串不送） */
	public string $ExpireDate = '';

	// region 付款方式開關（白名單勾選則帶對應欄位；未勾選不帶）

	/** @var int 信用卡一次付清（1=啟用） */
	public int $Credit = 0;

	/** @var string 信用卡分期期數（如 "3,6,12"；空字串不送） */
	public string $CreditInst = '';

	/** @var int ATM 虛擬帳號（1=啟用） */
	public int $ATM = 0;

	/** @var int 超商代碼 / 條碼（1=啟用） */
	public int $CVS = 0;

	/** @var int icash Pay（1=啟用） */
	public int $ICash = 0;

	/** @var int LINE Pay（1=啟用） */
	public int $LinePay = 0;

	/** @var int 街口支付（1=啟用） */
	public int $JKoPay = 0;

	/** @var int Apple Pay（1=啟用） */
	public int $ApplePay = 0;

	/** @var int Google Pay（1=啟用） */
	public int $GooglePay = 0;

	// endregion

	/** @var string 計算用 HashKey（不送 PAYUNi，private 不在 to_array 內） */
	private string $hash_key = '';

	/** @var string 計算用 HashIV（不送 PAYUNi，private 不在 to_array 內） */
	private string $hash_iv = '';

	/**
	 * 由訂單組裝建單參數（自行讀取 PayuniSettingsDTO）
	 *
	 * 冪等：MerTradeNo 純由 order_id 衍生（PayuniTradeNo::generate），同一訂單恆定，
	 * 不沿用 meta（即使尚未寫入 meta 也能算出相同值）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return self
	 */
	public static function instance( \WC_Order $order ): self {
		$settings = PayuniSettingsDTO::instance();

		// 金額一律來自訂單物件並無條件進位（禁前端輸入；避免少收）
		$total_amount = (int) \ceil( (float) $order->get_total() );

		$data = [
			'MerID'      => $settings->merchant_id,
			'Version'    => '2.0',
			'MerTradeNo' => PayuniTradeNo::generate( $order->get_id() ),
			'TradeAmt'   => $total_amount,
			'Timestamp'  => (string) \time(),
			'ProdDesc'   => ItemName::get( $order ),
			'ReturnURL'  => self::get_return_url( $order ),
			'NotifyURL'  => self::get_notify_url(),
			'ExpireDate' => self::resolve_expire_date( $settings ),
			// 內部用（private，不送 PAYUNi）
			'hash_key'   => $settings->hash_key,
			'hash_iv'    => $settings->hash_iv,
		];

		// 付款方式白名單 → 開關欄位
		$data = self::apply_payment_switches( $data, $settings );

		return new self( $data );
	}

	/**
	 * 依白名單設定付款方式開關
	 *
	 * 信用卡系 / ATM / CVS / 行動支付 → 對應開關設 1；
	 * CreditInst → 帶 settings installment_periods 期數字串（非 1）。
	 * 開關欄位名 = PayuniPaymentMethod::value（與 skill §付款方式開關 一對一）。
	 *
	 * @param array<string, mixed> $data     已組裝資料
	 * @param PayuniSettingsDTO    $settings 設定
	 * @return array<string, mixed>
	 */
	private static function apply_payment_switches( array $data, PayuniSettingsDTO $settings ): array {
		foreach ( $settings->allowed_payments as $payment ) {
			$method = PayuniPaymentMethod::tryFrom( (string) $payment );
			if ( null === $method ) {
				continue;
			}

			// CreditInst 特殊：值為分期期數字串（如 "3,6,12"），非 int 1
			if ( PayuniPaymentMethod::CreditInst === $method ) {
				$data[ $method->value ] = $settings->installment_periods;
				continue;
			}

			$data[ $method->value ] = 1;
		}

		return $data;
	}

	/**
	 * 解析 offline 繳費期限（YYYY-MM-DD）
	 *
	 * 僅當白名單含 offline 付款方式（ATM / CVS）時才需要；非 offline 一律不送（空字串）。
	 * 期限規則（payuni-upp-v2 §ExpireDate）：CVS 最大 +7 天、ATM 最大 +180 天、預設 +7 天。
	 * 以 settings expire_min（分鐘）換算天數，並對 CVS / ATM 上限做夾限。
	 *
	 * @param PayuniSettingsDTO $settings 設定
	 * @return string YYYY-MM-DD（無 offline 付款方式時回空字串）
	 */
	private static function resolve_expire_date( PayuniSettingsDTO $settings ): string {
		$has_atm = false;
		$has_cvs = false;
		foreach ( $settings->allowed_payments as $payment ) {
			$method = PayuniPaymentMethod::tryFrom( (string) $payment );
			if ( PayuniPaymentMethod::ATM === $method ) {
				$has_atm = true;
			}
			if ( PayuniPaymentMethod::CVS === $method ) {
				$has_cvs = true;
			}
		}

		if ( ! $has_atm && ! $has_cvs ) {
			return '';
		}

		// expire_min 為分鐘，換算為天數（不足 1 天以 1 天計，採無條件進位）
		$days = (int) \ceil( $settings->expire_min / ( 60 * 24 ) );
		if ( $days < 1 ) {
			$days = 1;
		}

		// 上限夾限：同時含 CVS 時取較嚴格的 7 天；僅 ATM 時最大 180 天
		$max_days = $has_cvs ? 7 : 180;
		if ( $days > $max_days ) {
			$days = $max_days;
		}

		$timestamp = \time() + ( $days * 86400 ); // 86400 = 1 天秒數
		return \wp_date( 'Y-m-d', $timestamp ) ?: \gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * 取得前景通知網址 ReturnURL（瀏覽器導回 order-received）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function get_return_url( \WC_Order $order ): string {
		return $order->get_checkout_order_received_url();
	}

	/**
	 * 取得背景通知網址 NotifyURL（server-to-server REST，僅限 80/443 port）
	 *
	 * Phase 4 callback 端點：power-checkout/payuni/upp/notify。
	 *
	 * @return string
	 */
	private static function get_notify_url(): string {
		return \site_url( 'wp-json/power-checkout/payuni/upp/notify', 'https' );
	}

	/**
	 * 初始化前處理：搬出 hash_key / hash_iv 到 private 屬性
	 *
	 * @return void
	 */
	protected function before_init(): void {
		if ( ! \is_array( $this->dto_data ) ) {
			return;
		}
		$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );

		if ( isset( $this->dto_data['hash_key'] ) && \is_string( $this->dto_data['hash_key'] ) ) {
			$this->hash_key = $this->dto_data['hash_key'];
			unset( $this->dto_data['hash_key'] );
		}
		if ( isset( $this->dto_data['hash_iv'] ) && \is_string( $this->dto_data['hash_iv'] ) ) {
			$this->hash_iv = $this->dto_data['hash_iv'];
			unset( $this->dto_data['hash_iv'] );
		}
	}

	/**
	 * 收集要進 EncryptInfo 的內層明文參數（過濾未啟用的付款方式開關）
	 *
	 * 順序：固定必填欄位 + 選填（ExpireDate）+ 已啟用付款方式開關。
	 * 未啟用的開關（值為 0 / 空字串）不納入，由 PayuniCrypto::encrypt 的 array_filter 再次保險過濾。
	 *
	 * @return array<string, string|int>
	 */
	private function collect_encrypt_params(): array {
		/** @var array<string, string|int> $all */
		$all = [
			'MerID'      => $this->MerID,
			'MerTradeNo' => $this->MerTradeNo,
			'TradeAmt'   => $this->TradeAmt,
			'Timestamp'  => $this->Timestamp,
			'ProdDesc'   => $this->ProdDesc,
			'ReturnURL'  => $this->ReturnURL,
			'NotifyURL'  => $this->NotifyURL,
			'ExpireDate' => $this->ExpireDate,
			// 付款方式開關（未啟用者於下方過濾）
			'Credit'     => $this->Credit,
			'CreditInst' => $this->CreditInst,
			'ATM'        => $this->ATM,
			'CVS'        => $this->CVS,
			'ICash'      => $this->ICash,
			'LinePay'    => $this->LinePay,
			'JKoPay'     => $this->JKoPay,
			'ApplePay'   => $this->ApplePay,
			'GooglePay'  => $this->GooglePay,
		];

		// 過濾空字串與 0（未啟用付款方式 / 未設定的選填欄位不送）
		$filtered = [];
		foreach ( $all as $key => $value ) {
			if ( '' === $value || 0 === $value ) {
				continue;
			}
			$filtered[ $key ] = $value;
		}

		return $filtered;
	}

	/**
	 * 取得實際送往 PAYUNi 的外層 form 參數
	 *
	 * { MerID, Version('2.0'), EncryptInfo（AES-256-GCM hex）, HashInfo（SHA256 大寫） }。
	 *
	 * @return array<string, string>
	 */
	public function to_form_params(): array {
		$crypto       = new PayuniCrypto( $this->hash_key, $this->hash_iv );
		$encrypt_info = $crypto->encrypt( $this->collect_encrypt_params() );
		$hash_info    = $crypto->hash_info( $encrypt_info );

		return [
			'MerID'       => $this->MerID,
			'Version'     => $this->Version,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $hash_info,
		];
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		// MerTradeNo：≤25、僅 [A-Za-z0-9_-]
		if ( '' !== $this->MerTradeNo ) {
			if ( \strlen( $this->MerTradeNo ) > 25 ) {
				throw new \Exception( 'MerTradeNo 長度不可超過 25' );
			}
			if ( 1 !== \preg_match( '/^[A-Za-z0-9_-]+$/', $this->MerTradeNo ) ) {
				throw new \Exception( 'MerTradeNo 僅允許 [A-Za-z0-9_-]' );
			}
		}

		// Version 固定 2.0
		if ( '2.0' !== $this->Version ) {
			throw new \Exception( "Version 必須為 2.0，收到：{$this->Version}" );
		}

		// ProdDesc ≤550
		if ( '' !== $this->ProdDesc && \mb_strlen( $this->ProdDesc, 'UTF-8' ) > 550 ) {
			throw new \Exception( 'ProdDesc 長度不可超過 550' );
		}

		// TradeAmt 必須 > 0（防金額竄改 / 0 元單）
		if ( $this->TradeAmt <= 0 ) {
			throw new \Exception( 'TradeAmt 必須大於 0' );
		}

		// 至少要有一種付款方式
		$has_payment = $this->Credit || $this->ATM || $this->CVS || $this->ICash
		|| $this->LinePay || $this->JKoPay || $this->ApplePay || $this->GooglePay
		|| '' !== $this->CreditInst;
		if ( ! $has_payment ) {
			throw new \Exception( '至少需啟用一種付款方式' );
		}
	}
}
