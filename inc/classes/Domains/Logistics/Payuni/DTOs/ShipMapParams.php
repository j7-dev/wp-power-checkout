<?php
/**
 * PAYUNi 超商門市地圖 ship_map 請求參數（內層 EncryptInfo 明文）
 *
 * 單段選店：Form POST 導向 PAYUNi ship_map 門市地圖（前景），消費者選店後 PAYUNi POST
 * 門市資訊（MapJson）回 MapReturnURL。屬性命名對齊 PAYUNi API（屬性名即 API 欄位名）。
 *
 * ⚠️ 與綠界三段暫存單不同：PAYUNi 無「暫存物流單」概念，ship_map 只負責回傳消費者選的門市，
 *    收件人資訊於建單（trade）階段才由商店組裝。
 *
 * Tag 是「處理標記」（2=回傳選取門市）**不是超商代號**；ShipType=1 固定 7-ELEVEN。
 *
 * @see .claude/skills/payuni-logistics-v3/references/cvs-apis.md#ship_map-1.1
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\DTOs;

use J7\WpUtils\Classes\DTO;

/** PAYUNi ship_map 請求參數 */
final class ShipMapParams extends DTO {

	/** @var string 自訂編號（≤ 20；商店辨識用；回呼時原樣帶回） */
	public string $MerKeyNo = '';

	/** @var int 寄件型態（1=常溫，2=冷凍） */
	public int $GoodsType = 1;

	/** @var string 物流型態（B2C / C2C） */
	public string $LgsType = 'B2C';

	/** @var int 通路類別（固定 1=7-ELEVEN） */
	public int $ShipType = 1;

	/** @var int 地圖涵蓋區域（1=本島 / 2=本島含離島；GoodsType=2 時固定 2） */
	public int $MapType = 1;

	/** @var string 門市資訊回傳 URL（須公開可訪問） */
	public string $MapReturnURL = '';

	/** @var int 處理標記（2=回傳選取的門市資訊；不是超商代號） */
	public int $Tag = 2;

	/** @var string 裝置標記（N=PC / Y=手機；空白預設 PC） */
	public string $MobileTag = 'N';

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		if ('' === \trim( $this->MerKeyNo )) {
			throw new \Exception( 'ShipMapParams: MerKeyNo 必填' );
		}

		if ('' === \trim( $this->MapReturnURL )) {
			throw new \Exception( 'ShipMapParams: MapReturnURL 必填（須公開可訪問）' );
		}
	}
}
