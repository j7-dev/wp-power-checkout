# language: zh-TW
@ignore @command
功能: 綠界全方位物流 v2 選店回呼（ClientReplyURL）
  作為 綠界ECPay 第三方系統
  我想要 在顧客選店後將選店結果回傳商家
  以便 商家取得 TempLogisticsID 與門市資訊以成立物流單

  # ILogisticsProvider::parse_store_selection() — 統一抽象選店回呼解析。
  # ECPay：顧客在 RWD 頁選店後，綠界以瀏覽器 Form POST 至 ClientReplyURL，body 含 ResultData（AES 加密），解密得 TempLogisticsID 與門市資訊。
  # PAYUNi 未來對應：ship_map callback 回 MapJson（StoreID/StoreName/Address），共用同一 interface 方法解析為統一 StoreSelectionResult。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定 account_type 為 "b2c"，b2c_merchant_id 為 2000132，b2c_hash_key 為 5294y06JbISpM5x9，b2c_hash_iv 為 v77hoKGq4kWxNNIS

  規則: 前置（參數）- ClientReplyURL 回呼必須含 ResultData

    場景: ResultData 為空時回呼處理失敗
      假設 系統中有訂單 #100
      當 綠界以空 ResultData POST 至 client_reply_url
      那麼 操作失敗，錯誤為 "選店結果為空"

  規則: 前置（參數）- ResultData 必須能以全方位物流 HashKey/HashIV 解密

    場景: ResultData 無法解密時回呼處理失敗
      假設 系統中有訂單 #100
      而且 ResultData 以錯誤的 HashKey 加密
      當 綠界 POST ResultData 至 client_reply_url
      那麼 操作失敗，錯誤為 "選店結果解密失敗"

  規則: 後置（狀態）- 解析成功後暫存 TempLogisticsID 與門市資訊於訂單

    場景: 超商選店成功後訂單保存暫存物流與門市資訊
      假設 系統中有訂單 #100
      而且 ResultData 解密後內容如下：
        | 欄位             | 值           |
        | TempLogisticsID  | 2264         |
        | CVSStoreID       | 991182       |
        | CVSStoreName     | 全家測試門市 |
        | CVSAddress       | 台北市中山區測試路1號 |
      當 綠界 POST ResultData 至 client_reply_url
      那麼 操作成功
      而且 訂單 #100 的 _pc_logistics_temp_id 為 "2264"
      而且 訂單 #100 的 _pc_logistics_store_id 為 "991182"
      而且 訂單 #100 的 _pc_logistics_store_name 為 "全家測試門市"
      而且 訂單 #100 的 _pc_logistics_store_addr 為 "台北市中山區測試路1號"
</content>
