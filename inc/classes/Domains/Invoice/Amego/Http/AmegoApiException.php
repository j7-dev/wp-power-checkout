<?php
/**
 * 光貿 Amego API 失敗例外（攜帶原始錯誤碼 / 訊息 / 種類）
 *
 * 為什麼需要：原本 {@see Requester} 在 send() / query() 失敗時丟裸 \Exception（訊息塞了
 * code/msg），且 post() / post_data() / query() 一律 catch 後回 null —— 「結構化的原始碼」對
 * 上層 provider 不可取。provider 無從區分 code=16（簽章驗證錯誤）、code=22（尚未申請 API 串接）、
 * code=3050141（已開折讓無法作廢）與對外連線失敗。
 *
 * 本例外把三項關鍵事實結構化攜帶，讓 Requester 能在 catch 時轉成「錯誤明細」供 provider
 * 的 map_error() 做權威映射（正規化錯誤模型的 raw_code / raw_message 來源）：
 *   - raw_code    Amego 原始錯誤碼（外層 code，數字字串，如 '16' / '22' / '3050141'；驗章 ezPay 不同，
 *                 Amego 的 code=16 即「簽章驗證錯誤」，故 sign 失敗也走 business kind 帶 raw_code='16'）。
 *   - raw_message Amego 原始錯誤訊息（外層 msg）。
 *   - kind        粗分類提示，讓 Requester 廉價標記非業務碼類錯誤：
 *                   'business'  = 外層 code 非 0 的業務錯誤碼（→ provider map_error(raw_code)）
 *                   'network'   = 對外 HTTP 連線失敗 / 逾時（wp_remote_post 回 WP_Error）→ provider 映射 NETWORK
 *                   'decode'    = JSON / 型別 / 結構解析失敗（→ provider 映射 PROVIDER）
 *
 * 注意：本例外僅在 Http 層（Requester）內部流通，provider 不直接 catch 本型別——provider 讀的是
 * Requester 經 catch 後落地的「正規化錯誤明細」（{@see Requester::get_last_error_detail()}）。
 *
 * @see Requester::send()
 * @see .claude/skills/amego-invoice/references/error-codes.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Http;

/** 光貿 Amego API 失敗例外（攜帶原始錯誤碼 / 訊息 / 種類） */
final class AmegoApiException extends \RuntimeException {

	/** @var string 種類：外層 code 非 0 的業務錯誤碼（含 code=16 簽章驗證錯誤） */
	public const KIND_BUSINESS = 'business';

	/** @var string 種類：對外 HTTP 連線失敗 / 逾時（wp_remote_post 回 WP_Error）→ provider 映射 NETWORK */
	public const KIND_NETWORK = 'network';

	/** @var string 種類：JSON / 型別 / 結構解析失敗 → provider 映射 PROVIDER */
	public const KIND_DECODE = 'decode';

	/**
	 * Constructor
	 *
	 * @param string $message    例外訊息（給 log；非使用者面）.
	 * @param string $raw_code   Amego 原始錯誤碼（外層 code 字串；連線 / 解析失敗時為空字串）.
	 * @param string $rawMessage Amego 原始錯誤訊息（外層 msg）.
	 * @param string $kind       失敗種類（KIND_BUSINESS / KIND_NETWORK / KIND_DECODE）.
	 */
	public function __construct(
		string $message,
		private readonly string $raw_code = '',
		private readonly string $rawMessage = '',
		private readonly string $kind = self::KIND_BUSINESS,
	) {
		parent::__construct( $message );
	}

	/**
	 * 取得 Amego 原始錯誤碼（外層 code 字串）
	 *
	 * @return string 原始錯誤碼；連線 / 解析失敗時為空字串.
	 */
	public function get_raw_code(): string {
		return $this->raw_code;
	}

	/**
	 * 取得 Amego 原始錯誤訊息（外層 msg）
	 *
	 * @return string 原始錯誤訊息.
	 */
	public function get_raw_message(): string {
		return $this->rawMessage;
	}

	/**
	 * 取得失敗種類提示
	 *
	 * @return string KIND_BUSINESS / KIND_NETWORK / KIND_DECODE.
	 */
	public function get_kind(): string {
		return $this->kind;
	}
}
