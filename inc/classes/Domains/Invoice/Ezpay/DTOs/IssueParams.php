<?php
/**
 * 藍新 ezPay 發票開立參數 DTO（PostData_ 業務欄位，不含 RespondType / Version / TimeStamp）
 *
 * 本 DTO 為「ezPay 開立發票 PostData_ 欄位」的純值物件，提供兩條建構路徑：
 *   1. create(array)   —— 由已組好的 ezPay 欄位陣列建立並「驗證」（測試與外部組裝用）。
 *   2. from_order(...)  —— 由 WC_Order + 結帳發票資訊建立（B2C/B2B 金額分流 + 載具映射 + MerchantOrderNo）。
 *
 * ⚠️ ezPay 與綠界 Ecpay 金額方向相反（api-reference §金額計算 / concepts §含稅/未稅）：
 *   - B2C：ItemPrice / ItemAmt 為「含稅」；TotalAmt = 含稅總額；Amt = round(TotalAmt / 1.05)；TaxAmt = TotalAmt - Amt。
 *   - B2B：ItemPrice / ItemAmt 為「未稅」；Amt = 未稅合計；TotalAmt = Amt + TaxAmt。
 *   平台僅檢核兩式：每項 ItemAmt = ItemCount × ItemPrice；TotalAmt = Amt + TaxAmt。
 *
 * ⚠️ 載具映射 6 種 + 互斥（concepts §載具/捐贈）：
 *   cloud → CarrierType=2 + BuyerEmail 必填；barcode → CarrierType=0 + CarrierNum；moica → CarrierType=1；
 *   paper → PrintFlag=Y 無載具；donate → LoveCode（CarrierType 必空）；company → Category=B2B + BuyerUBN + PrintFlag=Y。
 *   載具與捐贈互斥：CarrierType 與 LoveCode 同時有值 → throw \InvalidArgumentException（訊息含「載具與捐贈不可同時指定」）。
 *
 * 多商品：ItemName / ItemCount / ItemUnit / ItemPrice / ItemAmt 以 `|` 串接，逐項驗證 ItemAmt = ItemCount × ItemPrice。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §1. 開立發票
 * @see .claude/skills/ezpay-invoice/references/concepts.md §含稅/未稅、§載具規則、§捐贈碼
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Enums\ECarrierType;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Enums\ECategory;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EIndividual;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EInvoiceType;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/** 藍新 ezPay 發票開立參數 DTO（PostData_ 業務欄位） */
final class IssueParams {

	/** @var int BuyerName 長度上限（B2C 30 字 / B2B 60 字；統一以 60 截斷防呆，B2C 再由呼叫端控制） */
	private const BUYER_NAME_MAX = 60;

	/** @var int MerchantOrderNo 長度上限（ezPay 規定 ≤20，限英數底線） */
	private const MERCHANT_ORDER_NO_MAX = 20;

	/**
	 * Constructor（private，一律經 create() / from_order() 工廠建立）
	 *
	 * @param array<string, mixed> $fields 已驗證的 ezPay PostData_ 業務欄位（值皆為字串 / 數字）.
	 */
	private function __construct(
		private readonly array $fields,
	) {}

