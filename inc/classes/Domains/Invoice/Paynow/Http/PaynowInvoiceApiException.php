<?php
/**
 * PayNow 立吉富電子發票 API 失敗例外（攜帶原始錯誤型別 / 訊息 / 種類）
 *
 * 為什麼需要：原本 {@see InvoiceApiClient::decode_result()} 失敗時丟裸 \RuntimeException，
 * 訊息塞了 type / message 但「結構化的原始事實」對呼叫端不可取——provider 無從區分
 * validation_error（驗證）與 rejected（重複 / 查無 / 認證）與連線失敗。
 *
 * 本例外把三項關鍵事實結構化攜帶，讓 client 能在 catch 時轉成「錯誤明細」供 provider
 * 的 map_error() 做權威映射（正規化錯誤模型的 raw_code / raw_message 來源）：
 *   - raw_code    PayNow 原始錯誤「型別」（外層 type 值，如 'validation_error' / 'rejected' / 'failed'）。
 *                 ⚠️ PayNow 發票 API 官方未提供數字錯誤碼對照表，僅以 type + message 表達失敗，
 *                 故此處 raw_code 採用「外層 type」作為最穩定的權威分類來源。
 *   - raw_message PayNow 原始錯誤訊息（外層 message；map_error 以此做關鍵字補強分類）。
 *   - kind        粗分類提示，讓 client 廉價標記非業務型別類錯誤：
 *                   'signature' = 驗章失敗（PayNow 發票純 Bearer 認證、無對稱簽章，正常情形不會出現；
 *                                 保留常數以對齊 4 provider 模板，若未來出現驗章類錯誤可用）。
 *                   'business'  = 外層 type 非 success 的業務錯誤（→ provider map_error(type, message)）。
 *                   'network'   = 對外 HTTP 連線失敗 / 逾時（→ provider 映射 NETWORK）。
 *                   'decode'    = JSON / 型別 / 結構解析失敗（→ provider 映射 PROVIDER）。
 *
 * 注意：本例外僅在 client 內部流通，provider 不直接 catch 本型別——provider 讀的是
 * client 經 catch 後落地的「正規化錯誤明細」（{@see InvoiceApiClient::get_last_error_detail()}）。
 *
 * @see InvoiceApiClient::decode_result()
 * @see .claude/skills/paynow/references/invoice-api.md
 * @see .claude/skills/paynow/references/error-codes.md §10 體系 1/3 REST 狀態碼
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\Http;

/** PayNow 立吉富電子發票 API 失敗例外（攜帶原始錯誤型別 / 訊息 / 種類） */
final class PaynowInvoiceApiException extends \RuntimeException {

	/** @var string 種類：驗章失敗（PayNow 發票無對稱簽章，保留以對齊模板）→ provider 映射 SIGNATURE */
	public const KIND_SIGNATURE = 'signature';

	/** @var string 種類：外層 type 非 success 的業務錯誤 → provider map_error(raw_code=type, raw_message=message) */
	public const KIND_BUSINESS = 'business';

	/** @var string 種類：JSON / 型別 / 結構解析失敗 → provider 映射 PROVIDER */
	public const KIND_DECODE = 'decode';

	/** @var string 種類：對外 HTTP 連線失敗 / 逾時（wp_remote_* 回 WP_Error）→ provider 映射 NETWORK */
	public const KIND_NETWORK = 'network';

	/**
	 * Constructor
	 *
	 * @param string $message    例外訊息（給 log；非使用者面）.
	 * @param string $raw_code   PayNow 原始錯誤型別（外層 type 值；解析 / 連線失敗時為空字串）.
	 * @param string $rawMessage PayNow 原始錯誤訊息（外層 message）.
	 * @param string $kind       失敗種類（KIND_SIGNATURE / KIND_BUSINESS / KIND_DECODE / KIND_NETWORK）.
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
	 * 取得 PayNow 原始錯誤型別（外層 type 值）
	 *
	 * @return string 原始錯誤型別；解析 / 連線失敗時為空字串.
	 */
	public function get_raw_code(): string {
		return $this->raw_code;
	}

	/**
	 * 取得 PayNow 原始錯誤訊息（外層 message）
	 *
	 * @return string 原始錯誤訊息.
	 */
	public function get_raw_message(): string {
		return $this->rawMessage;
	}

	/**
	 * 取得失敗種類提示
	 *
	 * @return string KIND_SIGNATURE / KIND_BUSINESS / KIND_DECODE / KIND_NETWORK.
	 */
	public function get_kind(): string {
		return $this->kind;
	}
}
