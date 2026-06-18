<?php
/**
 * 有狀態的發票測試替身（MockProvider 狀態機）
 *
 * einvoice 導入第三個優點：in-memory 狀態機 fake，不是固定回 fixture 的 stub。
 *
 * 職責：
 *   - 實作三介面：IInvoiceService + ISupportsAllowance + ISupportsQuery
 *   - 維護 in-memory 發票狀態（已開立 / 已作廢）
 *   - 雙索引：Map<orderId → invoice_number> + Map<invoice_number → record>
 *   - issue() 第一步真跑 InvoiceParamsValidator::validate_for_dispatch()
 *   - 以正規化 code 回 WP_Error（經 NormalizedError::from()）
 *   - 非法狀態轉換回對應正規化 code：
 *     - 重複 issue（已開立同 order）→ CONFLICT
 *     - void 未開立 → NOT_FOUND
 *     - 重複 void（已作廢）→ CONFLICT
 *     - query 查無 → NOT_FOUND
 *
 * 設計原則：
 *   - 純測試碼（Tests namespace），不碰任何 production 檔
 *   - 不動 API_MODE 管線（與現有 mock/sandbox/prod 三模式並存）
 *   - 成功仍回 array（對齊真 provider 契約）
 *   - 可注入起始字軌/流水號（建構子參數），方便斷言固定值
 *
 * @see specs/features/invoice/invoice-mock-statemachine.feature
 * @see specs/open-issue/einvoice-adoption-implementation-plan.md §第八階段 步驟17
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice\Doubles;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\InvoiceParamsValidator;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;

/**
 * 有狀態的發票測試替身（in-memory 狀態機 fake）
 *
 * Provider ID：'mock'（測試專用）
 */
final class MockInvoiceProvider implements IInvoiceService, ISupportsAllowance, ISupportsQuery {

	/** @var string Provider ID（測試專用） */
	public const ID = 'mock';

	/** @var string 發票狀態：已開立 */
	private const STATE_ISSUED = 'issued';

	/** @var string 發票狀態：已作廢 */
	private const STATE_VOIDED = 'voided';

	/**
	 * in-memory 發票記錄
	 *
	 * 結構：
	 *   [
	 *     'invoice_number' => string,
	 *     'order_id'       => int,
	 *     'state'          => 'issued' | 'voided',
	 *     'total_amount'   => int,
	 *     'allowance'      => null | array<string, mixed>,  // 折讓資料
	 *   ]
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $records_by_invoice = [];

	/**
	 * orderId → invoice_number 正向索引
	 *
	 * @var array<int, string>
	 */
	private array $index_by_order = [];

	/**
	 * 字軌前綴（預設 MK）
	 *
	 * @var string
	 */
	private string $track_prefix;

	/**
	 * 流水號計數器（以 1 為底）
	 *
	 * @var int
	 */
	private int $sequence;

	/**
	 * 建構子
	 *
	 * @param string $track_prefix 字軌前綴，預設 'MK'（M=Mock，K=可辨識）
	 * @param int    $start_seq    起始流水號，預設 1（輸出如 MK00000001）
	 */
	public function __construct( string $track_prefix = 'MK', int $start_seq = 1 ) {
		$this->track_prefix = $track_prefix;
		$this->sequence     = $start_seq;
	}

