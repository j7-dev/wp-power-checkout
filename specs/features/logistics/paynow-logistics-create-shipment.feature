# language: zh-TW
@ignore @command
功能: PayNow 物流成立物流單（階段 B）
  作為 網站管理員
  我想要 成立 PayNow 正式物流單
  以便 取得物流單號並出貨

  # ILogisticsProvider::create_shipment() — 統一抽象階段 B（PayNow 為第三個 provider）。
  # PayNow 對應 POST /api/Orderapi/Add_Order（JsonOrder = base64(TripleDES DES-EDE3 encrypt(JSON))）。
  # 知識來源：依 woomp（class-paynow-shipping-request.php create_order / build_add_order_args / build_encrypted_args）反推。
  #   # CiC(ASM): 依 woomp 反推；端點/欄位/加密有程式碼實證，待 PayNow 官方文件核對。
  # 觸發時機：後台管理員於訂單頁手動成立（對齊既有 ECPay「後台手動」慣例；woomp 另有 order_status_processing 自動取號，本專案不採自動）。
  # 加密：TripleDES DES-EDE3 固定 key/IV NO_PADDING base64（與金流 HMAC / PAYUNi AES-256-GCM 全不同源）。
  # PassCode = strtoupper(sha1(user_account + OrderNo + TotalAmount + apicode))。

  背景:
    假設 "paynow_logistics" 已啟用
    而且 PayNow 物流設定 user_account、apicode、寄件人資訊已填
    而且 管理員已登入並取得 Nonce

  規則: 前置（狀態）- 超商取貨訂單必須已選店（有門市 meta）

    場景: 超商取貨訂單無門市資訊時成立物流單失敗
      假設 系統中有訂單 #100，運送方式為 "SEVEN"，無 _pc_paynow_logistics_store_id
      當 管理員呼叫 create_shipment(100)
      那麼 操作失敗，錯誤為 "尚未選店，無門市資訊"

  規則: 前置（參數）- 建單請求帶 OrderNo、TotalAmount、Logistic_service、DeliverMode 與 PassCode
    # CiC(ASM): build_add_order_args() 欄位：Description / DeliverMode / Logistic_service / user_account / apicode / OrderNo / Receiver_* / Sender_* / receiver_storeid / receiver_storename / PassCode / TotalAmount / EC。

    場景: 7-11 取貨不付款（線上付款）訂單建單請求組裝
      假設 系統中有訂單 #100，total 為 1000，付款方式為 "paynow"，運送方式為 "SEVEN"，已選店
      當 管理員呼叫 create_shipment(100)
      那麼 Add_Order 請求的 Logistic_service 為 "01"
      而且 請求的 DeliverMode 為 "02"
      而且 請求的 OrderNo 為訂單 #100 的訂單編號
      而且 請求的 TotalAmount 為 1000
      而且 請求的 PassCode 為 strtoupper(sha1(user_account + OrderNo + TotalAmount + apicode))
      而且 JsonOrder 為 base64 編碼的 TripleDES 密文

    場景: 取貨付款（COD）訂單帶 DeliverMode 01
      假設 系統中有訂單 #101，total 為 1000，付款方式為 "cod"，運送方式為 "SEVEN"，已選店
      當 管理員呼叫 create_shipment(101)
      那麼 請求的 DeliverMode 為 "01"

    場景: 黑貓宅配（TCAT）建單帶溫層與規格
      # CiC(ASM): woomp TCAT 走 s60 規格，帶 DeliveryType（溫層）+ Weight/Length/Width/Height。
      假設 系統中有訂單 #102，total 為 1000，運送方式為 "TCAT"，溫層為 "冷凍"
      當 管理員呼叫 create_shipment(102)
      那麼 請求帶收件地址（非門市）
      而且 請求的 DeliveryType 為 "0003"

  規則: 前置（參數）- 超取金額上限 20000、宅配金額上限 100000
    # CiC(ASM): woomp 註解「超取金額不得大於 20000，宅配金額不大於 100000」。# CiC(GAP): 超限的明確錯誤行為（前端擋 or API 回錯）待官方文件確認。

    場景: 超商取貨金額超過上限
      假設 系統中有訂單 #103，total 為 25000，運送方式為 "SEVEN"
      當 管理員呼叫 create_shipment(103)
      那麼 操作失敗，錯誤為 "超商取貨金額不得大於 20000"

  規則: 前置（回應）- 回應 Status 必須為 "S"（業務層檢查）

    場景: 回應 Status 為 F 時成立失敗
      假設 系統中有訂單 #100，已選店
      當 PayNow 回應 Status 為 "F"，ErrorMsg 為錯誤訊息
      那麼 操作失敗，錯誤為回應的 ErrorMsg
      而且 訂單 #100 有 order note 記錄建單失敗

  規則: 後置（狀態）- 成立成功後保存物流單號與託運資訊

    場景: 成立物流單成功後訂單保存 LogisticNumber
      假設 系統中有訂單 #100，已選店
      當 管理員呼叫 create_shipment(100)
      而且 PayNow 回應 Status 為 "S"，LogisticNumber、paymentno、validationno 有值
      那麼 操作成功
      而且 訂單 #100 的 _pc_paynow_logistics_ref 為回應的 LogisticNumber
      而且 訂單 #100 的 _pc_paynow_logistics_payment_no 為回應的 paymentno
      而且 訂單 #100 的 _pc_paynow_logistics_validation_no 為回應的 validationno
      而且 訂單 #100 有 order note 記錄物流單成立
      # CiC(GAP): LogisticNumber / paymentno / validationno 具體值待 sandbox 建單實測補。

  規則: 冪等 - 已有物流單且非無效時改走重新取號而非重複建單
    # CiC(ASM): woomp paynow_get_logistic_no()：已有 LogisticNumber 且 Status!=1 → ReNewOrder（/api/Orderapi/ReNewOrder）重新取號；否則 Add_Order。

    場景: 訂單已有有效物流單時呼叫成立物流單改為重新取號
      假設 系統中有訂單 #100，_pc_paynow_logistics_ref 有值，_pc_paynow_logistics_status 非 "1"
      當 管理員再次呼叫 create_shipment(100)
      那麼 系統呼叫 ReNewOrder 重新取號
      而且 訂單 #100 保存重新取號後的 PayNow 訂單編號（RenewOrderNo，列印標籤用）
