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

		// TODO 有沒有辦法自動判斷 錯誤裡面有沒有 order_id 有就同時寫入 order_note
		$trace     = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5); // 只看5層
		$functions = [];
		foreach ( $trace as $t ) {
			$line        = $t['line'] ?? 'N/A';
			$functions[] = "{$t['function']} #L:{$line}";
		}
		$error_messages = $this->error->get_error_messages();
		\J7\WpUtils\Classes\WC::log(
			$error_messages,
			'',
			'error',
			[
				'source' => "{$this->id}__errors",
				'trace'  => $functions,
			]
			);
	}
}
