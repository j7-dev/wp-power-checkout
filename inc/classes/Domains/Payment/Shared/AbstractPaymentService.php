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

	/** @var AbstractPaymentGateway 付款閘道 */
	public AbstractPaymentGateway $gateway;

	/** @var \WC_Order 訂單 */
	public \WC_Order $order;

	/** Constructor */
	public function __construct() {
		$this->error = new \WP_Error();
		\add_action('shutdown', [ $this, 'print_error' ]);
		\add_filter( 'woocommerce_payment_gateways', [ $this, 'add_method' ] );
	}

	/** 設定付款閘道和訂單 @param AbstractPaymentGateway $gateway 付款閘道 @param \WC_Order $order 訂單 */
	public function set_properties( AbstractPaymentGateway $gateway, \WC_Order $order ): void {
		$this->gateway = $gateway;
		$this->order   = $order;
	}

	/** 添加付款方式 @param array<string> $methods 付款方式  @return array<string> */
	abstract public function add_method( array $methods ): array;

	/** 每次請求結束時如果有錯誤就印出錯誤訊息 */
	public function print_error(): void {
		if ( !$this->error->has_errors() ) {
			return;
		}

		$error_messages = $this->error->get_error_messages();
		if ( ! $error_messages ) {
			return;
		}
		$this->gateway->logger( $error_messages[0], 'critical', [ 'messages' => $error_messages ], 5 );
	}
}
