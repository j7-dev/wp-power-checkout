# language: zh-TW
@ignore @command
功能: PayNow 物流列印託運單
  作為 網站管理員
  我想要 列印 PayNow 物流託運單
  以便 出貨時黏貼標籤

  # ILogisticsProvider::print_document() — 統一抽象（PayNow 為第三個 provider）。
  # PayNow 對應 per-service 列印端點（與 ECPay PrintTradeDocument 平行）。
  # 知識來源：依 woomp（class-paynow-shipping-request.php paynow_print_label）反推。
  #   # CiC(ASM): 依 woomp 反推；端點分流有實證，待 PayNow 官方文件核對。

  背景:
    假設 "paynow_logistics" 已啟用
    而且 管理員已登入並取得 Nonce
    而且 系統中有訂單 #100，_pc_paynow_logistics_ref 有值

  規則: 前置（狀態）- 訂單必須已有物流單號

    場景: 訂單無物流單號時列印失敗
      假設 系統中有訂單 #200，無 _pc_paynow_logistics_ref
      當 管理員呼叫 print_document(200)
      那麼 操作失敗，錯誤為 "尚無物流單，無法列印"

  規則: 前置（參數）- 列印端點依物流服務分流
    # CiC(ASM): woomp paynow_print_label() 依 service 分流：7-11=/api/Order711、全家 C2C=/api/OrderFamiC2C、HiLife=/api/OrderHiLife、
    #   黑貓=/Member/Order/PrintBlackCatLabel、大宗/冷凍各有 Print*Label 端點；大宗/冷凍/黑貓走 POST body LogisticNumbers，回 PDF。

    場景: 7-11 店到店列印走 Order711 端點
      假設 訂單 #100 運送方式為 "SEVEN"
      當 管理員呼叫 print_document(100)
      那麼 列印請求指向 7-11 列印端點（/api/Order711）

    場景: 黑貓宅配列印走 PrintBlackCatLabel 端點回 PDF
      假設 訂單 #100 運送方式為 "TCAT"
      當 管理員呼叫 print_document(100)
      那麼 列印請求指向黑貓列印端點（/Member/Order/PrintBlackCatLabel）
      而且 回應為 PDF 文件

  規則: 後置（回應）- 列印成功回傳標籤（URL 或 PDF）

    場景: 超商列印成功回傳標籤連結
      當 管理員呼叫 print_document(100)
      而且 PayNow 回應 Status 為 "S"
      那麼 操作成功
      而且 回傳標籤檔案連結供管理員下載或開啟
      # CiC(GAP): 標籤回應格式（S_+URL vs 直接 PDF）依 service 不同，具體值待 sandbox 實測補。

  規則: 後置（回應）- 重新取號後須以 RenewOrderNo 為列印訂單編號
    # CiC(ASM): woomp：重新取號（ReNewOrder）後列印標籤須以 RenewOrderNo（paynoworderno）為訂單編號，非原始訂單編號。

    場景: 已重新取號訂單列印以 RenewOrderNo 為訂單編號
      假設 訂單 #100 有 RenewOrderNo
      當 管理員呼叫 print_document(100)
      那麼 列印請求以 RenewOrderNo 為訂單編號
