# language: zh-TW
@ignore @query
功能: PayNow 電子發票查詢
  作為 網站管理員
  我想要 查詢 PayNow 已開立發票明細
  以便 確認發票狀態與財政部上傳結果

  # ISupportsQuery::query_invoice() — 選配能力介面（唯讀；PayNow 支援，與 ezPay / ECPay / Amego 平行）。
  # 知識來源：paynow skill references/invoice-api.md（體系 3）。
  # 端點：GET /api/invoices?InvoiceNumber=&OrderNo=&Limit=&Page=（Bearer JWT-Token）。
  # 唯讀：不變更任何訂單或發票狀態。

  # ⚠️ R5 實作更正：provider ID 為 "paynow_invoice"（非 "paynow"）。
  背景:
    假設 "paynow_invoice" 已啟用為電子發票 provider
    而且 管理員已登入並取得 Nonce
    而且 訂單 #100 已開立發票，_pc_issued_invoice_data 有 invoice_number

  規則: 前置（狀態）- 訂單必須已開立發票才能查詢

    場景: 未開立發票的訂單查詢回空
      假設 系統中有訂單 #200，尚未開立發票
      當 管理員呼叫 query_invoice(200)
      那麼 回傳空陣列

  規則: 前置（參數）- 查詢以 InvoiceNumber 或 OrderNo 為條件

    場景: 以發票號碼查詢
      當 管理員呼叫 query_invoice(100)
      那麼 GET /api/invoices 請求帶 InvoiceNumber 為訂單 #100 的發票號碼

  規則: 後置（回應）- 查詢成功回傳發票明細（不變更狀態）

    場景: 查詢已開立發票明細
      當 管理員呼叫 query_invoice(100)
      而且 PayNow 回應 type 為 "success"，result 含發票明細
      那麼 回傳發票明細
      而且 訂單 #100 的發票狀態不被變更
      # CiC(GAP): result 明細欄位（發票狀態 / 財政部上傳狀態 / 開立時間等）具體結構待 sandbox 查詢實測補。

  規則: 後置（回應）- 查詢失敗回空陣列不拋出

    場景: 查無發票時回空陣列
      當 管理員呼叫 query_invoice(100)
      而且 PayNow 回應 type 非 "success"
      那麼 回傳空陣列
      而且 不變更訂單狀態
