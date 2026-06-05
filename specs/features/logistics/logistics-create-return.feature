# language: zh-TW
@ignore @command
功能: 綠界全方位物流 v2 建立逆物流退貨單
  作為 網站管理員
  我想要 對已成立物流單的訂單建立退貨（逆物流）單
  以便 處理顧客退貨並追蹤退貨貨態

  # ILogisticsProvider::create_return() — 統一抽象退貨（逆物流）。
  # ECPay 依「原物流子類型」分派四個逆物流端點（guide 07 §B2C 退貨 / 宅配退貨）：
  #   FAMI    → /Express/v2/ReturnCVS         （全家逆物流）
  #   UNIMART → /Express/v2/ReturnUniMartCVS  （統一超商逆物流）
  #   HILIFE  → /Express/v2/ReturnHilifeCVS   （萊爾富逆物流）
  #   HOME    → /Express/v2/ReturnHome        （宅配逆物流，黑貓 TCAT）
  # 超商退貨 Data：MerchantID / LogisticsID / GoodsAmount(1~20000) / ServiceType="4"（退貨不付款）/
  #   SenderName / [SenderPhone] / ServerReplyURL。
  # 宅配退貨 Data：MerchantID / LogisticsID / GoodsAmount / Temperature(0001/0002/0003) /
  #   Distance(00 同縣市 / 01 外縣市 / 02 離島) / Specification(0001 60cm / 0002 90cm / 0003 120cm / 0004 150cm) /
  #   ServerReplyURL。
  # ServerReplyURL 指向既有貨態 callback（status-callback）；逆物流貨態沿用同一 AES-JSON 三層通知處理。
  # 回傳的逆物流單號 ReturnLogisticsID 寫入 _pc_logistics_return_ref（與正向 _pc_logistics_ref 區隔）。
  # 觸發時機：後台管理員於訂單頁手動建立退貨單（非自動）。

  背景:
    假設 "ecpay_logistics" 已啟用
    而且 ECPay 物流設定 account_type 為 "b2c"，b2c_merchant_id 為 2000132，b2c_hash_key 為 5294y06JbISpM5x9，b2c_hash_iv 為 v77hoKGq4kWxNNIS，mode 為 test
    而且 管理員已登入並取得 Nonce

  規則: 前置（狀態）- provider 必須啟用

    場景: provider 未啟用時退貨失敗
      假設 "ecpay_logistics" 未啟用
      而且 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"
      當 管理員呼叫 create_return(100)
      那麼 操作失敗，錯誤為 "綠界全方位物流未啟用"

  規則: 前置（狀態）- 訂單必須已成立正向物流單（有 _pc_logistics_ref）

    場景: 訂單無正向物流單時退貨失敗
      假設 系統中有訂單 #100，無 _pc_logistics_ref
      當 管理員呼叫 create_return(100)
      那麼 操作失敗，錯誤為 "尚未成立物流單，無法退貨"

  規則: 前置（狀態）- reply URL 必須公開可訪問（非 localhost，R6）

    場景: server_reply_url 為 localhost 時退貨失敗
      假設 ECPay 物流設定 server_reply_url 為 "http://localhost/status-callback"
      而且 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"
      當 管理員呼叫 create_return(100)
      那麼 操作失敗，錯誤為 "必須為公開可訪問的 URL"

  規則: 前置（參數）- 退貨端點依原物流子類型分派

    場景大綱: 超商退貨依子類型呼叫對應端點
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"，_pc_logistics_sub_type 為 "<子類型>"
      當 管理員呼叫 create_return(100)
      那麼 退貨請求送往端點 "<端點>"
      而且 退貨請求 Data 的 LogisticsID 為 "1234567890"
      而且 退貨請求 Data 的 ServiceType 為 "4"
      而且 退貨請求 Data 的 ServerReplyURL 指向貨態 callback
      而且 RqHeader 的 Revision 為 "1.0.0"

      例子:
        | 子類型   | 端點                            |
        | FAMI    | /Express/v2/ReturnCVS          |
        | UNIMART | /Express/v2/ReturnUniMartCVS   |
        | HILIFE  | /Express/v2/ReturnHilifeCVS    |

  規則: 前置（參數）- 宅配退貨帶 Temperature / Distance / Specification

    場景: 宅配退貨組裝溫層 / 距離 / 規格
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"，_pc_logistics_sub_type 為 "HOME"
      而且 訂單 #100 的 _pc_logistics_temperature 為 "0003"
      當 管理員呼叫 create_return(100)
      那麼 退貨請求送往端點 "/Express/v2/ReturnHome"
      而且 退貨請求 Data 的 Temperature 為 "0003"
      而且 退貨請求 Data 含 Distance 欄位
      而且 退貨請求 Data 含 Specification 欄位

  規則: 後置（狀態）- 退貨成功後保存逆物流單號

    場景: 超商退貨成功後保存 ReturnLogisticsID
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"，_pc_logistics_sub_type 為 "FAMI"
      當 管理員呼叫 create_return(100)
      而且 綠界回應 TransCode 為 1，解密後 RtnCode 為整數 1，回傳 ReturnLogisticsID
      那麼 操作成功
      而且 訂單 #100 的 _pc_logistics_return_ref 不為空
      而且 訂單 #100 有 order note 記錄退貨單成立

    場景: 宅配退貨成功後保存 ReturnLogisticsID
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"，_pc_logistics_sub_type 為 "HOME"
      當 管理員呼叫 create_return(100)
      而且 綠界回應 TransCode 為 1，解密後 RtnCode 為整數 1，回傳 ReturnLogisticsID
      那麼 操作成功
      而且 訂單 #100 的 _pc_logistics_return_ref 不為空

  規則: 後置（貨態）- 逆物流貨態沿用既有 status-callback

    場景: 逆物流貨態通知以 ReturnLogisticsID 反查訂單
      假設 系統中有訂單 #100，_pc_logistics_return_ref 為 "RET1234567890"
      當 綠界 POST 逆物流貨態通知，LogisticsID 為 "RET1234567890"，解密後 RtnCode 為整數 1，LogisticsStatus 為 "300"
      那麼 操作成功
      而且 訂單 #100 的 _pc_logistics_status 更新為 "300"
      而且 回應為 AES 加密 JSON 三層結構，Data 解密後 RtnCode 為 1

  規則: REST 端點（後台手動觸發）- 前置驗證對應 4xx

    場景: 退貨端點 provider 未啟用回 403
      假設 "ecpay_logistics" 未啟用
      而且 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"
      當 管理員 POST /logistics/100/return
      那麼 HTTP 狀態碼為 403

    場景: 退貨端點訂單不存在回 404
      當 管理員 POST /logistics/999999/return
      那麼 HTTP 狀態碼為 404

    場景: 退貨端點無正向物流單回 403
      假設 系統中有訂單 #100，無 _pc_logistics_ref
      當 管理員 POST /logistics/100/return
      那麼 HTTP 狀態碼為 403

    場景: 退貨端點成功回傳逆物流單號
      假設 系統中有訂單 #100，_pc_logistics_ref 為 "1234567890"，_pc_logistics_sub_type 為 "FAMI"
      當 管理員 POST /logistics/100/return
      那麼 HTTP 狀態碼為 200
      而且 回應 data 含 return_logistics_id
