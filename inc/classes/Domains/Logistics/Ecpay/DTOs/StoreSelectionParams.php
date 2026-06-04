<?php
/**
 * 綠界全方位物流 v2 RedirectToLogisticsSelection 內層 Data 參數
 *
 * 階段 A 選店：建立暫存物流單並導向 RWD 選店頁（回 HTML body）。
 * 屬性命名一律 camelCase 對齊綠界 API（屬性名即綠界欄位名，to_array() → AES 加密為 Data）。
 *
 * 三層結構中本 DTO 僅是「內層 Data」，外層 MerchantID / RqHeader（含 Revision 1.0.0 + 即時 Timestamp）
 * 由 LogisticsApiClient::build_envelope() 組裝。
 *
 * 必填（依 guide 07 §物流選擇頁面重導）：
 *  TempLogisticsID='0'（新建）/ GoodsAmount / GoodsName / SenderName / SenderZipCode /
 *  SenderAddress / ServerReplyURL / ClientReplyURL。
 * COD（取貨付款）：IsCollection='Y' + CollectionAmount=訂單金額；線上付款：IsCollection='N'。
 * 宅配冷凍：Temperature='0003'（常溫 0001 / 冷藏 0002 / 冷凍 0003）。
 * LogisticsSubType：限定可選的物流子類型（FAMI / UNIMART / HILIFE / HOME）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §物流選擇頁面重導 / 冷凍物流選擇
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界全方位物流 v2 RedirectToLogisticsSelection 內層 Data 參數 */
final class StoreSelectionParams extends DTO {

	/** @var string 暫存物流單號（新建固定 '0'） */
	public string $TempLogisticsID = '0';

	/** @var int 商品金額（新台幣整數） */
	public int $GoodsAmount = 0;

	/** @var string 商品名稱 */
	public string $GoodsName = '';

	/** @var string 寄件人姓名 */
	public string $SenderName = '';

	/** @var string 寄件人郵遞區號 */
	public string $SenderZipCode = '';

	/** @var string 寄件人地址 */
	public string $SenderAddress = '';

	/** @var string 物流狀態 callback URL（ServerReplyURL，僅 80/443，須公開可訪問） */
	public string $ServerReplyURL = '';

	/** @var string 消費者選店後前端跳轉 URL（ClientReplyURL，須公開可訪問） */
	public string $ClientReplyURL = '';

	/** @var string 物流子類型（FAMI / UNIMART / HILIFE / HOME；空字串時由 RWD 頁面全選） */
	public string $LogisticsSubType = '';

	/** @var string 是否代收貨款（'Y'=COD / 'N'=線上付款） */
	public string $IsCollection = 'N';

	/** @var int 代收貨款金額（IsCollection='Y' 時填訂單金額） */
	public int $CollectionAmount = 0;

	/** @var string 宅配溫層（常溫 0001 / 冷藏 0002 / 冷凍 0003；非宅配可留空） */
	public string $Temperature = '';

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		if ('' === \trim( $this->GoodsName )) {
			throw new \Exception( 'StoreSelectionParams: GoodsName 必填' );
		}

		if ('' === \trim( $this->SenderName )) {
			throw new \Exception( 'StoreSelectionParams: SenderName 必填' );
		}

		if ('' === \trim( $this->ServerReplyURL )) {
			throw new \Exception( 'StoreSelectionParams: ServerReplyURL 必填（須公開可訪問）' );
		}

		if ('' === \trim( $this->ClientReplyURL )) {
			throw new \Exception( 'StoreSelectionParams: ClientReplyURL 必填（須公開可訪問）' );
		}
	}

	/**
	 * 轉為綠界 Data 陣列（過濾留空的選填欄位，避免送出空字串）
	 *
	 * Temperature / LogisticsSubType 留空時不送出（綠界對空字串較敏感）。
	 *
	 * @return array<string, mixed>
	 */
	public function to_ecpay_data(): array {
		$data = $this->to_array();

		foreach ( [ 'Temperature', 'LogisticsSubType' ] as $optional_key ) {
			if ('' === (string) ( $data[ $optional_key ] ?? '' )) {
				unset( $data[ $optional_key ] );
			}
		}

		return $data;
	}
}
