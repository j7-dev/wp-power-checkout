<?php
/**
 * 綠界發票開立參數 DTO（內層 Data）
 *
 * 依結帳時填寫的發票資訊（InvoiceParams）決定開立 B2C（個人/雲端/載具/捐贈）或 B2B（公司統編）。
 *
 * 重要規則（查 ECPay-API-Skill guides/04, guides/05）：
 *   - B2C：Donation='1' 時 CarrierType 必須為空字串（互斥）；LoveCode 為愛心碼
 *   - B2C：CustomerPhone 或 CustomerEmail 至少填一個
 *   - B2C：SalesAmount 必須等於所有 Items[].ItemAmount 加總（四捨五入）
 *   - B2B：CustomerIdentifier（統編 8 碼）必填；無載具/捐贈；Items 用 ItemTax（稅額）非 ItemTaxType
 *   - 外層與 Data 層都要有 MerchantID
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums\ECarrierType;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums\ETaxType;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EIndividual;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EInvoiceType;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/** 綠界發票開立參數 DTO */
final class IssueParams extends DTO {

	/** @var string 特店編號（Data 層也需填） */
	public string $MerchantID = '';

	/** @var string 特店自訂編號，每次唯一；英數字，不可特殊符號 */
	public string $RelateNumber = '';

	/** @var string 客戶手機（Phone/Email 擇一） */
	public string $CustomerPhone = '';

	/** @var string 客戶 Email（Phone/Email 擇一） */
	public string $CustomerEmail = '';

	/** @var string 統一編號（8 碼數字）；B2B 必填、B2C 選填 */
	public string $CustomerIdentifier = '';

	/** @var string 客戶名稱 */
	public string $CustomerName = '';

	/** @var string 客戶地址 */
	public string $CustomerAddr = '';

	/** @var string 列印註記 '0'=不列印（雲端）'1'=列印紙本 */
	public string $Print = '0';

	/** @var string 捐贈 '0'=不捐贈 '1'=捐贈 */
	public string $Donation = '0';

	/** @var string 愛心碼（Donation='1' 時必填） */
	public string $LoveCode = '';

	/** @var string 載具類別 */
	public string $CarrierType = '';

	/** @var string 載具編號 */
	public string $CarrierNum = '';

	/** @var string 課稅別 */
	public string $TaxType = '1';

	/** @var int 發票總金額（含稅，須等於 Items 加總） */
	public int $SalesAmount = 0;

	/** @var array<int, array<string, mixed>> 商品明細 */
	public array $Items = [];

