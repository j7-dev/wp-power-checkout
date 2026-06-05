<?php
/**
 * 綠界全方位物流 v2 逆物流（退貨）內層 Data 參數
 *
 * 涵蓋四個逆物流端點（依原物流子類型分派）：
 *  - 超商退貨（ReturnCVS / ReturnUniMartCVS / ReturnHilifeCVS）：
 *      MerchantID / LogisticsID / GoodsAmount(1~20000) / ServiceType="4"（退貨不付款） /
 *      SenderName / [SenderPhone] / ServerReplyURL。
 *  - 宅配退貨（ReturnHome）：
 *      MerchantID / LogisticsID / GoodsAmount / Temperature(0001/0002/0003) /
 *      Distance(00 同縣市 / 01 外縣市 / 02 離島) / Specification(0001 60cm / 0002 90cm / 0003 120cm / 0004 150cm) /
 *      ServerReplyURL。
 *
 * 屬性命名一律 camelCase 對齊綠界 API（屬性名即綠界欄位名，to_array() → AES 加密為 Data）。
 * 外層 MerchantID / RqHeader（含 Revision 1.0.0 + 即時 Timestamp）由 LogisticsApiClient::build_envelope() 組裝；
 * 內層 Data 另含一份 MerchantID（綠界逆物流 Data 內亦需），由 ApiClient 退貨方法注入啟用帳號 MerchantID。
 *
 * to_ecpay_data() 過濾留空的選填 / 不適用欄位（超商不送 Temperature/Distance/Specification；
 * 宅配不送 ServiceType/SenderPhone），避免送出空字串。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §B2C 退貨 / 宅配退貨
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界全方位物流 v2 逆物流（退貨）內層 Data 參數 */
final class CreateReturnParams extends DTO {

	/** @var string 原正向物流單號 LogisticsID（必填，據此建立逆物流單） */
	public string $LogisticsID = '';

	/** @var int 商品金額（新台幣整數，1~20000） */
	public int $GoodsAmount = 0;

	/** @var string 商品名稱（選填） */
	public string $GoodsName = '';

	/** @var string 物流狀態 callback URL（ServerReplyURL，逆物流貨態通知；僅 80/443，須公開可訪問） */
	public string $ServerReplyURL = '';

	// region 超商退貨欄位（ReturnCVS / ReturnUniMartCVS / ReturnHilifeCVS）

	/** @var string 服務型態代碼（固定 "4"＝退貨不付款；宅配不送此欄） */
	public string $ServiceType = '';

	/** @var string 退貨人姓名（超商退貨必填；4~10 字元，禁特殊符號） */
	public string $SenderName = '';

	/** @var string 退貨人手機（選填；09 開頭，10 碼） */
	public string $SenderPhone = '';

	// endregion

	// region 宅配退貨欄位（ReturnHome）

	/** @var string 溫層（常溫 0001 / 冷藏 0002 / 冷凍 0003；宅配退貨必填） */
	public string $Temperature = '';

	/** @var string 距離（00 同縣市 / 01 外縣市 / 02 離島；宅配退貨必填） */
	public string $Distance = '';

	/** @var string 規格（0001 60cm / 0002 90cm / 0003 120cm / 0004 150cm；宅配退貨必填） */
	public string $Specification = '';

	// endregion

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		if ('' === \trim( $this->LogisticsID )) {
			throw new \Exception( 'CreateReturnParams: LogisticsID 必填（原正向物流單號）' );
		}

		if ('' === \trim( $this->ServerReplyURL )) {
			throw new \Exception( 'CreateReturnParams: ServerReplyURL 必填（逆物流貨態通知，須公開可訪問）' );
		}
	}

	/**
	 * 轉為綠界 Data 陣列（過濾留空的選填 / 不適用欄位）
	 *
	 * 超商退貨送出 ServiceType / SenderName / [SenderPhone]，不送 Temperature/Distance/Specification；
	 * 宅配退貨送出 Temperature / Distance / Specification，不送 ServiceType/SenderName/SenderPhone。
	 * 由 provider 依 sub_type 只填對應欄位，此處統一過濾空字串。
	 *
	 * @return array<string, mixed>
	 */
	public function to_ecpay_data(): array {
		$data = $this->to_array();

		$optional_keys = [
			'GoodsName',
			'ServiceType',
			'SenderName',
			'SenderPhone',
			'Temperature',
			'Distance',
			'Specification',
		];
		foreach ( $optional_keys as $key ) {
			if ('' === (string) ( $data[ $key ] ?? '' )) {
				unset( $data[ $key ] );
			}
		}

		return $data;
	}
}
