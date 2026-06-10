# language: zh-TW
@ignore @command
功能: PayNow 電子發票開立與作廢
  作為 系統 / 網站管理員
  我想要 以 PayNow 開立或作廢電子發票
  以便 符合台灣電子發票法規

  # IInvoiceService::issue() / cancel() — 統一抽象（PayNow 為第四個 invoice provider，與 Amego / ECPay / ezPay 平行）。
  # 沿用既有 /invoices/issue|cancel 端點與 provider-agnostic 共用層（結帳表單 / order meta / 退款 hook），僅 provider id 不同。
  # 知識來源：paynow skill references/invoice-api.md（體系 3，Bearer 商家 JWT-Token，無對稱加密）。
  # 端點：開立 POST /api/invoices/issue；作廢 POST /api/invoices/cancel（帶 invoice_number）。
  # 統一回應外層：{ status, type, message, result, request_id }；type=success 視為成功。
  # ⚠️ R5 實作更正：發票 provider ID 為 "paynow_invoice"（非 "paynow"），const ID='paynow_invoice'，
  #   WC option woocommerce_paynow_invoice_settings（與金流 woocommerce_paynow_settings 完全分離）。
  #   本 feature 場景中的 provider="paynow" 應視為 "paynow_invoice"；_pc_invoice_provider_id 實際值為 "paynow_invoice"。

  背景:
    假設 "paynow_invoice" 已啟用為電子發票 provider
    而且 PayNow 發票設定如下：
      | key       | value      |
      | jwt_token | (待澄清)   |
      | mode      | test       |
    # CiC(GAP): 商家 JWT-Token 具體測試值待用戶向 PayNow 申請發票 sandbox 憑證後補。
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1050  | processing |

  規則: 前置（參數）- 訂單必須存在

    場景: 訂單不存在
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/9999，provider 為 "paynow"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "找不到訂單"

  規則: 已開立過不重複開立（冪等）

    場景: 重複開立直接回傳已有資料
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 的 _pc_issued_invoice_data 已有 invoice_number
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "paynow"
      那麼 回應狀態碼為 200
      而且 PayNow 發票 API 未被呼叫

  規則: 認證 - 所有請求帶 Bearer 商家 JWT-Token

    場景: 開立請求帶 Authorization Bearer header
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "paynow"
      那麼 送出的請求 Header 帶 "Authorization: Bearer {jwt_token}"

  規則: 成功開立發票時儲存相關 meta

    場景: 首次開立 B2C 個人手機條碼載具發票成功
      # 結帳 individual=barcode → PayNow carrier_type=PhoneBarCodeCarrier，carrier_id1/carrier_id2 帶手機條碼；非統編 tax_amount=0（國稅局算稅）
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 PayNow 發票 API 開立發票回傳成功，type 為 "success"
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value      |
        | provider    | paynow     |
        | invoiceType | individual |
        | individual  | barcode    |
        | carrier     | /ABC1234   |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "paynow_invoice"
      而且 送出的 carrier_type 為 "PhoneBarCodeCarrier"
      而且 送出的 tax_amount 為 0

    場景: 首次開立 B2B 公司統編發票成功
      # 統編發票 → buyer.identifier 帶統編，tax_amount 帶實際稅額（非 0，自行算稅）
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 PayNow 發票 API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value    |
        | provider    | paynow   |
        | invoiceType | company  |
        | companyName | 測試公司 |
        | companyId   | 87654321 |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "paynow_invoice"
      而且 送出的 buyer.identifier 為 "87654321"
      而且 送出的 tax_amount 為實際稅額

    場景: 首次開立捐贈發票成功
      # invoiceType=donate → PayNow npoban（愛心碼）；載具與捐贈互斥（carrier_type 留空）
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 PayNow 發票 API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value  |
        | provider    | paynow |
        | invoiceType | donate |
        | donateCode  | 919    |
      那麼 回應狀態碼為 200
      而且 送出的 npoban 為 "919"
      而且 送出的 carrier_type 為空

  規則: 前置（參數）- 載具與捐贈互斥

    場景: 同時帶載具與捐贈碼時開立失敗
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數同時帶 carrier 與 donateCode
      那麼 開立失敗，錯誤為 "載具與捐贈不可同時指定"

  規則: 前置（參數）- 零稅率必填 is_pass_customs 與 zero_tax_rate_reason
    # CiC(ASM): paynow skill invoice-api §11.4：tax_type=ZeroTax 必填 is_pass_customs + zero_tax_rate_reason（如 ExportGoods）。

    場景: 零稅率發票缺 zero_tax_rate_reason 時開立失敗
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 為零稅率發票（tax_type=ZeroTax）但未帶 zero_tax_rate_reason
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "paynow"
      那麼 開立失敗，錯誤為 "零稅率發票必填零稅率原因"

  規則: 後置（回應）- type 非 success 時不寫入開立資料

    場景: 開立失敗時不寫入 issued_data
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 PayNow 發票 API 回傳 type 非 "success"
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "paynow"
      那麼 開立失敗
      而且 訂單 #100 的 _pc_issued_invoice_data 未被寫入
      而且 訂單 #100 有 order note 記錄錯誤
      # CiC(GAP): PayNow 發票錯誤碼具體值見 paynow skill error-codes，Example 具體碼待 sandbox 補。

  規則: 作廢 - 帶 invoice_number 作廢已開立發票

    場景: 作廢已開立發票成功
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 已開立發票，_pc_issued_invoice_data 有 invoice_number
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/cancel/100，provider 為 "paynow"
      那麼 回應狀態碼為 200
      而且 PayNow 作廢 API 帶 invoice_number
      而且 訂單 #100 的 _pc_cancelled_invoice_data 有值

  規則: 自動開立

    場景: 訂單狀態變更觸發自動開立
      假設 PayNow auto_issue_order_statuses 包含 "wc-processing"
      而且 訂單 #100 尚未開立發票
      當 訂單 #100 狀態從 "pending" 變為 "processing"
      那麼 WooCommerce 觸發 woocommerce_order_status_processing hook
      而且 PayNow Provider 的 issue 方法被呼叫
      而且 結果儲存至 _pc_issued_invoice_data
