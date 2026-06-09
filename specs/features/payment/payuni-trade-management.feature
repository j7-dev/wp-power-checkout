# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流後台交易管理（查詢補單 / 取消授權）
  作為 網站管理員
  我想要 在後台訂單頁查詢 PAYUNi 交易狀態並取消信用卡授權
  以便 處理 callback 漏接的對帳補單與取消未請款的授權

  # 範本：對齊既有 NewebpayMpg 後台訂單操作（QueryTradeInfo 查詢補單 + Cancel 取消授權）
  # /api/trade/query 已付款判定、/api/trade/cancel 取消授權參數與適用條件於實作階段依 payuni-upp-v2 skill 確定。
  # 具體 Example 資料（訂單、查詢回傳值）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 訂單必須使用 PAYUNi 付款

  規則: 交易查詢可於 callback 漏接時對帳補單（已付款且訂單尚未處理中則補單）

  規則: 信用卡訂單可取消授權（呼叫 PAYUNi /api/trade/cancel，僅信用卡未請款適用）

  規則: 非信用卡訂單不可取消授權

  規則: 後置（狀態）- 查詢 / 取消授權結果記錄 order note

  # ⚠ Example 區段：使用者未提供具體案例，暫不生成。Phase 03 以 payuni-upp-v2 skill + sandbox 驗證補充。
