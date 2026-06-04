# 綠界ECPay AIO 付款頁面

綠界託管的付款頁面（綠界 Cashier V5）。顧客下單後由 WooCommerce 在 order-received 頁以 auto-form 自動 submit 導轉至此。

## 描述

非本系統渲染的頁面（綠界託管）。顧客在此選擇付款方式並完成付款或取號。

## 關鍵屬性

- 顯示的付款方式取決於建單參數 ChoosePayment（建議 ALL + IgnorePayment 由後台勾選控制）：信用卡（一次付清 / 分期 CreditInstallment / 定期定額 PeriodAmount）、ATM、WebATM、CVS、BARCODE、ApplePay
- 信用卡 / WebATM / ApplePay：即時完成付款，3DS 由綠界透明處理（消費者在綠界頁面完成，商家無需額外實作）
- ATM / CVS / BARCODE：取號（顯示虛擬帳號 / 超商代碼 / 條碼），顧客臨櫃或轉帳繳費
- 綠界付款頁不可用 iframe 嵌入（會被擋）；ClientBackURL 顯示「返回商店」按鈕
