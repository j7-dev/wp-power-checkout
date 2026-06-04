# language: zh-TW
@ignore @command
功能: 綠界全方位物流 v2 成立正式物流單（階段 B）
  作為 網站管理員
  我想要 憑暫存物流單成立正式物流單
  以便 取得物流單號並出貨

  # ILogisticsProvider::create_shipment() — 統一抽象階段 B。
  # ECPay 對應 CreateByTempTrade（憑 TempLogisticsID，極少欄位，收件人已存暫存單）。
  # PAYUNi 未來對應 trade（商店組完整收件人 + 門市，一次建單），共用同一 interface 方法。
  # 觸發時機：後台管理員於訂單頁手動成立（非付款成功自動）。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定 account_type 為 "b2c"，b2c_merchant_id 為 2000132，b2c_hash_key 為 5294y06JbISpM5x9，b2c_hash_iv 為 v77hoKGq4kWxNNIS，mode 為 test
    而且 管理員已登入並取得 Nonce

  規則: 前置（狀態）- 訂單必須已有暫存物流單 TempLogisticsID

    場景: 訂單無 TempLogisticsID 時成立物流單失敗
      假設 系統中有訂單 #100，無 _pc_logistics_temp_id
      當 管理員呼叫 create_shipment(100)
      那麼 操作失敗，錯誤為 "尚未選店，無暫存物流單"

  規則: 前置（參數）- CreateByTempTrade 請求帶 TempLogisticsID 與 RqHeader Revision

    場景: 成立物流單請求組裝
      假設 系統中有訂單 #100，_pc_logistics_temp_id 為 "2264"
      當 管理員呼叫 create_shipment(100)
      那麼 CreateByTempTrade 請求 Data 的 TempLogisticsID 為 "2264"
      而且 RqHeader 的 Revision 為 "1.0.0"
      而且 RqHeader 的 Timestamp 為即時 time() 產生

  規則: 前置（回應）- 外層 TransCode 必須為整數 1（傳輸層檢查）

    場景: 外層 TransCode 非 1 時成立失敗
      假設 系統中有訂單 #100，_pc_logistics_temp_id 為 "2264"
      當 綠界回應 TransCode 為 0
      那麼 操作失敗，錯誤為 "傳輸層錯誤（TransCode）"

  規則: 前置（回應）- 解密後內層 RtnCode 必須為整數 1（業務層檢查）

    場景: 內層 RtnCode 非 1 時成立失敗
      假設 系統中有訂單 #100，_pc_logistics_temp_id 為 "2264"
      當 綠界回應 TransCode 為 1 但解密後 RtnCode 為整數 0
      那麼 操作失敗，錯誤為 "業務層錯誤（RtnCode）"

  規則: 後置（狀態）- 成立成功後保存統一物流單號 LogisticsID

    場景: 成立物流單成功後訂單保存 LogisticsID
      假設 系統中有訂單 #100，_pc_logistics_temp_id 為 "2264"
      當 管理員呼叫 create_shipment(100)
      而且 綠界回應 TransCode 為 1，解密後 RtnCode 為整數 1，LogisticsID 為 "1234567890"
      那麼 操作成功
      而且 訂單 #100 的 _pc_logistics_ref 為 "1234567890"
      而且 訂單 #100 有 order note 記錄物流單成立

  規則: 後置（狀態）- C2C 成立後額外保存寄貨編號與驗證碼

    場景: C2C 訂單成立物流單後保存 CVSPaymentNo 與 CVSValidationNo
      假設 ECPay 物流設定 account_type 為 "c2c"
      而且 系統中有訂單 #200，_pc_logistics_temp_id 為 "3300"
      當 管理員呼叫 create_shipment(200)
      而且 綠界回應 TransCode 為 1，解密後 RtnCode 為整數 1，LogisticsID 為 "9988776655"，CVSPaymentNo 為 "12345678"，CVSValidationNo 為 "9999"
      那麼 操作成功
      而且 訂單 #200 的 _pc_logistics_ref 為 "9988776655"
      而且 訂單 #200 的 _pc_logistics_cvs_payment_no 為 "12345678"
      而且 訂單 #200 的 _pc_logistics_cvs_validation_no 為 "9999"
</content>
