# PAYUNi UNi Embed V3 內嵌付款元件

PAYUNi 免跳轉支付元件，由 PAYUNi JS SDK（`uni-payment.js`，Ver 2.0）在 order-received 頁以 iframe 內嵌信用卡輸入欄位，消費者不離開商店網站完成付款。

## 描述

本系統 order-received 頁內嵌 PAYUNi UNi Embed 元件。顧客在此輸入卡片資訊，由 PAYUNi SDK 以 iframe 收集（卡號 / CVC 不經過商店前端 DOM），SDK 取得綁定 TOKEN 結果後回傳，後端再以原 SDK_TOKEN 呼叫 merchant_trade 完成幕後授權。掛載時機比照既有綠界 ECPG（`MountEcpgPayment()`，order-received 頁），classic / block checkout 共用。

## 關鍵屬性

- SDK 來源固定 `https://vendor.payuni.com.tw/sdk/uni-payment.js`，**禁止下載至商店主機託管**（PAYUNi 安全規範）；測試 / 正式由 `createSession` 的 `env: "S"｜"P"` 切換，非換 SDK URL
- iframe 容器必須使用固定 id：`put_card_no`（卡號）、`put_card_exp`（有效期限）、`put_card_cvc`（CVC）；約定 / 記憶卡號時另需 `put_token_type`（checkbox 容器）
- CSP 必須允許 `https://vendor.payuni.com.tw`（script-src + frame-src）；測試環境另允許 `https://sandbox-vendor.payuni.com.tw`（frame-src）
- SDK 流程：`createSession(SDK_TOKEN, options)` → `start()`（驗證 origin / token，顯示輸入框）→ `onUpdate(cb)`（欄位驗證狀態 true/null/false/typing）→ `getTradeResult(config)`（V3：僅取得卡號綁定 TOKEN 結果，不執行授權）
- 來源驗證：iframe 自動比對 `window.location.origin` 與 token_get 階段傳入的 `IFrameDomain`，不一致拋 `Code 1007` 元件不載入；取不到 origin（Safari 私密瀏覽）不中斷，僅顯示警語
- 分期：須先 `createSession` 設 `useInst: true` + `getCardAcceptInfo()` 取得可用期數，再 `getTradeResult({ cardInst: N })`
- 記憶卡號 / 約定卡：勾選 checkbox 後 `getTradeResult({ useDefault: true })` 使用記憶卡號交易
- 3D 驗證：後端 merchant_trade 回應含導頁 URL（或 API3D=1 強制 3D 的 URL）時，前端導向銀行 3D 驗證頁；銀行驗證後 PAYUNi Form POST 至 NotifyURL（交易結果以 NotifyURL 為準）
- SDK_TOKEN 10 分鐘有效（逾期 `IFTRADE04001`）；同一個 SDK_TOKEN 走完 token_get → getTradeResult → merchant_trade 全程
- ⚠️ 僅信用卡（一次付清 / 分期 / 約定 / 記憶卡號 / 強制約定）；ATM/CVS/LINE Pay 等請改用 UPP
