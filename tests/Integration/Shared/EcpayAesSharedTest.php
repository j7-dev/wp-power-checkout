<?php
/**
 * 綠界 AES-128-CBC 單一化共用 helper 等價 gating 測試（einvoice 導入第七階段）
 *
 * 對應 specs/features/shared/ecpay-aes-shared.feature：
 *   - 規則「加密等價」：抽取後密文與原 Invoice/Ecpay + 原 Payment/Ecpg 位元組一致。
 *   - 規則「解密等價」：抽取後可解回原文（含中文 UNESCAPED_UNICODE 向量）。
 *   - 規則「範圍邊界」：ezPay AES-256-CBC 不併入共用 helper（對同明文密文不同）。
 *
 * ⚠️ 最高風險：密文位元組改變 → 第三方解密失敗（KEY10002）。
 *    golden 密文為「重構前」由 tests/offline/ecpay-aes-golden-baseline.php 對固定向量
 *    （固定明文 + 固定 HashKey/HashIV）跑「現有」Invoice/Ecpay 與 Payment/Ecpg encrypt 所得，
 *    兩份完全相同（核實事實 #6）。本測試硬編這些 golden 作為期望值——若任何密文偏離即 fail，擋 merge。
 *
 * 加解密三處共用同一份 {@see EcpaySharedAes}；
 * Invoice/Ecpay 與 Payment/Ecpg 為薄包裝（委派至共用 helper）；
 * Logistics 直接 use 共用 helper（別名 AesCrypto）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Shared;

use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\AesCrypto as InvoiceEcpayAes;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\AesCrypto as EzpayAes;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto as PaymentEcpgAes;
use J7\PowerCheckout\Shared\Helpers\EcpayAesCrypto as EcpaySharedAes;
use Tests\Integration\TestCase;

/**
 * 綠界 AES-128-CBC 單一化等價測試類別
 *
 * @group edge
 * @group integration
 */
final class EcpayAesSharedTest extends TestCase {

	/** @var string 綠界 ECPG 線上金流測試環境 HashKey（16 bytes） */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 ECPG 線上金流測試環境 HashIV（16 bytes） */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/**
	 * 重構前 golden 密文（由 tests/offline/ecpay-aes-golden-baseline.php 產生）
	 *
	 * 這兩份在重構前即為 Invoice/Ecpay === Payment/Ecpg 的相同輸出（核實事實 #6）。
	 *
	 * @var string GOLDEN_SIMPLE simple 向量的 golden 密文.
	 */
	private const GOLDEN_SIMPLE = 'udqjXgM+7Q6lCrrculcvzUFnN5zv0ibax1glKFxrORrYAWmCexI0pK2LtLrYcz1jUJzLhpsw/yir7zryj55aMg==';

	/** @var string GOLDEN_CHINESE 含中文/空格/波浪號/特殊字元向量的 golden 密文. */
	private const GOLDEN_CHINESE = 'GITwvBJik45UR5fq9CIeIQBdx8dLt9BNWCo/Zh8l3Rd23oYV8cCiH0Q4xQ0O0XRnB5dPRpd8G+Jt9BNS65XKpGc1/YXAzMuQYyDkjhO87sWNDwLHjrF97y7vFvh/Z6mkn81Jf6CltaT/xmzkvZlRbwNk08mVGVScuybsb1DbU7qXDf9BYqgw8bahONBmjVAj838xUT1gfCVrrfQg4QNTEMFYji/HKQ0tsZv4ikbI3wzGgTfymgsrATXSb4vx/4CAx4hYvZ6jbeBwYHT49v22rK3/CdlohLsRyfXOMal1C8s=';

	/** @return array<string, mixed> simple 測試向量 */
	private function vector_simple(): array {
		return [
			'MerchantID' => '3002607',
			'RtnCode'    => 1,
		];
	}

	/** @return array<string, mixed> 含中文/空格/波浪號/單引號/標籤的測試向量 */
	private function vector_chinese(): array {
		return [
			'ItemName'  => '測試商品 A#測試商品 B',
			'TradeDesc' => "Order ~ test ' & <tag>",
			'Name'      => '王 大明',
		];
	}

	// ========== 規則：加密等價（密文位元組一致） ==========

