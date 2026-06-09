# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UPP 取號通知（ATM / CVS）
  作為 PAYUNi 第三方系統
  我想要 在顧客選擇 ATM 或超商代碼後通知取號資訊
  以便 顧客臨櫃或轉帳繳費、商家顯示繳費資訊

  # 範本：對齊既有 ecpay-aio-payment-info（ATM/CVS/BARCODE 取號通知）
  # 取號通知是否為獨立 endpoint（或併於 NotifyURL）、HashInfo 驗證、成功回應格式於實作階段依 payuni-upp-v2 skill 確定。
  # 具體 Example 資料（虛擬帳號、超商代碼、繳費期限）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（參數）- 取號通知必須通過 HashInfo（SHA256）驗證

  規則: 取號成功時訂單維持「等待付款」並記錄取號資訊

  規則: 後置（狀態）- 寫入取號資訊至 order meta _pc_payuni_payment_info（虛擬帳號 / 超商代碼 / 繳費期限）

  規則: 後置（回應）- 商家須回應 PAYUNi 成功以避免重送

  # ⚠ Example 區段：使用者未提供具體案例，暫不生成。Phase 03 以 payuni-upp-v2 skill + sandbox 驗證補充。
