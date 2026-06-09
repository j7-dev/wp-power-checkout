<?php
/**
 * PAYUNi UNi Embed V3 StatusManager（merchant_trade / NotifyURL 解密後內層 → 訂單狀態）
 *
 * 比照 Payuni\Managers\StatusManager，但收斂為「信用卡 only」：
 *  - 無 TradeStatus=0（取號）分支（UNi Embed 無 ATM / CVS 取號流程）。
 *  - TradeStatus=1（已付款）→ 金額防竄改 → payment_complete() 轉 processing + 寫付款明細。
 *  - TradeStatus=2 / 3 / 8 / 未知 → 維持 pending + order note。
 *
 * ⚠️ 資安鐵律（與 UPP 一致）：
 *  - 金額 / 狀態判定一律以「驗章後的 PAYUNi 回傳資料」為準，絕不信任前端。
 *  - 轉 processing 前比對內層 TradeAmt（int）與訂單應收（ceil 整數）；不符 → 維持 pending + 告警。
 *  - 外層 Status 非 SUCCESS、MerID 不符商店設定 → 一律不更新訂單。
 *  - 冪等：已 processing 不重複 payment_complete。
 *
 * ⚠️ Cycle 1 僅建立骨架；實際觸發點（merchant_trade 回應 / NotifyURL callback）於 Cycle 2 / 3 接上。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §merchant_trade 回傳 §NotifyURL
 * @see \J7\PowerCheckout\Domains\Payment\Payuni\Managers\StatusManager UPP 對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Managers;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Enums\PayuniUniEmbedTradeStatus;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\WpUtils\Classes\WP;

/** PAYUNi UNi Embed V3 訂單狀態管理 */
final class StatusManager {

	/** @var string 外層 / 內層成功狀態值 */
	private const STATUS_SUCCESS = 'SUCCESS';

	/** @var string UNi Embed 交易標記 Gateway 固定值（9=IFrame，與 UPP 的 2 區隔，防 UPP 回調混入 UNi Embed 路徑） */
	private const GATEWAY_VALUE = '9';

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
		// 最後防線 1：外層 / 內層 Status 非 SUCCESS → 維持 pending。
		$status = (string) ( $this->inner_payload['Status'] ?? '' );
		if ( self::STATUS_SUCCESS !== $status ) {
			$this->note_non_success();
			return;
		}

		// 最後防線 2：MerID 不符商店設定 → 防跨商店污染，維持 pending。
		if ( ! $this->is_merchant_matched() ) {
			$this->order->add_order_note(
				\sprintf(
					'PAYUNi UNi Embed 付款通知 MerID 不符商店設定（通知 MerID：%s），維持等待付款',
					(string) ( $this->inner_payload['MerID'] ?? '' )
				)
			);
			return;
		}

		$trade_status = PayuniUniEmbedTradeStatus::tryFrom( (int) ( $this->inner_payload['TradeStatus'] ?? -1 ) );

		// TradeStatus=1（已付款）→ 金額防竄改 → payment_complete
		if ( null !== $trade_status && $trade_status->is_paid() ) {
			$this->handle_paid();
			return;
		}

