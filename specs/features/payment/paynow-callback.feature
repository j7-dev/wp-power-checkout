# language: zh-TW
@ignore @command
功能: PayNow 立吉富付款結果通知（Webhook payment_result）
  作為 PayNow 第三方系統
  我想要 在顧客完成付款後幕後通知商家付款結果
  以便 WooCommerce 更新訂單狀態

  # 範本：對齊既有 payuni-uni-embed-callback / ecpay-ecpg-callback（幕後 POST + 驗簽 + 反查 + 冪等 + always HTTP 200）。
  # Webhook 端點為 POST /wp-json/power-checkout/paynow/notify（permission __return_true，驗證在 callback 內；always HTTP 200）。
  # 驗證鏈：X-Payment-Center-Hmac-Sha256（HMAC-SHA256, key=PrivateKey, 對 raw payload, timing-safe）→ 以 PaymentIntentId 反查訂單 → 比對 Amount 防竄改 → 冪等檢查（以 PaymentIntentId 為 key）。
  # ⚠️ 體系1 無對稱加密——驗簽用 HMAC-SHA256（與 PAYUNi AES-256-GCM / ezPay AES-256-CBC / ECPay AES-128-CBC 不同源，不可套用）。
  # Webhook payload 含 Status(Success/Failed) / OrderNo / PaymentNo / PaymentIntentId / TransactionNo / Amount / PaymentType / Meta。
  # 具體 Example（Webhook payload 實際值、HMAC 簽章）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（參數）- Webhook 必須通過 X-Payment-Center-Hmac-Sha256（HMAC-SHA256, key=PrivateKey）timing-safe 驗證

  規則: 前置（參數）- HMAC 須對原始 raw payload 計算（勿先 json_decode 再 re-encode）

  規則: 前置（狀態）- 必須以 PaymentIntentId（_pc_paynow_payment_intent_id）反查訂單，找不到則拒絕

  規則: 前置（參數）- 必須比對 Webhook Amount 與本地訂單金額（防止竄改），不符則拒絕

  規則: 前置（狀態）- 通知必須冪等處理（以 PaymentIntentId 為 key，已 processing 則 skip，PayNow 重送防護）

  規則: 後置（狀態）- Status=Success 時訂單轉「處理中」並寫入 _pc_paynow_payment_detail（TransactionNo / PaymentType / Meta）

  規則: 後置（狀態）- Status=Failed 時訂單維持「等待付款」並記錄 order note

  規則: 後置（回應）- 所有路徑（含驗簽 / 解析失敗與 \Throwable）一律回應 HTTP 200

  # --- 具體範例（paynow skill payment-rest-api §10 + PayNow sandbox）---
  # 端點 POST /wp-json/power-checkout/paynow/notify（permission __return_true，always HTTP 200）。
  # Header: X-Payment-Center-Topic: payment_result、X-Payment-Center-Hmac-Sha256: <hex 大寫>。
  # ⚠️ sandbox 憑證未到位（Q5 GAP）——以下 Example 以 mock payload + 自算 HMAC 驗證後端邏輯。

  場景: 付款成功（Status=Success）轉處理中並寫入付款明細
    假設 訂單 #100 付款方式為 paynow，本地金額 1000 元，_pc_paynow_payment_intent_id 為 "pp_test100"
    並且 訂單目前狀態為「等待付款」
    當 PayNow 以正確 X-Payment-Center-Hmac-Sha256 POST Webhook，payload 含 PaymentIntentId="pp_test100"、Status="Success"、Amount=1000
    那麼 通過 HMAC-SHA256 timing-safe 驗證
    並且 以 PaymentIntentId 反查到訂單 #100 且 Amount 與本地金額相符
    並且 訂單轉「處理中」並寫入 _pc_paynow_payment_detail
    並且 回應 HTTP 200

  場景: 付款失敗（Status=Failed）維持等待付款並記錄 order note
    假設 訂單 #105 付款方式為 paynow，_pc_paynow_payment_intent_id 為 "pp_test105"
    當 PayNow 以正確 HMAC POST Webhook，payload 含 PaymentIntentId="pp_test105"、Status="Failed"
    那麼 訂單維持「等待付款」
    並且 記錄 order note 說明付款失敗
    並且 回應 HTTP 200

  場景: HMAC 驗證失敗拒絕更新訂單但仍回 HTTP 200
    假設 訂單 #100 付款方式為 paynow
    當 PayNow 以錯誤 X-Payment-Center-Hmac-Sha256 POST Webhook
    那麼 timing-safe 驗證失敗，不更新訂單
    並且 回應 HTTP 200

  場景: 金額與本地不符（竄改）拒絕更新訂單
    假設 訂單 #100 付款方式為 paynow，本地金額 1000 元，_pc_paynow_payment_intent_id 為 "pp_test100"
    當 PayNow 以正確 HMAC POST Webhook，payload Status="Success" 但 Amount=1
    那麼 比對 Amount 不符本地金額，拒絕更新訂單
    並且 回應 HTTP 200

  場景: 重複通知冪等處理（已 processing 則 skip）
    假設 訂單 #100 付款方式為 paynow 且已是「處理中」，_pc_paynow_payment_intent_id 為 "pp_test100"
    當 PayNow 再次以正確 HMAC POST Webhook，payload Status="Success"
    那麼 以 PaymentIntentId 為 key 判定已處理並 skip，不重複更新
    並且 回應 HTTP 200
</content>
