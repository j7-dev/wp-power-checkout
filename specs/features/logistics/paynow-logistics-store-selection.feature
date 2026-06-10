# language: zh-TW
@ignore @command
功能: PayNow 物流取得選店導轉頁（階段 A）
  作為 網站訪客
  我想要 在結帳時選擇 PayNow 超商門市或黑貓宅配
  以便 完成物流配送設定

  # ILogisticsProvider::get_store_selection() — 統一抽象階段 A（PayNow 為第三個 provider）。
  # PayNow 對應 form POST 導轉 {api_url}/Member/Order/Choselogistics 地圖頁（與 ECPay RedirectToLogisticsSelection / PAYUNi ship_map 平行）。
  # 知識來源：paynow skill 無物流 API，本檔依 woomp（../woomp/includes/paynow-shipping/）反推。# CiC(ASM): 依 woomp class-paynow-shipping.php paynow_checkout_enqueue_scripts() 反推；端點/欄位有實證，待 PayNow 官方文件核對。
  # WC 整合：PayNow 物流做成 WC_Shipping_Method（每個 service 一個運送方式），顧客在 classic checkout 選運送方式後點「選擇超商」觸發。
  # 黑貓宅配（TCAT）不需選店，跳過本流程直接帶結帳收件地址 + 溫層。

  背景:
    假設 "paynow_logistics" 已啟用
    而且 PayNow 物流設定如下：
      | key             | value                                            |
      | user_account    | (待澄清)                                         |
      | apicode         | (待澄清)                                         |
      | mode            | test                                             |
      | enabled_methods | ["SEVEN","FAMI","HILIFE","TCAT"]                 |
    # CiC(GAP): user_account / apicode 具體測試值待用戶向 PayNow 申請物流 sandbox 憑證後補。

  規則: 前置（狀態）- 物流 provider 必須啟用

    場景: provider 未啟用時取得選店頁失敗
      假設 "paynow_logistics" 未啟用
      當 顧客觸發選店
      那麼 操作失敗，錯誤為 "PayNow 物流未啟用"

  規則: 前置（狀態）- 訂單必須存在

    場景: 訂單不存在時取得選店頁失敗
      當 WooCommerce 呼叫 get_store_selection(999)
      那麼 操作失敗，錯誤為 "找不到訂單"

  規則: 前置（參數）- 運送方式必須為已啟用的 PayNow 物流子類型

    場景: 選擇未啟用的物流子類型時取得選店頁失敗
      假設 系統中有訂單 #100，運送方式對應服務代碼為 "OKMART"
      當 WooCommerce 呼叫 get_store_selection(100)
      那麼 操作失敗，錯誤為 "運送方式必須為已啟用的 PayNow 物流子類型"

  規則: 前置（參數）- 選店請求帶 user_account、TripleDES 加密的 apicode、Logistic_serviceID 與 returnUrl
    # CiC(ASM): woomp 將 apicode 以 DES-EDE3 加密後送出（openssl_encrypt(apicode, 'DES-EDE3', key, OPENSSL_ZERO_PADDING)）；returnUrl 為選店 callback。

    場景: 7-11 超商取貨組裝選店導轉表單
      假設 系統中有訂單 #100，運送方式為 "SEVEN"
      當 WooCommerce 呼叫 get_store_selection(100)
      那麼 導轉表單 action 為 "{api_url}/Member/Order/Choselogistics"
      而且 表單帶 user_account
      而且 表單帶 TripleDES 加密的 apicode
      而且 表單的 Logistic_serviceID 為 "01"
      而且 表單帶 returnUrl 指向 PayNow 選店 callback

  規則: 前置（參數）- 黑貓宅配（TCAT）不觸發選店

    場景: 黑貓宅配運送方式不需選擇門市
      假設 系統中有訂單 #102，運送方式為 "TCAT"
      當 顧客在結帳頁選擇黑貓宅配
      那麼 不顯示「選擇超商」按鈕
      而且 後續直接以結帳收件地址與溫層建單（見 paynow-logistics-create-shipment）

  規則: 後置（回應）- 成功時回傳 PayNow 選店地圖導轉資訊供導轉

    場景: 超商取貨正常觸發選店時回傳選店導轉頁
      假設 系統中有訂單 #100，運送方式為 "SEVEN"
      當 WooCommerce 呼叫 get_store_selection(100)
      那麼 操作成功
      而且 回傳的 redirect_target 為導向 PayNow 選店地圖頁的 form POST HTML
      # CiC(GAP): test 環境 api_url 具體網域待 PayNow 官方文件確認（woomp 由 settings 設定，未硬編測試網域）。
