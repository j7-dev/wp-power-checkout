# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流退款
  作為 網站管理員
  我想要 對 PAYUNi 訂單發起退款
  以便 退還顧客款項

  # 範本：對齊既有 ecpay-refund / newebpay MPG 退款（信用卡 API 退款；非信用卡標人工）
  # /api/trade/close 退款參數、非信用卡（ATM/CVS/行動支付）API 退款能力於實作階段依 payuni-upp-v2 skill 確定（部分行動支付可能支援，多數須後台人工）。
  # 具體 Example 資料（訂單、金額、payment_type）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 訂單必須使用 PAYUNi 付款（非本 gateway 不處理該退款）

  規則: 信用卡訂單支援 API 退款（呼叫 PAYUNi /api/trade/close）

  規則: 不支援 API 退款的付款方式須 PAYUNi 後台人工，提示 "此付款方式不支援 API 退款，請至 PAYUNi 商家後台人工處理"

  規則: 後置（狀態）- 退款成功記錄 order note（金額與 TradeNo）

  # ⚠ Example 區段：使用者未提供具體案例，暫不生成。Phase 03 以 payuni-upp-v2 skill + sandbox 驗證補充。
