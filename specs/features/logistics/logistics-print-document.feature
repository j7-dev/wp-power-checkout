# language: zh-TW
@ignore @command
功能: 綠界全方位物流 v2 列印託運單
  作為 網站管理員
  我想要 列印物流託運單
  以便 出貨時貼附

  # ILogisticsProvider::print_document() — 統一抽象列印，回 HTML/PDF。
  # ECPay 對應 PrintTradeDocument（回 HTML，LogisticsID 可多筆，需 LogisticsSubType）。
  # PAYUNi 未來對應 print_label（超商）/ get_obt_number_pdf（宅配 PDF），共用同一 interface 方法。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定 account_type 為 "b2c"，b2c_merchant_id 為 2000132，b2c_hash_key 為 5294y06JbISpM5x9，b2c_hash_iv 為 v77hoKGq4kWxNNIS
    而且 管理員已登入並取得 Nonce

  規則: 前置（狀態）- 訂單必須已有正式物流單號

    場景: 訂單無 LogisticsID 時列印失敗
      假設 系統中有訂單 #100，無 _pc_logistics_ref
      當 管理員呼叫 print_document(100)
      那麼 操作失敗，錯誤為 "尚未成立物流單"

  規則: 前置（參數）- 列印請求帶 LogisticsID 與物流子類型

    場景: 列印請求組裝物流子類型
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"，物流子類型為 "FAMI"
      當 管理員呼叫 print_document(100)
      那麼 PrintTradeDocument 請求 Data 的 LogisticsID 包含 "1234567890"
      而且 PrintTradeDocument 請求 Data 的 LogisticsSubType 為 "FAMI"

  規則: 後置（回應）- 列印成功時回傳託運單 HTML

    場景: 列印已成立物流單回傳託運單頁
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"，物流子類型為 "FAMI"
      當 管理員呼叫 print_document(100)
      那麼 操作成功
      而且 回傳託運單 HTML（PrintTradeDocument）
</content>
