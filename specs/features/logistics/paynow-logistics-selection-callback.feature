# language: zh-TW
@ignore @command
功能: PayNow 物流選店回呼（階段 A.2）
  作為 PayNow 物流系統
  我想要 在顧客選店後回傳門市資訊
  以便 系統暫存門市供後續建單

  # ILogisticsProvider::parse_store_selection() — 統一抽象階段 A.2（PayNow 為第三個 provider）。
  # PayNow 對應選店地圖 returnUrl 導回（與 ECPay ClientReplyURL ResultData / PAYUNi MapJson callback 平行）。
  # 知識來源：依 woomp（class-paynow-shipping.php returnUrl = paynow_choose_cvs_callback）反推。# CiC(ASM): 依 woomp 反推；callback 欄位有實證，待 PayNow 官方文件核對。
  # REST：POST /wp-json/power-checkout/paynow/logistics/selection-callback（permission __return_true，內部驗證）。

  背景:
    假設 "paynow_logistics" 已啟用
    而且 系統中有訂單 #100，運送方式為 "SEVEN"

  規則: 前置（參數）- 回呼必須帶門市資訊（storeid / storename / storeaddress）

    場景: 缺少門市代碼時解析失敗
      當 PayNow 選店回呼缺少 storeid
      那麼 操作失敗，錯誤為 "選店回呼缺少門市資訊"

  規則: 後置（狀態）- 解析成功後將門市資訊寫入 order meta

    場景: 顧客選擇 7-11 門市後寫入門市 meta
      當 PayNow 選店回呼帶 storeid、storename、storeaddress
      那麼 操作成功
      而且 訂單 #100 的 _pc_paynow_logistics_store_id 為回呼的 storeid
      而且 訂單 #100 的 _pc_paynow_logistics_store_name 為回呼的 storename
      而且 訂單 #100 的 _pc_paynow_logistics_store_addr 為回呼的 storeaddress
      # CiC(GAP): storeid / storename / storeaddress 具體值待 sandbox 選店實測補（woomp 由 PayNow 地圖頁回傳）。

  規則: 後置（狀態）- 回呼以購物車 hash（cid）關聯對應訂單

    場景: 回呼帶 cid 對應到正確訂單
      # CiC(ASM): woomp returnUrl 帶 ?cid=cart_hash，用以關聯結帳中的購物車/訂單。
      當 PayNow 選店回呼帶 cid 為訂單 #100 的購物車 hash
      那麼 門市資訊寫入訂單 #100

  規則: 前置（參數）- 回呼來源驗證
    # CiC(BDY): woomp 的 returnUrl 為開放回呼（無明顯 PassCode/簽章驗證）。是否須驗 user_account / 簽章防偽待 PayNow 官方文件確認；
    #   實作階段比照既有 ECPay selection-callback（permission __return_true，內部解資料）慣例，並評估加上來源驗證。

    場景: 回呼來源驗證機制待確認
      當 PayNow 選店回呼抵達
      那麼 系統依官方文件決定是否驗證來源簽章
