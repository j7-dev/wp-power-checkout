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
			'userAgent' => $order->get_customer_user_agent(),
			'accept'    => ( new Helper( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_ACCEPT'] ?? '' ) ), 'HTTP_ACCEPT', 128 ) )->substr()->value,
			'language'  => ( new Helper( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) ), 'HTTP_ACCEPT_LANGUAGE', 32 ) )->substr()->value,
		];
		return new self($args);
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();

		( new Helper($this->ip, 'ip', 32) )->get_strlen(true);

		if ( isset( $this->screenWidth ) ) {
			( new Helper($this->screenWidth, 'screenWidth', 16) )->get_strlen(true);
		}

		if ( isset( $this->screenHeight ) ) {
			( new Helper($this->screenHeight, 'screenHeight', 16) )->get_strlen(true);
		}

		if ( isset( $this->javaEnabled ) ) {
			( new Helper($this->javaEnabled, 'javaEnabled', 16) )->get_strlen(true);
		}

		if ( isset( $this->timeZoneOffset ) ) {
			( new Helper($this->timeZoneOffset, 'timeZoneOffset', 16) )->get_strlen(true);
		}

		if ( isset( $this->transactionWebSite ) ) {
			( new Helper($this->transactionWebSite, 'transactionWebSite', 512) )->get_strlen(true);
		}

		if ( isset( $this->userAgent ) ) {
			( new Helper($this->userAgent, 'userAgent', 128) )->get_strlen(true);
		}

		if ( isset( $this->language ) ) {
			( new Helper($this->language, 'language', 32) )->get_strlen(true);
		}

		if ( isset( $this->colorDepth ) ) {
			( new Helper($this->colorDepth, 'colorDepth', 16) )->get_strlen(true);
		}

		if ( isset( $this->accept ) ) {
			( new Helper($this->accept, 'accept', 128) )->get_strlen(true);
		}
	}
}
