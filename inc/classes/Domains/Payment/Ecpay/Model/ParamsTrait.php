<?php
// phpcs:disable
declare(strict_types=1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Model;

/**
 * 綠界全方位金流 API RequestParams & ResponseParams 共用屬性
 *
 * @see https://developers.ecpay.com.tw/?p=2862
 * @see https://developers.ecpay.com.tw/?p=2878
 */
trait ParamsTrait
{

	/** @var string *特店編號 (10) */
	public string $MerchantID;

	/**
	 * @var string *特店交易編號 (20) 唯一英數字大小寫混合
	 * @see https://www.ecpay.com.tw/CascadeFAQ/CascadeFAQ_Qa?nID=1454
	 *  */
	public string $MerchantTradeNo;

	/**  @var string 特店旗下店舖代號 (10) 提供特店填入分店代號使用，僅可用英數字大小寫混合。(optional) 還不確定怎麼用，目前也沒有用到  */
	public string $StoreID;

	/**
	 *  @var string *選擇的付款方式 (20)
	 *  Request 用的話請固定填入 aio
	 *  Response 用的話請見下表
	 *  @see https://developers.ecpay.com.tw/?p=5686
	 * */
	public string $PaymentType = 'aio';

	/** @var string 特約合作平台商代號 (10) 為專案合作的平台商使用。 (optional) 不確定是什麼也沒有用到 */
	public string $PlatformID;

	/** @var string 自訂名稱欄位1 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField1;

	/** @var string 自訂名稱欄位2 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField2;

	/** @var string 自訂名稱欄位3 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField3;

	/** @var string 自訂名稱欄位4 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField4;
}
// phpcs:enable
