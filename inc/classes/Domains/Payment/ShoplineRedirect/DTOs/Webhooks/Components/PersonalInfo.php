<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks\Components;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\PersonalInfo as BasePersonalInfo;

/**
 * PersonalInfo 顧客账单資訊
 * 繼承 BasePersonalInfo
 * 每個都是選填，所以不用驗證也不用設定必填屬性
 *  */
final class PersonalInfo extends BasePersonalInfo {
	/** @var string 電話號碼 */
	public string $phoneNumber;

	/** @var string 電話國碼 */
	public string $phoneCountryCode;

	/** @var string 家庭電話號碼 */
	public string $homeTelephone;

	/** @var string 身份證件號碼 */
	public string $identityNumber;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [];

	/** 自訂驗證邏輯 */
	protected function validate(): void {}
}
