<?php
/**
 * 物流付款情境 LogisticsPaymentScenario
 *
 * 結帳頁顧客選擇，決定建立物流單時是否帶代收貨款（COD）。
 *  - online：線上付款（既有金流 gateway），建物流單 IsCollection=N
 *  - cod：取貨付款（IsCollection=Y + CollectionAmount=訂單金額），門市取貨時付款
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Enums;

/** 物流付款情境 */
enum LogisticsPaymentScenario: string {
	/** 線上付款（既有金流 gateway，建物流單 IsCollection=N） */
	case ONLINE = 'online';
	/** 取貨付款 COD（IsCollection=Y + CollectionAmount=訂單金額） */
	case COD = 'cod';

	/**
	 * 是否為代收貨款（COD）
	 *
	 * @return bool
	 */
	public function is_collection(): bool {
		return self::COD === $this;
	}
}