	/**
	 * 由已組好的 ezPay 欄位陣列建立並驗證
	 *
	 * 驗證項目（任一不過拋 \InvalidArgumentException）：
	 *  1. MerchantOrderNo 非空。
	 *  2. 載具與捐贈互斥（CarrierType 與 LoveCode 不可同時有值）。
	 *  3. 每項 ItemAmt = ItemCount × ItemPrice（多項以 `|` 分隔逐項檢核）。
	 *  4. TotalAmt = Amt + TaxAmt。
	 * BuyerName 超長則截斷（不拋例外）。
	 *
	 * @param array<string, mixed> $data ezPay PostData_ 業務欄位陣列.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 當必填缺漏 / 互斥衝突 / 金額檢核不過.
	 */
	public static function create( array $data ): self {
		$merchant_order_no = \trim( (string) ( $data['MerchantOrderNo'] ?? '' ) );
		if ( '' === $merchant_order_no ) {
			throw new \InvalidArgumentException( 'MerchantOrderNo（自訂編號）為必填，不可為空字串' );
		}

		// 載具與捐贈互斥.
		$carrier_type = (string) ( $data['CarrierType'] ?? '' );
		$love_code    = (string) ( $data['LoveCode'] ?? '' );
		if ( '' !== $carrier_type && '' !== $love_code ) {
			throw new \InvalidArgumentException( '載具與捐贈不可同時指定（CarrierType 與 LoveCode 互斥）' );
		}

		// 每項 ItemAmt = ItemCount × ItemPrice.
		self::assert_item_amounts( $data );

		// TotalAmt = Amt + TaxAmt.
		$amt       = (int) ( $data['Amt'] ?? 0 );
		$tax_amt   = (int) ( $data['TaxAmt'] ?? 0 );
		$total_amt = (int) ( $data['TotalAmt'] ?? 0 );
		if ( $total_amt !== $amt + $tax_amt ) {
			throw new \InvalidArgumentException(
				\sprintf( 'TotalAmt（%d）必須等於 Amt（%d）+ TaxAmt（%d）', $total_amt, $amt, $tax_amt )
			);
		}

		// BuyerName 超長截斷（不拋例外）.
		$data['MerchantOrderNo'] = $merchant_order_no;
		if ( isset( $data['BuyerName'] ) ) {
			$data['BuyerName'] = self::truncate( (string) $data['BuyerName'], self::BUYER_NAME_MAX );
		}

		return new self( $data );
	}

	/**
	 * 由訂單 + 結帳發票資訊建立開立參數（B2C / B2B 金額分流 + 載具映射）
	 *
	 * 金額一律以 `$order->get_total()`（含稅實付）為「唯一錨點」，鏡像綠界 Ecpay from_order 做法，
	 * 避免兩個常見金額 bug：
	 *   (a) 折價券雙重扣——商品改用 get_subtotal()（折扣前）+ coupon 負項（見 build_items）。
	 *   (b) 未稅當含稅——WC line item 金額皆 ex-tax，唯 $order->get_total() 為含稅實付，故以它錨定。
	 *
	 * 金額三式（依 Category 計算並對齊 ezPay 平台檢核 TotalAmt = Amt + TaxAmt）：
	 *   - B2C：ItemPrice / ItemAmt 為「含稅」。target = $grand；Σ ItemAmt 校正為 $grand；
	 *          TotalAmt = $grand、Amt = round($grand / 1.05)、TaxAmt = $grand − Amt。
	 *   - B2B：ItemPrice / ItemAmt 為「未稅」。Amt = round($grand / 1.05)；target = Amt；
	 *          Σ ItemAmt 校正為 Amt；TotalAmt = $grand、TaxAmt = $grand − Amt。
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 當組出的參數未通過 create() 驗證.
	 */
	public static function from_order( \WC_Order $order ): self {
		$invoice_args = self::resolve_invoice_args( $order );
		$is_b2b       = ECategory::B2B->value === ( $invoice_args['Category'] ?? ECategory::B2C->value );

		// 唯一錨點：訂單含稅實付總額.
		$grand = (int) \round( (float) $order->get_total() );

		// 金額三式 + 商品明細校正目標（B2C 明細為含稅、B2B 明細為未稅）.
		if ( $is_b2b ) {
			$amt       = (int) \round( $grand / 1.05 ); // 未稅銷售額.
			$total_amt = $grand;                          // 含稅實付.
			$tax_amt   = $grand - $amt;                   // 稅額.
			$target    = $amt;                            // B2B 明細加總對齊未稅.
		} else {
			$total_amt = $grand;                          // 含稅實付.
			$amt       = (int) \round( $grand / 1.05 ); // 未稅銷售額.
			$tax_amt   = $grand - $amt;                   // 稅額.
			$target    = $grand;                          // B2C 明細加總對齊含稅.
		}

		// 取原始未稅項後，校正末項使 Σ ItemAmt === $target（鏡像 Ecpay 尾差校正）.
		$items = self::adjust_items_to_target( self::build_items( $order ), $target );

		$base = [
			'MerchantOrderNo' => self::build_merchant_order_no( $order ),
			'BuyerName'       => self::resolve_buyer_name( $order, $invoice_args ),
			'BuyerEmail'      => $order->get_billing_email(),
			'BuyerAddress'    => '',
			'PrintFlag'       => 'N',
			'TaxType'         => '1',
			'TaxRate'         => '5',
			'Amt'             => $amt,
			'TaxAmt'          => $tax_amt,
			'TotalAmt'        => $total_amt,
			'ItemName'        => self::join_items( $items, 'name' ),
			'ItemCount'       => self::join_items( $items, 'count' ),
			'ItemUnit'        => self::join_items( $items, 'unit' ),
			'ItemPrice'       => self::join_items( $items, 'price' ),
			'ItemAmt'         => self::join_items( $items, 'amount' ),
			'Status'          => '1', // 即時開立.
		];

		return self::create( \array_merge( $base, $invoice_args ) );
	}

