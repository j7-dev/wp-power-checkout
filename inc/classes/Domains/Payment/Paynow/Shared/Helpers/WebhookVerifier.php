<?php
/**
 * PayNow Webhook（payment_result）HMAC-SHA256 驗簽器（資安核心）
 *
 * PayNow 體系 1 無對稱加密；Webhook 驗簽改用 HMAC-SHA256（key = PrivateKey），
 * 與 PAYUNi AES-256-GCM / ezPay AES-256-CBC / ECPay AES-128-CBC 不同源，**不可複用 PayuniCrypto**。
 *
 * 簽章規格（encryption.md §2 / php-examples.md §2）：
 *  - Header 名稱：X-Payment-Center-Hmac-Sha256。
 *  - 簽章計算：strtoupper(hash_hmac('sha256', raw_body, PrivateKey))。
 *  - 驗簽對象：**raw body**（原始 request body 字串；絕不 json_decode 後 re-encode，
 *    否則 key 順序 / 空白 / unicode escape 改變導致驗簽永遠失敗）。
 *  - 比對方式：hash_equals（timing-safe，防 timing attack）。
 *  - 大小寫正規化：兩端 strtoupper 後比對（容忍 PayNow 大小寫差異）。
 *
 * 資安守衛：
 *  - 簽章長度守衛：SHA256 hex 輸出固定 64 字元；長度不符（空字串 / 'TOOSHORT' 等）
 *    在進入 hash_equals 前就拒絕，避免 hash_equals('', '') 之類的空字串繞過。
 *  - verify() 為 pure computation（deterministic，無副作用）。
 *
 * @see .claude/skills/paynow/references/encryption.md §2（HMAC-SHA256 規格）
 * @see .claude/skills/paynow/references/php-examples.md §2（PaynowWebhookVerifier turnkey）
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 16
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers;

/** PayNow Webhook HMAC-SHA256 驗簽器 */
final class WebhookVerifier {

	/** @var int SHA256 hex 輸出固定長度（簽章長度守衛） */
	private const SIGNATURE_LENGTH = 64;

	/**
	 * Constructor
	 *
	 * @param string $private_key PayNow PrivateKey（HMAC 金鑰；不可公開）
	 */
	public function __construct(
		private readonly string $private_key,
	) {}

	/**
	 * 驗證 Webhook 簽章
	 *
	 * 流程：
	 *  1. 簽章長度守衛：正規化（trim + strtoupper）後須為 64 字元 hex；不符直接 false
	 *     （防空字串 / 短字串透過 hash_equals 繞過）。
	 *  2. 以 PrivateKey 對 raw_body 計算 HMAC-SHA256，strtoupper 正規化。
	 *  3. hash_equals timing-safe 比對（竄改 body / 竄改 sig / 錯誤 key 皆失敗）。
	 *
	 * ⚠️ 不對 raw_body 做長度守衛——空 body + 對應的正確空 body HMAC 應通過
	 *    （verify 本身是 pure computation；空 body 的攔截屬於 Callback 層職責）。
	 *
	 * @param string $raw_body  原始 request body（驗簽對象，勿 re-encode）
	 * @param string $signature Header X-Payment-Center-Hmac-Sha256 的值
	 * @return bool 驗簽通過回 true；否則 false
	 */
	public function verify( string $raw_body, string $signature ): bool {
		// 1. 簽章長度守衛（SHA256 hex 固定 64 字元；空字串 / 過短一律拒絕）
		$normalized_sig = \strtoupper( \trim( $signature ) );
		if ( self::SIGNATURE_LENGTH !== \strlen( $normalized_sig ) ) {
			return false;
		}

		// 2. 以 PrivateKey 對 raw body 計算 HMAC-SHA256（大寫正規化）
		$calculated = \strtoupper( \hash_hmac( 'sha256', $raw_body, $this->private_key ) );

		// 3. timing-safe 比對
		return \hash_equals( $calculated, $normalized_sig );
	}
}
