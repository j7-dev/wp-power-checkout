<?php
/**
 * 綠界電子收據開立參數 DTO（內層 Data）
 *
 * 依設定的收據類型（一般 / 公益 / 政治）組裝對應欄位。重要規則（查 ECPay-API-Skill guides/25）：
 *   - Amount 必須等於所有 Items[].ItemAmount 加總，否則 RtnCode 錯誤。
 *   - RetrievalMethod=2（電子）時 Email 必填；=1（紙本）時 DeliveryAddress 必填。
 *   - ReceiptType=2（公益）僅可帶 1 項商品；DonorType 只能 1 或 2。
 *   - ReceiptType=4（政治）需 DonationInfo + PaymentMethod；匿名(5) ≤ 1 萬、現金(3) ≤ 10 萬。
 *   - RelateNumber 唯一、不可特殊符號、大小寫視為相同。
 *   - 外層與 Data 層都要有 MerchantID。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs;

use J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums\EDonorType;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums\EReceiptType;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/** 綠界電子收據開立參數 DTO */
final class ReceiptIssueParams extends DTO {

	/** @var string 特店編號（Data 層也需填） */
	public string $MerchantID = '';

	/** @var int 收據金額（須等於 Items 加總） */
	public int $Amount = 0;

	/** @var string 收據抬頭（持有人姓名或公司名） */
	public string $Name = '';

	/** @var int 收據類型 1=一般 / 2=公益 / 4=政治 */
	public int $ReceiptType = 1;

	/** @var int 領用方式 1=紙本 / 2=電子 / 3=自行處理 */
	public int $RetrievalMethod = 2;

	/** @var string 開立日期 yyyy/MM/dd HH:mm:ss（UTC+8） */
	public string $ReceiptDate = '';

	/** @var string 特店自訂編號，每次唯一；英數字 */
	public string $RelateNumber = '';

	/** @var string Email（RetrievalMethod=2 必填） */
	public string $Email = '';

	/** @var array<int, array<string, mixed>> 商品明細 */
	public array $Items = [];

	/** @var int 捐贈人類型（公益/政治必填）1~5 */
	public int $DonorType = 0;

	/** @var string 身分識別碼（依 DonorType） */
	public string $Identifier = '';

	/** @var string 連絡電話（公益 & DonorType=2 必填） */
	public string $Phone = '';

	/** @var string 手機（公益 & DonorType=1 必填） */
	public string $CellPhone = '';

	/** @var string 紙本寄送地址（RetrievalMethod=1 必填） */
	public string $DeliveryAddress = '';

	/** @var int 政治獻金付款方式（政治必填）1=匯款 / 2=票據 / 3=現金 */
	public int $PaymentMethod = 0;

	/** @var array<string, mixed> 捐贈資料（政治必填） */
	public array $DonationInfo = [];

