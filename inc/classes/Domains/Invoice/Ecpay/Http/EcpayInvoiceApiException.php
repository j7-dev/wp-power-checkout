<?php
/**
 * 綠界 Ecpay 發票 API 失敗例外（攜帶原始錯誤碼 / 訊息 / 種類）
 *
 * 為什麼需要：原本 {@see InvoiceApiClient::request()} / decode 流程失敗時丟裸 \Exception，
 * 訊息塞了 TransCode / RtnCode 但「結構化的原始碼」對呼叫端不可取——provider 無從區分
 * 「RtnCode 業務碼」（如字軌用罄 / 已作廢）與「外層 TransCode AES / CheckMacValue 驗章失敗」
 * 與「對外連線逾時」。
 *
 * 本例外把三項關鍵事實結構化攜帶，讓 client 在 catch 時轉成「錯誤明細」供 provider 的
 * map_error() 做權威映射（正規化錯誤模型的 raw_code / raw_message 來源）：
 *   - raw_code    綠界原始錯誤碼（內層 RtnCode 字串化，如 '10000009'；驗章 / 連線時為空字串）。
 *   - raw_message 綠界原始錯誤訊息（內層 RtnMsg 或外層 TransMsg；map_error 以此做中文 regex 補強）。
 *   - kind        粗分類提示，讓 client 廉價標記非業務碼類錯誤：
 *                   'signature' = 外層 TransCode≠1 之 CheckMacValue / AES 驗章失敗（→ provider 映射 SIGNATURE）
 *                   'business'  = 外層 TransCode=1 但內層 RtnCode≠1 的業務錯誤碼（→ provider map_error(raw_code, raw_message)）
 *                   'network'   = 對外 HTTP 連線失敗 / 逾時（→ provider 映射 NETWORK）
 *                   'decode'    = JSON / 型別 / 結構解析失敗（→ provider 映射 PROVIDER）
 *
 * 注意：本例外僅在 client 內部流通，provider 不直接 catch 本型別——provider 讀的是
 * client 經 catch 後落地的「正規化錯誤明細」（{@see InvoiceApiClient::get_last_error_detail()}）。
 *
 * 形狀對齊 ezPay 的 {@see \J7\PowerCheckout\Domains\Invoice\Ezpay\Http\EzpayApiException}（4 provider 模板）。
 *
 * @see InvoiceApiClient::request()
 * @see .claude/skills/ECPay-API-Skill/guides/20-error-codes-reference.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Http;

/** 綠界 Ecpay 發票 API 失敗例外（攜帶原始錯誤碼 / 訊息 / 種類） */
final class EcpayInvoiceApiException extends \RuntimeException {

	/** @var string 種類：外層 TransCode≠1 之 CheckMacValue / AES 驗章失敗（→ SIGNATURE） */
	public const KIND_SIGNATURE = 'signature';

	/** @var string 種類：外層 TransCode=1 但內層 RtnCode≠1 的業務錯誤碼（→ map_error） */
	public const KIND_BUSINESS = 'business';

	/** @var string 種類：JSON / 型別 / 結構解析失敗（→ PROVIDER） */
	public const KIND_DECODE = 'decode';

	/** @var string 種類：對外 HTTP 連線失敗 / 逾時（wp_remote_post 回 WP_Error）→ provider 映射 NETWORK */
	public const KIND_NETWORK = 'network';

	/**
	 * Constructor
	 *
	 * @param string $message    例外訊息（給 log；非使用者面）.
	 * @param string $raw_code   綠界原始錯誤碼（內層 RtnCode 字串化；驗章 / 連線 / 解析失敗時為空字串）.
	 * @param string $rawMessage 綠界原始錯誤訊息（內層 RtnMsg 或外層 TransMsg）.
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
	 * 取得綠界原始錯誤碼（內層 RtnCode 字串化）
	 *
	 * @return string 原始錯誤碼；驗章 / 連線 / 解析失敗時為空字串.
	 */
	public function get_raw_code(): string {
		return $this->raw_code;
	}

	/**
	 * 取得綠界原始錯誤訊息（內層 RtnMsg 或外層 TransMsg）
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