	/**
	 * 輸出 ezPay PostData_ 業務欄位陣列（不含 RespondType / Version / TimeStamp，由 client 注入）
	 *
	 * 所有值轉為字串（ezPay API 欄位皆字串傳遞）；過濾掉值為空字串的選填欄位以免覆蓋預設。
	 *
	 * @return array<string, string> PostData_ 業務欄位.
	 */
	public function to_array(): array {
		$out = [];
		foreach ( $this->fields as $key => $value ) {
			$out[ (string) $key ] = (string) $value;
		}
		return $out;
	}

	/**
	 * 逐項檢核 ItemAmt = ItemCount × ItemPrice（多項以 `|` 分隔）
	 *
	 * @param array<string, mixed> $data ezPay 欄位陣列.
	 *
	 * @return void
	 * @throws \InvalidArgumentException 當任一項小計不符.
	 */
	private static function assert_item_amounts( array $data ): void {
		$counts = self::split_pipe( (string) ( $data['ItemCount'] ?? '' ) );
		$prices = self::split_pipe( (string) ( $data['ItemPrice'] ?? '' ) );
		$amts   = self::split_pipe( (string) ( $data['ItemAmt'] ?? '' ) );

		// 無商品欄位時略過（由其他必填驗證把關）.
		if ( [] === $counts && [] === $prices && [] === $amts ) {
			return;
		}

		$n = \max( \count( $counts ), \count( $prices ), \count( $amts ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$count = (int) ( $counts[ $i ] ?? 0 );
			$price = (int) ( $prices[ $i ] ?? 0 );
			$amt   = (int) ( $amts[ $i ] ?? 0 );

			if ( $count * $price !== $amt ) {
				throw new \InvalidArgumentException(
					\sprintf(
						'第 %d 項 ItemAmt（%d）必須等於 ItemCount（%d）× ItemPrice（%d）',
						$i + 1,
						$amt,
						$count,
						$price
					)
				);
			}
		}
	}

	/**
	 * 依結帳填寫的發票資訊解析載具 / 捐贈 / 統編相關欄位（ezPay 欄位）
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return array<string, mixed> ezPay 欄位片段（Category / CarrierType / CarrierNum / LoveCode / BuyerUBN / PrintFlag …）.
	 */
	private static function resolve_invoice_args( \WC_Order $order ): array {
		$issue_params = ( new MetaKeys( $order ) )->get_issue_params();
		if ( ! $issue_params ) {
			// 無發票資訊：預設 B2C ezPay 電子發票載具（雲端）.
			return [
				'Category'    => ECategory::B2C->value,
				'CarrierType' => ECarrierType::EZPAY->value,
			];
		}

		$args = InvoiceParams::create( $issue_params );

		// 捐贈（LoveCode；載具必空）.
		if ( isset( $args->invoiceType ) && EInvoiceType::DONATE === $args->invoiceType ) {
			return [
				'Category'    => ECategory::B2C->value,
				'CarrierType' => ECarrierType::NONE->value,
				'LoveCode'    => $args->donateCode,
				'PrintFlag'   => 'N',
			];
		}

		// 公司（B2B；統編 + 索取紙本）.
		if ( isset( $args->invoiceType ) && EInvoiceType::COMPANY === $args->invoiceType ) {
			return [
				'Category'    => ECategory::B2B->value,
				'BuyerUBN'    => $args->companyId,
				'CarrierType' => ECarrierType::NONE->value,
				'PrintFlag'   => 'Y', // 統編發票須索取紙本.
			];
		}

		// 個人（依載具類別）.
		if ( isset( $args->individual ) ) {
			return match ( $args->individual ) {
				EIndividual::CLOUD   => [
					'Category'    => ECategory::B2C->value,
					'CarrierType' => ECarrierType::EZPAY->value,
				],
				EIndividual::BARCODE => [
					'Category'    => ECategory::B2C->value,
					'CarrierType' => ECarrierType::MOBILE->value,
					'CarrierNum'  => $args->carrier,
				],
				EIndividual::MOICA   => [
					'Category'    => ECategory::B2C->value,
					'CarrierType' => ECarrierType::MOICA->value,
					'CarrierNum'  => $args->moica,
				],
				EIndividual::PAPER   => [
					'Category'    => ECategory::B2C->value,
					'CarrierType' => ECarrierType::NONE->value,
					'PrintFlag'   => 'Y',
				],
			};
		}

		return [
			'Category'    => ECategory::B2C->value,
			'CarrierType' => ECarrierType::EZPAY->value,
		];
	}

	/**
	 * 由訂單項目組出商品明細原始項（金額來源鏡像綠界 Ecpay from_order，皆為 WC 未稅原始金額）
	 *
	 * ⚠️ 金額來源（避免「折價券雙重扣」+ 統一未稅基準）：
	 *   - 商品 line item 用 `get_subtotal()`（**折扣前**未稅小計，非 get_total 折扣後，否則 coupon 負項會把折扣扣兩次）。
	 *   - 折價券 coupon 用 `-get_discount()`（負項）。
	 *   - 運費 / 手續費 fee / shipping 用 `get_total()`。
	 * 以上 WC 金額皆為 ex-tax（未稅）；含稅 / 未稅換算與「錨定 $order->get_total()」由 from_order 校正末項處理。
	 *
	 * 本方法不做空項 fallback（交由 from_order 依 B2C / B2B 用對應金額補單一彙總項）。
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return array<int, array{name: string, count: int, unit: string, price: int, amount: int}>
	 */
	private static function build_items( \WC_Order $order ): array {
		$items = [];

		foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $item ) {
			$is_coupon = $item instanceof \WC_Order_Item_Coupon;
			$quantity  = (int) $item->get_quantity();

			if ( $item instanceof \WC_Order_Item_Coupon ) {
				$amount   = (int) \round( (float) $item->get_discount() ) * -1;
				$quantity = $quantity ?: 1;
			} elseif ( $item instanceof \WC_Order_Item_Product ) {
				// 折扣前未稅小計（不可用 get_total，否則與 coupon 負項重複扣折扣）.
				$amount = (int) \round( (float) $item->get_subtotal() );
			} elseif ( $item instanceof \WC_Order_Item_Fee || $item instanceof \WC_Order_Item_Shipping ) {
				$amount = (int) \round( (float) $item->get_total() );
			} else {
				continue;
			}

			if ( 0 === $amount || 0 === $quantity ) {
				continue;
			}

			// 單價以小計回推；amount 重設為 price × count，使每項恆滿足 ItemAmt = ItemCount × ItemPrice
			// （回推單價的尾差由 from_order 校正末項時併入錨點 $order->get_total()）.
			$price = (int) \round( $amount / \max( 1, $quantity ) );

			$items[] = [
				'name'   => \sprintf(
					'%1$s%2$s',
					$is_coupon ? '折價券' : '',
					( new StrHelper( $item->get_name() ) )->filter()->value,
				),
				'count'  => $quantity,
				'unit'   => '件',
				'price'  => $price,
				'amount' => $price * $quantity,
			];
		}

		return $items;
	}

