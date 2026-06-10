<?php
/**
 * PayNow StatusManager（Webhook payment_result 驗簽後 → 訂單狀態）
 *
 * 完整化（Cycle 3）：在 Cycle 1「金額防竄改骨架」之上補完即時付款成功、離線付款兩階段、
 * 付款失敗、冪等等全分支。資料判定一律以「驗簽後的 PayNow 回傳資料」為準，絕不信任前端。
 *
 * 狀態分流（update_order_status）：
 *  0. 幣別守衛：訂單幣別 ≠ TWD → 維持 pending（PayNow 僅接 TWD，Amount 為 TWD 整數；
 *     store 預設幣別 USD，不守衛則 USD 訂單金額可能誤判相符）。
 *  1. Status=Success（即時付款 / 離線付款繳費完成）：
 *     冪等（已 processing skip）→ 金額防竄改（ctype_digit + ceil 比對）→ payment_complete()
 *     → processing + 寫 _pc_paynow_payment_detail。
 *  2. Status=Failed：維持 pending + order note（含 'Failed'）。
 *  3. 離線付款待繳（Status 非 Success/Failed 且 PaymentType=ATM/ConvenienceStore）：
 *     寫 _pc_paynow_payment_info（虛擬帳號 / 超商代碼待繳資訊）+ 維持 pending。
 *  4. 其餘（未知 Status / 空 Status）：維持 pending + order note。
 *
 * ⚠️ 與 PayuniUniEmbed StatusManager 差異：
 *  - 無 Gateway=9 守衛（PayNow 反查主鍵 PaymentIntentId 已保證來源；無 UPP/UNi Embed 混淆問題）。
 *  - 新增離線付款（payment_info + pending）分支——範本（信用卡 only）所無，須仔細。
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 17 §流程 3 §流程 4
 * @see specs/features/payment/paynow-callback.feature
 * @see specs/features/payment/paynow-payment-info.feature
 * @see \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Managers\StatusManager 範本對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Managers;

use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\WpUtils\Classes\WP;

/** PayNow 訂單狀態管理 */
final class StatusManager {

	/** @var string Webhook 成功狀態值（即時付款成功 / 離線付款繳費完成） */
	private const STATUS_SUCCESS = 'Success';

	/** @var string Webhook 失敗狀態值 */
	private const STATUS_FAILED = 'Failed';

	/** @var string PayNow 唯一受理幣別（Amount 為 TWD 整數） */
	private const CURRENCY_TWD = 'TWD';

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $payload 驗簽後的通知陣列（{ Status, Amount, PaymentIntentId, PaymentType, TransactionNo, Meta, ... }）
	 * @param \WC_Order            $order   訂單
	 */
	public function __construct(
		private readonly array $payload,
		private readonly \WC_Order $order,
	) {}

	/**
	 * 依通知更新訂單狀態
	 *
	 * @return void
	 */
	public function update_order_status(): void {
		// 最後防線 0：幣別守衛。PayNow 僅接 TWD；非 TWD（store 預設 USD）一律維持 pending，
		// 避免 USD 訂單金額（get_total）與 TWD Amount 在 ceil 後偶然相符而誤判付款成功。
		if ( ! $this->is_currency_twd() ) {
			$this->note(
				\sprintf(
					'PayNow 通知幣別守衛攔截：訂單幣別為 %s（PayNow 僅支援 TWD），維持等待付款',
					$this->order->get_currency()
				)
			);
			return;
		}

		$status = (string) ( $this->payload['Status'] ?? '' );

		// 分支 1：Status=Success（即時付款成功 / 離線付款繳費完成）→ 金額防竄改 → payment_complete
		if ( self::STATUS_SUCCESS === $status ) {
			$this->handle_success();
			return;
		}

		// 分支 2：Status=Failed → 維持 pending + order note（含 'Failed'）
		if ( self::STATUS_FAILED === $status ) {
			$this->note(
				\sprintf(
					'PayNow 付款失敗（Status：%s，PaymentType：%s），維持等待付款',
					$status,
					(string) ( $this->payload['PaymentType'] ?? '' )
				)
			);
			return;
		}

		// 分支 3：離線付款待繳（Status 非 Success/Failed 且 PaymentType 為待繳型 ATM/ConvenienceStore）
		// → 寫待繳資訊 + 維持 pending（顧客繳費後 PayNow 再推 Status=Success 走分支 1）。
		if ( $this->is_offline_pending() ) {
			$this->handle_offline_pending();
			return;
		}

		// 分支 4：其餘（未知 Status / 空 Status）→ 維持 pending + order note
		$this->note(
			\sprintf(
				'PayNow 通知 Status 非 Success/Failed（Status：%s），維持等待付款',
				$status
			)
		);
	}

