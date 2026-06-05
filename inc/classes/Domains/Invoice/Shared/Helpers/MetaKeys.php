<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Helpers;

use J7\PowerCheckout\Plugin;

/** 每次發票請求，不論是哪種發票，都將資料儲存在 order meta 中 */
class MetaKeys {

	/** @var string 紀錄開立發票的參數  */
	private const ISSUE_INVOICE_PARAMS_KEY = '_pc_issue_invoice_params';

	/** @var string 紀錄開立發票後的資料  */
	private const ISSUED_INVOICE_DATA_KEY = '_pc_issued_invoice_data';

	/** @var string 紀錄取消發票詳情 */
	private const CANCELLED_INVOICE_DATA_KEY = '_pc_cancelled_invoice_data';


	/** @var string 紀錄此訂單是用哪個發票服務開出的 */
	private const PROVIDER_ID_KEY = '_pc_invoice_provider_id';

	/** @var string 紀錄開立折讓後的資料（部分退款折讓單） */
	private const ALLOWANCE_DATA_KEY = '_pc_allowance_data';

	/** Construct */
	public function __construct(
		private readonly \WC_Order $order,
	) {}

	/** @return string 紀錄開立發票的參數 KEY */
	public static function get_issue_params_key(): string {
		return self::ISSUE_INVOICE_PARAMS_KEY;
	}

	/**
	 * @param string $key KEY
	 * @param mixed  $default 預設值
	 * @return array<string, mixed>|null 開立發票的參數
	 */
	public function get_issue_params( string $key = '', mixed $default = null ): array|null {
		$issue_params_array = $this->order->get_meta( self::ISSUE_INVOICE_PARAMS_KEY );

		if (!$issue_params_array) {
			return null;
		}

		// 如果值存在，且為 string，那應該是 json string
		if (\is_string($issue_params_array)) {
			// 先去除斜線
			$issue_params_string = \wp_unslash( $issue_params_array );
			try {
				$decoded = \json_decode( $issue_params_string, true, 512, JSON_THROW_ON_ERROR );
				/** @var array<string, mixed>|null $result */
				$result = \is_array($decoded) ? $decoded : null;
				return $result;
			} catch (\Throwable $e) {
				Plugin::logger(
					'json decode 失敗 meta key: ' . self::ISSUE_INVOICE_PARAMS_KEY,
					'error',
					[
						'error'  => $e->getMessage(),
						'params' => $issue_params_array,
					]
				);
				return null;
			}
		}

		if (\is_array($issue_params_array)) {
			/** @var array<string, mixed> $typed_array */
			$typed_array = $issue_params_array;
			if (!$key) {
				return $typed_array;
			}
			return [ $key => $typed_array[ $key ] ?? $default ];
		}
		return null;
	}

	/**
	 * 更新開立發票的參數
	 *
	 * @param array<string, mixed> $value 開立發票的參數
	 * @return void
	 */
	public function update_issue_params( array $value ): void {
		$this->order->update_meta_data( self::ISSUE_INVOICE_PARAMS_KEY, $value );
		$this->order->save_meta_data();
	}


	/**
	 * @param string $key KEY
	 * @param mixed  $default 預設值
	 * @return mixed 取得開立發票的資料
	 */
	public function get_issued_data( string $key = '', mixed $default = null ): mixed {
		$issue_data_array = (array) ( $this->order->get_meta( self::ISSUED_INVOICE_DATA_KEY ) ?: [] );
		if (!$key) {
			return $issue_data_array;
		}
		return $issue_data_array[ $key ] ?? $default;
	}

	/**
	 * 儲存開立發票的資料
	 *
	 * @param array<string, mixed> $value 開立發票的資料
	 * @return void
	 */
	public function update_issued_data( array $value ): void {
		$this->order->update_meta_data( self::ISSUED_INVOICE_DATA_KEY, $value );
		$this->order->save_meta_data();
	}

	/**
	 * 刪除開立發票相關的資料
	 * 通常是作廢發票時才調用
	 *
	 * @param bool $include_cancelled_data 是否將作廢發票的相關資料也一起刪除
	 *
	 * @return void
	 */
	public function clear_data( bool $include_cancelled_data = false ): void {
		$keys = [ self::ISSUE_INVOICE_PARAMS_KEY, self::ISSUED_INVOICE_DATA_KEY, self::PROVIDER_ID_KEY ];
		if ($include_cancelled_data) {
			$keys[] = self::CANCELLED_INVOICE_DATA_KEY;
		}
		foreach ($keys as $key) {
			$this->order->delete_meta_data( $key );
		}
		$this->order->save_meta_data();
	}


	/**
	 * 取得取消發票資料 array
	 *
	 * @return array<string, mixed>
	 */
	public function get_cancelled_data(): array {
		$cancel_data_array = $this->order->get_meta( self::CANCELLED_INVOICE_DATA_KEY ) ?: [];
		return \is_array($cancel_data_array) ? $cancel_data_array : [];
	}

	/**
	 * 儲存取消發票資料 array
	 *
	 * @param array<string, mixed> $value 取消發票資料 array
	 * @return void
	 */
	public function update_cancelled_data( array $value ): void {
		$this->order->update_meta_data( self::CANCELLED_INVOICE_DATA_KEY, $value );
		$this->order->save_meta_data();
	}


	/**
	 * 取得開立折讓資料 array
	 *
	 * @return array<string, mixed>
	 */
	public function get_allowance_data(): array {
		$allowance_data = $this->order->get_meta( self::ALLOWANCE_DATA_KEY ) ?: [];
		return \is_array($allowance_data) ? $allowance_data : [];
	}

	/**
	 * 儲存開立折讓資料 array
	 *
	 * @param array<string, mixed> $value 開立折讓資料 array
	 * @return void
	 */
	public function update_allowance_data( array $value ): void {
		$this->order->update_meta_data( self::ALLOWANCE_DATA_KEY, $value );
		$this->order->save_meta_data();
	}

	/**
	 * 刪除開立折讓資料（作廢折讓時調用）
	 *
	 * @return void
	 */
	public function clear_allowance_data(): void {
		$this->order->delete_meta_data( self::ALLOWANCE_DATA_KEY );
		$this->order->save_meta_data();
	}

	/**
	 * 取得電子發票服務 id
	 *
	 * @return string
	 */
	public function get_provider_id(): string {
		$value = $this->order->get_meta( self::PROVIDER_ID_KEY );
		return \is_string($value) ? $value : '';
	}

	/**
	 * 儲存電子發票服務 ID
	 *
	 * @param string $value 電子發票服務 ID
	 * @return void
	 */
	public function update_provider_id( string $value ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, $value );
		$this->order->save_meta_data();
	}
}
