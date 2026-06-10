# language: zh-TW
@ignore @command
功能: PayNow 立吉富離線付款待繳資訊（ATM / 超商代碼）
  作為 PayNow 第三方系統
  我想要 在顧客選擇離線付款方式時提供繳款資訊並於繳費後通知商家
  以便 WooCommerce 顯示繳款資訊並在繳費完成後更新訂單

  # 範本：對齊既有 ecpay-aio-payment-info / payuni-upp-payment-info（離線付款先取號待繳 → 繳費後 Webhook 補 Success）。
  # 離線付款方式（Q1 含）：ATM 虛擬帳號、超商代碼（ibon / FamiPort）。繳款資訊由 PayNow SDK / Webhook 提供。
  # ⚠️ PayNow 體系1 離線付款的繳款資訊（vAccount / 繳費代碼 / 條碼 / ExpireDate）取得方式（SDK 顯示 vs Webhook 攜帶）以 PayNow Component SDK v2 + sandbox 確認（GAP）。
  # 繳費完成後 PayNow 推 Webhook Status=Success，走 paynow-callback.feature 轉處理中。本 feature 聚焦「待繳資訊寫入 + 訂單維持等待付款」。
  # 具體 Example（vAccount / 繳費代碼實際值）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（狀態）- 訂單付款方式為 paynow 且顧客選擇離線付款（ATM / 超商代碼）

  規則: 前置（參數）- expireDays 須在合法範圍內（ATM / ConvenienceStore 有效），決定繳款期限

  規則: 後置（狀態）- 取得繳款資訊（vAccount / 繳費代碼 / 條碼 / ExpireDate）後寫入 _pc_paynow_payment_info

  規則: 後置（狀態）- 取得繳款資訊時訂單維持「等待付款」（尚未實際繳費）

  規則: 後置（狀態）- 顧客繳費後 PayNow 推 Webhook Status=Success，訂單轉「處理中」（走 paynow-callback）

  規則: 後置（狀態）- 繳款資訊在後台訂單頁與顧客 order-received 頁可見

  # --- 具體範例（paynow skill concepts §6 離線付款 + PayNow sandbox）---
  # ⚠️ sandbox 憑證未到位（Q5 GAP）——具體 vAccount / 繳費代碼 / 條碼值待 sandbox；以下 Example 驗證後端寫 meta 與狀態維持邏輯。

  場景: ATM 虛擬帳號取得待繳資訊並維持等待付款
    假設 PayNow gateway 已啟用
    並且 存在一筆付款方式為 paynow 的訂單 #110，金額 1500 元，顧客選擇 ATM 虛擬帳號
    當 後端取得 ATM 繳款資訊（虛擬帳號 + 繳款期限）
    那麼 繳款資訊寫入 _pc_paynow_payment_info
    並且 訂單維持「等待付款」
    並且 顧客於 order-received 頁可見虛擬帳號與繳款期限

  場景: 超商代碼取得待繳資訊並維持等待付款
    假設 PayNow gateway 已啟用
    並且 存在一筆付款方式為 paynow 的訂單 #111，金額 800 元，顧客選擇超商代碼（ibon）
    當 後端取得超商繳費代碼
    那麼 繳費代碼寫入 _pc_paynow_payment_info
    並且 訂單維持「等待付款」

  場景: 離線付款繳費完成後 Webhook 轉處理中
    假設 訂單 #110 付款方式為 paynow 且已寫入 ATM 待繳資訊，狀態為「等待付款」
    當 顧客完成 ATM 繳費，PayNow 推 Webhook Status="Success"
    那麼 訂單轉「處理中」（依 paynow-callback 驗簽 + 反查 + 冪等）
    並且 寫入 _pc_paynow_payment_detail
</content>
