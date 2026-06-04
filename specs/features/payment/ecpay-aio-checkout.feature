# language: zh-TW
@ignore @command
功能: 綠界 ECPay AIO 結帳付款（導轉式）
  作為 網站訪客
  我想要 使用綠界 ECPay AIO 進行線上付款
  以便 完成訂單結帳

  背景:
    假設 "ecpay_aio" 已啟用
    而且 ECPay AIO 設定如下：
      | key             | value                                   |
      | merchant_id     | 3002607                                 |
      | hash_key        | pwFHCqoQZGmho4w6                        |
      | hash_iv         | EkRm7iFT261dpevs                        |
      | mode            | test                                    |
      | allowed_payments | ["Credit","ATM","WebATM","CVS","BARCODE","ApplePay"] |
      | min_amount      | 5                                       |
      | max_amount      | 199999                                  |
      | expire_date     | 3                                       |

  規則: 前置（狀態）- 訂單必須存在

    場景: 訂單不存在時處理失敗
      假設 顧客已登入
      當 WooCommerce 呼叫 process_payment(999)
      那麼 回傳 result 為 "failure"
      而且 前台顯示錯誤通知 "處理結帳時發生錯誤，請查閱 綠界 ECPay AIO 的 log 紀錄了解詳情"

  規則: 前置（狀態）- Gateway 必須啟用且訂單金額在允許範圍內

    場景: 金額低於 min_amount 時 Gateway 不可用
      假設 系統中有訂單 #101，total 為 3，payment_method 為 "ecpay_aio"
      當 檢查 Gateway is_available
      那麼 回傳 false

    場景: 金額高於 max_amount 時 Gateway 不可用
      假設 系統中有訂單 #102，total 為 200000，payment_method 為 "ecpay_aio"
      當 檢查 Gateway is_available
      那麼 回傳 false

    場景: 金額在範圍內時 Gateway 可用
      假設 系統中有訂單 #104，total 為 1000，payment_method 為 "ecpay_aio"
      當 檢查 Gateway is_available
      那麼 回傳 true

  規則: 前置（參數）- CheckMacValue 以 SHA256 正確計算

    場景: 建單參數計算 CheckMacValue
      假設 系統中有訂單 #100，total 為 1000
      當 WooCommerce 組裝綠界 AIO 建單參數
      那麼 參數依 key 字母排序（ksort）後，前綴 HashKey、後綴 HashIV
      而且 以綠界 .NET urlencode 規則編碼後轉小寫
      而且 計算 SHA256 雜湊並轉大寫得到 CheckMacValue
      而且 EncryptType 為 1

  規則: 前置（參數）- ChoosePayment 必須為綠界允許值

    場景: ChoosePayment 為不允許的值時建單失敗
      假設 系統中有訂單 #100
      當 WooCommerce 以 ChoosePayment "InvalidMethod" 組裝建單參數
      那麼 操作失敗，錯誤為 "ChoosePayment 必須為綠界允許值"

    場景: 後台勾選的付款方式以 ALL + IgnorePayment 控制
      假設 ECPay AIO allowed_payments 為 ["Credit","ATM"]
      當 WooCommerce 組裝建單參數
      那麼 ChoosePayment 為 "ALL"
      而且 IgnorePayment 包含 "WebATM"
      而且 IgnorePayment 包含 "CVS"
      而且 IgnorePayment 包含 "BARCODE"
      而且 IgnorePayment 包含 "ApplePay"

  規則: 成功時於 order-received 頁 auto-form 自動 submit 導向綠界託管頁

    場景: 正常結帳流程跳轉綠界 Cashier
      假設 系統中有訂單 #100，total 為 1000，status 為 "pending"
      而且 顧客已登入
      當 WooCommerce 呼叫 process_payment(100)
      那麼 回傳 result 為 "success"
      而且 訂單 #100 有 order note "Pay via 綠界 ECPay AIO"
      而且 庫存被扣減
      而且 顧客回到 order-received 頁時，頁面 auto-form 自動 submit 至綠界 AioCheckOut/V5（test 環境 payment-stage.ecpay.com.tw）

  規則: 後置（狀態）- 建單時寫入冪等鍵 MerchantTradeNo

    場景: 建單後訂單保存 MerchantTradeNo
      假設 系統中有訂單 #100
      當 WooCommerce 組裝建單參數
      那麼 MerchantTradeNo 為英數字且長度不超過 20
      而且 訂單 #100 的 _pc_ecpay_trade_no 為該 MerchantTradeNo
