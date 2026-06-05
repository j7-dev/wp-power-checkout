<?php
/**
 * 購物車（cart / session）級發票資訊暫存
 *
 * 第一性原理：block 結帳發票表單在「下單前」填寫，此時尚無 WC 訂單，無法寫 order meta。
 * 故先把「已 sanitize + validate」的發票參數暫存於當前 WC session，下單時
 * （woocommerce_store_api_checkout_order_processed）再搬進 order meta（同 classic 的
 * `_pc_issue_invoice_params` key），下游開立發票邏輯零改動沿用。
 *
 * 與 CartLogisticsSession 的差異：
 *   - 物流選店需離站導轉綠界 RWD 頁，回呼可能不帶 cookie，故需 token → customer_id 索引。
 *   - 發票表單全程於結帳頁內 inline 填寫，由前端 extensionCartUpdate（帶 wp_rest nonce 的
 *     Store API 請求）寫入「當前 session」，無離站回呼，故僅需綁定當前 session，不需跨請求索引。
 *
 * 安全：寫入路徑為 Store API update callback（namespace pc_invoice），由 WC Store API 的
 * nonce 機制保護；本 helper 僅負責 session 讀寫，所有欄位驗證 / sanitize 由
 * InvoiceParamsValidator 於寫入前完成。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Helpers;

/** 購物車級發票資訊暫存 */
final class CartInvoiceSession {

	/** @var string WC session 內存放發票參數的鍵 */
	private const SESSION_KEY = 'pc_invoice_issue_params';

	/**
	 * 將發票參數寫入「當前 WC session」
	 *
	 * @param array<string, string> $params 已 sanitize + validate 的發票參數
	 * @return bool 是否成功寫入（session 不可用回 false）
	 */
	public static function store( array $params ): bool {
		$session = self::get_session();
		if (null === $session) {
			return false;
		}
		$session->set( self::SESSION_KEY, $params );
		$session->save_data();
		return true;
	}

	/**
	 * 讀取「當前 WC session」暫存的發票參數
	 *
	 * @return array<string, string>|null 發票參數，無則 null
	 */
	public static function get(): ?array {
		$session = self::get_session();
		if (null === $session) {
			return null;
		}
		$raw = $session->get( self::SESSION_KEY );
		if (!\is_array( $raw ) || [] === $raw) {
			return null;
		}
		/** @var array<string, string> $raw */
		return $raw;
	}

	/**
	 * 清除「當前 WC session」的發票參數暫存
	 *
	 * 下單搬 meta 後呼叫，避免殘留影響下一筆訂單。
	 *
	 * @return void
	 */
	public static function clear(): void {
		$session = self::get_session();
		if (null === $session) {
			return;
		}
		$session->set( self::SESSION_KEY, null );
		$session->save_data();
	}

	/**
	 * 取得當前 WC session（含 handler）
	 *
	 * ⚠️ WC()->session 執行期實為 WC_Session_Handler（含 save_data），但 stub 型別為抽象
	 * WC_Session；故回傳前 instanceof 收斂為具體 handler。
	 *
	 * @return \WC_Session_Handler|null
	 */
	private static function get_session(): ?\WC_Session_Handler {
		if (!\function_exists( 'WC' )) {
			return null;
		}
		/** @var \WC_Session_Handler|\WC_Session|null $session */
		$session = \WC()->session;
		if ($session instanceof \WC_Session_Handler) {
			return $session;
		}
		return null;
	}
}
