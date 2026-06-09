# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UNi Embed 付款結果通知（NotifyURL）
  作為 PAYUNi 第三方系統
  我想要 在顧客完成付款（含 3D 驗證）後幕後通知商家付款結果
  以便 WooCommerce 更新訂單狀態

  # 範本：對齊既有 ecpay-ecpg-callback / payuni-upp-callback（幕後 Form POST + 加密驗證 + 冪等 + always HTTP 200）。
  # NotifyURL 端點為 POST /wp-json/power-checkout/payuni/uni-embed/notify（permission __return_true，驗證在 callback 內；always HTTP 200）。
  # 驗證鏈：外層 HashInfo（SHA256，timing-safe）→ EncryptInfo（AES-256-GCM）解密 → 比對 MerTradeNo 反查訂單 → 比對 TradeAmt 防竄改 → 冪等檢查。
  # NotifyURL 僅 80/443 port；交易結果以 NotifyURL 為準（ReturnURL 可能漏收）。具體 Example（NotifyURL payload）待 sandbox 驗證階段補充（Phase 03）。

  規則: 前置（參數）- NotifyURL 通知必須通過外層 HashInfo（SHA256）timing-safe 驗證

  規則: 前置（參數）- 必須能以 AES-256-GCM 正確解密 EncryptInfo（AuthTag 驗證失敗即拒絕，不更新訂單）

  規則: 前置（狀態）- 必須以 MerTradeNo（_pc_payuni_uni_trade_no）反查訂單，找不到則拒絕

  規則: 前置（參數）- 必須比對 TradeAmt 與本地訂單金額（防止竄改），不符則拒絕

  規則: 前置（狀態）- 通知必須冪等處理（以 MerTradeNo 為 key，已 processing 則 skip，PAYUNi 重送防護）

  規則: 後置（狀態）- TradeStatus=1（已付款）時訂單轉「處理中」並寫入 _pc_payuni_uni_payment_detail

  規則: 後置（狀態）- TradeStatus=2（付款失敗）/ 3（付款取消）時訂單維持「等待付款」並記錄 order note

  規則: 後置（狀態）- TradeStatus=8（待確認，含 UNKNOWN / UNAPPROVED）時訂單維持「等待付款」並記錄 order note

  規則: 後置（回應）- 所有路徑（含解密 / 驗證失敗與 \Throwable）一律回應 HTTP 200

  # --- 具體範例（payuni-uni-embed-v3 skill + PAYUNi sandbox）---
  # 端點 POST /wp-json/power-checkout/payuni/uni-embed/notify（permission __return_true，always HTTP 200）。
  # NotifyURL 為 Form POST，外層 5 欄（MerID / Version=1.2 / EncryptInfo / HashInfo）；EncryptInfo 解密後含 TradeStatus / TradeAmt / TradeNo。

  場景: 付款成功（TradeStatus=1）轉處理中並寫入付款明細
    假設 訂單 #100 付款方式為 payuni_uni_embed，本地金額 1000 元，_pc_payuni_uni_trade_no 為 "PCE100"
    並且 訂單目前狀態為「等待付款」
    當 PAYUNi 以正確 HashInfo POST NotifyURL，EncryptInfo 解密後 MerTradeNo="PCE100"、TradeStatus="1"、TradeAmt=1000
    那麼 通過 HashInfo timing-safe 驗證與 AES-256-GCM 解密
    並且 以 MerTradeNo 反查到訂單 #100 且 TradeAmt 與本地金額相符
    並且 訂單轉「處理中」並寫入 _pc_payuni_uni_payment_detail
    並且 回應 HTTP 200

  場景大綱: 非成功狀態維持等待付款並記錄 order note
    假設 訂單 #<order> 付款方式為 payuni_uni_embed，_pc_payuni_uni_trade_no 為 "<trade_no>"
    當 PAYUNi 以正確 HashInfo POST NotifyURL，解密後 TradeStatus="<status>"
    那麼 訂單維持「等待付款」
    並且 記錄 order note 說明 "<note>"
    並且 回應 HTTP 200

    例子:
      | order | trade_no | status | note          |
      | 107   | PCE107   | 2      | 付款失敗      |
      | 108   | PCE108   | 3      | 付款取消      |
      | 109   | PCE109   | 8      | 訂單待確認    |

  場景: HashInfo 驗證失敗拒絕更新訂單但仍回 HTTP 200
    假設 訂單 #100 付款方式為 payuni_uni_embed
    當 PAYUNi 以錯誤 HashInfo POST NotifyURL
    那麼 timing-safe 驗證失敗，不解密、不更新訂單
    並且 回應 HTTP 200

  場景: 金額與本地不符（竄改）拒絕更新訂單
    假設 訂單 #100 付款方式為 payuni_uni_embed，本地金額 1000 元，_pc_payuni_uni_trade_no 為 "PCE100"
    當 PAYUNi 以正確 HashInfo POST NotifyURL，解密後 TradeStatus="1" 但 TradeAmt=1
    那麼 比對 TradeAmt 不符本地金額，拒絕更新訂單
    並且 回應 HTTP 200

  場景: 重複通知冪等處理（已 processing 則 skip）
    假設 訂單 #100 付款方式為 payuni_uni_embed 且已是「處理中」，_pc_payuni_uni_trade_no 為 "PCE100"
    當 PAYUNi 再次以正確 HashInfo POST NotifyURL，解密後 TradeStatus="1"
    那麼 以 MerTradeNo 為 key 判定已處理並 skip，不重複更新
    並且 回應 HTTP 200