	/**
	 * 共用 helper 加密 simple 向量 === golden（== 原 Invoice/Ecpay == 原 Payment/Ecpg）
	 *
	 * @test
	 * @group edge
	 */
	public function test_共用helper加密simple向量與golden位元組一致(): void {
		// Given
		$shared = new EcpaySharedAes( self::HASH_KEY, self::HASH_IV );

		// When
		$cipher = $shared->encrypt( $this->vector_simple() );

		// Then: 與重構前 golden 完全一致（KEY10002 護欄）
		$this->assertSame( self::GOLDEN_SIMPLE, $cipher );
	}

	/**
	 * 共用 helper 加密中文向量 === golden（UNESCAPED_UNICODE 保真）
	 *
	 * @test
	 * @group edge
	 */
	public function test_共用helper加密中文向量與golden位元組一致(): void {
		// Given
		$shared = new EcpaySharedAes( self::HASH_KEY, self::HASH_IV );

		// When
		$cipher = $shared->encrypt( $this->vector_chinese() );

		// Then
		$this->assertSame( self::GOLDEN_CHINESE, $cipher );
	}

	/**
	 * 原 Invoice/Ecpay 薄包裝加密 === golden（證明包裝後密文不變）
	 *
	 * @test
	 * @group edge
	 */
	public function test_Invoice_Ecpay包裝加密與golden位元組一致(): void {
		$invoice = new InvoiceEcpayAes( self::HASH_KEY, self::HASH_IV );
		$this->assertSame( self::GOLDEN_SIMPLE, $invoice->encrypt( $this->vector_simple() ) );
		$this->assertSame( self::GOLDEN_CHINESE, $invoice->encrypt( $this->vector_chinese() ) );
	}

	/**
	 * 原 Payment/Ecpg 薄包裝加密 === golden（證明包裝後密文不變）
	 *
	 * @test
	 * @group edge
	 */
	public function test_Payment_Ecpg包裝加密與golden位元組一致(): void {
		$ecpg = new PaymentEcpgAes( self::HASH_KEY, self::HASH_IV );
		$this->assertSame( self::GOLDEN_SIMPLE, $ecpg->encrypt( $this->vector_simple() ) );
		$this->assertSame( self::GOLDEN_CHINESE, $ecpg->encrypt( $this->vector_chinese() ) );
	}

	// ========== 規則：三處一致 ==========

	/**
	 * Invoice/Ecpay 包裝、Payment/Ecpg 包裝、共用 helper 對同輸入產出相同密文
	 *
	 * @test
	 * @group edge
	 */
	public function test_三處對同輸入產出相同密文(): void {
		$shared  = new EcpaySharedAes( self::HASH_KEY, self::HASH_IV );
		$invoice = new InvoiceEcpayAes( self::HASH_KEY, self::HASH_IV );
		$ecpg    = new PaymentEcpgAes( self::HASH_KEY, self::HASH_IV );

		foreach ( [ $this->vector_simple(), $this->vector_chinese() ] as $data ) {
			$c_shared  = $shared->encrypt( $data );
			$c_invoice = $invoice->encrypt( $data );
			$c_ecpg    = $ecpg->encrypt( $data );

			$this->assertSame( $c_shared, $c_invoice, 'Invoice/Ecpay 包裝密文須與共用 helper 一致' );
			$this->assertSame( $c_shared, $c_ecpg, 'Payment/Ecpg 包裝密文須與共用 helper 一致' );
		}
	}

	// ========== 規則：解密等價（抽取後可解回原文） ==========

	/**
	 * 共用 helper 解密原 ECPay golden 密文得回原文
	 *
	 * @test
	 * @group edge
	 */
	public function test_共用helper解密golden得回原文(): void {
		$shared = new EcpaySharedAes( self::HASH_KEY, self::HASH_IV );

		$this->assertSame( $this->vector_simple(), $shared->decrypt( self::GOLDEN_SIMPLE ) );
		$this->assertSame( $this->vector_chinese(), $shared->decrypt( self::GOLDEN_CHINESE ) );
	}

	/**
	 * decrypt(encrypt(x)) === x（round-trip，含中文 UNESCAPED_UNICODE）
	 *
	 * @test
	 * @group edge
	 */
	public function test_round_trip可還原原始陣列含中文(): void {
		$shared = new EcpaySharedAes( self::HASH_KEY, self::HASH_IV );
		$data   = $this->vector_chinese();

		$this->assertSame( $data, $shared->decrypt( $shared->encrypt( $data ) ) );
	}

