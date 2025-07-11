<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;

/**
 * Client 終端資訊
 * 請求會帶
 *  */
final class Client extends DTO {

	/** @var string (32) *顧客付款使用的 IP 地址，若 paymentBehavior 為定期扣款 Recurring，可填入特店辦公室 IP */
	public string $ip;

	/** @var string (16) 螢幕寬度（單位：像素） */
	public string $screenWidth;

	/** @var string (16) 螢幕高度（單位：像素） */
	public string $screenHeight;

	/** @var string (16) 持卡人終端是否能夠執行 Java */
	public string $javaEnabled;

	/** @var string (16) 時區，持卡人瀏覽器本地時間和UTC 時間之間的時差，以分鐘為單位。 值從 getTimezoneOffset() 方法回應 */
	public string $timeZoneOffset;

	/** @var string (512) 使用者瀏覽器目前 domain */
	public string $transactionWebSite;

	/** @var string (128) 瀏覽器使用者代理程式資訊 範例值：Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/72.0.3626.121 Safari/537.36 */
	public string $userAgent;

	/** @var string (32) 瀏覽器的 navigator.language 值 */
	public string $language;

	/** @var string (16) 視窗顏色, 取得瀏覽器 screen.colorDepth 範例值: 32 */
	public string $colorDepth;

	/** @var string (128) 瀏覽器 Accept 頭資訊 */
	public string $accept;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'ip',
	];

	/**
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$args = [
			'ip'        => $order->get_customer_ip_address(),
			'userAgent' => \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'accept'    => \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_ACCEPT'] ?? '' ) ),
			'language'  => \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) ),
		];
		return new self($args);
	}

	/** 自訂驗證邏輯 */
	protected function validate(): void {
		parent::validate();

		if ( Helper::strlen( $this->ip ) > 32 ) {
			$this->dto_error->add(
				'validate_failed',
				'ip 長度不能超過 32 位'
			);
		}

		if ( isset( $this->screenWidth ) ) {
			if ( Helper::strlen( $this->screenWidth ) > 16 ) {
				$this->dto_error->add(
					'validate_failed',
					'screenWidth 長度不能超過 16 位'
				);
			}
		}

		if ( isset( $this->screenHeight ) ) {
			if ( Helper::strlen( $this->screenHeight ) > 16 ) {
				$this->dto_error->add(
					'validate_failed',
					'screenHeight 長度不能超過 16 位'
				);
			}
		}

		if ( isset( $this->javaEnabled ) ) {
			if ( Helper::strlen( $this->javaEnabled ) > 16 ) {
				$this->dto_error->add(
					'validate_failed',
					'javaEnabled 長度不能超過 16 位'
				);
			}
		}

		if ( isset( $this->timeZoneOffset ) ) {
			if ( Helper::strlen( $this->timeZoneOffset ) > 16 ) {
				$this->dto_error->add(
					'validate_failed',
					'timeZoneOffset 長度不能超過 16 位'
				);
			}
		}

		if ( isset( $this->transactionWebSite ) ) {
			if ( Helper::strlen( $this->transactionWebSite ) > 512 ) {
				$this->dto_error->add(
					'validate_failed',
					'transactionWebSite 長度不能超過 512 位'
				);
			}
		}

		if ( isset( $this->userAgent ) ) {
			if ( Helper::strlen( $this->userAgent ) > 128 ) {
				$this->dto_error->add(
					'validate_failed',
					'userAgent 長度不能超過 128 位'
				);
			}
		}

		if ( isset( $this->language ) ) {
			if ( Helper::strlen( $this->language ) > 32 ) {
				$this->dto_error->add(
					'validate_failed',
					'language 長度不能超過 32 位'
				);
			}
		}

		if ( isset( $this->colorDepth ) ) {
			if ( Helper::strlen( $this->colorDepth ) > 16 ) {
				$this->dto_error->add(
					'validate_failed',
					'colorDepth 長度不能超過 16 位'
				);
			}
		}

		if ( isset( $this->accept ) ) {
			if ( Helper::strlen( $this->accept ) > 128 ) {
				$this->dto_error->add(
					'validate_failed',
					'accept 長度不能超過 128 位'
				);
			}
		}
	}
}
