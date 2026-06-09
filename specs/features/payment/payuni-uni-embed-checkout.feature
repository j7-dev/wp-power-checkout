# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UNi Embed 結帳取 SDK_TOKEN（V3 內嵌式）
  作為 網站訪客
  我想要 在商家頁面內嵌完成 PAYUNi 信用卡付款
  以便 不跳轉即完成結帳

  # 範本：對齊既有內嵌式 ecpay-ecpg-checkout（before_process_payment 取 token → 回 order-received → 前端 SDK 收卡）。
  # ⚠️ V3 關鍵差異：token_get 階段「不送訂單資料」（僅 MerID + Timestamp + IFrameDomain）；MerTradeNo / TradeAmt 在後續 merchant_trade（create-payment）才送。
  # token_get 端點為 /api/iframe/token_get（請求 Version 3.0 / 回傳 Version 3.0）；EncryptInfo（AES-256-GCM）+ HashInfo（SHA256）組裝細節依 payuni-uni-embed-v3 skill 確定（加密複用 Payuni/Shared/Helpers/PayuniCrypto）。
  # 憑證為 GAP：使用者未提供正式 MerID/HashKey/HashIV，實作用 PAYUNi sandbox 測試帳號，prod 後補，存 woocommerce_payuni_uni_embed_settings 不寫死。
  # 具體 Example 資料（IFrameDomain、SDK_TOKEN 值）待 sandbox 驗證階段補充（Phase 03 BDD Analysis）。

  規則: 前置（狀態）- 訂單必須存在

  規則: 前置（狀態）- Gateway 必須啟用且訂單金額在允許範圍內（信用卡 1～199,999 元）

  規則: 前置（參數）- token_get 請求須以 AES-256-GCM 加密為 EncryptInfo，並以 SHA256 計算 HashInfo（Version 3.0）

  規則: 前置（參數）- token_get 內層僅送 MerID + Timestamp + IFrameDomain，不送 MerTradeNo / TradeAmt（V3 特性）

  規則: 前置（參數）- IFrameDomain 必須含 https:// 且符合格式（中文 / 英數字 / -，不可開頭結尾為 -）

  規則: 成功時取得 SDK_TOKEN 並回傳 order-received URL 供前端 SDK 收卡

  規則: 後置（狀態）- 取得的 SDK_TOKEN 寫入 order meta _pc_payuni_uni_sdk_token（10 分鐘有效，供前端 SDK 與後續 merchant_trade 共用）

  規則: 後置（狀態）- token_get 失敗（外層 Status=ERROR 或限定 IP 未設定 TOKEN03005/03006）時記錄 order note，不轉訂單狀態

  # --- 具體範例（payuni-uni-embed-v3 skill + PAYUNi sandbox）---
  # token_get 端點 /api/iframe/token_get（請求/回傳 Version 固定 3.0）；內層僅 MerID + Timestamp + IFrameDomain（V3 不送訂單）。

  場景: 成功取得 SDK_TOKEN 並回傳 order-received URL
    假設 PAYUNi UNi Embed gateway 已啟用且使用 sandbox 測試帳號
    並且 存在一筆付款方式為 payuni_uni_embed 的訂單 #100，金額 1000 元
    當 顧客送出結帳，後端以 IFrameDomain "https://shop.example.com" 呼叫 token_get
    那麼 PAYUNi 回應外層 Status 為 "SUCCESS"
    並且 解密 EncryptInfo 後取得 Token（SDK_TOKEN，TokenExpired 10 分鐘）
    並且 SDK_TOKEN 寫入訂單 meta _pc_payuni_uni_sdk_token
    並且 後端回傳 order-received URL 供前端 uniPayment SDK 收卡

  場景: 金額超出信用卡允許範圍時拒絕取號
    假設 PAYUNi UNi Embed gateway 已啟用
    並且 存在一筆付款方式為 payuni_uni_embed 的訂單 #101，金額 200000 元
    當 顧客送出結帳
    那麼 後端拒絕並不呼叫 token_get
    並且 提示金額須介於 1～199,999 元

  場景: IFrameDomain 格式不合法時拒絕取號
    假設 PAYUNi UNi Embed gateway 已啟用
    並且 存在一筆付款方式為 payuni_uni_embed 的訂單 #102
    當 後端以不含 https:// 的 IFrameDomain "shop.example.com" 組裝 token_get
    那麼 後端拒絕送出（IFrameDomain 必須含 https:// 且符合格式）

  場景: 限定 IP 未設定導致 token_get 失敗
    假設 PAYUNi UNi Embed gateway 已啟用但後端來源 IP 未在 PAYUNi 後台白名單
    並且 存在一筆付款方式為 payuni_uni_embed 的訂單 #103
    當 後端呼叫 token_get
    那麼 PAYUNi 回應外層 Status 為 "ERROR"（錯誤碼 TOKEN03005 或 TOKEN03006）
    並且 訂單記錄 order note 說明取號失敗
    並且 訂單狀態維持「等待付款」不轉狀態
