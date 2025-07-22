<?php
/**
 * Shopline Payment RedirectGateway 導轉式支付測試
 * run `composer test "inc\tests\Domains\Payment\ShoplinePayment\RedirectGatewayTest.php"`
 */

 use J7\PowerCheckoutTests\Helper\Checkout;

$name = '【ShoplinePayment 導轉式支付】';

beforeAll(function () {
	// 測試開始前執行
	Checkout::instance();
});

beforeEach(function () {
	// 每次測試前執行
});

afterEach(function () {
	// 每次測試後執行
});

afterAll(function () {
	// 測試結束後執行
});

it("{$name} 正常結帳測試，是否成功取得跳轉 url", function () {
    // 測試建立 session 並取得 sessionUrl
});

it("{$name} 結帳金額超過最大金額", function () {
});

it("{$name} 結帳金額小於最小金額", function () {
});

it("{$name} 結帳失敗是否寫入 log", function () {
});

it("{$name} 超過時間未結帳就禁止付款", function () {
});

it("{$name} 接收 webhook 通知後，修改訂單到正確狀態", function () {
});

it("{$name} 測試環境與正式環境切換", function () {
	// 測試不同模式下的 API 端點
});

it("{$name} 重複付款防護測試", function () {
	// 測試同一訂單多次付款的處理
});

it("{$name} 訂單取消後不能付款", function () {
	// 測試已取消訂單的付款嘗試
});