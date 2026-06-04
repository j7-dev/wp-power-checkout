# language: zh-TW
@ignore @command
功能: 綠界全方位物流 v2 貨態通知（ServerReplyURL）
  作為 綠界ECPay 第三方系統
  我想要 在物流貨態變化時通知商家
  以便 商家更新訂單出貨狀態

  # ILogisticsProvider::handle_status_callback() — 統一抽象貨態 callback。
  # ECPay：ServerReplyURL 以 JSON body POST（AES-JSON 三層），回應必須是 AES 加密 JSON 三層結構（非 1|OK）。
  # PAYUNi 未來對應：貨態 Notify（4 form 欄位，AES-256-GCM），回應為 HTTP 200 純文字 "OK"。
  # 兩者「回應格式」差異完全收進各 provider，REST 路由層統一。
  # 貨態處理策略：記錄 _pc_logistics_status（綠界 LogisticsStatus 文字）；不直接改 WC 訂單狀態（與既有金流 StatusManager 解耦）。
  #   例外：COD 訂單收到「取件完成」貨態時，額外標記 _pc_logistics_collection_paid=yes 表示取貨付款完成。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定 account_type 為 "b2c"，b2c_merchant_id 為 2000132，b2c_hash_key 為 5294y06JbISpM5x9，b2c_hash_iv 為 v77hoKGq4kWxNNIS
    而且 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"

  規則: 前置（回應）- 外層 TransCode 必須為整數 1（傳輸層檢查）

    場景: TransCode 非 1 時回應錯誤但仍回 AES-JSON
      當 綠界 POST 貨態通知，TransCode 為 0
      那麼 回應為 AES 加密 JSON 三層結構
      而且 回應 Data 解密後 RtnCode 為 0
      而且 不更新訂單出貨狀態

  規則: 前置（狀態）- 必須驗證 MerchantID 為本商店

    場景: MerchantID 不符時拒絕處理
      當 綠界 POST 貨態通知，MerchantID 為 "9999999"
      那麼 不更新訂單出貨狀態
      而且 記錄安全警告 log（遮蔽 HashKey/HashIV）
      而且 回應為 AES 加密 JSON 三層結構

  規則: 前置（狀態）- 必須比對 LogisticsID 與訂單記錄

    場景: LogisticsID 無對應訂單時不處理
      當 綠界 POST 貨態通知，LogisticsID 為 "0000000000"
      那麼 找不到對應訂單
      而且 回應為 AES 加密 JSON 三層結構（避免重送風暴）

  規則: 後置（狀態）- 首次收到該貨態時更新出貨狀態並回 AES-JSON

    場景: 貨態為已出貨時更新出貨狀態
      當 綠界 POST 貨態通知，TransCode 為 1，LogisticsID 為 "1234567890"，解密後 RtnCode 為整數 1，LogisticsStatus 為 "300"
      那麼 操作成功
      而且 訂單 #100 的 _pc_logistics_status 更新為 "300"
      而且 訂單 #100 記錄已處理該貨態（防重）
      而且 回應為 AES 加密 JSON 三層結構，Data 解密後 RtnCode 為 1

  規則: 後置（狀態）- COD 訂單取件完成時標記取貨付款完成

    場景: COD 訂單取件完成貨態時標記已付款
      假設 訂單 #100 付款方式為 "cod"
      當 綠界 POST 貨態通知，TransCode 為 1，LogisticsID 為 "1234567890"，解密後 RtnCode 為整數 1，LogisticsStatus 為 "2067"
      那麼 操作成功
      而且 訂單 #100 的 _pc_logistics_status 更新為 "2067"
      而且 訂單 #100 的 _pc_logistics_collection_paid 為 "yes"
      而且 回應為 AES 加密 JSON 三層結構，Data 解密後 RtnCode 為 1

  規則: 後置（狀態）- 重複收到同一貨態時不重複處理（冪等）

    場景: 重複貨態通知不重複更新
      假設 訂單 #100 已處理過 LogisticsID "1234567890" 的貨態 "300"
      當 綠界再次 POST 相同貨態通知
      那麼 不重複更新訂單
      而且 仍回應 AES 加密 JSON 三層結構，Data 解密後 RtnCode 為 1
</content>