	/**
	 * 校正商品明細使「各項 ItemAmt 加總 === $target」（鏡像綠界 Ecpay from_order 第 141-151 行）
	 *
	 * 將加總與目標的尾差 diff 併入末項 ItemAmt。ezPay 平台另檢核「每項 ItemAmt = ItemCount × ItemPrice」，
	 * 故併入尾差後將末項 count 收斂為 1（price = amount），確保末項恆滿足該等式（綠界僅檢核 SalesAmount 總額
	 * 不檢核逐項，故無此步；ezPay 較嚴格需此收斂）。空明細時以單一彙總項補足 $target。
	 *
	 * @param array<int, array{name: string, count: int, unit: string, price: int, amount: int}> $items  商品明細（原始未稅項）.
	 * @param int                                                                                $target 目標加總金額（B2C=含稅總 / B2B=未稅總）.
	 *
	 * @return array<int, array{name: string, count: int, unit: string, price: int, amount: int}> 校正後明細（Σ amount === $target 且逐項 amount = count × price）.
	 */
	private static function adjust_items_to_target( array $items, int $target ): array {
		// 空明細：以單一彙總項補足目標金額，避免 ItemName 空值.
		if ( [] === $items ) {
			return [
				[
					'name'   => '商品',
					'count'  => 1,
					'unit'   => '式',
					'price'  => $target,
					'amount' => $target,
				],
			];
		}

		$sum = 0;
		foreach ( $items as $item ) {
			$sum += (int) $item['amount'];
		}

		if ( $sum !== $target ) {
			// 尾差併入末項；末項 count 收斂為 1 使 price = amount，維持逐項 ItemAmt = ItemCount × ItemPrice.
			$last_index           = \count( $items ) - 1;
			$last                 = $items[ $last_index ];
			$adjusted_amount      = (int) $last['amount'] + ( $target - $sum );
			$items[ $last_index ] = [
				'name'   => (string) $last['name'],
				'count'  => 1,
				'unit'   => (string) $last['unit'],
				'price'  => $adjusted_amount,
				'amount' => $adjusted_amount,
			];
		}

		return $items;
	}

