<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums;

/**
 * 綠界 AIO 取號 / 付款回應碼 RtnCode 語意
 *
 * ⚠️ 重要：綠界 AIO（CMV 類服務）Callback 的 RtnCode 一律為「字串」，
 * 必須用字串比對（=== '1'），不可用整數比對。本 enum 與 helper 皆以字串處理。
 *
 * 同一個成功的語意，依付款方式不同會回傳不同的 RtnCode：
 *  - 付款成功（所有付款方式的最終付款完成通知）：'1'
 *  - ATM 取號成功（尚未付款，消費者待轉帳）：'2'
 *  - CVS / BARCODE 取號成功（尚未付款，消費者待繳費）：'10100073'
 *
 * 「取號成功」不等於「付款成功」，不可將取號成功碼視為錯誤而取消訂單。
 *
 * @see https://developers.ecpay.com.tw/?p=2878
 */
enum RtnCode: string {
	/** 付款成功（信用卡 / ATM / CVS / BARCODE 等付款完成的最終通知） */
	case PAID_SUCCESS = '1';
	/** ATM 取號成功（取得虛擬帳號，尚未付款） */
	case ATM_GET_CODE_SUCCESS = '2';
	/** CVS / BARCODE 取號成功（取得繳費代碼 / 條碼，尚未付款） */
	case CVS_GET_CODE_SUCCESS = '10100073';

	/**
	 * 是否為「付款成功」
	 *
	 * @param string $rtn_code RtnCode（字串）
	 * @return bool
	 */
	public static function is_paid_success( string $rtn_code ): bool {
		return self::PAID_SUCCESS->value === $rtn_code;
	}

	/**
	 * 是否為「取號成功」（ATM / CVS / BARCODE，尚未付款）
	 *
	 * @param string $rtn_code RtnCode（字串）
	 * @return bool
	 */
	public static function is_get_code_success( string $rtn_code ): bool {
		return in_array(
			$rtn_code,
			[ self::ATM_GET_CODE_SUCCESS->value, self::CVS_GET_CODE_SUCCESS->value ],
			true
		);
	}

	/**
	 * 是否為任一種成功（付款成功 或 取號成功）
	 *
	 * @param string $rtn_code RtnCode（字串）
	 * @return bool
	 */
	public static function is_success( string $rtn_code ): bool {
		return self::is_paid_success( $rtn_code ) || self::is_get_code_success( $rtn_code );
	}
}
