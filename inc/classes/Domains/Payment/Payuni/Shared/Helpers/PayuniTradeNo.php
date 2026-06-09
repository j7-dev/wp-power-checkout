<?php
/**
 * PAYUNi UPP V2 交易單號 MerTradeNo 編解碼（冪等）
 *
 * PAYUNi MerTradeNo 規格（payuni-upp-v2 §EncryptInfo 通用請求參數）：
 *  - 長度 ≤ 25 碼。
 *  - 字元集 [A-Za-z0-9_-]。
 *  - 同一 MerID 10 分鐘內不可重複（UPP01007）。
 *
 * 設計：採「冪等」單號 —— 同一 order_id 多次呼叫產生相同單號。
 *   意圖：訂單付款重試時用相同 MerTradeNo 觸發 PAYUNi 去重保護，避免重複建單；
 *   且單號內嵌 order_id，NotifyURL / ReturnURL 回呼可直接反查訂單。
 *
 * 格式：PCU{order_id}（純由 order_id 衍生，不含 timestamp → 冪等）。
 *   - PCU 前綴：辨識本外掛 PAYUNi 單號 + 與其他 provider 區隔。
 *   - order_id 十進位：order_id ≤ 22 位數時仍 ≤25 字元（實務 DB id 遠小於此）。
 *
 * ⚠️ 對齊 EcpayAIO / NewebpayMpg TradeNo 模式（含 order_id 可反查），但 PAYUNi 強調冪等，
 *    故刻意不含 timestamp（藍新 / 綠界範本含 timestamp 是為了 per-merchant 唯一，語意不同）。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md §MerTradeNo
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers;

/** PAYUNi UPP V2 MerTradeNo 編解碼（冪等） */
final class PayuniTradeNo {

	/** @var int MerTradeNo 長度上限（PAYUNi 規格） */
	private const MAX_LENGTH = 25;

	/** @var string 前綴（英數，便於辨識與反解） */
	private const PREFIX = 'PCU';

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
