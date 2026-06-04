# language: zh-TW
功能: 電子發票開立
  作為 系統 / 網站管理員
  我想要 自動或手動開立電子發票
  以便 符合台灣電子發票法規

  背景:
    假設 "amego" 已啟用
    而且 Amego 設定如下：
      | key                        | value              |
      | invoice                    | 12345678           |
      | app_key                    | test_app_key       |
      | tax_rate                   | 0.05               |
      | mode                       | test               |
      | auto_issue_order_statuses  | ["wc-processing"]  |
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |

  規則: 前置（參數）- provider 必須是已啟用的 Invoice Provider

    場景: 指定不存在的 provider
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "nonexistent"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "找不到電子發票服務"

    場景: provider 不是 IInvoiceService 實例
      假設 管理員已登入並取得 Nonce
      而且 "fake_provider" 在容器中但不是 IInvoiceService
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "fake_provider"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "不是 Invoice Service"

  規則: 前置（參數）- 訂單必須存在

    場景: 訂單不存在
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/9999，provider 為 "amego"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "找不到訂單"

  規則: 已開立過不重複開立（冪等）

    場景: 重複開立直接回傳已有資料
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 的 _pc_issued_invoice_data 已有值：
        | key            | value      |
        | invoice_number | AB12345678 |
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "amego"
      那麼 回應狀態碼為 200
      而且 回應 data 包含 "invoice_number" 為 "AB12345678"
      而且 Amego API 未被呼叫

  規則: 成功開立發票時儲存相關 meta

    場景: 首次開立個人雲端發票成功
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 Amego API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value      |
        | provider    | amego      |
        | invoiceType | individual |
        | individual  | cloud      |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "amego"
      而且 訂單 #100 的 _pc_issue_invoice_params 有值

    場景: 首次開立公司發票成功
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 Amego API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value    |
        | provider    | amego    |
        | invoiceType | company  |
        | companyName | 測試公司 |
        | companyId   | 87654321 |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值

    場景: 首次開立捐贈發票成功
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 Amego API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value  |
        | provider    | amego  |
        | invoiceType | donate |
        | donateCode  | 7788   |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值

  規則: 自動開立

    場景: 訂單狀態變更觸發自動開立
      假設 Amego auto_issue_order_statuses 包含 "wc-processing"
      而且 訂單 #100 尚未開立發票
      當 訂單 #100 狀態從 "pending" 變為 "processing"
      那麼 WooCommerce 觸發 woocommerce_order_status_processing hook
      而且 Amego Provider 的 issue 方法被呼叫
      而且 結果儲存至 _pc_issued_invoice_data

  規則: API 呼叫時同步儲存發票參數到 order meta

    場景: issue API 呼叫前先儲存 issue_params
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value      |
        | provider    | amego      |
        | invoiceType | individual |
        | individual  | barcode    |
        | carrier     | /ABC1234   |
      那麼 訂單 #100 的 _pc_issue_invoice_params 包含 carrier 為 "/ABC1234"

  規則: 結帳頁發票資訊

    場景: 顧客填寫發票資訊
      當 顧客在結帳頁填寫發票類型和載具資訊並完成結帳
      那麼 發票資訊儲存至 _pc_issue_invoice_params

  規則: 綠界發票 provider 開立（B2C/B2B）

    場景: 以綠界 provider 開立 B2C 個人發票成功
      假設 "ecpay" 已啟用
      而且 ECPay 發票設定如下：
        | key         | value            |
        | merchant_id | 2000132          |
        | hash_key    | ejCk326UnaZWKisg |
        | hash_iv     | q9jcZX8Ib9LM8wYk |
        | mode        | test             |
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 綠界發票 API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value      |
        | provider    | ecpay      |
        | invoiceType | individual |
        | individual  | cloud      |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "ecpay"

    場景: 以綠界 provider 開立 B2B 公司發票成功
      假設 "ecpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 綠界發票 API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value    |
        | provider    | ecpay    |
        | invoiceType | company  |
        | companyName | 測試公司 |
        | companyId   | 87654321 |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "ecpay"
