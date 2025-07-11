<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Core;

/** Credit 綠界信用卡分期付款 */
final class CreditInstallment extends Credit {

	/** @var string 付款方式 ID */
	public $id = 'pc_ecpayaio_credit_installment';

	/** @var array<int> 分期期數 */
	public array $number_of_periods = [ 3, 6, 12, 18, 24 ];

	/** @var int 選擇的分期期數 */
	public int $number_of_period;

	/** Constructor */
	public function __construct() {
		/** @var mixed $saved_number_of_periods */
		$saved_number_of_periods = $this->get_option( 'number_of_periods', [] );
		$this->number_of_periods = is_array( $saved_number_of_periods ) ? $saved_number_of_periods : [];
		parent::__construct();
	}

	/** 取得付款方式標題 @return string */
	public function set_label(): string {
		return \__( 'ECPay Credit(installment)', 'power_checkout' );
	}

	/**
	 * 過濾表單欄位
	 *
	 * @param array<string, mixed> $fields 表單欄位
	 * @return array<string, mixed> 過濾後的表單欄位
	 * */
	public function filter_fields( array $fields ): array {
		$fields['number_of_periods'] = [
			'title'             => __( 'Enable number of periods', 'power_checkout' ),
			'type'              => 'multiselect',
			'class'             => 'wc-enhanced-select',
			'css'               => 'width: 400px;',
			'default'           => '',
			'description'       => '',
			'options'           => [
				/* translators: %d number of periods */
				3  => sprintf( __( '%d periods', 'ry-woocommerce-tools' ), 3 ),
				/* translators: %d number of periods */
				6  => sprintf( __( '%d periods', 'ry-woocommerce-tools' ), 6 ),
				/* translators: %d number of periods */
				12 => sprintf( __( '%d periods', 'ry-woocommerce-tools' ), 12 ),
				/* translators: %d number of periods */
				18 => sprintf( __( '%d periods', 'ry-woocommerce-tools' ), 18 ),
				/* translators: %d number of periods */
				24 => sprintf( __( '%d periods', 'ry-woocommerce-tools' ), 24 ),
			],
			'desc_tip'          => true,
			'custom_attributes' => [
				'data-placeholder' => _x( 'Number of periods', 'Gateway setting', 'ry-woocommerce-tools' ),
			],
		];
		return $fields;
	}

	/**
	 * 不同的 gateway 會有不同的自訂 request params
	 *
	 * @return array<string, mixed>
	 */
	public function extra_request_params(): array {
		return [
			'CreditInstallment' => $this->number_of_period,
		];
	}

	/**
	 * 結帳頁面顯示欄位 ex 刷卡欄位
	 *
	 * @return void
	 * */
	public function payment_fields() {
		parent::payment_fields();
		printf(
		/*html*/'
		<p>%1$s</p>
		',
		\_x( 'Number of periods', 'Checkout info', 'power_checkout' )
		);

		echo /*html*/'<select name="ecpay_number_of_periods">';
		foreach ( $this->number_of_periods as $number_of_periods ) {
			printf(
			/*html*/'
			<option value="%1$d">%1$d 期</option>
			',
			$number_of_periods
			);
		}
		echo /*html*/'</select>';
	}

	/** 在 process_payment 之前執行 */
	public function before_process_payment( \WC_Order $order ): void {
		if ( isset( $_POST['ecpay_number_of_periods'] ) ) { // phpcs:ignore
			$this->number_of_period = (int) $_POST['ecpay_number_of_periods']; // phpcs:ignore
		}
	}
}
