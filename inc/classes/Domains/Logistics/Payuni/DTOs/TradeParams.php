<?php
/**
 * PAYUNi 建立超商物流單 trade 請求參數（內層 EncryptInfo 明文）
 *
 * 階段 B 建單：PAYUNi 無暫存單，商店一次組齊「門市 + 完整收件人」呼叫 /api/logistics/trade，
 * 回傳統一物流單號 ShipTradeNo（主鍵）。屬性命名對齊 PAYUNi API。
 *
 * 必填（payuni-logistics-v3 cvs-apis.md#trade-1.3）：
 *   MerTradeNo（≤25,[A-Za-z0-9_-],10 分鐘內不可重複）/ GoodsType / LgsType / ShipType=1 /
 *   TradeAmt（1~20,000）/ ServiceType（1 取貨付款 / 3 不付款）/ StoreID（ship_map 取得）/
 *   Consignee（≤10）/ ConsigneeMobile（09 開頭）。
 * C2C 才可帶 RefundStoreID / SenderName / SenderMobile。
 *
 * @see .claude/skills/payuni-logistics-v3/references/cvs-apis.md#trade-1.3
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\DTOs;

use J7\WpUtils\Classes\DTO;

/** PAYUNi 建立超商物流單 trade 請求參數 */
final class TradeParams extends DTO {

	/** @var string 商店訂單編號（≤25；[A-Za-z0-9_-]；10 分鐘內不可重複） */
	public string $MerTradeNo = '';

	/** @var int 寄件型態（1=常溫，2=冷凍） */
	public int $GoodsType = 1;

	/** @var string 物流型態（B2C / C2C） */
	public string $LgsType = 'B2C';

	/** @var int 通路類別（固定 1=7-ELEVEN） */
	public int $ShipType = 1;

	/** @var int 訂單金額（= 取貨付款金額；上限 20,000；ServiceType=3 時為商品價值） */
	public int $TradeAmt = 0;

	/** @var int 取件方式（1=取貨付款 / 3=取貨不付款） */
	public int $ServiceType = 3;

	/** @var string 取件門市代碼（6 碼；由 ship_map 取得） */
	public string $StoreID = '';

	/** @var string 取件人姓名（≤10；最少 2 中文 / 4 英文，超商核對身分用） */
	public string $Consignee = '';

	/** @var string 取件人手機（09 開頭半形數字） */
	public string $ConsigneeMobile = '';

	/** @var string 指定退貨門市（僅 C2C；留空用商店預設） */
	public string $RefundStoreID = '';

	/** @var string 指定退貨收件人（僅 C2C；與 SenderMobile 同填或同不填） */
	public string $SenderName = '';

	/** @var string 指定退貨收件人手機（僅 C2C） */
	public string $SenderMobile = '';

	/** @var string 取貨付款完成取件通知 URL（ServiceType=1 才觸發；僅 80/443 port） */
	public string $NotifyURL = '';

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		if ('' === \trim( $this->MerTradeNo )) {
			throw new \Exception( 'TradeParams: MerTradeNo 必填' );
		}

		if ('' === \trim( $this->StoreID )) {
			throw new \Exception( 'TradeParams: StoreID 必填（由 ship_map 選店取得）' );
		}

		if ('' === \trim( $this->Consignee )) {
			throw new \Exception( 'TradeParams: Consignee 必填' );
		}

		if ('' === \trim( $this->ConsigneeMobile )) {
			throw new \Exception( 'TradeParams: ConsigneeMobile 必填' );
		}

		// PAYUNi CVS 取貨付款上限 20,000（quick-checks §Check 7）
		if ($this->TradeAmt > 20000) {
			throw new \Exception( 'TradeParams: TradeAmt 超過上限 20,000' );
		}
	}

	/**
	 * 轉為 PAYUNi 內層明文陣列（過濾留空的選填欄位，避免空字串進 querystring）
	 *
	 * C2C 退貨欄位 / NotifyURL 留空時不送出。
	 *
	 * @return array<string, mixed>
	 */
	public function to_payuni_data(): array {
		$data = $this->to_array();

		foreach ( [ 'RefundStoreID', 'SenderName', 'SenderMobile', 'NotifyURL' ] as $optional_key ) {
			if ('' === (string) ( $data[ $optional_key ] ?? '' )) {
				unset( $data[ $optional_key ] );
			}
		}

		return $data;
	}
}
