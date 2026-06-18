<?php
/**
 * 發票參數後端驗證 + sanitize（block 結帳發票表單專用）
 *
 * 第一性原理：前端表單可被繞過 / 偽造，故凡進入 session / order meta 的發票參數，
 * 一律於後端重新 sanitize（去除標籤、trim）+ validate（型別 / 格式 / 條件必填）。
 * 通過後輸出與 classic 結帳相同形狀的陣列（provider / invoiceType / individual /
 * carrier / moica / companyName / companyId / donateCode），下游零改動沿用。
 *
 * 驗證規則（對應 classic InvoiceApp/Steps 的語意）：
 *   - invoiceType ∈ {individual, company, donate}
 *   - individual（僅 individual 類型）∈ {cloud, barcode, moica, paper}
 *   - barcode 載具：/^\/[0-9A-Z+\-.]{7}$/（手機條碼）
 *   - moica 自然人憑證：/^[A-Z]{2}[0-9]{14}$/
 *   - company 統編：8 碼數字（companyId）；companyName 必填
 *   - donate 捐贈碼：3~7 碼數字（donateCode）
 *
 * 通過 validate 後，非當前發票類型的多餘欄位一律清空（與 classic handleIssue 的清欄邏輯一致），
 * 避免殘留不相關欄位污染 order meta。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Helpers;

use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EIndividual;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EInvoiceType;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;

/** 發票參數後端驗證 + sanitize */
final class InvoiceParamsValidator {

	/**
	 * @var array<int, int> 財政部統一編號 checksum 邏輯乘數（對應 8 碼，由左至右）
	 */
	private const UBN_WEIGHTS = [ 1, 2, 1, 2, 1, 2, 4, 1 ];

	/** @var string 手機條碼載具格式 /開頭 + 7 碼 [0-9A-Z+\-.] */
	private const CARRIER_PATTERN = '/^\/[0-9A-Z+\-.]{7}$/';

	/** @var string 自然人憑證格式：2 碼大寫英文 + 14 碼數字 */
	private const MOICA_PATTERN = '/^[A-Z]{2}[0-9]{14}$/';

	/** @var string 統一編號格式：8 碼數字 */
	private const COMPANY_ID_PATTERN = '/^[0-9]{8}$/';

	/** @var string 捐贈碼格式：3~7 碼數字 */
	private const DONATE_CODE_PATTERN = '/^[0-9]{3,7}$/';

	/**
	 * 驗證 + sanitize 發票參數
	 *
	 * @param array<string, mixed> $raw 前端送入的原始參數
	 * @return array<string, string> 通過驗證、已清欄的發票參數
	 * @throws \InvalidArgumentException 驗證失敗（訊息為使用者可讀的繁中提示）
	 */
	public static function validate( array $raw ): array {
		$provider     = self::sanitize( $raw['provider'] ?? '' );
		$invoice_type = self::sanitize( $raw['invoiceType'] ?? '' );

		if ('' === $provider) {
			throw new \InvalidArgumentException( \__( '請選擇發票服務', 'power_checkout' ) );
		}

		$type_enum = EInvoiceType::tryFrom( $invoice_type );
		if (null === $type_enum) {
			throw new \InvalidArgumentException( \__( '發票類型不正確', 'power_checkout' ) );
		}

		return match ( $type_enum ) {
			EInvoiceType::INDIVIDUAL => self::validate_individual( $provider, $raw ),
			EInvoiceType::COMPANY    => self::validate_company( $provider, $raw ),
			EInvoiceType::DONATE     => self::validate_donate( $provider, $raw ),
		};
	}

	/**
	 * Dispatch 級統一驗證（各 provider issue() 第一步呼叫）
	 *
	 * 第一性原理：表單級 {@see self::validate()} 只能擋住「結帳當下能驗的格式」，
	 * 但發票真正派送（dispatch）到第三方前，仍須以跨 provider 一致的不變式再守一道：
	 *   1. 財政部統一編號 checksum（表單級只驗 8 碼格式，不驗檢核碼；僅 B2B 有買方統編時驗）
	 *   2. 載具 / 捐贈互斥（einvoice carrier∩donation mutex）
	 *   3. 金額守恆 salesAmount + taxAmount === totalAmount（整數 TWD）
	 *
	 * 與表單級分工：表單級 throw \InvalidArgumentException（結帳流程攔截）；
	 * dispatch 級回 \WP_Error（成功回 null），失敗即 NormalizedError(VALIDATION)，
	 * 由呼叫端（provider issue()）直接 return，**不打第三方 API**。
	 *
	 * @param array<string, mixed> $params 待派送的發票參數，慣例鍵：
	 *                                      provider / companyId / carrier / donateCode /
	 *                                      salesAmount / taxAmount / totalAmount
	 * @return \WP_Error|null 通過回 null；任一不變式失敗回正規化 VALIDATION 錯誤
	 */
	public static function validate_for_dispatch( array $params ): ?\WP_Error {
		$provider = self::sanitize( $params['provider'] ?? '' );

		$ubn_error = self::validate_ubn_checksum( $params, $provider );
		if (null !== $ubn_error) {
			return $ubn_error;
		}

		$mutex_error = self::validate_carrier_donate_mutex( $params, $provider );
		if (null !== $mutex_error) {
			return $mutex_error;
		}

		return self::validate_amount_conservation( $params, $provider );
	}

