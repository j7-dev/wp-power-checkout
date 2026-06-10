<?php
/**
 * PayNow 物流 PassCode 計算 Helper（立吉富體系 1，woomp 對齊）
 *
 * PassCode 是 PayNow 物流 API 的請求驗章，規則（R6，woomp 實證）：
 *   PassCode = strtoupper( sha1( user_account . OrderNo . TotalAmount . apicode ) )
 *
 * ⚠️ TotalAmount 為純字串串接——格式敏感：
 *   "1000" 與 "1000.00" 會產生不同 sha1。實際應用須以 $order->get_total() 原值帶入，
 *   且須與 PayNow 後台對 TotalAmount 的格式預期一致，否則建單會被拒（PassCode 驗證失敗）。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 2 / §R6
 * @see ../woomp/.../shippings/api/class-paynow-shipping-request.php L780（build_pass_code）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers;

/** PayNow 物流 PassCode 計算 Helper */
final class PassCodeService {

	/**
	 * 計算 PassCode（大寫 SHA1，純字串串接）
	 *
	 * @param string $user_account PayNow 商家帳號
	 * @param string $order_no     訂單編號（OrderNo；建單用 PCN{order_id}，重新取號用 LogisticNumber）
	 * @param string $total        訂單金額（$order->get_total() 原值；⚠️ 格式敏感）
	 * @param string $apicode      商家 API 密碼
	 * @return string 40 字元大寫 SHA1 hex
	 */
	public static function build(
		string $user_account,
		string $order_no,
		string $total,
		string $apicode
	): string {
		return \strtoupper( \sha1( $user_account . $order_no . $total . $apicode ) );
	}
}
