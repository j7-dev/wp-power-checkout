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

/** 發票參數後端驗證 + sanitize */
final class InvoiceParamsValidator {

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
