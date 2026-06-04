# language: zh-TW
@ignore @command
功能: 綠界全方位物流 v2 取得選店導轉頁（階段 A）
  作為 網站訪客
  我想要 在結帳時選擇超商門市或宅配
  以便 完成物流配送設定

  # ILogisticsProvider::get_store_selection() — 統一抽象階段 A。
  # ECPay 對應 RedirectToLogisticsSelection（TempLogisticsID=0 建暫存單 + 回 RWD 選店 HTML）。
  # PAYUNi 未來對應 ship_map（form POST 導轉地圖頁），共用同一 interface 方法。
  # WC 整合：綠界物流做成 WC_Shipping_Method，顧客在 classic checkout 結帳頁選運送方式後點「選擇門市」觸發本流程。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定如下：
      | key              | value                                                                       |
      | account_type     | b2c                                                                         |
      | b2c_merchant_id  | 2000132                                                                     |
      | b2c_hash_key     | 5294y06JbISpM5x9                                                            |
      | b2c_hash_iv      | v77hoKGq4kWxNNIS                                                            |
      | c2c_merchant_id  | 2000933                                                                     |
      | c2c_hash_key     | XBERn1YOvpM9nfZc                                                            |
      | c2c_hash_iv      | h1ONHk4P4yqbl5LK                                                            |
      | mode             | test                                                                        |
      | enabled_methods  | ["FAMI","UNIMART","HILIFE","HOME"]                                          |
      | server_reply_url | https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback  |
      | client_reply_url | https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback |

  規則: 前置（狀態）- 物流 provider 必須啟用

    場景: provider 未啟用時取得選店頁失敗
      假設 "ecpay_logistics" 未啟用
      當 顧客觸發選店
      那麼 操作失敗，錯誤為 "綠界全方位物流未啟用"

  規則: 前置（狀態）- 訂單必須存在

    場景: 訂單不存在時取得選店頁失敗
      當 WooCommerce 呼叫 get_store_selection(999)
      那麼 操作失敗，錯誤為 "找不到訂單"

  規則: 前置（參數）- ClientReplyURL 與 ServerReplyURL 必須為公開可訪問 URL

    場景: ClientReplyURL 為 localhost 時取得選店頁失敗
      假設 系統中有訂單 #100，total 為 1000
      而且 ECPay 物流設定 client_reply_url 為 "http://localhost/callback"
      當 WooCommerce 呼叫 get_store_selection(100)
      那麼 操作失敗，錯誤為 "ClientReplyURL 必須為公開可訪問的 URL"

  規則: 前置（參數）- 運送方式必須為已啟用的物流子類型

    場景: 選擇未啟用的物流子類型時取得選店頁失敗
      假設 系統中有訂單 #100，運送方式為 "OKMART"
      當 WooCommerce 呼叫 get_store_selection(100)
      那麼 操作失敗，錯誤為 "運送方式必須為已啟用的綠界物流子類型"

  規則: 前置（參數）- RqHeader 必須帶 Revision "1.0.0" 與即時 Timestamp

    場景: 建立暫存單請求組裝 RqHeader 與 Data
      假設 系統中有訂單 #100，total 為 1000，運送方式為 "FAMI"
      當 WooCommerce 組裝 RedirectToLogisticsSelection 請求
      那麼 RqHeader 的 Revision 為 "1.0.0"
      而且 RqHeader 的 Timestamp 為即時 time() 產生
      而且 Data 以 AES-128-CBC 加密
      而且 Data 的 TempLogisticsID 為 "0"

  規則: 前置（參數）- account_type 決定使用的帳號與憑證

    場景: account_type 為 b2c 時使用 B2C 帳號
      假設 系統中有訂單 #100，運送方式為 "FAMI"
      而且 ECPay 物流設定 account_type 為 "b2c"
      當 WooCommerce 組裝 RedirectToLogisticsSelection 請求
      那麼 請求的 MerchantID 為 "2000132"
      而且 Data 以 b2c_hash_key、b2c_hash_iv 加密

    場景: account_type 為 c2c 時使用 C2C 帳號
      假設 系統中有訂單 #100，運送方式為 "FAMI"
      而且 ECPay 物流設定 account_type 為 "c2c"
      當 WooCommerce 組裝 RedirectToLogisticsSelection 請求
      那麼 請求的 MerchantID 為 "2000933"
      而且 Data 以 c2c_hash_key、c2c_hash_iv 加密

  規則: 前置（參數）- COD 訂單帶代收貨款參數

    場景: COD 訂單建立暫存單時帶 IsCollection 與 CollectionAmount
      假設 系統中有訂單 #100，total 為 1000，付款方式為 "cod"，運送方式為 "FAMI"
      當 WooCommerce 呼叫 get_store_selection(100)
      那麼 Data 的 IsCollection 為 "Y"
      而且 Data 的 CollectionAmount 為 1000

    場景: 線上付款訂單建立暫存單時不帶代收貨款
      假設 系統中有訂單 #101，total 為 1000，付款方式為 "ecpay_aio"，運送方式為 "FAMI"
      當 WooCommerce 呼叫 get_store_selection(101)
      那麼 Data 的 IsCollection 為 "N"

  規則: 前置（參數）- 宅配冷凍冷藏帶溫層 Temperature

    場景: 宅配冷凍商品建立暫存單時帶溫層
      假設 系統中有訂單 #102，total 為 1000，運送方式為 "HOME"，溫層為 "冷凍"
      當 WooCommerce 呼叫 get_store_selection(102)
      那麼 Data 的 Temperature 為 "0003"

  規則: 後置（回應）- 成功時回傳綠界 RWD 選店頁供導轉

    場景: 超商取貨正常觸發選店時回傳選店導轉頁
      假設 系統中有訂單 #100，total 為 1000，運送方式為 "FAMI"
      當 WooCommerce 呼叫 get_store_selection(100)
      那麼 操作成功
      而且 回傳的 redirect_target 為綠界 RWD 選店頁 HTML（test 環境 logistics-stage.ecpay.com.tw/Express/v2/RedirectToLogisticsSelection）
</content>
