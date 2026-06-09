# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UNi Embed 後台交易管理（查詢補單 / 請款 / 取消授權）
  作為 網站管理員
  我想要 在後台訂單頁查詢 PAYUNi 交易狀態、請款並取消信用卡授權
  以便 處理 callback 漏接的對帳補單與管理信用卡授權

  # 範本：對齊既有 payuni-trade-management（複用 Payuni/Http/QueryTradeClient /api/trade/query + DoActionClient /api/trade/close、/api/trade/cancel）。
  # 查詢補單：/api/trade/query，TradeStatus=1 + DataSource=A + 訂單尚未處理中則補單。
  # 請款：/api/trade/close CloseType=1，寫入 _pc_payuni_uni_capture_status='captured'。
  # 取消授權：/api/trade/cancel，寫入 _pc_payuni_uni_capture_status='voided'。
  # 具體 Example 資料（訂單、查詢回傳值）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 訂單必須使用 PAYUNi UNi Embed 付款

  規則: 交易查詢可於 callback 漏接時對帳補單（已付款 TradeStatus=1 + DataSource=A 且訂單尚未處理中則補單）

  規則: 信用卡訂單可請款（呼叫 PAYUNi /api/trade/close CloseType=1）

  規則: 信用卡訂單可取消授權（呼叫 PAYUNi /api/trade/cancel，僅信用卡未請款適用）

  規則: 後置（狀態）- 請款成功寫入 _pc_payuni_uni_capture_status='captured'

  規則: 後置（狀態）- 取消授權成功寫入 _pc_payuni_uni_capture_status='voided'

  規則: 後置（狀態）- 查詢 / 請款 / 取消授權結果記錄 order note

  # --- 具體範例（payuni-uni-embed-v3 skill + PAYUNi sandbox）---
  # 複用 Payuni/Http/QueryTradeClient（/api/trade/query）+ DoActionClient（/api/trade/close、/api/trade/cancel）。

  場景: 查詢補單（callback 漏接時對帳）
    假設 訂單 #113 使用 PAYUNi UNi Embed 付款且目前狀態為「等待付款」（NotifyURL 漏接）
    當 管理員於後台訂單頁點「查詢補單」呼叫 /api/trade/query
    那麼 PAYUNi 回應 TradeStatus="1" 且 DataSource="A"
    並且 訂單尚未處理中，後端據以補單轉「處理中」
    並且 記錄 order note

  場景: 信用卡請款成功
    假設 訂單 #114 使用 PAYUNi UNi Embed 付款且已授權未請款
    當 管理員於後台點「請款」呼叫 /api/trade/close（CloseType=1）
    那麼 請款成功
    並且 寫入 _pc_payuni_uni_capture_status 為 "captured"
    並且 記錄 order note

  場景: 信用卡取消授權成功
    假設 訂單 #115 使用 PAYUNi UNi Embed 付款且已授權未請款
    當 管理員於後台點「取消授權」呼叫 /api/trade/cancel
    那麼 取消授權成功
    並且 寫入 _pc_payuni_uni_capture_status 為 "voided"
    並且 記錄 order note