	/**
	 * 開立發票（有狀態 fake）
	 *
	 * 流程：
	 *   1. 冪等：已開立→直接回既有資料（不驗證、不更新）
	 *   2. dispatch 級統一驗證：InvoiceParamsValidator::validate_for_dispatch()
	 *      失敗→ VALIDATION，不寫狀態
	 *   3. 狀態機：已開立（冪等已處理）；正常→新建 issued record，自增 invoice_number
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @return array<string, mixed>|\WP_Error 成功回開立資料 array；失敗回正規化 WP_Error.
	 */
	public function issue( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order_id = $order_or_id instanceof \WC_Order
			? $order_or_id->get_id()
			: (int) $order_or_id;

		// 冪等：已開立直接回傳（不論狀態是 issued 還是 voided 的前任都已在索引）
		if ( isset( $this->index_by_order[ $order_id ] ) ) {
			$invoice_number = $this->index_by_order[ $order_id ];
			$record         = $this->records_by_invoice[ $invoice_number ];

			// 已作廢：也視為「已有開立紀錄」→ CONFLICT（不可重新對已作廢的訂單再開）
			if ( self::STATE_VOIDED === $record['state'] ) {
				return NormalizedError::from(
					ErrorCode::CONFLICT,
					'此訂單的發票已作廢，請重新確認',
					[
						'provider'    => self::ID,
						'raw_code'    => 'MOCK_ALREADY_VOIDED',
						'raw_message' => "訂單 #{$order_id} 發票已作廢",
					]
				);
			}

			// 已開立：CONFLICT
			return NormalizedError::from(
				ErrorCode::CONFLICT,
				'此訂單已開立發票，不可重複開立',
				[
					'provider'    => self::ID,
					'raw_code'    => 'MOCK_ALREADY_ISSUED',
					'raw_message' => "訂單 #{$order_id} 已開立 {$invoice_number}",
				]
			);
		}

		// dispatch 級統一驗證（真跑驗證層，同 production provider）
		$dispatch_params = $this->build_dispatch_params( $order_or_id );
		$validation_err  = InvoiceParamsValidator::validate_for_dispatch( $dispatch_params );
		if ( $validation_err instanceof \WP_Error ) {
			return $validation_err;
		}

		// 建立 invoice_number，自增流水號
		$invoice_number = $this->track_prefix . \str_pad( (string) $this->sequence, 8, '0', \STR_PAD_LEFT );
		$this->sequence++;

		$total = $order_or_id instanceof \WC_Order
			? (int) \round( (float) $order_or_id->get_total() )
			: 0;

		$record = [
			'invoice_number' => $invoice_number,
			'order_id'       => $order_id,
			'state'          => self::STATE_ISSUED,
			'total_amount'   => $total,
			'allowance'      => null,
		];

		// 寫入雙索引
		$this->records_by_invoice[ $invoice_number ]   = $record;
		$this->index_by_order[ $order_id ]             = $invoice_number;

		return [
			'invoice_number' => $invoice_number,
			'order_id'       => $order_id,
			'total_amount'   => $total,
			'state'          => self::STATE_ISSUED,
		];
	}

