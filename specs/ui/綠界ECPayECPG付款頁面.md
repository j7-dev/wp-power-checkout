# 綠界ECPay ECPG 站內付付款頁面

綠界站內付 2.0 內嵌付款元件，由綠界 JS SDK 在商家頁面渲染（不跳轉、不用 iframe）。

## 描述

本系統頁面內嵌綠界站內付元件。顧客在此輸入卡片資訊，由綠界 SDK 收集並回傳綁定結果。

## 關鍵屬性

- JS SDK 三依賴須按順序載入：jQuery → node-forge → `/Scripts/sdk-1.0.0.js`（大寫 S）
- 付款元件容器必須使用固定 `<div id="ECPayPayment">`（SDK 硬編碼此 id，自訂 id 不會渲染）
- SDK 初始化參數為字串 `'Stage'`（測試）/ `'Prod'`（正式），非整數
- CreatePayment 回應若含 `data.ThreeDInfo.ThreeDURL`（巢狀，非扁平）非空，前端必須導向 3D 驗證頁完成驗證
- ATM / CVS / BARCODE：CreatePayment 回應 Data 含繳費指示（虛擬帳號 / 超商代碼），需顯示給顧客；付款結果由 ReturnURL 非同步通知
