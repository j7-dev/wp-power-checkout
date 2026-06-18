# language: zh-TW
功能: 電子發票正規化錯誤模型
  作為 系統 / 網站管理員
  我想要 把發票操作的各種失敗以正規化錯誤物件區分（而非全部塌縮成空陣列）
  以便 呼叫端能判斷失敗類型、前端能顯示精確訊息、debug 能保留 provider 原始錯誤碼

  # einvoice 導入重點 #1：正規化錯誤物件。
  # PC 既有契約：IInvoiceService::issue()/cancel() 全回 array，失敗一律回 []（金鑰錯 / 網路斷 / 驗證失敗 / 重複開立無法區分）。
  # 改造後（用戶定案 = WP_Error）：成功仍回 array；失敗回 \WP_Error，$code = 正規化 code enum value，
  #   $data 帶 raw_code（provider 原始錯誤碼）/ raw_message / provider / raw（provider 原始回應）。
  # never-throw 鐵律保留：provider 公開方法 catch \Throwable 一律回 WP_Error，絕不向 WC hook 拋例外。
  # 正規化 code 為領域中立 backed enum，放 inc/classes/Shared/，發票 + 金流共用。

  背景:
    假設 "ezpay" 已啟用
    而且 ezPay 發票設定如下：
      | key         | value                            |
      | merchant_id | 3502275                          |
      | hash_key    | abcdefghijklmnopqrstuvwxyz123456 |
      | hash_iv     | 1234567890abcdef                 |
      | mode        | test                             |
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |

  規則: 後置（回應）- 開立成功時回傳陣列（既有契約不變）

    場景: 開立成功回傳發票資料陣列而非 WP_Error
      假設 訂單 #100 尚未開立發票
      而且 ezPay API 開立發票回傳成功，InvoiceNumber 為 "DS12223139"
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是陣列
      而且 回傳值不是 WP_Error
      而且 回傳陣列包含 "invoice_number" 為 "DS12223139"

  規則: 後置（回應）- 開立失敗時回傳 WP_Error 而非空陣列

    場景: 第三方回業務錯誤碼時回傳帶正規化 code 的 WP_Error
      假設 訂單 #100 尚未開立發票
      而且 ezPay API 開立發票回傳錯誤
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 屬於正規化 code 值域
      而且 WP_Error 的 error_data 包含 "raw_code"
      而且 WP_Error 的 error_data 包含 "raw_message"
      而且 WP_Error 的 error_data 包含 "provider" 為 "ezpay"
      而且 訂單 #100 的 _pc_issued_invoice_data 未被寫入

  規則: 正規化 code - 驗證失敗映射為 VALIDATION

    場景: 載具與捐贈互斥違反時回傳 VALIDATION
      假設 訂單 #100 尚未開立發票
      而且 訂單 #100 的發票參數同時帶載具與捐贈碼
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "VALIDATION"
      而且 ezPay API 未被呼叫

  規則: 正規化 code - 認證失敗映射為 AUTH

    場景: 商店金鑰錯誤時回傳 AUTH
      假設 訂單 #100 尚未開立發票
      而且 ezPay API 回傳金鑰 / 認證錯誤（如 KEY10002 解密錯誤 / 驗章金鑰不符）
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "AUTH"
      而且 WP_Error 的 error_data 的 raw_code 為 "KEY10002"

  規則: 正規化 code - 驗章失敗映射為 SIGNATURE

    場景: ezPay 回應 CheckCode 與本地計算不符時回傳 SIGNATURE
      # PC 新增於 einvoice 9 種之外的 code：金流 / 發票的驗章失敗（CheckCode / CheckMacValue / HMAC / HashInfo）語義獨立
      假設 訂單 #100 尚未開立發票
      而且 ezPay API 回傳的 CheckCode 與本地計算不符
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "SIGNATURE"
      而且 訂單 #100 的 _pc_issued_invoice_data 未被寫入

  規則: 正規化 code - 狀態衝突映射為 CONFLICT

    場景: 作廢已開過折讓的發票時回傳 CONFLICT
      # ezPay LIB10007：已開折讓的發票須先作廢折讓才能作廢發票
      假設 訂單 #100 的 _pc_invoice_provider_id 為 "ezpay"
      而且 訂單 #100 已開立 ezPay 發票且已開過折讓
      而且 ezPay API 作廢發票回傳錯誤代碼 "LIB10007"
      當 ezPay Provider 的 cancel 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "CONFLICT"
      而且 WP_Error 的 error_data 的 raw_code 為 "LIB10007"
      而且 訂單 #100 的 _pc_issued_invoice_data 未被清除

  規則: 正規化 code - 字軌號碼用罄映射為 NUMBER_EXHAUSTED

    場景: 發票字軌號碼用罄時回傳 NUMBER_EXHAUSTED
      假設 訂單 #100 尚未開立發票
      而且 provider 回傳字軌 / 號碼用罄錯誤
      當 Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "NUMBER_EXHAUSTED"

  規則: 正規化 code - 連線失敗映射為 NETWORK

    場景: 第三方 API 連線逾時時回傳 NETWORK
      假設 訂單 #100 尚未開立發票
      而且 ezPay API 連線逾時 / 無回應
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "NETWORK"

  規則: 正規化 code - 未分類錯誤碼映射為 PROVIDER

    場景: provider 回傳未在映射表中的業務錯誤碼時回傳 PROVIDER
      假設 訂單 #100 尚未開立發票
      而且 ezPay API 回傳映射表未涵蓋的錯誤代碼 "LIB99999"
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "PROVIDER"
      而且 WP_Error 的 error_data 的 raw_code 為 "LIB99999"

  規則: 正規化 code - 未預期例外映射為 UNKNOWN 且不破壞主流程

    場景: provider 內部拋出未預期例外時回傳 UNKNOWN 而非向上拋
      # never-throw 鐵律：catch \Throwable → logger（同步 order note）→ 回 WP_Error，絕不斷 WC hook
      假設 訂單 #100 尚未開立發票
      而且 ezPay Provider 內部因未預期狀況拋出 \Throwable
      當 ezPay Provider 的 issue 方法被呼叫
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "UNKNOWN"
      而且 例外不會向 WooCommerce hook 傳播
      而且 訂單 #100 留下記錄錯誤的 order note

  規則: 後置（回應）- REST 端點將 WP_Error 映射為帶正規化 code 的錯誤回應

    場景: issue 端點收到 provider 回傳的 WP_Error 時回應帶 error_code
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 ezPay Provider issue 回傳 WP_Error（code 為 "VALIDATION"）
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "ezpay"
      那麼 回應為錯誤狀態碼
      而且 回應體包含 "error_code" 為 "VALIDATION"
      而且 回應體包含 "raw_code"
      而且 回應體包含使用者可讀的 "message"

    場景: issue 端點收到 provider 成功陣列時回應 200
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未開立發票
      而且 ezPay Provider issue 回傳成功陣列
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "ezpay"
      那麼 回應狀態碼為 200
      而且 回應 data 有值

  規則: 後置（回應）- AUTH / VALIDATION 映射為 4xx，NETWORK / PROVIDER / UNKNOWN 映射為 5xx

    場景: VALIDATION 錯誤映射為用戶端錯誤狀態碼
      假設 管理員已登入並取得 Nonce
      而且 ezPay Provider issue 回傳 WP_Error（code 為 "VALIDATION"）
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "ezpay"
      那麼 回應狀態碼為 422

    場景: NETWORK 錯誤映射為伺服器端錯誤狀態碼
      假設 管理員已登入並取得 Nonce
      而且 ezPay Provider issue 回傳 WP_Error（code 為 "NETWORK"）
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/issue/100，provider 為 "ezpay"
      那麼 回應狀態碼為 502

  規則: 型別守衛 - 提供判斷 WP_Error 是否為正規化發票錯誤的方法

    場景: 正規化發票錯誤可被型別守衛辨識
      假設 一個由 provider 產生的正規化發票 WP_Error
      當 呼叫端以型別守衛檢查該值
      那麼 型別守衛回傳為真
      而且 可取出正規化 code 與 raw_code
