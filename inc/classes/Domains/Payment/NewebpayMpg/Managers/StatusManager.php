<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Managers;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgPaymentMethod;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgStatus;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\WpUtils\Classes\WP;

/**
 * 藍新 NewebPay MPG StatusManager
 *
 * 依解密後的通知（含 Status + Result）轉換訂單狀態：
 *  - Status='SUCCESS' 且 Result.RespondCode='00'（付款成功）→ 處理中（先做金額防竄改比對）。
 *  - Status='SUCCESS' 但為 offline 取號（VACC/CVS/BARCODE，無 RespondCode='00'）→ 寫繳費資訊，不改狀態。
 *  - 其他（付款失敗）→ 維持等待付款，記錄 Status / Message / RespondCode。
 *
 * 金額防竄改：轉 processing 前比對 Result.Amt 與訂單應收（ceil 整數）。
 * 不符 → 維持 pending + order note 告警，絕不自動完成付款。
 *
 * 比照 EcpayAIO\Managers\StatusManager。
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §Callback Response Parameters
 */
final class StatusManager {

	/** @var array<string, mixed> 解密後的通知 Result 物件 */
	private readonly array $result;

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $_decoded 解密後的完整通知（{ Status, Message, Result }，已驗章）
	 * @param \WC_Order            $order    訂單
	 */
	public function __construct(
		private readonly array $_decoded,
		private readonly \WC_Order $order,
	) {
		$result       = $this->_decoded['Result'] ?? [];
		$this->result = \is_array( $result ) ? $result : [];
	}

	/**
	 * 依 Status + RespondCode 更新訂單狀態
	 *
	 * @return void
	 */
	public function update_order_status(): void {
		$status       = (string) ( $this->_decoded['Status'] ?? '' );
		$message      = (string) ( $this->_decoded['Message'] ?? '' );
		$respond_code = (string) ( $this->result['RespondCode'] ?? '' );
		$payment_type = (string) ( $this->result['PaymentType'] ?? '' );

		$meta_keys = new MpgMetaKeys( $this->order );

		// 寫入付款明細（整個 Result，供後台顯示與退款分流）
		$meta_keys->update_payment_detail( $this->result );

		// 寫入藍新 TradeNo（退款 / 請款 / 取消授權用）
		$trade_no = (string) ( $this->result['TradeNo'] ?? '' );
		if ( '' !== $trade_no ) {
			$meta_keys->update_trade_no( $trade_no );
		}

		// order note 記錄通知明細
		$this->order->add_order_note(
			WP::array_to_html( $this->result, [ 'title' => '藍新金流付款結果通知' ] )
		);

		// offline 取號（Status=SUCCESS 但非付款成功）→ 寫繳費資訊，不改狀態
		if ( $this->is_offline_get_code( $status, $respond_code, $payment_type ) ) {
			$this->handle_offline_get_code( $meta_keys, $payment_type );
			return;
		}

		// 付款成功判定（Status=SUCCESS 且 RespondCode=00）
		if ( MpgStatus::is_paid_success( $status, $respond_code ) ) {
			// 金額防竄改：不符則維持 pending + 告警
			if ( ! $this->is_amount_matched() ) {
				$this->order->add_order_note(
					\sprintf(
						'藍新付款通知金額不符，Result.Amt：%s，訂單應收：%d，維持等待付款（疑似竄改）',
						(string) ( $this->result['Amt'] ?? '' ),
						$this->get_order_amount()
					)
				);
				$this->order->update_status( OrderStatus::PENDING->value );
				return;
			}

			$this->order->payment_complete( $trade_no );
			$this->order->update_status( OrderStatus::PROCESSING->value );
			return;
		}

		// 其他（付款失敗）→ 維持等待付款（記錄 Status / Message / 銀行碼 RespondCode / 收單行 AuthBank）
		$auth_bank = (string) ( $this->result['AuthBank'] ?? '' );
		$this->order->add_order_note(
			\sprintf(
				'藍新付款失敗，Status：%s，Message：%s，RespondCode（銀行碼）：%s，AuthBank：%s',
				$status,
				$message,
				$respond_code,
				$auth_bank
			)
		);
		$this->order->update_status( OrderStatus::PENDING->value );
	}

	/**
	 * 是否為 offline 取號通知（Status=SUCCESS、PaymentType 為 VACC/CVS/BARCODE、尚未付款）
	 *
	 * Offline 取號通知 Status 為 SUCCESS 但 RespondCode 非 '00'（或無 RespondCode），
	 * 且付款方式為取號型；此時應寫繳費資訊但不改狀態。
	 *
	 * @param string $status       頂層 Status
	 * @param string $respond_code Result.RespondCode
	 * @param string $payment_type Result.PaymentType
	 *
	 * @return bool
	 */
	private function is_offline_get_code( string $status, string $respond_code, string $payment_type ): bool {
		if ( ! MpgStatus::is_status_success( $status ) ) {
			return false;
		}
		// 已付款成功（RespondCode=00）不算取號
		if ( MpgStatus::RESPOND_CODE_SUCCESS === $respond_code ) {
			return false;
		}
		$method = MpgPaymentMethod::tryFrom( $payment_type );
		return null !== $method && $method->is_offline();
	}

	/**
	 * 處理 offline 取號（寫繳費資訊，不改狀態）
	 *
	 * @param MpgMetaKeys $meta_keys    meta 存取
	 * @param string      $payment_type 付款方式
	 *
	 * @return void
	 */
	private function handle_offline_get_code( MpgMetaKeys $meta_keys, string $payment_type ): void {
		// 冪等：已寫入取號資訊則不重複
		if ( $meta_keys->get_payment_info() ) {
			return;
		}

		// 萃取繳費資訊欄位（依付款方式不同）
		$info_keys = [ 'PaymentType', 'BankCode', 'CodeNo', 'Barcode_1', 'Barcode_2', 'Barcode_3', 'ExpireDate', 'StoreType', 'StoreCode' ];
		$info      = [];
		foreach ( $info_keys as $key ) {
			if ( isset( $this->result[ $key ] ) && '' !== $this->result[ $key ] ) {
				$info[ $key ] = $this->result[ $key ];
			}
		}

		$meta_keys->update_payment_info( $info );

		$this->order->add_order_note(
			WP::array_to_html(
				$info,
				[ 'title' => \sprintf( '藍新金流 %s 取號繳費資訊', $payment_type ) ]
			)
		);
		// 不改訂單狀態（維持等待付款）
	}

	/**
	 * 比對藍新回傳金額（Result.Amt）是否等於訂單應收總額
	 *
	 * 藍新金額為整數新台幣，訂單總額以 ceil 取整後比對。
	 *
	 * @return bool
	 */
	private function is_amount_matched(): bool {
		$amt = (int) ( $this->result['Amt'] ?? 0 );
		return $amt === $this->get_order_amount();
	}

	/**
	 * 取得訂單應收金額（整數新台幣）
	 *
	 * @return int
	 */
	private function get_order_amount(): int {
		return (int) \ceil( (float) $this->order->get_total() );
	}
}
