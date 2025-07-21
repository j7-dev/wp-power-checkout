<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks\Components;

use J7\WpUtils\Classes\DTO;

/**
 * Billing 顧客帳單資訊
 *  */
final class Billing extends DTO {
	/** @var PersonalInfo 發卡國家編碼 */
	public PersonalInfo $personalInfo;

	/** @var Address 地址，有帳單的場景下必填 */
	public Address $address;

	/** @var Descriptor 銀行帳單描述資訊 */
	public Descriptor $descriptor;

	/**
	 * @param array<string, mixed> $args 原始資料
	 * @return self
	 */
	public static function create( array $args ): self {
		if ( isset( $args['PersonalInfo'] ) ) {
			$args['PersonalInfo'] = PersonalInfo::parse( $args['PersonalInfo'] );
		}
		if ( isset( $args['Address'] ) ) {
			$args['Address'] = Address::parse( $args['Address'] );
		}
		if ( isset( $args['Descriptor'] ) ) {
			$args['Descriptor'] = Descriptor::parse( $args['Descriptor'] );
		}
		return new self( $args );
	}
}
