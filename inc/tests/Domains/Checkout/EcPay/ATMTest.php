<?php
/**
 * 模擬綠界結帳 ATM 櫃員機
 */

use J7\PowerCheckoutTests\Helper\Checkout;

$name = '【綠界結帳 ATM 櫃員機】';

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

it("{$name} 正常結帳測試", function () {
		expect(class_exists('\J7\PowerCheckout\Plugin'))->toBeTrue();
});

it("{$name} 結帳金額超過最大金額", function () {
	expect(true)->toBeTrue();
});

it("{$name} 結帳金額小於最小金額", function () {
	expect(true)->toBeTrue();
});

it("{$name} 結帳失敗是否寫入 log", function () {
	expect(true)->toBeTrue();
});

it("{$name} 超過時間未結帳就禁止付款", function () {
	expect(true)->toBeTrue();
});
