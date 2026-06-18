<?php
/**
 * 正規化錯誤碼（領域中立，發票 + 金流共用）
 *
 * einvoice 導入第一階段地基：把各 provider 的原始錯誤碼歸一為一組固定的正規化 code，
 * 讓呼叫端能判斷失敗類型、前端能顯示精確訊息、REST 層能映射為適當的 HTTP 狀態碼。
 *
 * 放於 Shared 領域（非任何單一 provider 命名空間），供 Invoice 4 provider
 * 與 Payment 6+ 金流共用。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Errors;

/**
 * 正規化錯誤碼 enum（backed string，value = case 名）
 *
 * 共 10 值。對應實作計劃「正規化 code 值域」表。
 */
enum ErrorCode: string {

	/** 認證失敗（金鑰 / JWT / 商店憑證 / 簽章金鑰錯誤，如 ezPay KEY10002） */
	case AUTH = 'AUTH';

	/** 驗證失敗（UBN checksum / 載具捐贈互斥 / 金額守恆 / 退款金額不合法 / 必填缺） */
	case VALIDATION = 'VALIDATION';

	/** 查無資源（查無發票 / 查無交易） */
	case NOT_FOUND = 'NOT_FOUND';

	/** 狀態衝突（重複開立 / 已作廢 / 已開折讓，如 ezPay LIB10007 / 重複處理） */
	case CONFLICT = 'CONFLICT';

	/** 字軌號碼用罄（發票專屬） */
	case NUMBER_EXHAUSTED = 'NUMBER_EXHAUSTED';

	/** 驗章失敗（CheckCode / CheckMacValue / HMAC / HashInfo 不符；PC 新增於 einvoice 9 種之外） */
	case SIGNATURE = 'SIGNATURE';

	/** 不支援的操作（不支援折讓 / 查詢；退款不支援 / capture / void no-op） */
	case UNSUPPORTED = 'UNSUPPORTED';

	/** 連線失敗（API 連線失敗 / 逾時 / 無回應） */
	case NETWORK = 'NETWORK';

	/** provider 回未分類錯誤碼（映射表未涵蓋，保留 raw_code 供 debug） */
	case PROVIDER = 'PROVIDER';

	/** 未預期 \Throwable（never-throw 鐵律下，driver 例外一律歸此） */
	case UNKNOWN = 'UNKNOWN';

	/**
	 * 映射為建議的 REST HTTP 狀態碼
	 *
	 * 對應實作計劃「正規化 code 值域」表的「REST HTTP 映射」欄。
	 * AUTH→401、VALIDATION→422、NOT_FOUND→404、CONFLICT / NUMBER_EXHAUSTED→409、
	 * SIGNATURE / UNSUPPORTED→400、NETWORK / PROVIDER→502、UNKNOWN→500。
	 *
	 * @return int HTTP 狀態碼
	 */
	public function to_http_status(): int {
		return match ( $this ) {
			self::AUTH             => 401,
			self::VALIDATION       => 422,
			self::NOT_FOUND        => 404,
			self::CONFLICT         => 409,
			self::NUMBER_EXHAUSTED => 409,
			self::SIGNATURE        => 400,
			self::UNSUPPORTED      => 400,
			self::NETWORK          => 502,
			self::PROVIDER         => 502,
			self::UNKNOWN          => 500,
		};
	}
}
