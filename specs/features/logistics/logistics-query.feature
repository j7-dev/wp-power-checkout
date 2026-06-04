# language: zh-TW
@ignore @query
功能: 綠界全方位物流 v2 查詢物流單狀態
  作為 網站管理員
  我想要 查詢物流單的即時狀態
  以便 掌握配送進度

  # ILogisticsProvider::query_shipment() — 統一抽象查詢。
  # ECPay 對應 QueryLogisticsTradeInfo；PAYUNi 未來對應 query（依 LgsType 回傳欄位略異），共用同一 interface 方法。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定 account_type 為 "b2c"，b2c_merchant_id 為 2000132，b2c_hash_key 為 5294y06JbISpM5x9，b2c_hash_iv 為 v77hoKGq4kWxNNIS
    而且 管理員已登入並取得 Nonce

  規則: 前置（狀態）- 訂單必須已有正式物流單號

    場景: 訂單無 LogisticsID 時查詢失敗
      假設 系統中有訂單 #100，無 _pc_logistics_ref
      當 管理員呼叫 query_shipment(100)
      那麼 操作失敗，錯誤為 "尚未成立物流單"

  規則: 後置（回應）- 查詢成功時回傳物流單即時狀態

    場景: 查詢已成立物流單回傳狀態
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"
      當 管理員呼叫 query_shipment(100)
      而且 綠界回應 TransCode 為 1，解密後 RtnCode 為整數 1
      那麼 操作成功
      而且 查詢結果應包含：
        | 欄位          | 說明           |
        | logistics_id  | 物流單號       |
        | status        | 即時貨態       |
        | store_info    | 門市資訊（超商）|
</content>