		// TradeStatus=2 / 3 / 8 / 未知 → 維持 pending + order note
		$this->handle_unpaid( $trade_status );
	}

	/**
	 * 處理已付款（TradeStatus=1）
	 *
	 * 冪等：已 processing 不重複 payment_complete。
	 * 金額防竄改：TradeAmt 不符 → 維持 pending + 告警，絕不 payment_complete。
	 *
	 * @return void
	 */
	private function handle_paid(): void {
		// 冪等：已 processing 則不重複 payment_complete（PAYUNi 可能重送通知）
		if ( $this->order->has_status( OrderStatus::PROCESSING->value ) ) {
			return;
		}

		// Gateway 守衛：UNi Embed 回傳 Gateway 固定為 9（IFrame）。
		// 非 9（如 UPP 的 2）代表交易來源錯置 / 偽造 → 維持 pending（防 UPP 回調混入 UNi Embed 路徑）。
		if ( ! $this->is_gateway_matched() ) {
			$this->order->add_order_note(
				\sprintf(
					'PAYUNi UNi Embed 付款通知 Gateway 不符（通知 Gateway：%s，應為 %s），維持等待付款（疑似交易來源錯置 / 偽造）',
					(string) ( $this->inner_payload['Gateway'] ?? '' ),
					self::GATEWAY_VALUE
				)
			);
			return;
		}

		// 金額防竄改：TradeAmt 不符 → 維持 pending + 告警 note
		if ( ! $this->is_amount_matched() ) {
			$this->order->add_order_note(
				\sprintf(
					'PAYUNi UNi Embed 付款通知金額不符，通知 TradeAmt：%s，訂單應收：%d，維持等待付款（疑似竄改）',
					(string) ( $this->inner_payload['TradeAmt'] ?? '' ),
					$this->get_order_amount()
				)
			);
			return;
		}

		// 寫付款明細（整個內層通知，供後台顯示與退款分流）
		( new PayuniUniEmbedMetaKeys( $this->order ) )->update_payment_detail( $this->inner_payload );

		$this->order->add_order_note(
			WP::array_to_html( $this->inner_payload, [ 'title' => 'PAYUNi UNi Embed 付款成功通知' ] )
		);

		$trade_no = (string) ( $this->inner_payload['TradeNo'] ?? '' );
		$this->order->payment_complete( $trade_no );
		$this->order->update_status( OrderStatus::PROCESSING->value );
	}

	/**
	 * 處理非付款成功（TradeStatus=2 / 3 / 8 / 未知）
	 *
	 * @param PayuniUniEmbedTradeStatus|null $trade_status 交易狀態（未知值為 null）
	 * @return void
	 */
	private function handle_unpaid( ?PayuniUniEmbedTradeStatus $trade_status ): void {
		$label = null !== $trade_status ? $trade_status->label() : '未知狀態';
		$this->order->add_order_note(
			\sprintf(
				'PAYUNi UNi Embed 付款未完成（%s），Status：%s，Message：%s，TradeStatus：%s',
				$label,
				(string) ( $this->inner_payload['Status'] ?? '' ),
				(string) ( $this->inner_payload['Message'] ?? '' ),
				(string) ( $this->inner_payload['TradeStatus'] ?? '' )
			)
		);
	}

	/**
	 * 外層 / 內層 Status 非 SUCCESS 的記錄（維持 pending）
	 *
	 * @return void
	 */
	private function note_non_success(): void {
		$this->order->add_order_note(
			\sprintf(
				'PAYUNi UNi Embed 通知 Status 非 SUCCESS（Status：%s，Message：%s），維持等待付款',
				(string) ( $this->inner_payload['Status'] ?? '' ),
				(string) ( $this->inner_payload['Message'] ?? '' )
			)
		);
	}

	/**
	 * 比對通知金額（內層 TradeAmt）是否等於訂單應收總額
	 *
	 * 資安強化：先以「純正整數字串」格式驗證 TradeAmt，再比對數值。
	 *  - 非純數字（如 '1000abc' / '-1000'）→ ctype_digit 為 false → 直接視為不符（防 (int) cast 截斷誤判）。
	 *  - '0' / 空字串 → 視為不符（信用卡金額不可能為 0）。
	 *  - 通過格式後，數值須等於訂單應收（ceil 整數）。
	 *
	 * @return bool
	 */
	private function is_amount_matched(): bool {
		$raw = (string) ( $this->inner_payload['TradeAmt'] ?? '' );

		// 格式守衛：必須為純數字字串（不含 +/-/小數點/英文），且去除前導零後仍 > 0。
		// ctype_digit 對 '1000abc' / '-1000' / '' 一律回 false，避免 (int) cast 截斷後誤判相符。
		if ( '' === $raw || ! \ctype_digit( $raw ) ) {
			return false;
		}

		$trade_amt = (int) $raw;
		if ( $trade_amt <= 0 ) {
			return false;
		}

		return $trade_amt === $this->get_order_amount();
	}

	/**
	 * 通知 Gateway 是否為 UNi Embed 固定值（9=IFrame）
	 *
	 * UNi Embed 與 UPP 在同一 PAYUNi 平台並列；UPP 回傳 Gateway=2、UNi Embed=9。
	 * 此守衛確保 UNi Embed StatusManager 僅接受 Gateway=9 的 payload，
	 * 防 UPP 回調誤觸發到 UNi Embed 路徑（以低安全等級流程接收高安全等級交易）。
	 *
	 * @return bool
	 */
	private function is_gateway_matched(): bool {
		$gateway = (string) ( $this->inner_payload['Gateway'] ?? '' );
		return self::GATEWAY_VALUE === $gateway;
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
	 * 缺商店設定 MerID（如測試未填）時不做此防線（回 true）。
	 *
	 * @return bool
	 */
	private function is_merchant_matched(): bool {
		$notify_mer_id   = (string) ( $this->inner_payload['MerID'] ?? '' );
		$settings_mer_id = PayuniUniEmbedSettingsDTO::instance()->merchant_id;

		if ( '' === $settings_mer_id ) {
			return true;
		}

		return \hash_equals( $settings_mer_id, $notify_mer_id );
	}
}
