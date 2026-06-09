# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UNi Embed 退款
  作為 網站管理員
  我想要 對 PAYUNi UNi Embed 訂單發起退款
  以便 退還顧客款項

  # 範本：對齊既有 payuni-refund / ecpay-refund（信用卡 API 退款；複用 Payuni/Http/DoActionClient /api/trade/close）。
  # UNi Embed 僅信用卡（PaymentType 固定 1），退款走 /api/trade/close CloseType=2；wpdb TRANSACTION + 失敗 ROLLBACK + 刪 refund（比照 ECPG）。
  # 具體 Example 資料（訂單、金額、TradeNo）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 訂單必須使用 PAYUNi UNi Embed 付款（非本 gateway 不處理該退款）

  規則: 前置（參數）- 退款金額一律來自 WC refund 物件，非前端傳入

  規則: 信用卡訂單支援 API 退款（呼叫 PAYUNi /api/trade/close，CloseType=2）

  規則: 後置（狀態）- 退款成功記錄 order note（金額與 TradeNo）

  規則: 後置（狀態）- 退款失敗時 ROLLBACK、記錄 order note、刪除該筆 refund

  # --- 具體範例（payuni-uni-embed-v3 skill + PAYUNi sandbox）---
  # UNi Embed 僅信用卡（PaymentType=1），退款走 /api/trade/close CloseType=2（複用 Payuni/Http/DoActionClient）。

  場景: 信用卡全額退款成功
    假設 訂單 #100 使用 PAYUNi UNi Embed 付款，金額 1000 元且已付款（_pc_payuni_uni_payment_detail TradeNo "UNI20260609001"）
    當 管理員對訂單 #100 發起全額退款（WC refund 金額 1000 元）
    那麼 後端呼叫 PAYUNi /api/trade/close（CloseType=2、TradeNo "UNI20260609001"）
    並且 退款成功並記錄 order note（金額 1000 與 TradeNo）

  場景: 信用卡部分退款成功
    假設 訂單 #110 使用 PAYUNi UNi Embed 付款，金額 1000 元且已付款
    當 管理員對訂單 #110 發起部分退款（WC refund 金額 300 元）
    那麼 後端以 WC refund 物件金額 300 元（非前端傳入）呼叫 /api/trade/close CloseType=2
    並且 退款成功並記錄 order note

  場景: 退款失敗時 ROLLBACK 並刪除該筆 refund
    假設 訂單 #111 使用 PAYUNi UNi Embed 付款且已付款
    當 管理員發起退款但 PAYUNi /api/trade/close 回應失敗
    那麼 wpdb 交易 ROLLBACK
    並且 刪除該筆 WC refund
    並且 記錄 order note 說明退款失敗

  場景: 非本 gateway 訂單不由本 gateway 處理退款
    假設 訂單 #112 使用其他付款方式（非 payuni_uni_embed）
    當 對訂單 #112 發起退款
    那麼 PAYUNi UNi Embed gateway 不處理該退款
