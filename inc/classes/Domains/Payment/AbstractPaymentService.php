<?php

declare (strict_types = 1);

namespace J7\PowerPayment\Domains\Payment;

/**
 * 付款服務抽象類別
 * 1. 請求結束時檢查是否有錯誤，有就印出，提供統一錯誤處理日誌
 */
abstract class AbstractPaymentService {

	/** @var string 服務 ID，例如 EcpayAIO，用來識別服務 */
	public string $id;

	/** @var 'prod' | 'test' 模式 */
	public string $mode;

	/** @var \WP_Error 錯誤訊息 */
	public $error;

	/** Constructor */
	public function __construct() {
		$this->error = new \WP_Error();
		\add_action('shutdown', [ $this, 'print_error' ]);
	}

	/** 每次請求結束時如果有錯誤就印出錯誤訊息 */
	public function print_error(): void {
		if ( !$this->error->has_errors() ) {
			return;
		}

		$error_messages = $this->error->get_error_messages();
		\J7\WpUtils\Classes\WC::log(
			$error_messages,
			'',
			'error',
			[
				'source' => "[error]power-payment_{$this->id} 請求結束 errors",
			]
			);
	}
}
