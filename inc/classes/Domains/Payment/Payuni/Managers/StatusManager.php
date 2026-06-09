<?php
/**
 * PAYUNi UPP V2 StatusManager（NotifyURL / ReturnURL 解密後的內層通知 → 訂單狀態）
 *
 * 依解密並驗章後的「內層通知陣列」轉換訂單狀態，比照 NewebpayMpg\Managers\StatusManager：
 *  - TradeStatus=1（已付款）→ 先做金額防竄改比對 → payment_complete() 轉 processing + 寫付款明細。
 *  - TradeStatus=0（取號成功）→ 維持 pending + 寫繳費資訊（ATM / CVS），不寫付款明細。
 *  - TradeStatus=2（失敗）/ 3（取消）/ 8（待確認）/ 未知 → 維持 pending + order note 記錄 Status/Message。
 *
 * ⚠️ 資安鐵律（金流安全核心）：
 *  - 金額 / 狀態判定一律以「驗章後的 PAYUNi 回傳資料」為準，絕不信任前端。
 *  - 轉 processing 前比對內層 TradeAmt（int）與訂單應收（ceil 整數）；不符 → 維持 pending + 告警，絕不 payment_complete。
 *  - StatusManager 作為最後防線：外層 Status 非 SUCCESS、MerID 不符商店設定 → 一律不更新訂單（防跨商店污染 / 偽造）。
 *  - 冪等：已 processing 不重複 payment_complete。
 *
 * @see .claude/skills/payuni-upp-v2/references/upp-response-params.md §內層通用欄位 / §虛擬帳號 / §超商代碼
 * @see specs/features/payment/payuni-upp-callback.feature
 * @see specs/features/payment/payuni-upp-payment-info.feature
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Managers;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniTradeStatus;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\WpUtils\Classes\WP;

/**
 * PAYUNi UPP V2 訂單狀態管理
 */
final class StatusManager {

	/** @var string 外層 / 內層成功狀態值 */
	private const STATUS_SUCCESS = 'SUCCESS';

	/** @var array<string> ATM 取號繳費資訊欄位（PaymentType=2） */
	private const ATM_INFO_KEYS = [ 'PaymentType', 'BankType', 'PayNo', 'PaySet', 'ExpireDate' ];

	/** @var array<string> CVS 取號繳費資訊欄位（PaymentType=3） */
	private const CVS_INFO_KEYS = [ 'PaymentType', 'Store', 'PayNo', 'ExpireDate' ];

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $inner_payload 解密並驗章後的內層通知陣列（{ Status, TradeStatus, TradeAmt, ... }）
	 * @param \WC_Order            $order         訂單
	 */
	public function __construct(
		private readonly array $inner_payload,
		private readonly \WC_Order $order,
	) {}

	/**
	 * 依內層 TradeStatus 更新訂單狀態
	 *
	 * @return void
	 */
	public function update_order_status(): void {
		// 最後防線 1：外層 / 內層 Status 非 SUCCESS → 不信任此 payload，維持 pending。
		$status = (string) ( $this->inner_payload['Status'] ?? '' );
		if ( self::STATUS_SUCCESS !== $status ) {
			$this->note_non_success();
			return;
		}

		// 最後防線 2：MerID 不符商店設定 → 防跨商店污染，維持 pending。
		if ( ! $this->is_merchant_matched() ) {
			$this->order->add_order_note(
				\sprintf(
					'PAYUNi 付款通知 MerID 不符商店設定（通知 MerID：%s），維持等待付款（疑似偽造 / 跨商店污染）',
					(string) ( $this->inner_payload['MerID'] ?? '' )
				)
			);
			return;
		}

		$trade_status = PayuniTradeStatus::tryFrom( (int) ( $this->inner_payload['TradeStatus'] ?? -1 ) );

		// TradeStatus=1（已付款）→ 金額防竄改 → payment_complete
		if ( null !== $trade_status && $trade_status->is_paid() ) {
			$this->handle_paid();
			return;
		}

		// TradeStatus=0（取號成功）→ 維持 pending + 寫繳費資訊
		if ( null !== $trade_status && $trade_status->is_get_code() ) {
			$this->handle_get_code();
			return;
		}

		// TradeStatus=2 / 3 / 8 / 未知 → 維持 pending + order note 記錄
		$this->handle_unpaid( $trade_status );
	}

