<?php
/**
 * ezPay 純函式離線驗證 harness
 *
 * 不依賴 PHPUnit、不依賴 WordPress、不依賴資料庫——但**驗證真實實作類別**
 * （透過 composer autoloader 載入 J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\*）。
 * 因 LocalWP 本機 PHPUnit DB 連線受限，此 harness 為本機唯一可跑的 crypto 硬證據。
 *
 * 執行方式：
 *   php tests/offline/ezpay-pure-harness.php
 *
 * 結果：全部通過 → exit 0；任一失敗（含類別尚未實作的 fatal）→ exit 非 0。
 *   - 實作前（Red）：fatal「Class AesCrypto not found」
 *   - 實作後（Green）：全部 PASS
 *
 * 官方向量出處：
 *  - AesCrypto：ezpay-invoice skill references/concepts.md §AES-256-CBC 加密
 *  - CheckCode：ezpay-invoice skill references/concepts.md §CheckCode 回應驗證
 */

declare( strict_types=1 );

require_once __DIR__ . '/../../vendor/autoload.php';

use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\CheckCodeService;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\UrlEncoder;

// ========== Harness 框架 ==========

$pass  = 0;
$fail  = 0;
$cases = [];

function assert_eq( string $label, mixed $expected, mixed $actual ): void {
	global $pass, $fail, $cases;
	if ( $expected === $actual ) {
		++$pass;
		$cases[] = [
			'status' => 'PASS',
			'label'  => $label,
		];
	} else {
		++$fail;
		$cases[] = [
			'status'   => 'FAIL',
			'label'    => $label,
			'expected' => var_export( $expected, true ),
			'actual'   => var_export( $actual, true ),
		];
	}
}

function assert_true( string $label, bool $condition ): void {
	assert_eq( $label, true, $condition );
}

function assert_matches( string $label, string $pattern, string $value ): void {
	assert_true( $label, (bool) preg_match( $pattern, $value ) );
}

// ========== 測試向量 ==========

$KEY = 'abcdefghijklmnopqrstuvwxyzabcdef'; // 32 bytes
$IV  = '1234567891234567';                 // 16 bytes

// 官方 CheckCode 固定向量（來自 concepts.md §官方範例）
$CHECK_VECTOR = [
	'HashKey'         => 'abcdefghijklmnopqrstuvwxyzabcdef',
	'HashIV'          => '1234567891234567',
	'InvoiceTransNo'  => '14061313541640927',
	'MerchantID'      => '3622183',
	'MerchantOrderNo' => '201409170000001',
	'RandomNum'       => '0142',
	'TotalAmt'        => '500',
	// 官方期望輸出（SHA256 大寫）
	'expected'        => '303AB800650B724733B5D91CBCE075D9EA09E4CDE9CD33461D45F07D5EC7EECB',
];

// ========== AesCrypto 測試（真實類別） ==========

$crypto = new AesCrypto( $KEY, $IV );

// 1. 加密輸出為小寫 hex
$ct = $crypto->encrypt( 'test=1' );
assert_matches( 'AesCrypto: 輸出為小寫 hex', '/^[0-9a-f]+$/', $ct );

// 2. 加解密往返
$plain = 'MerchantID=MS12345678&RespondType=JSON&Version=1.5';
assert_eq( 'AesCrypto: 加解密往返', $plain, $crypto->decrypt( $crypto->encrypt( $plain ) ) );

// 3. blocksize=32 邊界（長度 33）
$plain33 = str_repeat( 'A', 33 );
assert_eq( 'AesCrypto: blocksize=32 邊界（長33）', $plain33, $crypto->decrypt( $crypto->encrypt( $plain33 ) ) );

// 4. 精確 32 bytes（PKCS#7 補整 block）
$plain32 = str_repeat( 'X', 32 );
assert_eq( 'AesCrypto: 精確32bytes往返', $plain32, $crypto->decrypt( $crypto->encrypt( $plain32 ) ) );

// 5. 中文 rawurlencode
$chinese = 'ItemName=' . rawurlencode( '測試商品' );
assert_eq( 'AesCrypto: 中文 rawurlencode 往返', $chinese, $crypto->decrypt( $crypto->encrypt( $chinese ) ) );

// 6. 空字串
assert_eq( 'AesCrypto: 空字串往返', '', $crypto->decrypt( $crypto->encrypt( '' ) ) );

// 7. ezPay blocksize=32 自補 padding 與標準 16-block PKCS#7 產生不同密文
// 證明真實 AesCrypto 用的是 blocksize=32 自補（非 openssl 預設 block16），避免 KEY10002。
$std_plain16    = str_repeat( 'A', 32 );
$wrongCipherHex = bin2hex( (string) openssl_encrypt( $std_plain16, 'AES-256-CBC', $KEY, OPENSSL_RAW_DATA, $IV ) );
$custom_cipher  = $crypto->encrypt( $std_plain16 );
assert_true( 'AesCrypto: 標準16-blockPadding加密與ezPay-blocksize32加密產生不同密文', $wrongCipherHex !== $custom_cipher );

