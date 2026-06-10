<?php
/**
 * PayNow 電子發票載具類型 CarrierType
 *
 * PayNow 發票 API（體系 3）以「字串列舉」傳入載具類型，故 backing type 為 string，
 * 且 enum value 直接對應 PayNow API 的字面值（None / PhoneBarCodeCarrier …）。
 *  - None                 實體列印發票（紙本）；carrier_id1 / carrier_id2 留空。
 *  - PhoneBarCodeCarrier  個人手機載具（手機條碼）；carrier_id1 / carrier_id2 帶載具明碼 / 隱碼。
 *  - EasyCardCarrier      悠遊卡載具；carrier_id1 / carrier_id2 帶載具明碼 / 隱碼。
 *  - CitizenDigitalCardNo 自然人憑證；carrier_id1 / carrier_id2 帶載具明碼 / 隱碼。
 *  - BuyerSno             PayNow 會員載具；留空（有買方手機時 PayNow 依手機帶會員載具號碼）。
 *
 * 規則：捐贈發票 carrier_type 帶 None（紙本）並帶 npoban（愛心碼）；載具與捐贈互斥。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §10 載具 / 課稅別 / 零稅率原因全表
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums;

/** PayNow 電子發票載具類型 */
enum ECarrierType: string {
	// 實體列印發票（紙本）.
	case None = 'None';

	// 個人手機載具（手機條碼）.
	case PhoneBarCodeCarrier = 'PhoneBarCodeCarrier';

	// 悠遊卡載具.
	case EasyCardCarrier = 'EasyCardCarrier';

	// 自然人憑證.
	case CitizenDigitalCardNo = 'CitizenDigitalCardNo';

	// PayNow 會員載具.
	case BuyerSno = 'BuyerSno';

	/**
	 * 取得載具類型中文標籤
	 *
	 * @return string 標籤.
	 */
	public function label(): string {
		return match ( $this ) {
			self::None                 => '紙本發票',
			self::PhoneBarCodeCarrier  => '手機條碼載具',
			self::EasyCardCarrier      => '悠遊卡載具',
			self::CitizenDigitalCardNo => '自然人憑證',
			self::BuyerSno             => 'PayNow 會員載具',
		};
	}
}