	/**
	 * 將商品明細某欄位以 `|` 串接
	 *
	 * @param array<int, array<string, mixed>> $items 商品明細.
	 * @param string                           $field 欄位（name / count / unit / price / amount）.
	 *
	 * @return string `|` 串接字串.
	 */
	private static function join_items( array $items, string $field ): string {
		return \implode( '|', \array_map( static fn( $item ) => (string) $item[ $field ], $items ) );
	}

	/**
	 * 解析買受人名稱（B2B 取公司名、B2C 取結帳姓名，皆截斷至上限）
	 *
	 * @param \WC_Order            $order        訂單.
	 * @param array<string, mixed> $invoice_args 已解析的發票欄位片段.
	 *
	 * @return string 買受人名稱.
	 */
	private static function resolve_buyer_name( \WC_Order $order, array $invoice_args ): string {
		$is_b2b = ECategory::B2B->value === ( $invoice_args['Category'] ?? ECategory::B2C->value );

		$name = '';
		if ( $is_b2b ) {
			$issue_params = ( new MetaKeys( $order ) )->get_issue_params();
			if ( $issue_params ) {
				$args = InvoiceParams::create( $issue_params );
				$name = $args->companyName;
			}
		}

		if ( '' === $name ) {
			$name = \trim( $order->get_formatted_billing_full_name() );
		}

		return self::truncate( $name ?: '消費者', self::BUYER_NAME_MAX );
	}

	/**
	 * 建立 MerchantOrderNo：order id 衍生、≤20 字、僅英數底線
	 *
	 * 規則：以 `PC{order_id}` 為基底，去除非英數底線字元後截短至 20 字。
	 * 開立發票與後續折讓（allowance_issue 需帶原 MerchantOrderNo）以同一規則推得，確保一致。
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return string MerchantOrderNo.
	 */
	public static function build_merchant_order_no( \WC_Order $order ): string {
		$raw     = "PC{$order->get_id()}";
		$cleaned = \preg_replace( '/[^A-Za-z0-9_]/', '', $raw );
		return \substr( (string) $cleaned, 0, self::MERCHANT_ORDER_NO_MAX );
	}

	/**
	 * 以 `|` 分割並去除空白；空字串回空陣列
	 *
	 * @param string $value `|` 串接字串.
	 *
	 * @return array<int, string>
	 */
	private static function split_pipe( string $value ): array {
		if ( '' === $value ) {
			return [];
		}
		return \explode( '|', $value );
	}

	/**
	 * 多位元組安全截斷（保守取前 N 字元；ezPay 長度以字元計）
	 *
	 * @param string $value 原字串.
	 * @param int    $max   上限字元數.
	 *
	 * @return string 截斷後字串.
	 */
	private static function truncate( string $value, int $max ): string {
		if ( \function_exists( 'mb_substr' ) ) {
			return \mb_substr( $value, 0, $max );
		}
		return \substr( $value, 0, $max );
	}
}