	/**
	 * 處理已付款（TradeStatus=1）
	 *
	 * 冪等：已 processing 不重複 payment_complete。
	 * 金額防竄改：TradeAmt 不符訂單應收 → 維持 pending + 告警，絕不 payment_complete。
	 *
	 * @return void
	 */
	private function handle_paid(): void {
		// 冪等：已 processing 則不重複 payment_complete（PAYUNi 可能重送通知）
		if ( $this->order->has_status( OrderStatus::PROCESSING->value ) ) {
			return;
		}

		// 金額防竄改（資安最關鍵）：TradeAmt 不符 → 維持 pending + 告警 note
		if ( ! $this->is_amount_matched() ) {
			$this->order->add_order_note(
				\sprintf(
					'PAYUNi 付款通知金額不符，通知 TradeAmt：%s，訂單應收：%d，維持等待付款（疑似竄改）',
					(string) ( $this->inner_payload['TradeAmt'] ?? '' ),
					$this->get_order_amount()
				)
			);
			return;
		}

		// 寫付款明細（整個內層通知，供後台顯示與退款分流）
		( new PayuniMetaKeys( $this->order ) )->update_payment_detail( $this->inner_payload );

		$this->order->add_order_note(
			WP::array_to_html( $this->inner_payload, [ 'title' => 'PAYUNi 付款成功通知' ] )
		);

		$trade_no = (string) ( $this->inner_payload['TradeNo'] ?? '' );
		$this->order->payment_complete( $trade_no );
		$this->order->update_status( OrderStatus::PROCESSING->value );
	}

	/**
	 * 處理取號成功（TradeStatus=0，ATM / CVS）
	 *
	 * 維持 pending，寫繳費資訊（以最後一次通知為準，覆寫）。
	 *
	 * @return void
	 */
	private function handle_get_code(): void {
		$info = $this->extract_payment_info();

		// 覆寫繳費資訊（PAYUNi 可能重送取號通知；以最後一次為準）
		( new PayuniMetaKeys( $this->order ) )->update_payment_info( $info );

		$this->order->add_order_note(
			WP::array_to_html( $info, [ 'title' => 'PAYUNi 取號繳費資訊' ] )
		);
		// 不改訂單狀態（維持等待付款）
	}

	/**
	 * 處理非付款成功（TradeStatus=2 / 3 / 8 / 未知）
	 *
	 * 維持 pending，order note 記錄 Status / Message / TradeStatus。
	 *
	 * @param PayuniTradeStatus|null $trade_status 交易狀態（未知值為 null）
	 * @return void
	 */
	private function handle_unpaid( ?PayuniTradeStatus $trade_status ): void {
		$label = null !== $trade_status ? $trade_status->label() : '未知狀態';
		$this->order->add_order_note(
			\sprintf(
				'PAYUNi 付款未完成（%s），Status：%s，Message：%s，TradeStatus：%s',
				$label,
				(string) ( $this->inner_payload['Status'] ?? '' ),
				(string) ( $this->inner_payload['Message'] ?? '' ),
				(string) ( $this->inner_payload['TradeStatus'] ?? '' )
			)
		);
		// 不改訂單狀態（維持等待付款）
	}

	/**
	 * 外層 / 內層 Status 非 SUCCESS 的記錄（維持 pending）
	 *
	 * @return void
	 */
	private function note_non_success(): void {
		$this->order->add_order_note(
			\sprintf(
				'PAYUNi 通知 Status 非 SUCCESS（Status：%s，Message：%s），維持等待付款',
				(string) ( $this->inner_payload['Status'] ?? '' ),
				(string) ( $this->inner_payload['Message'] ?? '' )
			)
		);
	}

	/**
	 * 萃取取號繳費資訊欄位（依 PaymentType 取 ATM / CVS 欄位）
	 *
	 * @return array<string, mixed>
	 */
	private function extract_payment_info(): array {
		$payment_type = (string) ( $this->inner_payload['PaymentType'] ?? '' );
		// PaymentType=3 為超商代碼（CVS）；其餘（含 2=ATM）取 ATM 欄位集。
		$keys = '3' === $payment_type ? self::CVS_INFO_KEYS : self::ATM_INFO_KEYS;

		$info = [];
		foreach ( $keys as $key ) {
			if ( isset( $this->inner_payload[ $key ] ) && '' !== $this->inner_payload[ $key ] ) {
				$info[ $key ] = $this->inner_payload[ $key ];
			}
		}

		return $info;
	}

	/**
	 * 比對通知金額（內層 TradeAmt）是否等於訂單應收總額
	 *
	 * PAYUNi TradeAmt 為新台幣整數；訂單總額以 ceil 取整後比對（對齊建單時 (int) ceil 進位）。
	 *
	 * @return bool
	 */
	private function is_amount_matched(): bool {
		$trade_amt = (int) ( $this->inner_payload['TradeAmt'] ?? 0 );
		return $trade_amt === $this->get_order_amount();
	}

	/**
	 * 取得訂單應收金額（整數新台幣，ceil 進位）
	 *
	 * @return int
	 */
	private function get_order_amount(): int {
		return (int) \ceil( (float) $this->order->get_total() );
	}

	/**
	 * 通知 MerID 是否符合商店設定
	 *
	 * 缺商店設定 MerID（如測試未填）時不做此防線（回 true），交由 Callback 層的外層 MerID 比對把關。
	 *
	 * @return bool
	 */
	private function is_merchant_matched(): bool {
		$notify_mer_id   = (string) ( $this->inner_payload['MerID'] ?? '' );
		$settings_mer_id = PayuniSettingsDTO::instance()->merchant_id;

		if ( '' === $settings_mer_id ) {
			return true;
		}

		return \hash_equals( $settings_mer_id, $notify_mer_id );
	}
}
