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

  規則: ezPay 發票 provider 開立（B2C/B2B）
    # ezPay = 藍新 NewebPay 旗下品牌；AES-256-CBC PostData_ + CheckCode SHA256 驗章。
    # 沿用統一 IInvoiceService + 既有 /invoices/issue 端點，僅 provider id 不同。
    # invoice_issue Version=1.5；即時開立 Status=1 回應才帶 InvoiceNumber。

    場景: 以 ezPay provider 開立 B2C 個人雲端發票成功
      假設 "ezpay" 已啟用
      而且 ezPay 發票設定如下：
        | key         | value                            |
        | merchant_id | 3502275                          |
        | hash_key    | abcdefghijklmnopqrstuvwxyz123456 |
        | hash_iv     | 1234567890abcdef                 |
        | mode        | test                             |
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 ezPay API 開立發票回傳成功，InvoiceNumber 為 "DS12223139"
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value      |
        | provider    | ezpay      |
        | invoiceType | individual |
        | individual  | cloud      |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "ezpay"
      而且 _pc_issued_invoice_data 包含 "invoice_number" 為 "DS12223139"
      而且 _pc_issued_invoice_data 包含 "invoice_trans_no"
      而且 _pc_issued_invoice_data 包含 "random_num"

    場景: 以 ezPay provider 開立 B2C 手機條碼載具發票成功
      # 結帳表單 individual=barcode → ezPay CarrierType=0（手機條碼載具），CarrierNum 須 rawurlencode
      假設 "ezpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 ezPay API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value      |
        | provider    | ezpay      |
        | invoiceType | individual |
        | individual  | barcode    |
        | carrier     | /ABC1234   |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 送出的 PostData 中 CarrierType 為 "0"

    場景: 以 ezPay provider 開立 B2C 捐贈發票成功
      # 結帳表單 invoiceType=donate → ezPay LoveCode；載具與捐贈互斥（CarrierType 須空）
      假設 "ezpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 ezPay API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value  |
        | provider    | ezpay  |
        | invoiceType | donate |
        | donateCode  | 7788   |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 送出的 PostData 中 LoveCode 為 "7788"
      而且 送出的 PostData 中 CarrierType 為空

    場景: 以 ezPay provider 開立 B2B 公司統編發票成功
      # B2B → Category=B2B，ItemPrice/ItemAmt 為未稅金額（與 B2C 含稅不同）；BuyerUBN 必填、PrintFlag 必填 Y
      假設 "ezpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 ezPay API 開立發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數為：
        | key         | value    |
        | provider    | ezpay    |
        | invoiceType | company  |
        | companyName | 測試公司 |
        | companyId   | 87654321 |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issued_invoice_data 有值
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "ezpay"
      而且 送出的 PostData 中 Category 為 "B2B"
      而且 送出的 PostData 中 BuyerUBN 為 "87654321"

  規則: 前置（參數）- ezPay 載具與捐贈互斥

    場景: 同時帶載具與捐贈碼時開立失敗
      # ezPay 規定 CarrierType 有值時 LoveCode 必為空；違反會被 ezPay 平台拒絕
      假設 "ezpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，參數同時帶 carrier 與 donateCode
      那麼 開立失敗，錯誤為"載具與捐贈不可同時指定"

  規則: 後置（回應）- ezPay 回應須通過 CheckCode 驗章
    # ezPay 回應帶 CheckCode（SHA256），驗算 5 欄位（InvoiceTransNo/MerchantID/MerchantOrderNo/RandomNum/TotalAmt）A-Z 排序 + HashIV/HashKey 夾後比對

    場景: CheckCode 驗章失敗時不寫入開立資料
      假設 "ezpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 ezPay API 回傳的 CheckCode 與本地計算不符
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "ezpay"
      那麼 開立失敗
      而且 訂單 #100 的 _pc_issued_invoice_data 未被寫入

  # ──────────────────────────────────────────────────────────────
  # einvoice 導入 #1：開立失敗的回傳契約演進（原失敗一律塌縮回 [] → 改回 WP_Error 帶正規化 code）
  # 完整錯誤模型契約見 invoice/invoice-error-model.feature；此處僅補開立端的正規化斷言。
  # ──────────────────────────────────────────────────────────────

  規則: 後置（回應）- 開立失敗回傳帶正規化 code 的 WP_Error（取代回傳空陣列）

    場景: 開立第三方錯誤時 issue 回傳 WP_Error 而非空陣列
      假設 "ezpay" 已啟用
      而且 訂單 #100 尚未開立發票
      而且 ezPay API 開立發票回傳錯誤
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 屬於正規化 code 值域
      而且 WP_Error 的 error_data 包含 "raw_code"
      而且 WP_Error 的 error_data 包含 "provider" 為 "ezpay"
      而且 訂單 #100 的 _pc_issued_invoice_data 未被寫入

    場景: CheckCode 驗章失敗時回傳 SIGNATURE 錯誤
      假設 "ezpay" 已啟用
      而且 訂單 #100 尚未開立發票
      而且 ezPay API 回傳的 CheckCode 與本地計算不符
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "SIGNATURE"

  規則: 後置（回應）- issue 端點將開立失敗的 WP_Error 映射為帶 error_code 的回應

    場景: issue 端點對驗證失敗回應帶 error_code
      假設 "ezpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 訂單 #100 的發票參數同時帶載具與捐贈碼
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "ezpay"
      那麼 回應為錯誤狀態碼
      而且 回應體包含 "error_code" 為 "VALIDATION"
      而且 回應體包含使用者可讀的 "message"