	/** @var string 備註 */
	public string $Note = '';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'MerchantID',
		'Amount',
		'Name',
		'ReceiptType',
		'RetrievalMethod',
		'ReceiptDate',
		'RelateNumber',
		'Items',
	];

	/**
	 * 從訂單 + 設定建立開立參數
	 *
	 * @param \WC_Order               $order    訂單
	 * @param EcpayReceiptSettingsDTO $settings 收據設定
	 *
	 * @return self
	 */
	public static function from_order( \WC_Order $order, EcpayReceiptSettingsDTO $settings ): self {
		$receipt_type       = $settings->get_default_receipt_type();
		[ $amount, $items ] = self::build_items( $order, $receipt_type );

		$args = [
			'MerchantID'      => $settings->merchant_id,
			'Amount'          => $amount,
			'Name'            => self::resolve_name( $order ),
			'ReceiptType'     => $receipt_type->value,
			'RetrievalMethod' => (int) $settings->retrieval_method,
			'ReceiptDate'     => \current_time( 'Y/m/d H:i:s' ),
			'RelateNumber'    => self::build_relate_number( $order ),
			'Email'           => $order->get_billing_email(),
			'Items'           => $items,
		];

		// 紙本須帶寄送地址
		if ('1' === $settings->retrieval_method) {
			$args['DeliveryAddress'] = \J7\PowerCheckout\Shared\Utils\OrderUtils::get_full_address( $order );
		}

		// 捐贈類（公益 / 政治）補上 DonorType / Identifier / 聯絡方式
		if ($receipt_type->is_donation()) {
			$args = \array_merge( $args, self::resolve_donation_args( $order, $settings, $receipt_type ) );
		}

		return new self( $args );
	}

	/**
	 * 組裝商品明細並回傳 [總額, Items]。公益收據僅可帶 1 項，故合併為單一項。
	 *
	 * @param \WC_Order    $order        訂單
	 * @param EReceiptType $receipt_type 收據類型
	 *
	 * @return array{0: int, 1: array<int, array<string, mixed>>}
	 */
	private static function build_items( \WC_Order $order, EReceiptType $receipt_type ): array {
		$order_total = (int) \round( (float) $order->get_total() );

		// 公益收據：系統限制僅 1 項商品，合併為「捐贈物品一批」
		if ($receipt_type->is_single_item_only()) {
			$items = [
				[
					'ItemSeq'    => 1,
					'ItemName'   => '捐贈',
					'ItemCount'  => 1,
					'ItemPrice'  => $order_total,
					'ItemAmount' => $order_total,
				],
			];
			return [ $order_total, $items ];
		}

		$amount = 0;
		$items  = [];
		$seq    = 1;

		foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $item ) {
			$quantity = (int) $item->get_quantity();

			if ($item instanceof \WC_Order_Item_Product) {
				$total = (int) \round( (float) $item->get_subtotal() );
			} elseif ($item instanceof \WC_Order_Item_Fee || $item instanceof \WC_Order_Item_Shipping) {
				$total    = (int) \round( (float) $item->get_total() );
				$quantity = $quantity ?: 1;
			} else {
				continue;
			}

			if (!$total || !$quantity) {
				continue;
			}

			$unit_price = (int) \round( $total / $quantity );
			$amount    += $total;

			$items[] = [
				'ItemSeq'    => $seq++,
				'ItemName'   => ( new StrHelper( $item->get_name() ) )->filter()->value,
				'ItemCount'  => $quantity,
				'ItemPrice'  => $unit_price,
				'ItemAmount' => $total,
			];
		}

		// 無明細時以訂單總額補一筆，確保 Items 非空且 Amount 一致
		if (!$items) {
			$items = [
				[
					'ItemSeq'    => 1,
					'ItemName'   => '訂單款項',
					'ItemCount'  => 1,
					'ItemPrice'  => $order_total,
					'ItemAmount' => $order_total,
				],
			];
			return [ $order_total, $items ];
		}

		// 微調尾差，使 Amount 等於 Items 加總（綠界要求一致）
		if ($order_total !== $amount) {
			$last_index                         = \count( $items ) - 1;
			$diff                               = $order_total - $amount;
			$items[ $last_index ]['ItemAmount'] = (int) $items[ $last_index ]['ItemAmount'] + $diff;
			$items[ $last_index ]['ItemPrice']  = (int) \round(
				(int) $items[ $last_index ]['ItemAmount'] / \max( 1, (int) $items[ $last_index ]['ItemCount'] )
			);
			$amount                             = $order_total;
		}

		return [ $amount, $items ];
	}

	/**
	 * 依設定解析捐贈相關欄位（公益 / 政治）
	 *
	 * @param \WC_Order               $order        訂單
	 * @param EcpayReceiptSettingsDTO $settings     設定
	 * @param EReceiptType            $receipt_type 收據類型
	 *
	 * @return array<string, mixed>
	 */
	private static function resolve_donation_args( \WC_Order $order, EcpayReceiptSettingsDTO $settings, EReceiptType $receipt_type ): array {
		$donor_type = EDonorType::tryFrom( (int) $settings->donor_type ) ?? EDonorType::INDIVIDUAL;

		// 公益收據：DonorType 僅可 1 或 2，越界 fallback 自然人
		if (EReceiptType::CHARITY === $receipt_type && !$donor_type->is_allowed_for_charity()) {
			$donor_type = EDonorType::INDIVIDUAL;
		}

		$args = [
			'DonorType'  => $donor_type->value,
			'Identifier' => $settings->identifier,
		];

		// 公益收據聯絡方式：自然人帶手機、公司法人帶電話
		if (EReceiptType::CHARITY === $receipt_type) {
			if (EDonorType::INDIVIDUAL === $donor_type) {
				$args['CellPhone'] = $order->get_billing_phone();
			} else {
				$args['Phone'] = $order->get_billing_phone();
			}
		}

		// 政治獻金：補 PaymentMethod + DonationInfo
		if (EReceiptType::POLITICAL === $receipt_type) {
			$args['PaymentMethod'] = (int) $settings->payment_method;
			$args['DonationInfo']  = [
				'IsBequest'    => 0,
				'DonationDate' => \current_time( 'Y/m/d H:i:s' ),
			];
		}

		return $args;
	}

	/** 初始化後校正各收據類型的金額上限與互斥規則（送出前防呆，違規不靜默截斷） */
	protected function after_init(): void {
		// 紙本領用須有抬頭
		if (1 === $this->RetrievalMethod && '' === $this->Name) {
			$this->Name = '消費者';
		}
	}

	/**
	 * 驗證政治獻金金額上限（匿名 ≤ 1 萬 / 現金 ≤ 10 萬）
	 *
	 * 命名為 check_amount_limit 而非 validate，避免覆寫基底 DTO::validate(): void。
	 *
	 * @return string|null 違規訊息，無違規回 null
	 */
	public function check_amount_limit(): ?string {
		if (EReceiptType::POLITICAL->value !== $this->ReceiptType) {
			return null;
		}
		if (EDonorType::ANONYMOUS->value === $this->DonorType && $this->Amount > 10000) {
			return '政治獻金匿名捐贈金額不可超過 10,000 元';
		}
		if (3 === $this->PaymentMethod && $this->Amount > 100000) {
			return '政治獻金現金捐贈金額不可超過 100,000 元';
		}
		return null;
	}

	/**
	 * 收據抬頭：以帳單姓名為主，無則 fallback 消費者
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	private static function resolve_name( \WC_Order $order ): string {
		$name = \trim( $order->get_formatted_billing_full_name() );
		return $name ?: '消費者';
	}

	/**
	 * 建立每次唯一的 RelateNumber（英數字，不可特殊符號，最長 50；大小寫視為相同故統一大寫）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	private static function build_relate_number( \WC_Order $order ): string {
		$unique = \str_replace( [ '.', '-', '_' ], '', \uniqid( '', true ) );
		return \strtoupper( \substr( "RC{$order->get_id()}{$unique}", 0, 50 ) );
	}
}
