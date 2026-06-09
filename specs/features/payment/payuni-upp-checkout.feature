# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UPP 結帳付款（導轉式）
  作為 網站訪客
  我想要 使用 PAYUNi 統一金流進行線上付款
  以便 完成訂單結帳

  # 範本：對齊既有導轉式金流 ecpay-aio-checkout / newebpay_mpg（auto-form POST 至第三方託管頁）
  # 加密與參數組裝細節（EncryptInfo AES-256-GCM / HashInfo SHA256 的欄位組成與排序、allowed_payments 對應 PAYUNi 參數名）於實作階段依 payuni-upp-v2 skill 確定。
  # 憑證為 GAP（Q6）：使用者未提供正式 MerID/HashKey/HashIV，實作用 PAYUNi sandbox 測試帳號，prod 後補，存 woocommerce_payuni_upp_settings 不寫死。
  # 具體 Example 資料待 sandbox 驗證階段補充（Phase 03 BDD Analysis）。

  規則: 前置（狀態）- 訂單必須存在

  規則: 前置（狀態）- Gateway 必須啟用且訂單金額在允許範圍內（min_amount / max_amount）

  規則: 前置（參數）- 建單參數須以 AES-256-GCM 加密為 EncryptInfo，並以 SHA256 計算 HashInfo

  規則: 前置（參數）- 付款方式必須為後台 allowed_payments 允許值（信用卡一次/分期、ATM、CVS、LINE Pay、街口、Apple Pay、Google Pay）

  規則: 成功時於 order-received 頁 auto-form 自動 submit 導向 PAYUNi UNiPaypage（/api/upp）

  規則: 後置（狀態）- 建單時寫入冪等鍵 MerTradeNo 至 order meta _pc_payuni_trade_no

  # ⚠ Example 區段：使用者未提供具體案例（含 sandbox 憑證與參數值），暫不生成。Phase 03 以 payuni-upp-v2 skill + sandbox 驗證補充。
