# PayNow Component SDK v2 內嵌付款元件

PayNow 免跳轉內嵌付款元件，由 PayNow Component SDK v2（`https://js.paynow.com.tw/sdk/v2/index.js`）在 order-received 頁以 iframe 內嵌付款欄位，消費者不離開商店網站完成付款。

## 描述

本系統 order-received 頁內嵌 PayNow Component SDK v2 元件。顧客在此完成付款，由 PayNow SDK 以 iframe 收集卡片 / 付款資訊（卡號 / CVC 不經過商店前端 DOM），SDK `checkout()` 直接與 PayNow 完成授權（含信用卡 3DS / 超商代碼取號 / ATM 待繳 / LINE Pay / Apple Pay）。掛載時機比照既有 PAYUNi UNi Embed（`MountPaynowPayment()`，order-received 頁），classic / block checkout 共用。

> ⚠️ 與 PAYUNi UNi Embed 的關鍵差異：PayNow SDK `checkout()` **直接完成授權 + 3DS**，前端**不需**把綁定結果 POST 回後端再呼叫 merchant_trade（PayNow 體系1 無此中間步驟）。付款結果以後端 Webhook（payment_result）為準。

## 關鍵屬性

- SDK 來源固定 `https://js.paynow.com.tw/sdk/v2/index.js`；測試 / 正式由 `createPayment` 的 `env: 'sandbox' | 'production'` 切換，非換 SDK URL
- 掛載點容器 `<div id="paynow-container">`；`secret` 來自後端建立的 PaymentIntent（`result.secret`，格式 `pp_xxx_st_xxx`），`publicKey` 為商家公鑰
- CSP 必須允許 `https://js.paynow.com.tw`（script-src + frame-src）
- SDK 流程：`PayNow.createPayment({publicKey, secret, env})` → `PayNow.mount('#paynow-container', options)`（options 可帶 appearance / locale，預設 'en'，可 `updateLocale('zh_tw')`）→ 顧客填付款資訊 → `PayNow.checkout()`（回 Promise，`response.error` 為錯誤）
- SDK 事件：`on('mounted')` UI 掛載完成、`on('update', data)` 欄位內容變更（含 cardType / isComplete / status FieldErrorStatus）、`on('paymentMethodSelected', method)` 付款方式變更（CreditCard / ATM / CreditCardInstallment）
- 付款成功與否**以後端 Webhook / `GET /payment-intents/:id` 為準**（SDK checkout 成功只代表前端流程完成）
- 信用卡 3DS：由 SDK iframe 內部處理（pending_review → success），前端不需自行串 fingerprint-session / OTP confirm（那是後端收單 checkout 流程才需要，本元件走 SDK iframe）
- 離線付款（ATM / 超商代碼）：SDK 顯示繳款資訊（vAccount / 繳費代碼 / 條碼 / ExpireDate），消費者離站繳費後 PayNow 推 Webhook
- ⚠️ 具體 SDK 掛載 DOM id / options 細節以 PayNow Component SDK v2 文件（`docs.paynow.com.tw/component/`）為準；本專案 skill 未涵蓋 Component 外站細節，實作階段需以 PayNow sandbox 驗證（GAP）
</content>
