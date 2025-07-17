<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\Shared;

/**
 * 付款服務抽象類別
 * 1. 請求結束時檢查是否有錯誤，有就印出，提供統一錯誤處理日誌
 */
abstract class AbstractPaymentService {

	/** @var string 服務 ID */
	public string $id;

	/** @var \WP_Error 錯誤訊息 */
	public \WP_Error $error;

	/** Constructor */
	public function __construct() {
		$this->error = new \WP_Error();
		\add_action('shutdown', [ $this, 'print_error' ]);
		\add_filter( 'woocommerce_payment_gateways', [ $this, 'add_method' ] );
	}

	/**
	 * 添加付款方式
	 *
	 * @param array<string> $methods 付款方式
	 *
	 * @return array<string>
	 */
	public function add_method( array $methods ): array {
		return $methods;
	}

	/** 每次請求結束時如果有錯誤就印出錯誤訊息 */
	public function print_error(): void {
		if ( !$this->error->has_errors() ) {
			return;
		}

		$error_messages = $this->error->get_error_messages();
		if ( ! $error_messages ) {
			return;
		}
		\J7\WpUtils\Classes\WC::logger(
			$error_messages[0],
			'error',
			[
				'messages' => $error_messages,
			],
			$this->id . '__errors'
		);
	}
}
