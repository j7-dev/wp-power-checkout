<?php
/**
 * PayNow 電子發票開立參數 DTO（/api/invoices/issue 業務欄位）
 *
 * 本 DTO 為「PayNow 開立發票 request body」的純值物件，提供兩條建構路徑：
 *   1. create(array)   —— 由已組好的 PayNow 欄位陣列建立並「驗證」（測試與外部組裝用）。
 *   2. from_order(...)  —— 由 WC_Order + 結帳發票資訊建立（B2C / B2B 稅額分流 + 載具映射 + order_no）。
 *
 * ⚠️ 稅額計算（R10，invoice-api §3）：
 *   - 非統編（B2C）→ tax_amount = 0（國稅局算稅，不帶稅額）。
 *   - 統編（B2B）  → tax_amount = 實際稅額（自行計算；5% = round(total / 1.05) 為未稅，稅額 = total − 未稅）。
 *
 * ⚠️ 驗證規則（任一不過拋 \InvalidArgumentException）：
 *   - order_no 非空。
 *   - 載具與捐贈互斥：carrier_type 非 None 且 npoban 有值 → throw（訊息含「載具與捐贈不可同時指定」）。
 *   - 零稅率（tax_type=ZeroTax）必填 zero_tax_rate_reason → 缺者 throw（訊息含「零稅率發票必填零稅率原因」）。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §3 單張發票開立 / §10 載具課稅別 / §11 各情境範例
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\DTOs;

use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\ECarrierType;
use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\ETaxType;
use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\EZeroTaxReason;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EIndividual;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EInvoiceType;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/** PayNow 電子發票開立參數 DTO（/api/invoices/issue 業務欄位） */
final class IssueParams {

	/** @var int order_no 長度上限（合理上限 64 字、僅英數底線） */
	private const ORDER_NO_MAX = 64;

	/** @var float 台灣加值型營業稅率 5% */
	private const TAX_RATE = 1.05;

	/**
	 * Constructor（private，一律經 create() / from_order() 工廠建立）
	 *
	 * @param array<string, mixed> $fields 已驗證的 PayNow issue body 業務欄位.
	 */
	private function __construct(
		private readonly array $fields,
	) {}

	/**
	 * 由已組好的 PayNow 欄位陣列建立並驗證
	 *
	 * 驗證項目（任一不過拋 \InvalidArgumentException）：
	 *  1. order_no 非空。
	 *  2. 載具與捐贈互斥（carrier_type 非 None 且 npoban 有值）。
	 *  3. 零稅率（tax_type=ZeroTax）必填 zero_tax_rate_reason。
	 *
	 * @param array<string, mixed> $data PayNow issue body 業務欄位陣列.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 當必填缺漏 / 互斥衝突 / 零稅率缺原因.
	 */
	public static function create( array $data ): self {
		$order_no = \trim( (string) ( $data['order_no'] ?? '' ) );
		if ( '' === $order_no ) {
			throw new \InvalidArgumentException( 'order_no（訂單編號）為必填，不可為空字串' );
		}

		// 載具與捐贈互斥（carrier_type 非 None 且 npoban 有值）.
		$carrier_type = (string) ( $data['carrier_type'] ?? ECarrierType::None->value );
		$npoban       = \trim( (string) ( $data['npoban'] ?? '' ) );
		if ( ECarrierType::None->value !== $carrier_type && '' !== $npoban ) {
			throw new \InvalidArgumentException( '載具與捐贈不可同時指定（carrier_type 與 npoban 互斥）' );
		}

		// 零稅率必填零稅率原因.
		$tax_type             = (string) ( $data['tax_type'] ?? ETaxType::SaleTax->value );
		$zero_tax_rate_reason = \trim( (string) ( $data['zero_tax_rate_reason'] ?? '' ) );
		if ( ETaxType::ZeroTax->value === $tax_type
			&& ( '' === $zero_tax_rate_reason || EZeroTaxReason::None->value === $zero_tax_rate_reason ) ) {
			throw new \InvalidArgumentException( '零稅率發票必填零稅率原因（zero_tax_rate_reason）' );
		}

		$data['order_no'] = $order_no;

		return new self( $data );
	}

