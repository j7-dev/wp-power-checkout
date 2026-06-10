# language: zh-TW
@ignore @query
功能: PayNow 物流查詢物流單狀態
  作為 網站管理員
  我想要 查詢 PayNow 物流單即時貨態
  以便 掌握配送進度並補單

  # ILogisticsProvider::query_shipment() — 統一抽象（PayNow 為第三個 provider）。
  # PayNow 對應 GET /api/Orderapi/Get_Order_Info?LogisticNumber=&sno=（與 ECPay QueryLogisticsTradeInfo / PAYUNi ship query 平行）。
  # 知識來源：依 woomp（class-paynow-shipping-request.php query_order / update_order_logistic_meta）反推。
  #   # CiC(ASM): 依 woomp 反推；端點/回應欄位有實證，待 PayNow 官方文件核對。
  # ⚠️ 貨態通知機制（R1 實證更正）：PayNow 物流**有**主動貨態推送（woomp class-paynow-shipping-response.php L34 實證），
  #   推送至 POST /wp-json/power-checkout/paynow/logistics/status-callback，payload 含 orderno/PayNowLogisticCode/Detail_Status_Description/paymentno；
  #   本查詢方法（query_shipment / Get_Order_Info）為**補單手段**，與貨態推送並存；
  #   handle_status_callback() 已實作為「解析推送 payload → orderno 反查 → 冪等 → 更新 meta」，非退化為查詢。

  背景:
    假設 "paynow_logistics" 已啟用
    而且 管理員已登入並取得 Nonce
    而且 系統中有訂單 #100，_pc_paynow_logistics_ref 有值

  規則: 前置（狀態）- 訂單必須已有物流單號

    場景: 訂單無物流單號時查詢失敗
      假設 系統中有訂單 #200，無 _pc_paynow_logistics_ref
      當 管理員呼叫 query_shipment(200)
      那麼 操作失敗，錯誤為 "尚無物流單，無法查詢"

  規則: 前置（參數）- 查詢請求帶 LogisticNumber 與 sno

    場景: 查詢請求組裝
      當 管理員呼叫 query_shipment(100)
      那麼 Get_Order_Info 請求的 LogisticNumber 為訂單 #100 的 _pc_paynow_logistics_ref
      而且 請求的 sno 為 "1"

  規則: 後置（回應）- 查詢成功回傳貨態並寫回 order meta

    場景: 查詢物流單狀態並更新訂單
      當 管理員呼叫 query_shipment(100)
      而且 PayNow 回應 Status、Delivery_Status、PayNowLogisticCode、Detail_Status_Description
      那麼 操作成功
      而且 訂單 #100 的 _pc_paynow_logistics_status 為回應的 Status
      而且 訂單 #100 記錄 Delivery_Status 與 PayNowLogisticCode
      而且 訂單 #100 記錄狀態更新時間
      # CiC(GAP): Status（0 成立中 / 1 無效）與 Delivery_Status / PayNowLogisticCode 具體貨態碼對照待 sandbox + 官方文件補。

  規則: 後置（狀態）- 貨態為「已取貨完成」且為 COD 訂單時標記取貨付款完成
    # CiC(BDY): 對齊 ECPay COD 取貨完成 → _pc_logistics_collection_paid=yes 慣例；PayNow 對應貨態碼待官方文件確認。# CiC(GAP): 「已取貨完成」對應的 PayNowLogisticCode 待補。

    場景: COD 訂單取貨完成後標記代收貨款已收
      假設 系統中有訂單 #100，付款方式為 "cod"
      當 query_shipment(100) 回傳貨態為「已取貨完成」
      那麼 訂單 #100 標記取貨付款完成