	/**
	 * 跨包裝解密：共用 helper 加密 → Invoice/Ecpg 包裝可解；反向亦然
	 *
	 * @test
	 * @group edge
	 */
	public function test_跨包裝加解密可互通(): void {
		$shared  = new EcpaySharedAes( self::HASH_KEY, self::HASH_IV );
		$invoice = new InvoiceEcpayAes( self::HASH_KEY, self::HASH_IV );
		$ecpg    = new PaymentEcpgAes( self::HASH_KEY, self::HASH_IV );
		$data    = $this->vector_simple();

		// 共用 helper 加密，兩包裝解密
		$cipher = $shared->encrypt( $data );
		$this->assertSame( $data, $invoice->decrypt( $cipher ) );
		$this->assertSame( $data, $ecpg->decrypt( $cipher ) );
	}

	// ========== 規則：base64 alphabet 不變（標準 +/=，非 URL-safe） ==========

	/**
	 * 密文為標準 Base64 alphabet，不含 URL-safe 的 - 與 _
	 *
	 * @test
	 * @group edge
	 */
	public function test_密文為標準Base64_alphabet不含URL_safe字元(): void {
		$shared = new EcpaySharedAes( self::HASH_KEY, self::HASH_IV );
		$cipher = $shared->encrypt( $this->vector_simple() );

		$this->assertMatchesRegularExpression( '#^[A-Za-z0-9+/=]+$#', $cipher );
		$this->assertStringNotContainsString( '-', $cipher );
		$this->assertStringNotContainsString( '_', $cipher );
		// golden 本身即含 + 與 / 與 =，再次確認非 URL-safe
		$this->assertStringContainsString( '+', self::GOLDEN_SIMPLE );
		$this->assertStringContainsString( '/', self::GOLDEN_SIMPLE );
		$this->assertStringContainsString( '=', self::GOLDEN_SIMPLE );
	}

	// ========== 規則：範圍邊界（ezPay AES-256 不受影響、未被誤併） ==========

	/**
	 * ezPay AES-256-CBC 對同明文產出「不同」於 ECPay 的密文（證明未被誤併）
	 *
	 * ezPay 為 AES-256-CBC + hex 小寫 + 自補 PKCS#7 blocksize=32，演算法本質不同；
	 * 其輸入為已組好的 key=value 明文字串（非陣列），輸出為 hex（無 +//=）。
	 *
	 * @test
	 * @group edge
	 */
	public function test_ezPay_AES256對同明文產出不同於ECPay的密文(): void {
		// ezPay key 需 32 bytes；以 ECPG key padEnd 至 32 取得固定向量
		$ezpay = new EzpayAes( \str_pad( self::HASH_KEY, 32, '0' ), self::HASH_IV );

		// ezPay 輸入是字串明文（非陣列）
		$ezpay_cipher = $ezpay->encrypt( 'MerchantID=3002607&RtnCode=1' );

		// ECPay 共用 helper 對「等義」資料的密文
		$ecpay_cipher = ( new EcpaySharedAes( self::HASH_KEY, self::HASH_IV ) )->encrypt( $this->vector_simple() );

		// Then: 兩者位元組必不同（演算法不同：256 vs 128、hex vs base64、padding 不同）
		$this->assertNotSame( $ecpay_cipher, $ezpay_cipher );
		// ezPay 為小寫 hex（無 base64 的 +//=），ECPay golden 為 base64
		$this->assertMatchesRegularExpression( '#^[0-9a-f]+$#', $ezpay_cipher );
		$this->assertNotSame( self::GOLDEN_SIMPLE, $ezpay_cipher );
	}

	/**
	 * ezPay 維持自補 PKCS#7 blocksize=32 與 hex 小寫輸出（round-trip 自洽，不受 ECPay 重構影響）
	 *
	 * @test
	 * @group edge
	 */
	public function test_ezPay維持獨立實作round_trip自洽(): void {
		$ezpay     = new EzpayAes( \str_pad( self::HASH_KEY, 32, '0' ), self::HASH_IV );
		$plaintext = 'MerchantID=3002607&TimeStamp=1700000000';

		$cipher = $ezpay->encrypt( $plaintext );

		// hex 小寫輸出
		$this->assertMatchesRegularExpression( '#^[0-9a-f]+$#', $cipher );
		// 自洽 round-trip 還原
		$this->assertSame( $plaintext, $ezpay->decrypt( $cipher ) );
	}
}
