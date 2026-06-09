# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UNi Embed 幕後授權（merchant_trade）
  作為 網站訪客
  我想要 在輸入卡片並取得綁定結果後完成幕後授權
  以便 不跳轉即完成付款（含 3D 驗證）

  # 範本：對齊既有 ecpay-ecpg-checkout 的 CreatePayment 段 + EcpgFrontendApi（前端 order_key 驗證 → 後端授權 → 3DS 分流）。
  # 前端把綁定結果 POST 回後端 create-payment 端點（order_key 驗證，非 nonce；訪客結帳 session 不穩，比照 ECPG）。
  # 後端以原 SDK_TOKEN 呼叫 /api/iframe/merchant_trade（請求 Version 1.0 / 回傳 Version 1.2）；此階段才送 MerTradeNo + TradeAmt + ProdDesc。
  # 回應 Gateway 固定 9（IFrame，與 UPP 的 2 不同）；TradeStatus 1/2/3/8、PaymentType 固定 1（信用卡）；分期 AuthType=2、銀聯 AuthType=7。
  # 具體 Example 資料（測試卡號 4147631000000001、SDK_TOKEN、3D URL）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 訂單必須存在且付款方式為 payuni_uni_embed

  規則: 前置（參數）- 前端請求須通過 order_key 驗證（hash_equals 比對 $order->get_order_key()，不符回 403）

  規則: 前置（狀態）- 訂單須已有 _pc_payuni_uni_sdk_token（未走過 token_get 則流程異常拒絕）

  規則: 前置（參數）- merchant_trade 須以原 SDK_TOKEN 授權，並送 MerTradeNo（≤25 字元，10 分鐘內不可重複）+ TradeAmt + ProdDesc

  規則: 成功時 merchant_trade 回應以 AES-256-GCM 解密、HashInfo 驗證後判定授權結果

  規則: 回應為 3D 交易（含導頁 URL 或 API3D=1 強制 3D）時前端導向銀行 3D 驗證頁

  規則: 回應為非 3D 直接授權（Status=SUCCESS）時不導頁，等待 NotifyURL 幕後確認

  規則: 後置（狀態）- 建單時寫入冪等鍵 MerTradeNo 至 order meta _pc_payuni_uni_trade_no

  規則: 後置（回應）- 例外（傳輸層 / 業務層失敗）一律 catch，回前端通用錯誤訊息不外洩內部細節（細節寫 order note / log）

  # --- 具體範例（payuni-uni-embed-v3 skill + PAYUNi sandbox 測試卡）---
  # merchant_trade 端點 /api/iframe/merchant_trade（請求 Version 1.0 / 回傳 1.2）；回應 Gateway 固定 9、PaymentType 固定 1。
  # sandbox 測試卡：4147631000000001=一次付清成功；4147631000000002=模擬 3D 取消（ECI 不符）；3560562000000001=分期成功。

  場景: 非 3D 直接授權成功不導頁，等待 NotifyURL 確認
    假設 訂單 #100 付款方式為 payuni_uni_embed 且已有 _pc_payuni_uni_sdk_token
    並且 顧客以測試卡 "4147631000000001" 於前端 SDK 完成綁卡
    當 前端以正確 order_key POST 至 create-payment 觸發 merchant_trade（TradeAmt 1000、MerTradeNo "PCE100"）
    那麼 PAYUNi 回應外層 Status 為 "SUCCESS"
    並且 解密後 Gateway 為 "9"、PaymentType 為 "1"
    並且 寫入冪等鍵 MerTradeNo "PCE100" 至 _pc_payuni_uni_trade_no
    並且 回前端 need_3ds 為 false，前端不導頁等待 NotifyURL 幕後確認

  場景: 強制 3D（API3D=1）回應導頁 URL，前端導向銀行 3D 驗證
    假設 訂單 #104 付款方式為 payuni_uni_embed 且已有 _pc_payuni_uni_sdk_token
    並且 顧客以測試卡 "4147631000000001" 完成綁卡
    當 前端以正確 order_key POST 至 create-payment，後端以 API3D=1 呼叫 merchant_trade
    那麼 PAYUNi 回應 Status 為 "SUCCESS"、Message 為 "建立幕後3D成功" 並帶 URL
    並且 回前端 need_3ds 為 true 與 three_d_url
    並且 前端導向 three_d_url 進行銀行 3D 驗證

  場景: order_key 不符回 403 不送出授權
    假設 訂單 #100 付款方式為 payuni_uni_embed 且已有 _pc_payuni_uni_sdk_token
    當 前端以錯誤的 order_key POST 至 create-payment
    那麼 回應 HTTP 403「訂單驗證失敗」
    並且 不呼叫 merchant_trade

  場景: 訂單未走過 token_get（缺 SDK_TOKEN）時拒絕授權
    假設 訂單 #105 付款方式為 payuni_uni_embed 但無 _pc_payuni_uni_sdk_token
    當 前端以正確 order_key POST 至 create-payment
    那麼 後端判定流程異常並拒絕，不呼叫 merchant_trade

  場景: 授權傳輸層 / 業務層失敗回通用錯誤不外洩細節
    假設 訂單 #106 付款方式為 payuni_uni_embed 且已有 _pc_payuni_uni_sdk_token
    並且 顧客以模擬授權失敗的測試卡 "4147631000000002" 完成綁卡
    當 前端以正確 order_key POST 至 create-payment 觸發 merchant_trade
    那麼 後端 catch 例外，回前端通用錯誤訊息「授權失敗，請稍後再試或聯繫商家」
    並且 失敗細節寫入 order note / log，不外洩內部細節至前端
