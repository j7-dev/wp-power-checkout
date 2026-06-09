<?php
/**
 * PAYUNi UNi Embed V3 交易單號 MerTradeNo 編解碼（冪等）
 *
 * PAYUNi MerTradeNo 規格（payuni-uni-embed-v3 §EncryptInfo 內層 merchant_trade）：
 *  - 長度 ≤ 25 碼。
 *  - 字元集 [A-Za-z0-9_-]。
 *  - 同一 MerID 10 分鐘內不可重複。
 *
 * ⚠️ V3 特性：MerTradeNo 在 merchant_trade（Cycle 2 create-payment）階段才送，
 *    token_get 階段「不送」訂單欄位。本 helper 僅負責「冪等生成 / 反解」單號，
 *    供 merchant_trade 與 NotifyURL 反查訂單使用（與 UPP 的 PayuniTradeNo 同設計）。
 *
 * 設計：採「冪等」單號 —— 同一 order_id 多次呼叫產生相同單號（純由 order_id 衍生，不含 timestamp）。
 *   意圖：付款重試時用相同 MerTradeNo 觸發 PAYUNi 去重保護，避免重複建單；單號內嵌 order_id 可反查訂單。
 *
 * 格式：PCE{order_id}。
 *   - PCE 前綴（PAYUNi Checkout Embed）：與 UPP 的 PCU 前綴明確區隔，兩者不撞。
 *   - order_id 十進位：實務 DB id 遠小於 ≤25 字元上限。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §MerTradeNo
 * @see \J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo UPP 對照（前綴 PCU）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers;

/** PAYUNi UNi Embed V3 MerTradeNo 編解碼（冪等） */
final class PayuniUniEmbedTradeNo {

	/** @var int MerTradeNo 長度上限（PAYUNi 規格） */
	private const MAX_LENGTH = 25;

	/** @var string 前綴（PAYUNi Checkout Embed，與 UPP 的 PCU 區隔） */
	private const PREFIX = 'PCE';

	/**
	 * 由訂單 ID 生成冪等的 MerTradeNo（≤25 碼、僅 [A-Za-z0-9_-]）
	 *
	 * 純函數：相同 order_id 必產生相同單號（冪等），不同 order_id 必不同。
	 *
	 * @param int $order_id 訂單 ID
	 * @return string MerTradeNo
	 */
	public static function generate( int $order_id ): string {
		$trade_no = self::PREFIX . $order_id;
		// 防禦：理論上 order_id 不會讓單號超過 25 碼，仍截斷以符合 PAYUNi 硬限制
		return \substr( $trade_no, 0, self::MAX_LENGTH );
	}

	/**
	 * 由 MerTradeNo 反解出訂單 ID
	 *
	 * 容錯：前綴不符 / 數字段缺失 → 回 null（不拋例外）。
	 *
	 * @param string $trade_no MerTradeNo
	 * @return int|null 訂單 ID（無法解析時回 null）
	 */
	public static function parse_order_id( string $trade_no ): int|null {
		if ( ! \str_starts_with( $trade_no, self::PREFIX ) ) {
			return null;
		}

		$without_prefix = \substr( $trade_no, \strlen( self::PREFIX ) );

		// 前綴後必須是純數字（order_id 十進位），否則視為不合法
		if ( '' === $without_prefix || ! \ctype_digit( $without_prefix ) ) {
			return null;
		}

		return (int) $without_prefix;
	}
}
