<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Interfaces;

/**
 * 金流 Provider 統一介面
 *
 * 第一性原理依據：
 *   Logistics 領域有 `ILogisticsProvider`（10 方法）、Invoice 領域有 `IInvoiceService`，
 *   唯獨 Payment 領域僅有極簡的單方法 `IGateway`（只定義 process_payment）。
 *   三個 provider 領域應共享一致的抽象哲學 —— Payment 不該是唯一例外。
 *
 *   `IPaymentProvider` 形式化既有契約（process_payment / process_refund / get_settings），
 *   並補上 4 個能力差異方法（query_trade / capture / void_auth / get_supported_payment_methods）。
 *   這 4 個能力方法由 `AbstractPaymentGateway` 提供安全預設實作（no-op 或回空陣列），
 *   讓不支援該能力的金流不被強迫實作，而支援的金流則自行覆寫。
 *
 *   本介面 extends `IGateway` 以維持向後相容：既有 `implements IGateway` 的 gateway
 *   透過繼承鏈即自動滿足本介面，無需逐支改動 class 宣告。
 *
 * @see \J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider 物流領域對照
 */
interface IPaymentProvider extends IGateway {

	/**
	 * 處理付款
	 *
	 * @param int $order_id 訂單 ID
	 * @return array{result: string, redirect?: string}
	 * @throws \Exception 如果訂單不存在
	 */
	public function process_payment( $order_id ): array;

	/**
	 * 處理退款
	 *
	 * 契約（never-throw）：
	 *   - 成功 → 回傳 `true`。
	 *   - 失敗 → 回傳經 {@see \J7\PowerCheckout\Shared\Errors\NormalizedError::from()} 建立的
	 *     正規化 `\WP_Error`（$code = {@see \J7\PowerCheckout\Shared\Errors\ErrorCode} 的 value；
	 *     $data 帶 raw_code / raw_message / provider / raw 四鍵）。
	 *   - **絕不 throw**：catch `\Throwable` 後一律改回正規化 `\WP_Error`，
	 *     不向 WooCommerce 退款流程拋例外（避免中斷退款）。
	 *
	 * 各正規化 code 適用情境（範式，供各金流覆寫時對齊）：
	 *   - 付款方式不支援 API 退款 → `ErrorCode::UNSUPPORTED`（且不打第三方 API）。
	 *   - 退款金額不合法（超出可退餘額 / ≤0 / 必填缺）→ `ErrorCode::VALIDATION`（且不打 API）。
	 *   - 商店憑證 / 簽章金鑰錯誤 → `ErrorCode::AUTH`。
	 *   - 第三方連線失敗 / 逾時 → `ErrorCode::NETWORK`。
	 *   - 未預期 `\Throwable` → `ErrorCode::UNKNOWN`（並記 order note）。
	 *
	 * 註：型別保留 `bool|\WP_Error`（不窄化為 `true|\WP_Error`），
	 * 以維持與 `\WC_Payment_Gateway::process_refund` 的相容；
	 * 但語義上失敗一律回正規化 `\WP_Error`，不再回裸 `false`。
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error 成功為 true，失敗為正規化 \WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' );

	/**
	 * 查詢交易 / 補單
	 * 不支援查詢的金流回傳空陣列（安全預設）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 交易查詢結果，預設為空陣列
	 */
	public function query_trade( \WC_Order $order ): array;

	/**
	 * 請款（capture）
	 * 不支援線上請款的金流為 no-op（安全預設，不 throw）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function capture( \WC_Order $order ): void;

	/**
	 * 取消授權（void authorization）
	 * 不支援取消授權的金流為 no-op（安全預設，不 throw）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function void_auth( \WC_Order $order ): void;

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否包含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 設定陣列
	 */
	public static function get_settings( bool $with_default = true ): array;

	/**
	 * 取得支援的付款方式清單
	 * 預設回傳空陣列（安全預設）
	 *
	 * @return array<int|string, mixed> 支援的付款方式清單
	 */
	public function get_supported_payment_methods(): array;
}