	/**
	 * 由訂單 + 結帳發票資訊建立開立參數（B2C / B2B 稅額分流 + 載具映射）
	 *
	 * 金額一律以 `$order->get_total()`（含稅實付）為「唯一錨點」，鏡像 ezPay from_order 做法。
	 * 稅額計算（R10）：
	 *   - B2C（非統編）→ tax_amount = 0（國稅局算稅）。
	 *   - B2B（統編）  → tax_amount = total − round(total / 1.05)。
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 當組出的參數未通過 create() 驗證.
	 */
	public static function from_order( \WC_Order $order ): self {
		$invoice_fields = self::resolve_invoice_fields( $order );
		/** @var array<string, mixed> $buyer */
		$buyer  = \is_array( $invoice_fields['buyer'] ?? null ) ? $invoice_fields['buyer'] : [];
		$is_b2b = '' !== (string) ( $buyer['identifier'] ?? '' );

		// 唯一錨點：訂單含稅實付總額.
		$total_amount = (int) \round( (float) $order->get_total() );

		// 稅額分流（R10）：B2C=0、B2B=實際稅額.
		$tax_amount = $is_b2b
		? $total_amount - (int) \round( $total_amount / self::TAX_RATE )
		: 0;

		$items = self::build_items( $order, $total_amount, $is_b2b ? $tax_amount : 0 );

		// 課稅別 / 零稅率原因：以結帳填寫的原始發票資訊覆蓋（讓 create() 的零稅率驗證得以生效）.
		$tax_fields = self::resolve_tax_fields( $order );

		$base = [
			'order_no'             => self::build_merchant_order_no( $order ),
			'send_paper'           => false,
			'send_sms'             => false,
			'npoban'               => null,
			'total_amount'         => $total_amount,
			'tax_amount'           => $tax_amount,
			'tax_type'             => ETaxType::SaleTax->value,
			'main_remark'          => null,
			'is_pass_customs'      => null,
			'zero_tax_rate_reason' => EZeroTaxReason::None->value,
			'items'                => $items,
		];

		return self::create( \array_merge( $base, $invoice_fields, $tax_fields ) );
	}

	/**
	 * 解析結帳填寫的課稅別 / 零稅率原因（原始發票資訊）
	 *
	 * InvoiceParams DTO 不承載 tax_type / zero_tax_rate_reason，故直接讀原始 issue_params。
	 * 僅輸出有值欄位，使 from_order 的預設課稅別（SaleTax）僅在結帳未指定時生效；
	 * 結帳指定 ZeroTax 而未帶原因時，由 create() 的零稅率驗證攔截。
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return array<string, mixed> 課稅別片段（[tax_type] / [zero_tax_rate_reason]）.
	 */
	private static function resolve_tax_fields( \WC_Order $order ): array {
		$issue_params = ( new MetaKeys( $order ) )->get_issue_params();
		if ( ! \is_array( $issue_params ) || ! $issue_params ) {
			return [];
		}

		$fields = [];

		$tax_type = \trim( (string) ( $issue_params['tax_type'] ?? '' ) );
		if ( '' !== $tax_type ) {
			$fields['tax_type'] = $tax_type;
		}

		$zero_tax_rate_reason = \trim( (string) ( $issue_params['zero_tax_rate_reason'] ?? '' ) );
		if ( '' !== $zero_tax_rate_reason ) {
			$fields['zero_tax_rate_reason'] = $zero_tax_rate_reason;
		}

		return $fields;
	}

	/**
	 * 輸出 PayNow issue body 業務欄位陣列
	 *
	 * 保留 buyer / items 巢狀陣列結構（PayNow API request body 為 JSON，非扁平字串）。
	 *
	 * @return array<string, mixed> issue body 業務欄位.
	 */
	public function to_array(): array {
		return $this->fields;
	}

	/**
	 * 依結帳填寫的發票資訊解析載具 / 捐贈 / 統編相關欄位（PayNow 欄位）
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return array<string, mixed> PayNow 欄位片段（carrier_type / carrier_id1 / carrier_id2 / npoban / buyer …）.
	 */
	private static function resolve_invoice_fields( \WC_Order $order ): array {
		$buyer = [
			'name'       => self::resolve_buyer_name( $order ),
			'identifier' => '',
			'address'    => '',
			'phone'      => $order->get_billing_phone(),
			'email'      => $order->get_billing_email(),
		];

		$issue_params = ( new MetaKeys( $order ) )->get_issue_params();
		if ( ! $issue_params ) {
			// 無發票資訊：預設 B2C 紙本（None）.
			return [
				'carrier_type' => ECarrierType::None->value,
				'carrier_id1'  => null,
				'carrier_id2'  => null,
				'npoban'       => null,
				'buyer'        => $buyer,
			];
		}

		$args = InvoiceParams::create( $issue_params );

		// 捐贈（npoban；載具留 None）.
		if ( isset( $args->invoiceType ) && EInvoiceType::DONATE === $args->invoiceType ) {
			return [
				'carrier_type' => ECarrierType::None->value,
				'carrier_id1'  => null,
				'carrier_id2'  => null,
				'npoban'       => $args->donateCode,
				'buyer'        => $buyer,
			];
		}

		// 公司（B2B；統編，載具 None）.
		if ( isset( $args->invoiceType ) && EInvoiceType::COMPANY === $args->invoiceType ) {
			$buyer['name']       = $args->companyName ?: $buyer['name'];
			$buyer['identifier'] = $args->companyId;
			return [
				'carrier_type' => ECarrierType::None->value,
				'carrier_id1'  => null,
				'carrier_id2'  => null,
				'npoban'       => null,
				'buyer'        => $buyer,
			];
		}

		// 個人（依載具類別）.
		if ( isset( $args->individual ) ) {
			// 個人發票若仍帶捐贈碼，視為「載具與捐贈同時指定」的衝突來源，
			// 透傳為 npoban 讓 create() 的互斥驗證攔截（barcode/moica/cloud 皆有明碼或會員載具）.
			$npoban = '' !== \trim( $args->donateCode ) ? $args->donateCode : null;

			return match ( $args->individual ) {
				EIndividual::BARCODE => [
					'carrier_type' => ECarrierType::PhoneBarCodeCarrier->value,
					'carrier_id1'  => $args->carrier,
					'carrier_id2'  => $args->carrier,
					'npoban'       => $npoban,
					'buyer'        => $buyer,
				],
				EIndividual::MOICA   => [
					'carrier_type' => ECarrierType::CitizenDigitalCardNo->value,
					'carrier_id1'  => $args->moica,
					'carrier_id2'  => $args->moica,
					'npoban'       => $npoban,
					'buyer'        => $buyer,
				],
				// CLOUD（PayNow 會員載具）/ PAPER（紙本）皆無明碼載具.
				EIndividual::CLOUD   => [
					'carrier_type' => ECarrierType::BuyerSno->value,
					'carrier_id1'  => null,
					'carrier_id2'  => null,
					'npoban'       => $npoban,
					'buyer'        => $buyer,
				],
				EIndividual::PAPER   => [
					'carrier_type' => ECarrierType::None->value,
					'carrier_id1'  => null,
					'carrier_id2'  => null,
					'npoban'       => null,
					'buyer'        => $buyer,
				],
			};
		}

		// 預設 B2C 紙本.
		return [
			'carrier_type' => ECarrierType::None->value,
			'carrier_id1'  => null,
			'carrier_id2'  => null,
			'npoban'       => null,
			'buyer'        => $buyer,
		];
	}

