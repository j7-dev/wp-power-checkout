# PAYUNi UPP 付款頁面（UNiPaypage）

PAYUNi 託管的整合支付頁（UNiPaypage Version 2.0）。顧客下單後由 WooCommerce 在 order-received 頁以 auto-form 自動 submit 導轉至此。

## 描述

非本系統渲染的頁面（PAYUNi 託管）。顧客在此選擇付款方式並完成付款或取號。

## 關鍵屬性

- 顯示的付款方式取決於建單參數（後台 allowed_payments 控制）：信用卡（一次付清 / 分期 InstFlag）、ATM 虛擬帳號、CVS 超商代碼、行動支付（LINE Pay / 街口 / Apple Pay / Google Pay）
- 信用卡 / 行動支付：即時完成付款，3DS 由 PAYUNi 處理（消費者在 PAYUNi 頁面完成）
- ATM / CVS：取號（顯示虛擬帳號 / 超商代碼 / 繳費期限），顧客臨櫃或轉帳繳費
- 導轉式（非 iframe 內嵌）；付款完成後 PAYUNi 以 NotifyURL 幕後通知、ReturnURL 前景導回商家結果頁

註：UNiPaypage 頁面具體欄位與 UI 細節屬 PAYUNi 託管範圍，本系統不渲染，無需建模。
