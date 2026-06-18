<?php
/**
 * Golden ciphertext baseline harness — ECPay AES-128-CBC byte-identical proof
 *
 * 目的：在「重構前」證明 Invoice/Ecpay/AesCrypto 與 Payment/Ecpg/AesCrypto 對固定向量
 *      產出位元組完全相同的密文（核實事實 #6）。把輸出記為 golden，寫進等價測試硬編期望值。
 *
 * 此 harness 不依賴 WP_UnitTestCase / DB；只 shim 一個 \wp_json_encode（= json_encode + 同 flags），
 * 其餘 urlencode/openssl/base64/substr 皆原生 PHP。
 *
 * 執行：php tests/offline/ecpay-aes-golden-baseline.php
 */

declare(strict_types=1);

// ---- WordPress 函式 shim（僅補 AesCrypto 用到的 \wp_json_encode） ----
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * 對齊 WP core wp_json_encode 的核心行為：json_encode( $data, $options, $depth )。
	 * AesCrypto 只傳兩參數（data + options），depth 預設 512。
	 *
	 * @param mixed $data    資料.
	 * @param int   $options json flags.
	 * @param int   $depth   遞迴深度.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

// ---- 直接 require 兩份「現有」實作（重構前；繞過 autoload 以鎖定當前 namespace 類別） ----
$base = dirname( __DIR__, 2 );
require_once $base . '/inc/classes/Domains/Invoice/Ecpay/Shared/Helpers/AesCrypto.php';
require_once $base . '/inc/classes/Domains/Payment/Ecpg/Shared/Helpers/AesCrypto.php';
require_once $base . '/inc/classes/Domains/Invoice/Ezpay/Shared/Helpers/AesCrypto.php';

use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\AesCrypto as InvoiceEcpayAes;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto as PaymentEcpgAes;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\AesCrypto as EzpayAes;

// ---- 固定測試向量（固定明文 + 固定 HashKey / HashIV） ----
const HASH_KEY = 'pwFHCqoQZGmho4w6'; // 16 bytes（綠界 ECPG 線上測試 key）
const HASH_IV  = 'EkRm7iFT261dpevs'; // 16 bytes

$vectors = [
	'simple'  => [
		'MerchantID' => '3002607',
		'RtnCode'    => 1,
	],
	'nested'  => [
		'MerchantID' => '3002607',
		'PayToken'   => 'header.payload.sig',
		'OrderInfo'  => [
			'MerchantTradeNo' => 'EG200ABCDEF',
			'TotalAmount'     => 1000,
		],
	],
	'chinese' => [
		'ItemName'  => '測試商品 A#測試商品 B',
		'TradeDesc' => "Order ~ test ' & <tag>",
		'Name'      => '王 大明',
	],
];

$invoice = new InvoiceEcpayAes( HASH_KEY, HASH_IV );
$ecpg    = new PaymentEcpgAes( HASH_KEY, HASH_IV );
$ezpay   = new EzpayAes( str_pad( HASH_KEY, 32, '0' ), HASH_IV ); // ezPay 需 32-byte key

echo "=== ECPay AES-128-CBC golden baseline (pre-refactor) ===\n";
echo 'HASH_KEY=' . HASH_KEY . "  HASH_IV=" . HASH_IV . "\n\n";

$all_match = true;
foreach ( $vectors as $name => $data ) {
	$c_invoice = $invoice->encrypt( $data );
	$c_ecpg    = $ecpg->encrypt( $data );
	$same      = hash_equals( $c_invoice, $c_ecpg );
	$all_match = $all_match && $same;

	echo "[vector: {$name}]\n";
	echo "  Invoice/Ecpay : {$c_invoice}\n";
	echo "  Payment/Ecpg  : {$c_ecpg}\n";
	echo '  byte-identical: ' . ( $same ? 'YES' : '*** NO ***' ) . "\n";

	// round-trip 還原驗證
	$rt = $invoice->decrypt( $c_invoice );
	echo '  round-trip ok : ' . ( $rt === $data ? 'YES' : '*** NO ***' ) . "\n";
	echo "\n";
}

// ezPay（AES-256-CBC hex）對同明文必然不同於 ECPay（證明排除對象未被誤併）
$ez_plain = 'MerchantID=3002607&RtnCode=1';
$c_ezpay  = $ezpay->encrypt( $ez_plain );
echo "[ezPay AES-256-CBC hex — 排除對象]\n";
echo "  Ezpay encrypt(string): {$c_ezpay}\n";
echo '  與 ECPay simple 密文不同: ' . ( $c_ezpay !== $invoice->encrypt( $vectors['simple'] ) ? 'YES' : '*** NO ***' ) . "\n\n";

echo '=== RESULT: ' . ( $all_match ? 'ALL ECPay vectors byte-identical (核實事實 #6 成立)' : '*** MISMATCH — 立即停止 ***' ) . " ===\n";

exit( $all_match ? 0 : 1 );