	/**
	 * 由訂單項目組出商品明細（PayNow items；以含稅實付總額為錨點，無商品時補單一彙總項）
	 *
	 * @param \WC_Order $order        訂單.
	 * @param int       $total_amount 含稅實付總額（錨點）.
	 * @param int       $tax_amount   整張發票稅額（B2C=0；B2B=實際稅額）.
	 *
	 * @return array<int, array<string, mixed>> PayNow items 明細.
	 */
	private static function build_items( \WC_Order $order, int $total_amount, int $tax_amount ): array {
		$items = [];
		$sum   = 0;

		foreach ( $order->get_items( [ 'line_item' ] ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$quantity = (int) $item->get_quantity();
			$amount   = (int) \round( (float) $item->get_subtotal() );
			if ( 0 === $amount || 0 === $quantity ) {
				continue;
			}
			$unit_price = (int) \round( $amount / \max( 1, $quantity ) );
			$amount     = $unit_price * $quantity;
			$sum       += $amount;

			$items[] = [
				'quantity'    => $quantity,
				'unit_price'  => $unit_price,
				'amount'      => $amount,
				'tax_type'    => ETaxType::SaleTax->value,
				'tax_amount'  => 0,
				'description' => ( new StrHelper( $item->get_name() ) )->filter()->value,
			];
		}

		// 空明細：以單一彙總項補足含稅實付總額.
		if ( [] === $items ) {
			return [
				[
					'quantity'    => 1,
					'unit_price'  => $total_amount,
					'amount'      => $total_amount,
					'tax_type'    => ETaxType::SaleTax->value,
					'tax_amount'  => $tax_amount,
					'description' => '商品',
				],
			];
		}

		// 尾差校正：使 Σ amount === total_amount，並將整張發票稅額寫入末項.
		$last_index                         = \count( $items ) - 1;
		$adjusted                           = (int) $items[ $last_index ]['amount'] + ( $total_amount - $sum );
		$items[ $last_index ]['quantity']   = 1;
		$items[ $last_index ]['unit_price'] = $adjusted;
		$items[ $last_index ]['amount']     = $adjusted;
		$items[ $last_index ]['tax_amount'] = $tax_amount;

		return $items;
	}

	/**
	 * 解析買受人名稱（取結帳姓名，空則回「消費者」）
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return string 買受人名稱.
	 */
	private static function resolve_buyer_name( \WC_Order $order ): string {
		$name = \trim( $order->get_formatted_billing_full_name() );
		return $name ?: '消費者';
	}

	/**
	 * 建立 order_no：order id 衍生、≤64 字、僅英數底線
	 *
	 * 規則：以 `PCN{order_id}` 為基底（與金流 trade_no 同前綴族），去除非英數底線字元後截短至 64 字。
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return string order_no.
	 */
	public static function build_merchant_order_no( \WC_Order $order ): string {
		$raw     = "PCN{$order->get_id()}";
		$cleaned = \preg_replace( '/[^A-Za-z0-9_]/', '', $raw );
		return \substr( (string) $cleaned, 0, self::ORDER_NO_MAX );
	}
}
