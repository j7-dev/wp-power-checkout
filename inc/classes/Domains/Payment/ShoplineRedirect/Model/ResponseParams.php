<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core\Service;

/**
 * 綠界全方位金流 API 必填參數 DTO
 *
 * @see https://developers.ecpay.com.tw/?p=2862
 */
final class ResponseParams extends DTO {

	use ParamsTrait; // 共用屬性

	/**
	 * @var int 交易狀態
	 * @see https://developers.ecpay.com.tw/?p=2881
	 * 1 交易成功
	 * 2 等 ATM 付款 ATM 回傳值時為2時，交易狀態為取號成功，其餘為失敗。
	 * 10100073 等超商付款 CVS/BARCODE回傳值時為10100073時，交易狀態為取號成功，其餘為失敗。
	 * 10300066：「交易付款結果待確認中，請勿出貨」，請至廠商管理後台確認已付款完成再出貨。
	 * 10100248：「拒絕交易，請客戶聯繫發卡行確認原因」
	 * 10100252：「額度不足，請客戶檢查卡片額度或餘額」
	 * 10100254：「交易失敗，請客戶聯繫發卡行確認交易限制」
	 * 10100251：「卡片過期，請客戶檢查卡片重新交易」
	 * 10100255：「報失卡，請客戶更換卡片重新交易」
	 * 10100256：「被盜用卡，請客戶更換卡片重新交易」
	 *  */
	public int $RtnCode;

	/** @var string 交易訊息(200) */
	public string $RtnMsg;

	/** @var string 綠界的交易編號 請保存綠界的交易編號與特店交易編號[MerchantTradeNo]的關連。(20) */
	public string $TradeNo;

	/** @var int 交易金額 */
	public int $TradeAmt;

	/** @var string 付款時間 格式為yyyy/MM/dd HH:mm:ss (20) */
	public string $PaymentDate;

	/** @var float 交易服務金額 交易手續費+交易處理費的總金額 */
	public float $PaymentTypeChargeFee;

	/** @var int 訂單成立時間 格式為yyyy/MM/dd HH:mm:ss */
	public int $TradeDate;

	/** @var int 是否為模擬付款 0=不是 1=是 */
	public int $SimulatePaid;

	/** @var string 檢查碼 */
	public string $CheckMacValue;

	// ----- ▼ ATM 付款才有 ----- //

	/** @var string 銀行代碼(3) ATM付款才有 */
	public string $BankCode;

	/** @var string 繳費虛擬帳號(16) ATM付款才有 */
	public string $vAccount;

	// ----- ▼ CVS 付款才有 ----- //

	/** @var string 繳費代碼(14) 當付款方式為CVS時回傳，若付款方式為BARCODE時回傳空白 */
	public string $PaymentNo;

	// ----- ▼ CVS & BARCODE 付款才有 ----- //

	/** @var string 條碼第一段號碼(20) 如果是代碼，則此欄位回傳空白 */
	public string $Barcode1;

	/** @var string 條碼第二段號碼(20) 如果是代碼，則此欄位回傳空白 */
	public string $Barcode2;

	/** @var string 條碼第三段號碼(20) 如果是代碼，則此欄位回傳空白 */
	public string $Barcode3;

	// ----- ▼ CVS & BARCODE & ATM 付款才有 ----- //

	/** @var string 繳費期限(20) 格式為yyyy/MM/dd ATM付款才有 */
	public string $ExpireDate;

	// ----- ▼ 綠界沒有的自訂屬性 ----- //

	/** @var string 交易狀態的文字描述 */
	public string $RtnCodeLabel;

	// ----- ▼ 私有屬性 ----- //

	/** @var array<string, string|int> 原始資料 */
	protected array $dto_data = [];


	/**
	 * 取得實例
	 *
	 *  @param array<string, mixed> $params
	 */
	public static function instance( array $params ): self {
		$params = \wp_unslash( $params ); // 去除轉譯斜線
		return new self( $params );
	}

	/** @return string 取得交易狀態的文字描述 */
	public function get_rtn_code_label(): string {
		return match ($this->RtnCode) {
			1 => '交易成功',
			2 => '等 ATM 付款',
			10100073 => '等超商付款',
			10300066 => '交易付款結果待確認中，請勿出貨',
			10100248 => '拒絕交易，請客戶聯繫發卡行確認原因',
			10100252 => '額度不足，請客戶檢查卡片額度或餘額',
			10100254 => '交易失敗，請客戶聯繫發卡行確認交易限制',
			10100251 => '卡片過期，請客戶檢查卡片重新交易',
			10100255 => '報失卡，請客戶更換卡片重新交易',
			10100256 => '被盜用卡，請客戶更換卡片重新交易',
			default => '未知',
		};
	}

	/**
	 * 收到綠界的付款結果訊息，並判斷檢查碼是否相符
	 *
	 * @see https://developers.ecpay.com.tw/?p=2878
	 * @return bool 是否驗證成功
	 */
	public function is_check_value_valid(): bool {
		$service = Service::instance();

		$check_value = $service->get_check_value( $this->dto_data, 'sha256' );
		return $this->CheckMacValue === $check_value;
	}

	/** 自訂驗證邏輯 */
	protected function validate(): void {
		if (in_array($this->SimulatePaid, [ 0, 1 ])) {
			throw new \Exception('SimulatePaid 必須是 0 或 1');
		}

		if (empty($this->MerchantTradeNo)) {
			throw new \Exception('MerchantTradeNo 不存在');
		}
	}

	/** 初始化後執行 */
	protected function after_init(): void {
		$this->RtnCodeLabel = $this->get_rtn_code_label();
	}
}
