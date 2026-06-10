# language: zh-TW
@ignore @query
功能: PayNow 立吉富後台交易管理（補查付款意圖 / 退款查詢）
  作為 管理員
  我想要 在後台查詢 PayNow 付款意圖與退款狀態
  以便 對帳補單與確認退款結果

  # 範本：對齊既有 payuni-uni-embed-trade-management（後台訂單頁手動操作 admin order action）。
  # ⚠️ PayNow 體系1 無 capture（請款）/ void_auth（取消授權）端點——本 feature 僅含「查詢」類能力（Q4：補查付款意圖 + 退款查詢）。
  #     capture / void_auth 維持 AbstractPaymentGateway no-op 預設，不在本 feature 範圍。
  # 補查付款意圖 GET /api/v1/payment-intents/:id（status：draft/processing/pending_review/success/canceled）。
  # 退款查詢 GET /api/v1/refunds/:uuid（status：success/failed/rejected/processing）。
  # admin order action 比照既有 payuni_uni_embed pc_payuni_uni_* 慣例（後台訂單頁按鈕）。
  # 具體 Example（payment-intent / refund 實際回應）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 補查訂單必須付款方式為 paynow 且有 _pc_paynow_payment_intent_id

  規則: 後置（回應）- 補查付款意圖回傳付款意圖狀態（draft / processing / pending_review / success / canceled）

  規則: 後置（狀態）- 補查發現 status=success 且本地訂單尚未「處理中」時，依 callback 同等驗證（金額防竄改 + 冪等）後補單轉處理中

  規則: 前置（狀態）- 退款查詢必須有退款 uuid（來自 _pc_paynow_refund_detail）

  規則: 後置（回應）- 退款查詢回傳退款狀態（success / failed / rejected / processing）

  規則: 後置（狀態）- 退款查詢結果寫回 _pc_paynow_refund_detail 並記錄 order note

  # --- 具體範例（paynow skill payment-rest-api §4.2 §5.3 + PayNow sandbox）---
  # ⚠️ sandbox 憑證未到位（Q5 GAP）——以下 Example 以 mock 回應驗證查詢後端邏輯。

  場景: 補查付款意圖確認已付款後補單
    假設 訂單 #100 付款方式為 paynow，_pc_paynow_payment_intent_id 為 "pp_test100"，本地金額 1000 元，狀態為「等待付款」
    並且 PayNow 端該付款意圖已 success（Webhook 漏收）
    當 管理員於後台點擊補查付款意圖
    那麼 後端 GET /api/v1/payment-intents/pp_test100 回 status="success"
    並且 通過金額防竄改與冪等檢查後訂單補單轉「處理中」

  場景: 補查付款意圖狀態尚未成功時不補單
    假設 訂單 #101 付款方式為 paynow，_pc_paynow_payment_intent_id 為 "pp_test101"，狀態為「等待付款」
    當 管理員補查付款意圖，PayNow 回 status="draft"
    那麼 訂單維持「等待付款」不補單
    並且 記錄 order note 說明目前付款意圖狀態

  場景: 退款查詢回傳退款狀態並寫回明細
    假設 訂單 #100 付款方式為 paynow，_pc_paynow_refund_detail 含退款 uuid "rf_test100"
    當 管理員於後台點擊退款查詢
    那麼 後端 GET /api/v1/refunds/rf_test100 回退款狀態
    並且 退款狀態寫回 _pc_paynow_refund_detail 並記錄 order note
</content>
