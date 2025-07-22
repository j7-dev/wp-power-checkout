<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\WpUtils\Classes\DTO;

/**
 * InstrumentCard 卡類付款工具資訊
 *  */
final class InstrumentCard extends DTO {
	/** @var string 卡類型 */
	public string $type;

	/** @var string 卡組織 */
	public string $brand;

	/** @var string 有效期限：年 */
	public string $expireYear;

	/** @var string 有效期限：月 */
	public string $expireMonth;

	/** @var string 持卡人姓名 */
	public string $holder;

	/** @var string 卡號前六位 */
	public string $first;

	/** @var string 卡號後四位 */
	public string $last;

	/** @var string 發卡行編碼 */
	public string $issuer;

	/** @var string 發卡國家編碼 */
	public string $issuerCountry;

	/** @var bool 付款工具卡是否逾期(true:逾期，false:未逾期) */
	public bool $expired;
}
