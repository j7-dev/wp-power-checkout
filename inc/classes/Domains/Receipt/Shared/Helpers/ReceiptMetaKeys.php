<?php
/**
 * 電子收據 order meta 存取 helper（HPOS-aware，一律經 WC_Order CRUD）
 *
 * 與電子發票 MetaKeys 分離（不同 meta key 命名空間），讓收據與發票可在同一訂單並存：
 *  - 發票：_pc_issued_invoice_data / _pc_cancelled_invoice_data / _pc_invoice_provider_id
 *  - 收據：_pc_issued_receipt_data / _pc_cancelled_receipt_data / _pc_receipt_provider_id
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Shared\Helpers;

/** 電子收據 order meta 存取 helper */
final class ReceiptMetaKeys {

	/** @var string 紀錄開立收據後的資料 */
	private const ISSUED_RECEIPT_DATA_KEY = '_pc_issued_receipt_data';

	/** @var string 紀錄作廢收據詳情 */
	private const CANCELLED_RECEIPT_DATA_KEY = '_pc_cancelled_receipt_data';

	/** @var string 紀錄此訂單是用哪個收據服務開出的 */
	private const PROVIDER_ID_KEY = '_pc_receipt_provider_id';

	/** Construct */
	public function __construct(
		private readonly \WC_Order $order,
	) {}

	/**
	 * 取得開立收據的資料
	 *
	 * @return array<string, mixed>
	 */
	public function get_issued_data(): array {
		$data = $this->order->get_meta( self::ISSUED_RECEIPT_DATA_KEY ) ?: [];
		return \is_array( $data ) ? $data : [];
	}

	/**
	 * 儲存開立收據的資料
	 *
	 * @param array<string, mixed> $value 開立收據的資料
	 * @return void
	 */
	public function update_issued_data( array $value ): void {
		$this->order->update_meta_data( self::ISSUED_RECEIPT_DATA_KEY, $value );
		$this->order->save_meta_data();
	}

	/**
	 * 取得作廢收據資料
	 *
	 * @return array<string, mixed>
	 */
	public function get_cancelled_data(): array {
		$data = $this->order->get_meta( self::CANCELLED_RECEIPT_DATA_KEY ) ?: [];
		return \is_array( $data ) ? $data : [];
	}

	/**
	 * 儲存作廢收據資料
	 *
	 * @param array<string, mixed> $value 作廢收據資料
	 * @return void
	 */
	public function update_cancelled_data( array $value ): void {
		$this->order->update_meta_data( self::CANCELLED_RECEIPT_DATA_KEY, $value );
		$this->order->save_meta_data();
	}

	/**
	 * 刪除開立收據相關資料（作廢成功後呼叫）
	 *
	 * @param bool $include_cancelled_data 是否一併刪除作廢資料
	 * @return void
	 */
	public function clear_data( bool $include_cancelled_data = false ): void {
		$keys = [ self::ISSUED_RECEIPT_DATA_KEY, self::PROVIDER_ID_KEY ];
		if ($include_cancelled_data) {
			$keys[] = self::CANCELLED_RECEIPT_DATA_KEY;
		}
		foreach ($keys as $key) {
			$this->order->delete_meta_data( $key );
		}
		$this->order->save_meta_data();
	}

	/** @return string 取得收據服務 id */
	public function get_provider_id(): string {
		$value = $this->order->get_meta( self::PROVIDER_ID_KEY );
		return \is_string( $value ) ? $value : '';
	}

	/**
	 * 儲存收據服務 ID
	 *
	 * @param string $value 收據服務 ID
	 * @return void
	 */
	public function update_provider_id( string $value ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, $value );
		$this->order->save_meta_data();
	}
}
