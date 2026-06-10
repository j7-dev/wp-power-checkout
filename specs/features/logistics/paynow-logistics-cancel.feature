# language: zh-TW
@ignore @command
功能: PayNow 物流取消物流單
  作為 網站管理員
  我想要 取消 PayNow 物流單
  以便 訂單取消或重選門市時作廢物流單

  # ILogisticsProvider::cancel_shipment() — 統一抽象（PayNow 為第三個 provider）。
  # PayNow 對應 DELETE /api/Orderapi/CancelOrder（與 ECPay CancelC2COrder 平行）。
  # 知識來源：依 woomp（class-paynow-shipping-request.php cancel_order）反推。
  #   # CiC(ASM): 依 woomp 反推；端點/欄位有實證，待 PayNow 官方文件核對。
  # ⚠️ 與 ECPay（僅 C2C 帳號可取消）不同：PayNow CancelOrder 不限帳號類型（依 woomp 實作，所有 service 皆可取消）。

  背景:
    假設 "paynow_logistics" 已啟用
    而且 管理員已登入並取得 Nonce
    而且 系統中有訂單 #100，_pc_paynow_logistics_ref 有值

  規則: 前置（狀態）- 訂單必須已有物流單號

    場景: 訂單無物流單號時取消失敗
      假設 系統中有訂單 #200，無 _pc_paynow_logistics_ref
      當 管理員呼叫 cancel_shipment(200)
      那麼 操作失敗，錯誤為 "尚無物流單，無法取消"

  規則: 前置（參數）- 取消請求帶 LogisticNumber、sno 與 PassCode

    場景: 取消請求組裝
      當 管理員呼叫 cancel_shipment(100)
      那麼 CancelOrder 請求以 DELETE 方法送出
      而且 請求的 LogisticNumber 為訂單 #100 的 _pc_paynow_logistics_ref
      而且 請求的 sno 為 "1"
      而且 請求帶 PassCode

  規則: 後置（狀態）- 取消成功後標記物流單為無效
    # CiC(ASM): woomp：回應字串含 'S' 視為取消成功 → Status=1（無效訂單）。

    場景: 取消物流單成功後訂單標記無效
      當 管理員呼叫 cancel_shipment(100)
      而且 PayNow 回應含 "S"
      那麼 操作成功
      而且 訂單 #100 的 _pc_paynow_logistics_status 為 "1"
      而且 訂單 #100 有 order note 記錄取消成功

    場景: 取消物流單失敗時保留物流單並提示手動處理
      當 管理員呼叫 cancel_shipment(100)
      而且 PayNow 回應不含 "S"
      那麼 操作失敗
      而且 訂單 #100 有 order note 提示請手動取消

  規則: 逆物流（退貨）尚未實作
    # CiC(GAP): woomp 無 PayNow 逆物流 API 證據 → ILogisticsProvider::create_return() throw \Exception('尚未實作')（既有慣例）。待 PayNow 官方文件確認是否有退貨 API。

    場景: 建立退貨單時拋出尚未實作
      當 管理員呼叫 create_return(100)
      那麼 拋出例外 "尚未實作"
