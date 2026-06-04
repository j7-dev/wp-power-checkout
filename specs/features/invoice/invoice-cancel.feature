# language: zh-TW
功能: 電子發票作廢
  作為 系統 / 網站管理員
  我想要 自動或手動作廢電子發票
  以便 處理退貨或訂單取消情境

  背景:
    假設 "amego" 已啟用
    而且 Amego 設定如下：
      | key                         | value           |
      | mode                        | test            |
      | auto_cancel_order_statuses  | ["wc-refunded"] |
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |
    而且 訂單 #100 已開立發票：
      | meta_key                | meta_value                       |
      | _pc_issued_invoice_data | {"invoice_number": "AB12345678"} |
      | _pc_invoice_provider_id | amego                            |

  規則: 前置（參數）- 訂單必須存在

    場景: 訂單不存在
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/cancel/9999
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "找不到訂單"

  規則: 前置（參數）- 必須有對應的 invoice provider

    場景: 訂單的 _pc_invoice_provider_id 對應不到已啟用的 provider
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "nonexistent"
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/cancel/100
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "不是 Invoice Service"

  規則: 已作廢過不重複作廢（冪等）

    場景: 重複作廢直接回傳已有資料
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 的 _pc_cancelled_invoice_data 已有值
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/cancel/100
      那麼 回應狀態碼為 200
      而且 Amego API 未被呼叫

  規則: 成功作廢時清除開立資料並儲存作廢資料

    場景: 首次作廢發票成功
      假設 管理員已登入並取得 Nonce
      而且 訂單 #100 尚未作廢發票
      而且 Amego API 作廢發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/cancel/100
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_issue_invoice_params 已被清除
      而且 訂單 #100 的 _pc_issued_invoice_data 已被清除
      而且 訂單 #100 的 _pc_invoice_provider_id 已被清除
      而且 訂單 #100 的 _pc_cancelled_invoice_data 有值

  規則: 自動作廢

    場景: 訂單進入 refunded 狀態時自動作廢
      假設 Amego auto_cancel_order_statuses 包含 "wc-refunded"
      而且 訂單 #100 已開立發票
      當 訂單 #100 狀態從 "processing" 變為 "refunded"
      那麼 WooCommerce 觸發 woocommerce_order_status_refunded hook
      而且 Amego Provider 的 cancel 方法被呼叫
      而且 訂單 #100 的 _pc_cancelled_invoice_data 有值
      而且 訂單 #100 的 _pc_issue_invoice_params 已被清除
      而且 訂單 #100 的 _pc_issued_invoice_data 已被清除
      而且 訂單 #100 的 _pc_invoice_provider_id 已被清除

  規則: 手動作廢

    場景: 管理員手動作廢
      假設 管理員已登入並取得 Nonce
      當 管理員點擊 "作廢發票"
      那麼 系統呼叫 cancel API
      而且 發票作廢成功

  規則: 綠界發票 provider 作廢

    場景: 以綠界 provider 作廢發票成功
      假設 "ecpay" 已啟用
      而且 管理員已登入並取得 Nonce
      而且 訂單 #100 的 _pc_invoice_provider_id 為 "ecpay"
      而且 訂單 #100 已開立綠界發票且尚未作廢
      而且 綠界發票 API 作廢發票回傳成功
      當 管理員發送 POST /wp-json/power-checkout/v1/invoices/cancel/100
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_cancelled_invoice_data 有值
      而且 訂單 #100 的 _pc_issued_invoice_data 已被清除
      而且 訂單 #100 的 _pc_invoice_provider_id 已被清除
