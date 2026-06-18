<?php
/**
 * 藍新 ezPay API 失敗例外（攜帶原始錯誤碼 / 訊息 / 種類）
 *
 * 為什麼需要：原本 {@see InvoiceApiClient::decode_result()} 失敗時丟裸 \RuntimeException，
 * 訊息塞了 Status 錯誤碼但「結構化的原始碼」對呼叫端不可取——provider 無從區分
 * LIB10007（已開折讓）與 KEY10002（金鑰錯）與 CheckCode 驗章失敗。
 *
 * 本例外把三項關鍵事實結構化攜帶，讓 client 能在 catch 時轉成「錯誤明細」供 provider
 * 的 map_error() 做權威映射（正規化錯誤模型的 raw_code / raw_message 來源）：
 *   - raw_code    ezPay 原始錯誤碼（外層 Status 值，如 'LIB10007' / 'KEY10002'）。
 *   - raw_message ezPay 原始錯誤訊息（外層 Message）。
 *   - kind        粗分類提示，讓 client 廉價標記非業務碼類錯誤：
 *                   'signature' = CheckCode 回應驗章失敗（→ provider 映射 SIGNATURE）
 *                   'business'  = 外層 Status 非 SUCCESS 的業務錯誤碼（→ provider map_error(raw_code)）
 *                   'network'   = 對外 HTTP 連線失敗 / 逾時（→ provider 映射 NETWORK）
 *                   'decode'    = JSON / 型別 / 結構解析失敗（→ provider 映射 PROVIDER）
 *
 * 注意：本例外僅在 client 內部流通，provider 不直接 catch 本型別——provider 讀的是
 * client 經 catch 後落地的「正規化錯誤明細」（{@see InvoiceApiClient::get_last_error_detail()}）。
 *
 * @see InvoiceApiClient::decode_result()
 * @see .claude/skills/ezpay-invoice/references/error-codes.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Http;

/** 藍新 ezPay API 失敗例外（攜帶原始錯誤碼 / 訊息 / 種類） */
final class EzpayApiException extends \RuntimeException {

	/** @var string ezPay 失敗種類提示：signature（驗章）/ business（業務碼）/ decode（解析） */
	public const KIND_SIGNATURE = 'signature';

	/** @var string 種類：外層 Status 非 SUCCESS 的業務錯誤碼 */
	public const KIND_BUSINESS = 'business';

	/** @var string 種類：JSON / 型別 / 結構解析失敗 */
	public const KIND_DECODE = 'decode';

	/** @var string 種類：對外 HTTP 連線失敗 / 逾時（wp_remote_post 回 WP_Error）→ provider 映射 NETWORK */
	public const KIND_NETWORK = 'network';

	/**
	 * Constructor
	 *
	 * @param string $message    例外訊息（給 log；非使用者面）.
	 * @param string $raw_code   ezPay 原始錯誤碼（外層 Status 值；驗章 / 解析失敗時為空字串）.
	 * @param string $rawMessage ezPay 原始錯誤訊息（外層 Message）.
	 * @param string $kind       失敗種類（KIND_SIGNATURE / KIND_BUSINESS / KIND_DECODE）.
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
	 * 取得 ezPay 原始錯誤碼（外層 Status 值）
	 *
	 * @return string 原始錯誤碼；驗章 / 解析失敗時為空字串.
	 */
	public function get_raw_code(): string {
		return $this->raw_code;
	}

	/**
	 * 取得 ezPay 原始錯誤訊息（外層 Message）
	 *
	 * @return string 原始錯誤訊息.
	 */
	public function get_raw_message(): string {
		return $this->rawMessage;
	}

	/**
	 * 取得失敗種類提示
	 *
	 * @return string KIND_SIGNATURE / KIND_BUSINESS / KIND_DECODE.
	 */
	public function get_kind(): string {
		return $this->kind;
	}
}