	/**
	 * 作廢發票（有狀態 fake）
	 *
	 * 狀態轉換：
	 *   - 未開立 → NOT_FOUND
	 *   - 已作廢 → CONFLICT
	 *   - 已開立 → 轉為 voided，回 array
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @return array<string, mixed>|\WP_Error 成功回作廢資料 array；失敗回正規化 WP_Error.
	 */
	public function cancel( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order_id = $order_or_id instanceof \WC_Order
			? $order_or_id->get_id()
			: (int) $order_or_id;

		// 未開立 → NOT_FOUND
		if ( ! isset( $this->index_by_order[ $order_id ] ) ) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				'此訂單尚未開立發票，無法作廢',
				[
					'provider'    => self::ID,
					'raw_code'    => 'MOCK_NOT_ISSUED',
					'raw_message' => "訂單 #{$order_id} 尚未開立發票",
				]
			);
		}

		$invoice_number = $this->index_by_order[ $order_id ];
		$record         = &$this->records_by_invoice[ $invoice_number ];

		// 已作廢 → CONFLICT
		if ( self::STATE_VOIDED === $record['state'] ) {
			return NormalizedError::from(
				ErrorCode::CONFLICT,
				'此發票已作廢，不可重複作廢',
				[
					'provider'    => self::ID,
					'raw_code'    => 'MOCK_ALREADY_VOIDED',
					'raw_message' => "訂單 #{$order_id} 發票 {$invoice_number} 已作廢",
				]
			);
		}

		// 轉為已作廢
		$record['state'] = self::STATE_VOIDED;

		return [
			'status'         => 'cancelled',
			'invoice_number' => $invoice_number,
			'order_id'       => $order_id,
			'rtn_msg'        => 'Mock 作廢成功',
		];
	}

	/**
	 * 取得發票號碼
	 *
	 * @param \WC_Order $order 訂單.
	 * @return string 發票號碼；未開立回空字串.
	 */
	public function get_invoice_number( \WC_Order $order ): string {
		$order_id = $order->get_id();
		return $this->index_by_order[ $order_id ] ?? '';
	}

	/**
	 * 取得設定（mock 無真實設定，回空陣列）
	 *
	 * @param bool $with_default 是否帶預設值（測試替身忽略此參數）.
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [
			'enabled' => 'yes',
			'mode'    => 'mock',
		];
	}

	/**
	 * 開立折讓（有狀態 fake）
	 *
	 * 前置：須已開立發票（NOT_FOUND）；已有折讓時冪等回傳。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @param float         $amount      折讓金額.
	 * @param string        $notify_mail 通知 Email（mock 不使用）.
	 * @return array<string, mixed>|\WP_Error 成功回折讓資料 array；失敗回正規化 WP_Error.
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array|\WP_Error {
		$order_id = $order_or_id instanceof \WC_Order
			? $order_or_id->get_id()
			: (int) $order_or_id;

		// 未開立 → NOT_FOUND
		if ( ! isset( $this->index_by_order[ $order_id ] ) ) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				'此訂單尚未開立發票，無法開立折讓',
				[ 'provider' => self::ID ]
			);
		}

		$invoice_number = $this->index_by_order[ $order_id ];
		$record         = &$this->records_by_invoice[ $invoice_number ];

		// 已作廢 → NOT_FOUND（作廢後不可折讓）
		if ( self::STATE_VOIDED === $record['state'] ) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				'此發票已作廢，無法開立折讓',
				[ 'provider' => self::ID ]
			);
		}

		// 冪等：已有折讓直接回傳
		if ( null !== $record['allowance'] ) {
			/** @var array<string, mixed> $allowance */
			$allowance = $record['allowance'];
			return $allowance;
		}

		$allowance_data = [
			'allowance_number' => 'MK_AL_' . $invoice_number,
			'allowance_amount' => (int) \round( $amount ),
			'invoice_number'   => $invoice_number,
		];
		$record['allowance'] = $allowance_data;

		return $allowance_data;
	}

	/**
	 * 作廢折讓（有狀態 fake）
	 *
	 * 前置：須有已開立折讓資料（否則 NOT_FOUND）。成功後清除折讓資料。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @return array<string, mixed>|\WP_Error 成功回作廢結果 array；失敗回正規化 WP_Error.
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order_id = $order_or_id instanceof \WC_Order
			? $order_or_id->get_id()
			: (int) $order_or_id;

		// 未開立 → NOT_FOUND
		if ( ! isset( $this->index_by_order[ $order_id ] ) ) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				'此訂單尚未開立發票，無折讓可作廢',
				[ 'provider' => self::ID ]
			);
		}

		$invoice_number = $this->index_by_order[ $order_id ];
		$record         = &$this->records_by_invoice[ $invoice_number ];

		// 無折讓 → NOT_FOUND
		if ( null === $record['allowance'] ) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				'查無已開立折讓，無法作廢折讓',
				[ 'provider' => self::ID ]
			);
		}

		// 清除折讓資料
		$record['allowance'] = null;

		return [
			'status'  => 'allowance_invalid',
			'rtn_msg' => 'Mock 折讓作廢成功',
		];
	}

	/**
	 * 查詢發票明細（有狀態 fake，支援 orderId / invoice_number 雙索引）
	 *
	 * 路由：
	 *   - 傳入 WC_Order / int  → 以 orderId 查（正向索引）
	 *   - 傳入 invoice_number 字串  → 以發票號碼查（反向索引；但 ISupportsQuery 簽名只接受 WC_Order|int，
	 *     因此以 query_by_invoice_number() 公開輔助方法補足測試用途）
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @return array<string, mixed>|\WP_Error 成功回發票明細 array；查無回正規化 WP_Error(NOT_FOUND).
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order_id = $order_or_id instanceof \WC_Order
			? $order_or_id->get_id()
			: (int) $order_or_id;

		if ( ! isset( $this->index_by_order[ $order_id ] ) ) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				'此訂單尚未開立發票，無可查詢的發票',
				[ 'provider' => self::ID ]
			);
		}

		$invoice_number = $this->index_by_order[ $order_id ];
		$record         = $this->records_by_invoice[ $invoice_number ];

		return [
			'invoice_number' => $record['invoice_number'],
			'order_id'       => $record['order_id'],
			'state'          => $record['state'],
			'total_amount'   => $record['total_amount'],
		];
	}

	/**
	 * 以發票號碼查詢（雙索引反向查詢輔助方法，測試用途）
	 *
	 * ISupportsQuery::query_invoice() 只接受 WC_Order|int，故以此補足「以發票號碼查訂單」的測試路徑。
	 *
	 * @param string $invoice_number 發票號碼.
	 * @return array<string, mixed>|\WP_Error 成功回發票明細 array（含 order_id）；查無回 NOT_FOUND.
	 */
	public function query_by_invoice_number( string $invoice_number ): array|\WP_Error {
		if ( ! isset( $this->records_by_invoice[ $invoice_number ] ) ) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				"查無發票號碼 {$invoice_number}",
				[ 'provider' => self::ID ]
			);
		}

		$record = $this->records_by_invoice[ $invoice_number ];
		return [
			'invoice_number' => $record['invoice_number'],
			'order_id'       => $record['order_id'],
			'state'          => $record['state'],
			'total_amount'   => $record['total_amount'],
		];
	}

	/**
	 * 重置所有 in-memory 狀態（測試隔離用）
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->records_by_invoice = [];
		$this->index_by_order     = [];
		$this->sequence           = 1;
	}

	/**
	 * 檢查指定訂單的發票狀態是否為已開立
	 *
	 * @param int $order_id 訂單 ID.
	 * @return bool
	 */
	public function is_issued( int $order_id ): bool {
		if ( ! isset( $this->index_by_order[ $order_id ] ) ) {
			return false;
		}
		$invoice_number = $this->index_by_order[ $order_id ];
		return self::STATE_ISSUED === $this->records_by_invoice[ $invoice_number ]['state'];
	}

	/**
	 * 檢查指定訂單的發票狀態是否為已作廢
	 *
	 * @param int $order_id 訂單 ID.
	 * @return bool
	 */
	public function is_voided( int $order_id ): bool {
		if ( ! isset( $this->index_by_order[ $order_id ] ) ) {
			return false;
		}
		$invoice_number = $this->index_by_order[ $order_id ];
		return self::STATE_VOIDED === $this->records_by_invoice[ $invoice_number ]['state'];
	}

	// ========================================================================
	// 私有輔助方法
	// ========================================================================

	/**
	 * 組建 dispatch 級統一驗證參數
	 *
	 * 因 MockProvider 無真實訂單 meta（可能是純 int 呼叫），
	 * 以最小有效參數建構（B2C 個人發票，無統編 / 無載具 / 無捐贈碼）。
	 * 金額以 WC_Order 的 get_total() 計算；純 int 呼叫則以 0 填充（仍可驗互斥 / 載具格式）。
	 *
	 * ⚠️ 測試覆蓋的驗證情境（如互斥/統編錯誤）應傳入 WC_Order 並預寫 issue_params meta，
	 *    或直接以 int 搭配注入參數的方式取得完整覆蓋。
	 *    對照 PaynowInvoiceProvider::build_dispatch_params() 的設計取向。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @return array<string, mixed>
	 */
	private function build_dispatch_params( \WC_Order|int $order_or_id ): array {
		if ( $order_or_id instanceof \WC_Order ) {
			$grand = (int) \round( (float) $order_or_id->get_total() );

			// 讀取訂單的 issue_params（含 carrier / donateCode 等，供互斥驗證）
			$meta      = $order_or_id->get_meta( '_pc_issue_params', true );
			$company_id  = '';
			$carrier     = '';
			$donate_code = '';

			if ( \is_array( $meta ) && $meta ) {
				$company_id  = (string) ( $meta['companyId'] ?? '' );
				$carrier     = (string) ( $meta['carrier'] ?? '' );
				$donate_code = (string) ( $meta['donateCode'] ?? '' );
			}
		} else {
			$grand       = 1000; // 預設有效金額（salesAmount=952 + taxAmount=48 = 1000）
			$company_id  = '';
			$carrier     = '';
			$donate_code = '';
		}

		$sales = (int) \round( $grand / 1.05 );
		$tax   = $grand - $sales;

		return [
			'provider'    => self::ID,
			'companyId'   => $company_id,
			'carrier'     => $carrier,
			'donateCode'  => $donate_code,
			'salesAmount' => $sales,
			'taxAmount'   => $tax,
			'totalAmount' => $grand,
		];
	}
}
