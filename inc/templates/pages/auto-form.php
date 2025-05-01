<?php
/**
 * 因為綠界請求只能透過前端表單提交，所以需要此組件
 *
 * @see https://developers.ecpay.com.tw/?p=2872
 *  */

/** @var array{params: array<string, string|int>, url: string} $args 模板參數 */
@[
	'params'   => $params,
	'url'      => $url,
] = $args;


printf(
/*html*/'<form method="post" id="pp-ecpay-form" action="%s" style="display:none;">
',
\esc_url( $url )
);
foreach ( $params as $key => $value ) {
	printf(
	/*html*/'
<input type="hidden" name="%s" value="%s">
',
	\esc_attr( $key ),
	\esc_attr( (string) $value )
	);
}
echo /*html*/'</form>';

?>

<script type="module">
document.addEventListener('DOMContentLoaded', function() {
	document.getElementById('pp-ecpay-form').submit();
});
</script>
