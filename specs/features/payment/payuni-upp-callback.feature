# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UPP 付款結果通知（NotifyURL）
  作為 PAYUNi 第三方系統
  我想要 在顧客完成付款後幕後通知商家付款結果
  以便 WooCommerce 更新訂單狀態

  # 範本：對齊既有 ecpay-aio-callback / newebpay MPG callback（幕後 Form POST + 加密驗證 + 冪等 + 回應成功）
  # HashInfo 驗證欄位、PAYUNi 成功狀態碼、成功回應格式於實作階段依 payuni-upp-v2 skill 確定。
  # 具體 Example 資料（NotifyURL payload）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（參數）- NotifyURL 通知必須通過 HashInfo（SHA256）timing-safe 驗證

  規則: 前置（狀態）- 通知必須冪等處理（以 MerTradeNo 為 key，PAYUNi 會重送）

  規則: 付款成功時訂單轉「處理中」

  規則: 付款失敗時訂單維持「等待付款」並記錄 order note

  規則: 後置（回應）- 商家須回應 PAYUNi 成功以避免重送

  規則: 後置（狀態）- 寫入付款明細至 order meta _pc_payuni_payment_detail

  # ⚠ Example 區段：使用者未提供具體案例，暫不生成。Phase 03 以 payuni-upp-v2 skill + sandbox 驗證補充。
