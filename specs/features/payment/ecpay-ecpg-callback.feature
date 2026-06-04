# language: zh-TW
@ignore @command
功能: 綠界 ECPay ECPG 付款結果通知（ReturnURL 幕後）
  作為 綠界 ECPay 系統
  我想要 在站內付付款完成後幕後通知商家
  以便 商家更新訂單狀態並回應 1|OK

  背景:
    假設 "ecpay_ecpg" 已啟用
    而且 系統中有以下訂單：
      | orderId | total | status  | payment_method | trade_no    |
      | 200     | 1000  | pending | ecpay_ecpg     | EG200ABCDEF |
    而且 綠界 ECPG ReturnURL 端點為 POST /wp-json/power-checkout/ecpay/ecpg/return（JSON POST）

  規則: 前置（參數）- 必須能以 AES-128-CBC 解密 Data 為合法 JSON

    場景: Data 解密失敗時拒絕處理
      當 綠界發送 ECPG ReturnURL 通知，Data 無法以 AES-128-CBC 正確解密
      那麼 訂單 #200 狀態維持 "pending"
      而且 不更新付款明細

  規則: 前置（參數）- 必須雙層錯誤檢查（TransCode 傳輸層 → RtnCode 業務層）

    場景: TransCode 非 1 時視為失敗
      當 綠界發送 ECPG ReturnURL 通知，TransCode 為 0
      那麼 訂單 #200 狀態維持 "pending"
      而且 訂單 #200 有 order note 記錄傳輸層失敗

  規則: 前置（參數）- 必須冪等處理（以 MerchantTradeNo 為 key）

    場景: 重複通知同一 MerchantTradeNo 時不重複處理
      假設 訂單 #200 已因 MerchantTradeNo "EG200ABCDEF" 轉為 "processing"
      當 綠界再次發送 ECPG ReturnURL 通知，MerchantTradeNo 為 "EG200ABCDEF"
      那麼 訂單 #200 狀態維持 "processing"
      而且 回應純文字 "1|OK"

  規則: 後置（狀態）- TransCode 與 RtnCode 皆為 1 時訂單轉「處理中」

    場景: 付款成功通知
      當 綠界發送 ECPG ReturnURL 通知，TransCode 為 1 且 RtnCode 為 1，MerchantTradeNo 為 "EG200ABCDEF"
      那麼 訂單 #200 狀態為 "processing"
      而且 訂單 #200 的 _pc_ecpay_payment_detail 有值

  規則: 後置（狀態）- 業務層失敗（RtnCode 非 1）時維持「等待付款」

    場景: 信用卡授權失敗通知
      當 綠界發送 ECPG ReturnURL 通知，TransCode 為 1 但 RtnCode 為 10100050，MerchantTradeNo 為 "EG200ABCDEF"
      那麼 訂單 #200 狀態維持 "pending"
      而且 訂單 #200 有 order note 記錄失敗 RtnCode 與 RtnMsg

  規則: 後置（回應）- 必須回應純文字 "1|OK" 且 HTTP 200

    場景: 處理完成後回應綠界
      當 綠界發送任一 ECPG ReturnURL 通知
      那麼 回應狀態碼為 200
      而且 回應 body 為純文字 "1|OK"