	/**
	 * 不變式 1：財政部統一編號 checksum
	 *
	 * 演算法（財政部標準）：
	 *   weights = [1,2,1,2,1,2,4,1]
	 *   sum = Σ ( 各碼 × 對應乘數 後，乘積的「十位數 + 個位數」相加 )
	 *   若第 7 碼（0-indexed 6）為 7：valid = (sum % 5 === 0) || ((sum + 1) % 5 === 0)
	 *   否則：valid = (sum % 5 === 0)
	 *
	 * 僅在有買方統編（B2B）時驗；companyId 為空（B2C）直接放行。
	 * 非 8 碼數字一律視為不合法（dispatch 級即使表單級漏放也須擋）。
	 *
	 * @param array<string, mixed> $params   發票參數
	 * @param string               $provider 發票服務 id（供錯誤 context）
	 * @return \WP_Error|null 通過 / B2C 回 null；不合法回 VALIDATION
	 */
	private static function validate_ubn_checksum( array $params, string $provider ): ?\WP_Error {
		$company_id = self::sanitize( $params['companyId'] ?? '' );

		// B2C（無買方統編）不驗 checksum
		if ('' === $company_id) {
			return null;
		}

		if (1 !== \preg_match( self::COMPANY_ID_PATTERN, $company_id )) {
			return self::validation_error(
				\__( '統一編號需為 8 碼數字', 'power_checkout' ),
				$provider,
				$company_id
			);
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$product = (int) $company_id[ $i ] * self::UBN_WEIGHTS[ $i ];
			// 乘積各位數字相加（如 28 → 2 + 8 = 10、18 → 1 + 8 = 9）
			$sum += \intdiv( $product, 10 ) + ( $product % 10 );
		}

		// 第 7 碼（0-indexed 6）為 7 的進位特例
		$is_valid = '7' === $company_id[6]
			? ( 0 === $sum % 5 || 0 === ( $sum + 1 ) % 5 )
			: ( 0 === $sum % 5 );

		if (!$is_valid) {
			return self::validation_error(
				\__( '統一編號檢核碼不正確', 'power_checkout' ),
				$provider,
				$company_id
			);
		}

		return null;
	}

	/**
	 * 不變式 2：載具 / 捐贈互斥
	 *
	 * 同時帶 carrier（載具）與 donation（捐贈碼）→ VALIDATION；兩者只能擇一。
	 *
	 * @param array<string, mixed> $params   發票參數
	 * @param string               $provider 發票服務 id
	 * @return \WP_Error|null 互斥成立回 null；同時指定回 VALIDATION
	 */
	private static function validate_carrier_donate_mutex( array $params, string $provider ): ?\WP_Error {
		$carrier     = self::sanitize( $params['carrier'] ?? '' );
		$donate_code = self::sanitize( $params['donateCode'] ?? '' );

		if ('' !== $carrier && '' !== $donate_code) {
			return self::validation_error(
				\__( '載具與捐贈不可同時指定', 'power_checkout' ),
				$provider,
				null
			);
		}

		return null;
	}

	/**
	 * 不變式 3：金額守恆 salesAmount + taxAmount === totalAmount（整數 TWD）
	 *
	 * @param array<string, mixed> $params   發票參數
	 * @param string               $provider 發票服務 id
	 * @return \WP_Error|null 守恆回 null；不守恆回 VALIDATION
	 */
	private static function validate_amount_conservation( array $params, string $provider ): ?\WP_Error {
		$sales = (int) ( $params['salesAmount'] ?? 0 );
		$tax   = (int) ( $params['taxAmount'] ?? 0 );
		$total = (int) ( $params['totalAmount'] ?? 0 );

		if (( $sales + $tax ) !== $total) {
			return self::validation_error(
				\__( '金額不守恆（銷售額 + 稅額 ≠ 總額）', 'power_checkout' ),
				$provider,
				(string) $total
			);
		}

		return null;
	}

	/**
	 * 建立 dispatch 級正規化 VALIDATION 錯誤
	 *
	 * @param string      $message     使用者可讀訊息（text domain：power_checkout）
	 * @param string      $provider    發票服務 id
	 * @param string|null $raw_message 觸發錯誤的原始值（供 debug；可為 null）
	 * @return \WP_Error 正規化錯誤（code = VALIDATION）
	 */
	private static function validation_error( string $message, string $provider, ?string $raw_message ): \WP_Error {
		return NormalizedError::from(
			ErrorCode::VALIDATION,
			$message,
			[
				'provider'    => '' === $provider ? null : $provider,
				'raw_message' => $raw_message,
			]
		);
	}

