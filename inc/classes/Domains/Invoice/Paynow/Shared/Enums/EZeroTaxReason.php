<?php
/**
 * PayNow 電子發票零稅率原因 ZeroTaxRateReason
 *
 * 僅 tax_type=ZeroTax（零稅率）時適用。PayNow 發票 API（體系 3）以「字串列舉」傳入，
 * 故 backing type 為 string，且 enum value 直接對應 PayNow API 的字面值。
 * 對齊官方零稅率原因全表（共 10 個 case）：
 *  - None                    無
 *  - ExportGoods             外銷貨物
 *  - ExportLabor             與外銷有關之勞務，或在國內提供而在國外使用之勞務
 *  - FreeTaxGoods            依法設立之免稅商店銷售與過境或出境旅客之貨物
 *  - OperatingGoodsOrLabor   銷售與保稅區營業人供營運之貨物或勞務
 *  - InterNationsTransPort   國際間之運輸（外國運輸事業需相等待遇 / 免徵類似稅捐為限）
 *  - InterNationsShip        國際運輸用之船舶、航空器及遠洋漁船
 *  - SalesInterNationsShip   銷售與國際運輸用之船舶、航空器及遠洋漁船所使用之貨物或修繕勞務
 *  - Eight                   保稅區營業人銷售與課稅區營業人未輸往課稅區而直接出口之貨物
 *  - Nine                    保稅區營業人銷售與課稅區營業人存入自由港區事業或海關保稅倉庫 / 物流中心以供外銷之貨物
 *
 * @see .claude/skills/paynow/references/invoice-api.md §10 載具 / 課稅別 / 零稅率原因全表
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums;

/** PayNow 電子發票零稅率原因 */
enum EZeroTaxReason: string {
	// 無.
	case None = 'None';

	// 外銷貨物.
	case ExportGoods = 'ExportGoods';

	// 與外銷有關之勞務，或在國內提供而在國外使用之勞務.
	case ExportLabor = 'ExportLabor';

	// 依法設立之免稅商店銷售與過境或出境旅客之貨物.
	case FreeTaxGoods = 'FreeTaxGoods';

	// 銷售與保稅區營業人供營運之貨物或勞務.
	case OperatingGoodsOrLabor = 'OperatingGoodsOrLabor';

	// 國際間之運輸.
	case InterNationsTransPort = 'InterNationsTransPort';

	// 國際運輸用之船舶、航空器及遠洋漁船.
	case InterNationsShip = 'InterNationsShip';

	// 銷售與國際運輸用之船舶、航空器及遠洋漁船所使用之貨物或修繕勞務.
	case SalesInterNationsShip = 'SalesInterNationsShip';

	// 保稅區營業人銷售與課稅區營業人未輸往課稅區而直接出口之貨物.
	case Eight = 'Eight';

	// 保稅區營業人銷售與課稅區營業人存入自由港區事業或海關保稅倉庫 / 物流中心以供外銷之貨物.
	case Nine = 'Nine';

	/**
	 * 取得零稅率原因中文標籤
	 *
	 * @return string 標籤.
	 */
	public function label(): string {
		return match ( $this ) {
			self::None                  => '無',
			self::ExportGoods           => '外銷貨物',
			self::ExportLabor           => '與外銷有關之勞務，或在國內提供而在國外使用之勞務',
			self::FreeTaxGoods          => '依法設立之免稅商店銷售與過境或出境旅客之貨物',
			self::OperatingGoodsOrLabor => '銷售與保稅區營業人供營運之貨物或勞務',
			self::InterNationsTransPort => '國際間之運輸',
			self::InterNationsShip      => '國際運輸用之船舶、航空器及遠洋漁船',
			self::SalesInterNationsShip => '銷售與國際運輸用之船舶、航空器及遠洋漁船所使用之貨物或修繕勞務',
			self::Eight                 => '保稅區營業人銷售與課稅區營業人未輸往課稅區而直接出口之貨物',
			self::Nine                  => '保稅區營業人銷售與課稅區營業人存入自由港區事業或海關保稅倉庫 / 物流中心以供外銷之貨物',
		};
	}
}
