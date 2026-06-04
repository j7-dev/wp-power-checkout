# language: zh-TW
@ignore @command
功能: 綠界全方位物流 v2 取消 C2C 物流單
  作為 網站管理員
  我想要 取消尚未寄件的 C2C 物流單
  以便 處理顧客取消或改單

  # ILogisticsProvider::cancel_shipment() — 統一抽象取消。
  # ECPay 對應 CancelC2COrder（僅 C2C 帳號 2000933，需 CVSPaymentNo/CVSValidationNo）。
  # PAYUNi 未來對應對等取消操作（視支援度），共用同一 interface 方法。
  # 僅 C2C 適用；B2C 無此操作。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定 account_type 為 "c2c"，c2c_merchant_id 為 2000933，c2c_hash_key 為 XBERn1YOvpM9nfZc，c2c_hash_iv 為 h1ONHk4P4yqbl5LK
    而且 管理員已登入並取得 Nonce

  規則: 前置（狀態）- account_type 必須為 c2c

    場景: B2C 帳號呼叫取消時失敗
      假設 ECPay 物流設定 account_type 為 "b2c"
      而且 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"
      當 管理員呼叫 cancel_shipment(100)
      那麼 操作失敗，錯誤為 "取消物流單僅支援 C2C 帳號"

  規則: 前置（狀態）- 訂單必須已有 C2C 寄貨編號與驗證碼

    場景: 訂單缺寄貨編號時取消失敗
      假設 系統中有訂單 #200，_pc_logistics_ref 為 "9988776655"，無 _pc_logistics_cvs_payment_no
      當 管理員呼叫 cancel_shipment(200)
      那麼 操作失敗，錯誤為 "缺少 C2C 寄貨編號，無法取消"

  規則: 前置（參數）- CancelC2COrder 請求帶 LogisticsID、CVSPaymentNo、CVSValidationNo

    場景: 取消請求組裝
      假設 系統中有訂單 #200，_pc_logistics_ref 為 "9988776655"，_pc_logistics_cvs_payment_no 為 "12345678"，_pc_logistics_cvs_validation_no 為 "9999"
      當 管理員呼叫 cancel_shipment(200)
      那麼 CancelC2COrder 請求 Data 的 LogisticsID 為 "9988776655"
      而且 CancelC2COrder 請求 Data 的 CVSPaymentNo 為 "12345678"
      而且 CancelC2COrder 請求 Data 的 CVSValidationNo 為 "9999"

  規則: 後置（狀態）- 取消成功後標記訂單物流已取消

    場景: 取消 C2C 物流單成功
      假設 系統中有訂單 #200，_pc_logistics_ref 為 "9988776655"，_pc_logistics_cvs_payment_no 為 "12345678"，_pc_logistics_cvs_validation_no 為 "9999"
      當 管理員呼叫 cancel_shipment(200)
      而且 綠界回應 TransCode 為 1，解密後 RtnCode 為整數 1
      那麼 操作成功
      而且 訂單 #200 的 _pc_logistics_status 為 "cancelled"
      而且 訂單 #200 有 order note 記錄物流單取消
</content>