	/**
	 * 驗證個人發票（雲端 / 手機條碼 / 自然人憑證 / 紙本）
	 *
	 * @param string               $provider 發票服務 id
	 * @param array<string, mixed> $raw      原始參數
	 * @return array<string, string>
	 * @throws \InvalidArgumentException 驗證失敗
	 */
	private static function validate_individual( string $provider, array $raw ): array {
		$individual = self::sanitize( $raw['individual'] ?? '' );
		$ind_enum   = EIndividual::tryFrom( $individual );
		if (null === $ind_enum) {
			throw new \InvalidArgumentException( \__( '個人發票類型不正確', 'power_checkout' ) );
		}

		$carrier = self::sanitize( $raw['carrier'] ?? '' );
		$moica   = self::sanitize( $raw['moica'] ?? '' );

		// 手機條碼：載具必填且格式正確
		if (EIndividual::BARCODE === $ind_enum) {
			if ('' === $carrier || 1 !== \preg_match( self::CARRIER_PATTERN, $carrier )) {
				throw new \InvalidArgumentException( \__( '手機條碼載具格式不正確', 'power_checkout' ) );
			}
			$moica = '';
		} elseif (EIndividual::MOICA === $ind_enum) {
			// 自然人憑證：必填且格式正確
			if ('' === $moica || 1 !== \preg_match( self::MOICA_PATTERN, $moica )) {
				throw new \InvalidArgumentException( \__( '自然人憑證格式不正確', 'power_checkout' ) );
			}
			$carrier = '';
		} else {
			// 雲端 / 紙本：不需載具與自然人憑證
			$carrier = '';
			$moica   = '';
		}

		return self::assemble(
			[
				'provider'    => $provider,
				'invoiceType' => EInvoiceType::INDIVIDUAL->value,
				'individual'  => $ind_enum->value,
				'carrier'     => $carrier,
				'moica'       => $moica,
			]
		);
	}

	/**
	 * 驗證公司發票（統編 + 公司名稱）
	 *
	 * @param string               $provider 發票服務 id
	 * @param array<string, mixed> $raw      原始參數
	 * @return array<string, string>
	 * @throws \InvalidArgumentException 驗證失敗
	 */
	private static function validate_company( string $provider, array $raw ): array {
		$company_id   = self::sanitize( $raw['companyId'] ?? '' );
		$company_name = self::sanitize( $raw['companyName'] ?? '' );

		if (1 !== \preg_match( self::COMPANY_ID_PATTERN, $company_id )) {
			throw new \InvalidArgumentException( \__( '統一編號需為 8 碼數字', 'power_checkout' ) );
		}
		if ('' === $company_name) {
			throw new \InvalidArgumentException( \__( '請填寫公司名稱', 'power_checkout' ) );
		}

		return self::assemble(
			[
				'provider'    => $provider,
				'invoiceType' => EInvoiceType::COMPANY->value,
				'companyId'   => $company_id,
				'companyName' => $company_name,
			]
		);
	}

	/**
	 * 驗證捐贈發票（捐贈碼）
	 *
	 * @param string               $provider 發票服務 id
	 * @param array<string, mixed> $raw      原始參數
	 * @return array<string, string>
	 * @throws \InvalidArgumentException 驗證失敗
	 */
	private static function validate_donate( string $provider, array $raw ): array {
		$donate_code = self::sanitize( $raw['donateCode'] ?? '' );
		if (1 !== \preg_match( self::DONATE_CODE_PATTERN, $donate_code )) {
			throw new \InvalidArgumentException( \__( '捐贈碼需為 3~7 碼數字', 'power_checkout' ) );
		}

		return self::assemble(
			[
				'provider'    => $provider,
				'invoiceType' => EInvoiceType::DONATE->value,
				'donateCode'  => $donate_code,
			]
		);
	}

	/**
	 * 補齊所有欄位（未填者一律空字串），輸出與 classic 結帳相同形狀
	 *
	 * @param array<string, string> $filled 已驗證填入的欄位
	 * @return array<string, string>
	 */
	private static function assemble( array $filled ): array {
		$base = [
			'provider'    => '',
			'invoiceType' => '',
			'individual'  => '',
			'carrier'     => '',
			'moica'       => '',
			'companyName' => '',
			'companyId'   => '',
			'donateCode'  => '',
		];
		return \array_merge( $base, $filled );
	}

	/**
	 * Sanitize 單一字串欄位（去標籤 + trim）
	 *
	 * @param mixed $value 原始值
	 * @return string
	 */
	private static function sanitize( mixed $value ): string {
		if (!\is_string( $value )) {
			return '';
		}
		return \trim( \sanitize_text_field( \wp_unslash( $value ) ) );
	}
}
