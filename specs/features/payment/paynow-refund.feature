# language: zh-TW
@ignore @command
功能: PayNow 立吉富退款（REST 退款開立）
  作為 管理員
  我想要 對 PayNow 訂單發起退款
  以便 退還顧客款項

  # 範本：對齊既有 payuni-uni-embed-refund / ecpay-refund（後台訂單頁觸發退款 → API 退款 → 依付款方式分流）。
  # 退款端點 POST /api/v1/payment-intents/:id/refunds（Bearer PrivateKey），帶 amount + reason；ATM 退款另需 bankCode + bankBranchCode + bankAccount。
  # 退款狀態類型：success / failed / rejected（原因在 RejectReason）/ processing / validation_error；HTTP 200/400/422。
  # 退款路由：信用卡 / ATM 走 REST refunds；超商代碼 / LINE Pay / Apple Pay 是否支援 API 退款依 PayNow 規格（BDY），不支援者 WP_Error('refund_unsupported') 人工退款。
  # 沿用既有 power-checkout/v1 /refund（gateway process_refund 分流）；退款金額防呆走 WC 既有 remaining_refund_amount。
  # 具體 Example（退款 uuid / RejectReason 實際值）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 退款訂單必須付款方式為 paynow 且已付款（有 _pc_paynow_payment_intent_id）

  規則: 前置（參數）- 退款金額不得超過訂單可退款餘額

  規則: 前置（參數）- 退款須帶 reason（<= 255 字），透過 Bearer PrivateKey 呼叫 REST refunds

  規則: 前置（參數）- ATM 退款必填 bankCode / bankBranchCode / bankAccount；信用卡退款不需要

  規則: 前置（狀態）- 不支援 API 退款的付款方式回傳 WP_Error('refund_unsupported')（信用卡 / ATM 走 REST refunds；其餘付款方式 API 退款支援度依 PayNow 規格於實作階段以 paynow skill 確認，不支援者一律走人工退款，見 Execution Plan GAP 登記）

  規則: 後置（狀態）- 退款成功（status=success）時寫入 _pc_paynow_refund_detail 並記錄 order note

  規則: 後置（狀態）- 退款被拒絕（rejected）/ 失敗（failed）時記錄 order note（含 RejectReason），不標記為已退款

  規則: 後置（狀態）- 退款處理中（processing）時記錄 order note，待後續退款查詢確認

  # --- 具體範例（paynow skill payment-rest-api §5 + PayNow sandbox）---
  # 端點 POST /api/v1/payment-intents/:id/refunds；回 result.type（success/failed/rejected/processing）。
  # ⚠️ sandbox 憑證未到位（Q5 GAP）——以下 Example 以 mock 回應驗證 process_refund 分流與寫 meta 邏輯。

  場景: 信用卡訂單全額退款成功
    假設 訂單 #100 付款方式為 paynow（信用卡），_pc_paynow_payment_intent_id 為 "pp_test100"，已付款 1000 元
    當 管理員於後台發起全額退款，reason 為 "顧客取消訂單"
    那麼 後端以 Bearer PrivateKey 呼叫 POST /api/v1/payment-intents/pp_test100/refunds
    並且 PayNow 回 status="success"
    並且 寫入 _pc_paynow_refund_detail 並記錄 order note

  場景: ATM 訂單退款必填銀行帳號
    假設 訂單 #110 付款方式為 paynow（ATM），_pc_paynow_payment_intent_id 為 "pp_test110"，已付款 1500 元
    當 管理員發起退款但未填 bankCode / bankBranchCode / bankAccount
    那麼 後端拒絕送出，提示 ATM 退款必填銀行代碼 / 分行代碼 / 帳號

  場景: 超商代碼訂單不支援 API 退款
    假設 訂單 #111 付款方式為 paynow（超商代碼），已付款 800 元
    當 管理員嘗試發起 API 退款
    那麼 後端回傳 WP_Error("refund_unsupported")
    並且 提示須於 PayNow 後台人工退款

  場景: 退款被拒絕時記錄原因不標記已退款
    假設 訂單 #100 付款方式為 paynow（信用卡），_pc_paynow_payment_intent_id 為 "pp_test100"
    當 管理員發起退款但 PayNow 回 status="rejected"（含 RejectReason）
    那麼 記錄 order note 說明退款被拒絕與 RejectReason
    並且 訂單不標記為已退款
</content>
