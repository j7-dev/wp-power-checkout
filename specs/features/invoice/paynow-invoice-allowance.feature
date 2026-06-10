# language: zh-TW
@ignore @command
功能: PayNow 電子發票折讓
  作為 系統 / 網站管理員
  我想要 對 PayNow 已開立發票開立或作廢折讓單
  以便 部分退款時正確處理發票折讓

  # ISupportsAllowance::issue_allowance() / invalid_allowance() — 選配能力介面（PayNow 支援，與 ezPay / ECPay 平行）。
  # 知識來源：paynow skill references/invoice-api.md（體系 3）。
  # 端點：折讓開立 POST /api/invoices/allowance（回 allowance_number）；折讓作廢 POST /api/invoices/cancel-allowance（帶 allowance_number）。
  # 觸發：部分退款 woocommerce_order_refunded hook（沿用 provider-agnostic 退款自動折讓 hook）。

  # ⚠️ R5 實作更正：provider ID 為 "paynow_invoice"（非 "paynow"）。
  背景:
    假設 "paynow_invoice" 已啟用為電子發票 provider
    而且 訂單 #100 已開立發票，_pc_issued_invoice_data 有 invoice_number
    而且 PayNow auto_allowance_on_refund 為 "yes"

  規則: 前置（狀態）- 訂單必須已開立發票才能折讓

    場景: 未開立發票的訂單退款不開折讓
      假設 系統中有訂單 #200，尚未開立發票
      當 訂單 #200 部分退款 500
      那麼 不呼叫 PayNow 折讓 API

  規則: 後置（狀態）- 部分退款觸發折讓開立並儲存折讓號碼

    場景: 部分退款自動開立折讓
      當 訂單 #100 部分退款 500
      那麼 PayNow 折讓 API（/api/invoices/allowance）被呼叫
      而且 折讓請求帶 invoice_number 為訂單 #100 的發票號碼
      而且 折讓請求帶折讓品項（quantity / unit_price / amount / tax / tax_type / invoice_body_sequence_number）
      而且 訂單 #100 的 _pc_allowance_data 有 allowance_number
      # CiC(GAP): allowance_number 具體值待 sandbox 折讓實測補。

  規則: 全額退款走作廢發票而非折讓

    場景: 全額退款不開折讓改作廢發票
      當 訂單 #100 全額退款 1050
      那麼 不呼叫 PayNow 折讓 API
      而且 走作廢發票邏輯（見 invoice-cancel）

  規則: 作廢折讓 - 帶 allowance_number 作廢已開立折讓

    場景: 作廢已開立折讓成功
      假設 訂單 #100 的 _pc_allowance_data 有 allowance_number
      當 管理員作廢折讓
      那麼 PayNow 折讓作廢 API（/api/invoices/cancel-allowance）被呼叫
      而且 請求帶 allowance_number
      而且 訂單 #100 的折讓資料被清除
      # CiC(BDY): 折讓作廢的觸發來源（後台手動按鈕 vs hook）待實作期決策；ezPay 為手動，PayNow 對齊。