// ========== CheckCodeService 測試（真實類別） ==========

$cv      = $CHECK_VECTOR;
$checker = new CheckCodeService( $cv['HashKey'], $cv['HashIV'] );

$fields = [
	'InvoiceTransNo'  => $cv['InvoiceTransNo'],
	'MerchantID'      => $cv['MerchantID'],
	'MerchantOrderNo' => $cv['MerchantOrderNo'],
	'RandomNum'       => $cv['RandomNum'],
	'TotalAmt'        => $cv['TotalAmt'],
];

// 8. 官方固定向量
assert_eq( 'CheckCode: 官方固定向量', $cv['expected'], $checker->compute( $fields ) );

// 9. 大寫輸出
$code = $checker->compute( $fields );
assert_eq( 'CheckCode: 輸出為大寫', strtoupper( $code ), $code );

// 10. 欄位順序無影響（ksort）
$shuffled = [
	'TotalAmt'        => $cv['TotalAmt'],
	'RandomNum'       => $cv['RandomNum'],
	'InvoiceTransNo'  => $cv['InvoiceTransNo'],
	'MerchantOrderNo' => $cv['MerchantOrderNo'],
	'MerchantID'      => $cv['MerchantID'],
];
assert_eq( 'CheckCode: 欄位順序無影響（ksort）', $cv['expected'], $checker->compute( $shuffled ) );

// 11. verify() 正確 checkcode 回 true
assert_true( 'CheckCode: verify() 正確 checkcode 回 true', $checker->verify( $fields, $cv['expected'] ) );

// 12. verify() 偽造 checkcode 回 false
assert_eq( 'CheckCode: verify() 偽造 checkcode 回 false', false, $checker->verify( $fields, 'DEADBEEF' . str_repeat( '0', 56 ) ) );

// 13. TotalAmt 篡改後驗證失敗
$tampered             = $fields;
$tampered['TotalAmt'] = '9999';
assert_eq( 'CheckCode: 篡改 TotalAmt 後驗證失敗', false, $checker->verify( $tampered, $cv['expected'] ) );

// ========== UrlEncoder 測試（真實類別） ==========

$encoder = new UrlEncoder();

// 14. 基本 encode
$result = $encoder->encode(
	[
		'RespondType' => 'JSON',
		'Version'     => '1.5',
	]
	);
assert_true( 'UrlEncoder: 基本 encode 含 RespondType=JSON', str_contains( $result, 'RespondType=JSON' ) );

// 15. 中文 rawurlencode（空白→%20）
$result = $encoder->encode( [ 'ItemName' => '測試 商品' ] );
assert_true( 'UrlEncoder: 中文空白→%20（rawurlencode）', str_contains( $result, '%20' ) );
assert_true( 'UrlEncoder: 中文空白不含+（非 urlencode）', ! str_contains( $result, 'ItemName=+' ) );

// 16. CarrierNum 前後空白 trim
$result = $encoder->encode( [ 'CarrierNum' => '  /ABC123  ' ] );
parse_str( $result, $parsed );
assert_eq( 'UrlEncoder: CarrierNum 前後空白被 trim', '/ABC123', $parsed['CarrierNum'] ?? '' );

// 17. 空陣列回空字串
assert_eq( 'UrlEncoder: 空陣列回空字串', '', $encoder->encode( [] ) );

// 18. 數值型態正確序列化
$result = $encoder->encode( [ 'TotalAmt' => 100 ] );
parse_str( $result, $parsed );
assert_eq( 'UrlEncoder: 數值型態序列化為字串', '100', $parsed['TotalAmt'] ?? '' );

// ========== 輸出結果 ==========

echo PHP_EOL;
echo '==================================================' . PHP_EOL;
echo 'ezPay 純函式離線驗證 harness（驗證真實實作類別）' . PHP_EOL;
echo '==================================================' . PHP_EOL;

foreach ( $cases as $case ) {
	$status = $case['status'];
	$label  = $case['label'];
	if ( 'PASS' === $status ) {
		echo "  [PASS] {$label}" . PHP_EOL;
	} else {
		echo "  [FAIL] {$label}" . PHP_EOL;
		echo "         期望: {$case['expected']}" . PHP_EOL;
		echo "         實際: {$case['actual']}" . PHP_EOL;
	}
}

echo PHP_EOL;
echo '--------------------------------------------------' . PHP_EOL;
echo "總計：{$pass} 通過 / {$fail} 失敗 / " . ( $pass + $fail ) . ' 項' . PHP_EOL;
echo '--------------------------------------------------' . PHP_EOL;

if ( $fail > 0 ) {
	echo PHP_EOL . '結果：FAIL — 請修正上述錯誤後重新執行' . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . '結果：PASS — 全部通過' . PHP_EOL;
exit( 0 );
