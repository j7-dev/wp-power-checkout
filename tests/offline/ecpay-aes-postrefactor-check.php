<?php
/**
 * Post-refactor 等價驗證 harness（offline，不依賴 WP DB）
 *
 * 證明：單一化共用 helper + 兩薄包裝（Invoice/Ecpay、Payment/Ecpg）對固定向量
 *      產出的密文與「重構前 golden」位元組一致。三者皆走 composer PSR-4 autoload，
 *      確認 autoload 解析新 Shared\Helpers\EcpayAesCrypto 正常。
 *
 * 執行：php tests/offline/ecpay-aes-postrefactor-check.php
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $d 資料.
	 * @param int   $o flags.
	 * @param int   $de depth.
	 * @return string|false
	 */
	function wp_json_encode( $d, $o = 0, $de = 512 ) {
		return json_encode( $d, $o, $de );
	}
}

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$shared  = 'J7\PowerCheckout\Shared\Helpers\EcpayAesCrypto';
$invoice = 'J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\AesCrypto';
$ecpg    = 'J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto';

echo 'shared  class_exists via autoload : ' . ( class_exists( $shared ) ? 'YES' : 'NO' ) . "\n";
echo 'invoice class_exists via autoload : ' . ( class_exists( $invoice ) ? 'YES' : 'NO' ) . "\n";
echo 'ecpg    class_exists via autoload : ' . ( class_exists( $ecpg ) ? 'YES' : 'NO' ) . "\n\n";

$key = 'pwFHCqoQZGmho4w6';
$iv  = 'EkRm7iFT261dpevs';

$vectors = [
	'simple'  => [ 'MerchantID' => '3002607', 'RtnCode' => 1 ],
	'chinese' => [ 'ItemName' => '測試商品 A#測試商品 B', 'TradeDesc' => "Order ~ test ' & <tag>", 'Name' => '王 大明' ],
];

// 重構前 golden（由 ecpay-aes-golden-baseline.php 產生）
$golden = [
	'simple'  => 'udqjXgM+7Q6lCrrculcvzUFnN5zv0ibax1glKFxrORrYAWmCexI0pK2LtLrYcz1jUJzLhpsw/yir7zryj55aMg==',
	'chinese' => 'GITwvBJik45UR5fq9CIeIQBdx8dLt9BNWCo/Zh8l3Rd23oYV8cCiH0Q4xQ0O0XRnB5dPRpd8G+Jt9BNS65XKpGc1/YXAzMuQYyDkjhO87sWNDwLHjrF97y7vFvh/Z6mkn81Jf6CltaT/xmzkvZlRbwNk08mVGVScuybsb1DbU7qXDf9BYqgw8bahONBmjVAj838xUT1gfCVrrfQg4QNTEMFYji/HKQ0tsZv4ikbI3wzGgTfymgsrATXSb4vx/4CAx4hYvZ6jbeBwYHT49v22rK3/CdlohLsRyfXOMal1C8s=',
];

$all_ok = true;
foreach ( $vectors as $name => $data ) {
	$cs = ( new $shared( $key, $iv ) )->encrypt( $data );
	$ci = ( new $invoice( $key, $iv ) )->encrypt( $data );
	$ce = ( new $ecpg( $key, $iv ) )->encrypt( $data );
	$ok = ( $cs === $golden[ $name ] && $ci === $golden[ $name ] && $ce === $golden[ $name ] );
	$all_ok = $all_ok && $ok;
	echo "[vector: {$name}] shared/invoice/ecpg all == pre-refactor golden: " . ( $ok ? 'YES' : '*** NO ***' ) . "\n";

	$rt = ( new $shared( $key, $iv ) )->decrypt( $cs );
	echo "  round-trip (shared decrypt): " . ( $rt === $data ? 'YES' : '*** NO ***' ) . "\n";
}

echo "\n=== RESULT: " . ( $all_ok ? 'POST-REFACTOR byte-identical to golden (密文未變)' : '*** CIPHERTEXT CHANGED — 不可放行 ***' ) . " ===\n";
exit( $all_ok ? 0 : 1 );
