# language: zh-TW
@ignore @command
功能: PayNow 立吉富結帳建立付款意圖（體系 1 PaymentIntent）
  作為 網站訪客
  我想要 在商家頁面內嵌完成 PayNow 付款
  以便 不跳轉即完成結帳

  # 範本：對齊既有內嵌式 payuni-uni-embed-checkout / ecpay-ecpg-checkout（before_process_payment 取 secret → 回 order-received → 前端 SDK 收卡）。
  # ⚠️ 與 UNi Embed 差異：PayNow 體系1 SDK checkout() 直接授權，無 create-payment 中間步驟（少一支 FrontendApi）。
  # 端點 POST /api/v1/payment-intents（Bearer PrivateKey）；建立後回 result.id(pp_xxx) + result.secret(pp_xxx_st_xxx)，狀態 draft。
  # 付款方式範圍（Q1 全部）：CreditCard / CreditCardInstallment / ATM / ConvenienceStore(ibon,FamiPort) / LINEPayOnline / LINEPayOffline / ApplePay（排除 ApplePayDeferred 不可與其他併用）。
  # 憑證為 GAP（Q5）：使用者未提供 sandbox PublicKey/PrivateKey，實作以 API_MODE=mock 為準，憑證到位後補端到端。存 woocommerce_paynow_settings 不寫死。
  # 具體 Example 資料（secret 實際值、各付款方式 SDK 行為）待 sandbox 驗證階段補充（Phase 03 BDD Analysis）。

  規則: 前置（狀態）- 訂單必須存在

  規則: 前置（狀態）- Gateway 必須啟用且訂單幣別為 TWD

  規則: 前置（參數）- 建立付款意圖須帶 amount / currency=TWD / allowedPaymentMethods / webhookUrl，並以 Bearer PrivateKey 認證

  規則: 前置（參數）- allowedPaymentMethods 僅限本次裁決範圍（信用卡/信用卡分期/ATM/超商代碼/LINE Pay/Apple Pay），不得含 ApplePayDeferred

  規則: 前置（參數）- 信用卡分期時 allowInstallments 須為合法期數（3/6/9/12/18/24）

  規則: 後置（回應）- 建立付款意圖回傳的 meta.allowInstallments 列出各期數費率（費率由 PayNow 動態提供，規格不寫死）

  規則: 前置（狀態）- 建立付款意圖前寫入冪等鍵 _pc_paynow_trade_no（格式 PCN{order_id}）

  規則: 成功時取得 result.id 與 result.secret 並回傳 order-received URL 供前端 SDK 收單

  規則: 後置（狀態）- 取得的 payment_intent id 寫入 _pc_paynow_payment_intent_id、secret 寫入 _pc_paynow_secret（供前端 SDK 與 Webhook 反查）

  規則: 後置（狀態）- 建立付款意圖失敗（API 回非 success）時記錄 order note，訂單維持「等待付款」不轉狀態

  # --- 具體範例（paynow skill payment-rest-api + PayNow sandbox）---
  # 端點 POST /api/v1/payment-intents；回應外層 { status:200, type:"success", result:{ id, secret, status:"draft", ... } }。
  # ⚠️ sandbox 憑證未到位（Q5 GAP）——以下 Example 以 mock 回應驗證後端組裝與寫 meta 邏輯，secret 實際值待 sandbox。

  場景: 成功建立付款意圖並回傳 order-received URL
    假設 PayNow gateway 已啟用且使用 sandbox 環境
    並且 存在一筆付款方式為 paynow 的訂單 #100，金額 1000 元，幣別 TWD
    當 顧客送出結帳，後端以 allowedPaymentMethods 含信用卡呼叫建立付款意圖
    那麼 PayNow 回應外層 type 為 "success"，result.status 為 "draft"
    並且 取得 result.id（pp_xxx）與 result.secret（pp_xxx_st_xxx）
    並且 id 寫入訂單 meta _pc_paynow_payment_intent_id、secret 寫入 _pc_paynow_secret
    並且 冪等鍵 _pc_paynow_trade_no 為 "PCN100"
    並且 後端回傳 order-received URL 供前端 Component SDK 收單

  場景: 非 TWD 幣別時拒絕建立付款意圖
    假設 PayNow gateway 已啟用
    並且 存在一筆付款方式為 paynow 的訂單 #101，幣別為 USD
    當 顧客送出結帳
    那麼 後端拒絕並不呼叫建立付款意圖
    並且 提示僅支援新台幣（TWD）

  場景: 建立付款意圖失敗時維持等待付款並記錄 order note
    假設 PayNow gateway 已啟用
    並且 存在一筆付款方式為 paynow 的訂單 #102
    當 後端呼叫建立付款意圖但 PayNow 回應非 success
    那麼 訂單記錄 order note 說明建立付款意圖失敗
    並且 訂單狀態維持「等待付款」不轉狀態
</content>
