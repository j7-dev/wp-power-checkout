<?php
/**
 * 統一物流抽象 ILogisticsProvider
 *
 * 鏡像 Invoice\Shared\Interfaces\IInvoiceService，吸收兩階段選店
 * （ECPay get_store_selection → parse_store_selection → create_shipment 三步）
 * 與 callback 回應格式差異（ECPay AES-JSON 三層 vs 未來 PAYUNi 200-OK，差異收進 provider）。
 *
 * 設計可容未來 PAYUNi（本次只實作 ecpay_logistics）。
 * 回傳統一 array<string, mixed>（不在 interface 邊界引入 DTO）；
 * 各方法失敗一律 throw（REST 層 catch 轉 HTTP code）；
 * callback 例外吞掉仍回 AES-JSON（防 60 分重送風暴）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Interfaces;

interface ILogisticsProvider {

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否帶預設值（false = 只拿 DB 值）
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array;

	/**
	 * 階段 A：取得門市選擇導轉資訊（RWD 選店頁）
	 *
	 * 前置驗證 provider 啟用 / 訂單存在 / reply URL 非 localhost / sub_type ∈ enabled_methods，
	 * 組裝 RedirectToLogisticsSelection（含 IsCollection / Temperature）並 AES 加密。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（如 sub_type / payment_scenario）
	 *
	 * @return array<string, mixed> 含 redirect_target（RWD HTML）
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array;

	/**
	 * 解析選店回呼（ClientReplyURL）
	 *
	 * 解 ResultData → TempLogisticsID + 門市資訊，寫入 order meta。
	 *
	 * @param array<string, mixed> $raw 綠界 Form POST 原始資料（含 ResultData）
	 *
	 * @return array<string, mixed> 解析後的暫存單號與門市資訊
	 */
	public function parse_store_selection( array $raw ): array;

	/**
	 * 階段 B：成立物流單（CreateByTempTrade）
	 *
	 * 須先完成選店（order meta 有 TempLogisticsID），回傳統一物流單號 LogisticsID。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed> 含 logistics_id
	 */
	public function create_shipment( \WC_Order $order ): array;

	/**
	 * 查詢物流單（QueryLogisticsTradeInfo）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed> 含 logistics_id / status / store_info
	 */
	public function query_shipment( \WC_Order $order ): array;

	/**
	 * 列印物流單（PrintTradeDocument）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string 列印文件 HTML
	 */
	public function print_document( \WC_Order $order ): string;

	/**
	 * 取消物流單（C2C CancelC2COrder；僅 C2C 帳號支援）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed>
	 */
	public function cancel_shipment( \WC_Order $order ): array;

	/**
	 * 建立退貨單（逆物流）
	 *
	 * 由已出貨訂單建立逆物流單。前置：provider 啟用 / 訂單存在 /
	 * 已成立正向物流單（order meta 有 LogisticsID）/ reply URL 公開可訪問。
	 * 依原始 sub_type 分派各 provider 對應的退貨 API。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 前置驗證失敗或 API 呼叫失敗
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array;

	/**
	 * 處理貨態 callback（ServerReplyURL；回 AES-JSON 三層）
	 *
	 * 所有路徑（含例外）皆最終回 AES-JSON，避免綠界 60 分重送風暴。
	 *
	 * @param \WP_REST_Request $request 綠界 JSON body POST 請求
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response;

	/**
	 * 取得結帳頁可選的物流子類型（enabled_methods 子集）
	 *
	 * @return array<int, string> 子類型 value 陣列（如 ['FAMI', 'UNIMART', 'HOME']）
	 */
	public function get_supported_methods(): array;
}