	/** @var string 發票類型 '07'=一般 '08'=特種 */
	public string $InvType = '07';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'MerchantID',
		'RelateNumber',
		'SalesAmount',
		'Items',
	];

	/**
	 * 從訂單 + 設定建立 B2C / B2B 開立參數
	 *
	 * @param \WC_Order $order       訂單
	 * @param string    $merchant_id 特店編號
	 *
	 * @return self
	 */
	public static function from_order( \WC_Order $order, string $merchant_id ): self {
		$sales_amount = 0;
		$items        = [];
		$seq          = 1;

		foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $item ) {
			$is_coupon = $item instanceof \WC_Order_Item_Coupon;
			$quantity  = (int) $item->get_quantity();

			if ($item instanceof \WC_Order_Item_Coupon) {
				$total    = (int) \round( (float) $item->get_discount() ) * -1;
				$quantity = $quantity ?: 1;
			} elseif ($item instanceof \WC_Order_Item_Product) {
				$total = (int) \round( (float) $item->get_subtotal() );
			} elseif ($item instanceof \WC_Order_Item_Fee || $item instanceof \WC_Order_Item_Shipping) {
				$total = (int) \round( (float) $item->get_total() );
			} else {
				continue;
			}

			if (!$total || !$quantity) {
				continue;
			}

			$unit_price    = (int) \round( $total / $quantity );
			$sales_amount += $total;

			$items[] = [
				'ItemSeq'     => $seq++,
				'ItemName'    => \sprintf(
					'%1$s%2$s',
					$is_coupon ? '折價券' : '',
					( new StrHelper( $item->get_name() ) )->filter()->value,
				),
				'ItemCount'   => $quantity,
				'ItemWord'    => '件',
				'ItemPrice'   => $unit_price,
				'ItemTaxType' => self::map_tax_type( $item )->value,
				'ItemAmount'  => $total,
			];
		}

		// 確保 SalesAmount 與 Items 加總一致（綠界要求加總相符），以訂單總額為準微調尾差
		$order_total = (int) \round( (float) $order->get_total() );
		if ($items && $order_total !== $sales_amount) {
			$diff                               = $order_total - $sales_amount;
			$last_index                         = \count( $items ) - 1;
			$items[ $last_index ]['ItemAmount'] = (int) $items[ $last_index ]['ItemAmount'] + $diff;
			$items[ $last_index ]['ItemPrice']  = (int) \round(
				(int) $items[ $last_index ]['ItemAmount'] / \max( 1, (int) $items[ $last_index ]['ItemCount'] )
			);
			$sales_amount                       = $order_total;
		}

		$base_args = [
			'MerchantID'    => $merchant_id,
			'RelateNumber'  => self::build_relate_number( $order ),
			'CustomerPhone' => $order->get_billing_phone(),
			'CustomerEmail' => $order->get_billing_email(),
			'CustomerName'  => \trim( $order->get_formatted_billing_full_name() ),
			'CustomerAddr'  => OrderUtils::get_full_address( $order ),
			'SalesAmount'   => $sales_amount,
			'Items'         => $items,
			'InvType'       => '07',
		];

		$invoice_args = self::resolve_invoice_args( $order );

		return new self( \wp_parse_args( $invoice_args, $base_args ) );
	}

	/**
	 * 依結帳填寫的發票資訊解析載具 / 捐贈 / 統編相關欄位
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed>
	 */
	private static function resolve_invoice_args( \WC_Order $order ): array {
		$issue_params = ( new MetaKeys( $order ) )->get_issue_params();
		if (!$issue_params) {
			// 無發票資訊：預設開立綠界雲端載具個人發票
			return [ 'CarrierType' => ECarrierType::ECPAY->value ];
		}

		$args = InvoiceParams::create( $issue_params );

		// 捐贈
		if (isset( $args->invoiceType ) && EInvoiceType::DONATE === $args->invoiceType) {
			return [
				'Donation'    => '1',
				'LoveCode'    => $args->donateCode,
				'CarrierType' => ECarrierType::NONE->value, // 捐贈與載具互斥
			];
		}

		// 公司（B2B）
		if (isset( $args->invoiceType ) && EInvoiceType::COMPANY === $args->invoiceType) {
			return [
				'CustomerIdentifier' => $args->companyId,
				'CustomerName'       => $args->companyName ?: $order->get_formatted_billing_full_name(),
				'CarrierType'        => ECarrierType::NONE->value,
				'Print'              => '1', // 統編發票須列印
			];
		}

		// 個人
		if (isset( $args->individual )) {
			return match ($args->individual) {
				EIndividual::CLOUD   => [ 'CarrierType' => ECarrierType::ECPAY->value ],
				EIndividual::BARCODE => [
					'CarrierType' => ECarrierType::MOBILE->value,
					'CarrierNum'  => $args->carrier,
				],
				EIndividual::MOICA   => [
					'CarrierType' => ECarrierType::MOICA->value,
					'CarrierNum'  => $args->moica,
				],
				EIndividual::PAPER   => [
					'CarrierType' => ECarrierType::NONE->value,
					'Print'       => '1',
				],
			};
		}

		return [ 'CarrierType' => ECarrierType::ECPAY->value ];
	}

	/** 初始化後校正捐贈 / 載具互斥規則 */
	protected function after_init(): void {
		// Donation='1' 時 CarrierType 必須為空（互斥）
		if ('1' === $this->Donation) {
			$this->CarrierType = '';
		}
		// 列印紙本須有客戶名稱與地址
		if ('1' === $this->Print && !$this->CustomerName) {
			$this->CustomerName = '消費者';
		}
	}

	/**
	 * 對應 order item 課稅別到綠界 TaxType
	 *
	 * @param \WC_Order_Item $item Order item
	 *
	 * @return ETaxType
	 */
	private static function map_tax_type( \WC_Order_Item $item ): ETaxType {
		$amego_tax_type = OrderUtils::get_tax_type( $item );
		return match ($amego_tax_type->value) {
			2       => ETaxType::ZERO_RATED,
			3       => ETaxType::EXEMPT,
			default => ETaxType::TAXABLE,
		};
	}

	/**
	 * 建立每次唯一的 RelateNumber（英數字，不可特殊符號，最長 50）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	private static function build_relate_number( \WC_Order $order ): string {
		$unique = \str_replace( [ '.', '-', '_' ], '', \uniqid( '', true ) );
		return \substr( "PC{$order->get_id()}{$unique}", 0, 50 );
	}
}
