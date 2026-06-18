# language: zh-TW
功能: 金流正規化錯誤模型
  作為 系統 / 網站管理員
  我想要 把金流操作（退款 / 查詢 / 請款 / 取消授權）的失敗以正規化錯誤物件區分
  以便 與發票領域共用同一套正規化錯誤碼，呼叫端與前端能一致處理金流失敗

  # einvoice 啟發外溢 Payment 領域（用戶 Q3 定案 = Invoice + Payment 一起）。
  # 正規化 code enum 為領域中立（放 inc/classes/Shared/），發票 + 金流共用同一份。
  # PC 現況：IPaymentProvider::process_refund() 已回 bool|\WP_Error 且 docblock「不應 throw」——本模型形式化並豐富化
  #   （正規化 code 入 WP_Error $data：raw_code / raw_message / provider / raw）。
  # 金流特有失敗類別：退款不支援（UNSUPPORTED）、callback 驗簽失敗（SIGNATURE）、金額守恆（VALIDATION）。
  #
  # ⚠️ 範圍邊界（硬約束，不可違反）：
  #   - NotifyURL / Webhook callback 的「always-200」行為「完全不變」——callback 內部驗簽失敗仍回 HTTP 200，
  #     正規化錯誤模型「不」用於改變 callback 的 HTTP 回應；callback 失敗僅記 order note。
  #   - WP_Error 僅用於 process_refund 回傳 + REST 退款 / 補單 / 查詢端點回應 + admin order action 結果。
  #   - capture() / void_auth() 既有 no-op 安全預設不變；不支援者回傳行為維持。

  背景:
    假設 "payuni_upp" 已啟用
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |

  規則: 後置（回應）- 退款成功時回傳 true（既有契約不變）

    場景: 信用卡退款成功回傳 true
      假設 訂單 #100 以信用卡付款且可 API 退款
      而且 第三方退款 API 回傳成功
      當 對訂單 #100 呼叫 process_refund
      那麼 回傳值為 true
      而且 回傳值不是 WP_Error

  規則: 正規化 code - 不支援 API 退款的付款方式回傳 UNSUPPORTED

    場景: ATM 付款退款回傳帶 UNSUPPORTED 的 WP_Error
      假設 訂單 #100 以 ATM 虛擬帳號付款（不支援 API 退款）
      當 對訂單 #100 呼叫 process_refund
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "UNSUPPORTED"
      而且 例外不會向 WooCommerce 退款流程傳播

  規則: 正規化 code - 退款金額不合法回傳 VALIDATION

    場景: 退款金額超出可退餘額時回傳 VALIDATION
      假設 訂單 #100 可退餘額為 500
      當 對訂單 #100 呼叫 process_refund 退款金額 800
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "VALIDATION"
      而且 第三方退款 API 未被呼叫

  規則: 正規化 code - 認證失敗回傳 AUTH

    場景: 商店憑證錯誤導致退款失敗時回傳 AUTH
      假設 訂單 #100 以信用卡付款
      而且 第三方退款 API 回傳商店憑證 / 簽章金鑰錯誤
      當 對訂單 #100 呼叫 process_refund
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "AUTH"
      而且 WP_Error 的 error_data 包含 "raw_code"

  規則: 正規化 code - 連線失敗回傳 NETWORK

    場景: 退款 API 連線逾時回傳 NETWORK
      假設 訂單 #100 以信用卡付款
      而且 第三方退款 API 連線逾時
      當 對訂單 #100 呼叫 process_refund
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "NETWORK"

  規則: 範圍邊界 - callback 驗簽失敗仍回 HTTP 200（always-200 不變）

    場景: NotifyURL 收到驗簽失敗的請求仍回應 HTTP 200
      # 硬約束：正規化錯誤模型不改變 callback 的 always-200 行為；僅內部記錄 SIGNATURE 類錯誤於 order note
      假設 訂單 #100 等待付款通知
      而且 第三方送來 HashInfo / 簽章驗證失敗的 NotifyURL 請求
      當 系統處理該 NotifyURL 請求
      那麼 回應 HTTP 狀態碼為 200
      而且 訂單 #100 狀態未被變更為已付款
      而且 系統內部記錄驗簽失敗（正規化 code 為 "SIGNATURE"）於 order note

  規則: 後置（回應）- REST 退款端點將 WP_Error 映射為帶正規化 code 的錯誤回應

    場景: 退款端點收到 UNSUPPORTED 的 WP_Error 時回應帶 error_code
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 以不支援 API 退款的方式付款
      當 管理員發送 POST /wp-json/power-checkout/v1/refund 對訂單 #100
      那麼 回應為錯誤狀態碼
      而且 回應體包含 "error_code" 為 "UNSUPPORTED"
      而且 回應體包含使用者可讀的 "message"

  規則: 跨領域一致 - 金流與發票共用同一份正規化 code 值域

    場景: 金流退款的 UNSUPPORTED 與發票折讓不支援使用同一 enum
      假設 一個金流退款產生的 UNSUPPORTED WP_Error
      而且 一個發票折讓不支援產生的 UNSUPPORTED WP_Error
      當 比較兩者的正規化 code
      那麼 兩者的 code 值相同
      而且 兩者都來自領域中立的正規化 code enum
