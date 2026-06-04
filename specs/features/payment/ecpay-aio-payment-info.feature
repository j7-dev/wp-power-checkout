# language: zh-TW
@ignore @command
功能: 綠界 ECPay AIO 取號通知（PaymentInfoURL，ATM/CVS/BARCODE）
  作為 綠界 ECPay 系統
  我想要 在 ATM/CVS/BARCODE 取號完成後通知商家繳費資訊
  以便 商家保存虛擬帳號/超商代碼並顯示給顧客

  背景:
    假設 "ecpay_aio" 已啟用
    而且 系統中有以下訂單：
      | orderId | total | status  | payment_method | trade_no    |
      | 100     | 1000  | pending | ecpay_aio      | EC100ABCDEF |
    而且 綠界 PaymentInfoURL 端點為 POST /wp-json/power-checkout/ecpay/aio/payment-info

  規則: 前置（參數）- 必須通過 CheckMacValue 驗證

    場景: CheckMacValue 不符時拒絕處理
      當 綠界發送 PaymentInfoURL 通知，CheckMacValue 與重新計算結果不符
      那麼 不更新取號資訊
      而且 訂單 #100 狀態維持 "pending"

  規則: 後置（狀態）- ATM 取號成功（RtnCode "2"）時保存繳費資訊且訂單維持「等待付款」

    場景: ATM 取號成功
      當 綠界發送 PaymentInfoURL 通知，RtnCode 為 "2"，PaymentType 為 "ATM_TAISHIN"，含 BankCode "812"、vAccount "9103522175887271"、ExpireDate "2026/06/10"
      那麼 訂單 #100 狀態維持 "pending"
      而且 訂單 #100 的 _pc_ecpay_payment_info 包含 BankCode 為 "812"
      而且 訂單 #100 的 _pc_ecpay_payment_info 包含 vAccount 為 "9103522175887271"
      而且 訂單 #100 的 _pc_ecpay_payment_info 包含 ExpireDate 為 "2026/06/10"

  規則: 後置（狀態）- CVS/BARCODE 取號成功（RtnCode "10100073"）時保存繳費資訊且訂單維持「等待付款」

    場景: CVS 超商代碼取號成功
      當 綠界發送 PaymentInfoURL 通知，RtnCode 為 "10100073"，PaymentType 為 "CVS_CVS"，含 PaymentNo "LLL22247310"、ExpireDate "2026/06/10 23:59:59"
      那麼 訂單 #100 狀態維持 "pending"
      而且 訂單 #100 的 _pc_ecpay_payment_info 包含 PaymentNo 為 "LLL22247310"

  規則: 後置（狀態）- 取號資訊顯示給顧客

    場景: order-received 頁顯示繳費資訊
      假設 訂單 #100 的 _pc_ecpay_payment_info 已保存 ATM 繳費資訊
      當 顧客檢視 order-received 頁面
      那麼 頁面顯示銀行代碼、虛擬帳號與繳費期限

  規則: 後置（回應）- 必須回應純文字 "1|OK" 且 HTTP 200

    場景: 處理完成後回應綠界
      當 綠界發送任一 PaymentInfoURL 通知
      那麼 回應狀態碼為 200
      而且 回應 body 為純文字 "1|OK"