	/**
	 * 處理付款成功（Status=Success）
	 *
	 * 冪等：已 processing 不重複 payment_complete（PayNow 可能重送通知）。
	 * 終態守衛（資安 F-1）：已 refunded / cancelled / completed → 不得被重放「復活」為 processing。
	 * 金額防竄改：Amount 不符 → 維持 pending + 告警，絕不 payment_complete。
	 *
	 * @return void
	 */
	private function handle_success(): void {
		// 冪等：已 processing 則 skip（重複通知不重複處理）
		if ( $this->order->has_status( OrderStatus::PROCESSING->value ) ) {
			return;
		}

		// 終態守衛（資安 F-1）：訂單已進入終態（refunded / cancelled / completed）時，
		// 即使收到合法且金額相符的成功通知（重放 / 重送），也絕不再 payment_complete()，
		// 避免終態訂單被「復活」為 processing。記可審計 order note 後 return。
		if ( $this->is_final_status() ) {
			$this->note(
				\sprintf(
					'PayNow 終態守衛攔截：訂單已為終態（%s），收到合法成功通知但拒絕復活為處理中（疑似重放 / 重送）',
					$this->order->get_status()
				)
			);
			return;
		}

		// 金額防竄改：Amount 不符 → 維持 pending + 告警 note
		if ( ! $this->is_amount_matched() ) {
			$this->note(
				\sprintf(
					'PayNow 付款通知金額不符，通知 Amount：%s，訂單應收：%d，維持等待付款（疑似竄改）',
					(string) ( $this->payload['Amount'] ?? '' ),
					$this->get_order_amount()
				)
			);
			return;
		}

		// 寫付款明細（整個通知，供後台顯示與退款分流）
		( new PaynowMetaKeys( $this->order ) )->update_payment_detail( $this->payload );

		$this->order->add_order_note(
			WP::array_to_html( $this->payload, [ 'title' => 'PayNow 付款成功通知' ] )
		);

		$transaction_no = (string) ( $this->payload['TransactionNo'] ?? '' );
		$this->order->payment_complete( $transaction_no );
		$this->order->update_status( OrderStatus::PROCESSING->value );
	}

	/**
	 * 處理離線付款待繳（ATM / 超商代碼，Webhook 兩階段第一階段）
	 *
	 * 寫入 _pc_paynow_payment_info（虛擬帳號 / 超商代碼 / 繳費期限），維持 pending；
	 * 待繳資訊優先取 payload['Meta']（PayNow Webhook 攜帶），無 Meta 時退而存整個 payload，
	 * 供 order-received 頁與後台顯示。
	 *
	 * @return void
	 */
	private function handle_offline_pending(): void {
		$meta = $this->payload['Meta'] ?? null;
		$info = \is_array( $meta ) && [] !== $meta ? $meta : $this->payload;

		( new PaynowMetaKeys( $this->order ) )->update_payment_info( $info );

		$this->note(
			\sprintf(
				'PayNow 離線付款待繳（%s），已寫入繳款資訊，等待顧客繳費',
				(string) ( $this->payload['PaymentType'] ?? '' )
			)
		);
	}

	/**
	 * 是否為離線付款待繳階段
	 *
	 * 條件：Status 非 Success/Failed（已於 update_order_status 排除）且 PaymentType 屬待繳型
	 * （ATM / ConvenienceStore，PaynowPaymentMethod::is_offline()）。
	 *
	 * @return bool
	 */
	private function is_offline_pending(): bool {
		$payment_type = (string) ( $this->payload['PaymentType'] ?? '' );
		$method       = PaynowPaymentMethod::tryFrom( $payment_type );

		return null !== $method && $method->is_offline();
	}

	/**
	 * 比對通知金額（Amount）是否等於訂單應收總額
	 *
	 * 資安強化：先以「純正整數字串」格式驗證 Amount，再比對數值。
	 *  - 非純數字（如 '999.99' / '-1000' / '999abc'）→ ctype_digit 為 false → 直接視為不符
	 *    （避免 (int) cast 截斷後誤判相符）。
	 *  - '0' / 空字串 / 欄位缺失 → 視為不符（成功交易金額不可能為 0）。
	 *  - 通過格式後，數值須等於訂單應收（ceil 整數）。
	 *
	 * @return bool
	 */
	private function is_amount_matched(): bool {
		$raw = (string) ( $this->payload['Amount'] ?? '' );

		if ( '' === $raw || ! \ctype_digit( $raw ) ) {
			return false;
		}

		$amount = (int) $raw;
		if ( $amount <= 0 ) {
			return false;
		}

		return $amount === $this->get_order_amount();
	}

	/**
	 * 訂單是否已進入終態（資安 F-1 重放守衛清單）
	 *
	 * 終態定義為「已退款 / 已取消 / 已完成」——這些狀態不應被任何後續成功通知逆轉。
	 * 比照 callback 層 / 本 StatusManager 既有寫法，直接使用 OrderStatus enum 值；
	 * 透過 WC_Order::has_status（接受字串陣列）一次比對，避免逐一硬編字串。
	 *
	 * ⚠️ 不含 pending：pending → processing 為正常付款轉換，不得被守衛誤擋（對照組）。
	 *
	 * @return bool
	 */
	private function is_final_status(): bool {
		return $this->order->has_status(
			[
				OrderStatus::REFUNDED->value,
				OrderStatus::CANCELLED->value,
				OrderStatus::COMPLETED->value,
			]
		);
	}

	/**
	 * 訂單幣別是否為 TWD
	 *
	 * @return bool
	 */
	private function is_currency_twd(): bool {
		return self::CURRENCY_TWD === $this->order->get_currency();
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
	 * 記錄 order note（維持 pending）
	 *
	 * @param string $message 訊息
	 * @return void
	 */
	private function note( string $message ): void {
		$this->order->add_order_note( $message );
	}
}
