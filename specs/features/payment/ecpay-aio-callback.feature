# language: zh-TW
@ignore @command
功能: 綠界 ECPay AIO 付款結果通知（ReturnURL 幕後）
  作為 綠界 ECPay 系統
  我想要 在付款完成後幕後通知商家付款結果
  以便 商家更新訂單狀態並回應 1|OK

  背景:
    假設 "ecpay_aio" 已啟用
    而且 系統中有以下訂單：
      | orderId | total | status  | payment_method | trade_no    |
      | 100     | 1000  | pending | ecpay_aio      | EC100ABCDEF |
    而且 綠界 ReturnURL 端點為 POST /wp-json/power-checkout/ecpay/aio/return

  規則: 前置（參數）- 必須通過 CheckMacValue 驗證

    場景: CheckMacValue 不符時拒絕處理
      當 綠界發送 ReturnURL 通知，CheckMacValue 與重新計算結果不符
      那麼 訂單 #100 狀態維持 "pending"
      而且 不更新付款明細

    場景: CheckMacValue 正確時接受處理
      當 綠界發送 ReturnURL 通知，CheckMacValue 正確且 RtnCode 為 "1"
      那麼 系統以 timing-safe 方式比對 CheckMacValue
      而且 通過驗證並繼續處理

  規則: 前置（參數）- 必須冪等處理（以 MerchantTradeNo 為 key）

    場景: 重複通知同一 MerchantTradeNo 時不重複處理
      假設 訂單 #100 已因 MerchantTradeNo "EC100ABCDEF" 轉為 "processing"
      當 綠界再次發送 ReturnURL 通知，MerchantTradeNo 為 "EC100ABCDEF"
      那麼 訂單 #100 狀態維持 "processing"
      而且 回應純文字 "1|OK"

  規則: 後置（狀態）- RtnCode 為 "1" 時訂單轉「處理中」

    場景: 付款成功通知
      當 綠界發送 ReturnURL 通知，RtnCode 為 "1"，MerchantTradeNo 為 "EC100ABCDEF"
      那麼 訂單 #100 狀態為 "processing"
      而且 訂單 #100 的 _pc_ecpay_payment_detail 有值
      而且 訂單 #100 有 order note 包含付款明細

  規則: 後置（狀態）- RtnCode 非 "1" 且非取號成功碼時維持「等待付款」

    場景: 付款失敗通知
      當 綠界發送 ReturnURL 通知，RtnCode 為 "10100050"，MerchantTradeNo 為 "EC100ABCDEF"
      那麼 訂單 #100 狀態維持 "pending"
      而且 訂單 #100 有 order note 記錄失敗 RtnCode 與 RtnMsg

  規則: 後置（回應）- 必須回應純文字 "1|OK" 且 HTTP 200

    場景: 處理完成後回應綠界
      當 綠界發送任一 ReturnURL 通知
      那麼 回應狀態碼為 200
      而且 回應 body 為純文字 "1|OK"
