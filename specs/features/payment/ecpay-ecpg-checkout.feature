# language: zh-TW
@ignore @command
功能: 綠界 ECPay ECPG 站內付結帳（站內付 2.0 內嵌）
  作為 網站訪客
  我想要 在商家頁面內嵌完成綠界信用卡付款
  以便 不跳轉即完成結帳

  背景:
    假設 "ecpay_ecpg" 已啟用
    而且 ECPay ECPG 設定如下：
      | key         | value            |
      | merchant_id | 3002607          |
      | hash_key    | pwFHCqoQZGmho4w6 |
      | hash_iv     | EkRm7iFT261dpevs |
      | mode        | test             |
      | min_amount  | 5                |
      | max_amount  | 199999           |

  規則: 前置（參數）- GetTokenbyTrade 必須提供 ConsumerInfo 的 Email 與 Phone

    場景: ConsumerInfo 缺 Email 時取 token 失敗
      假設 系統中有訂單 #200，total 為 1000
      當 WooCommerce 呼叫 GetTokenbyTrade，ConsumerInfo 未填 Email
      那麼 操作失敗，RtnCode 非 1
      而且 訂單 #200 有 order note 記錄取 token 失敗原因

    場景: ConsumerInfo 完整時取得交易 token
      假設 系統中有訂單 #200，total 為 1000，含 billing email "buyer@example.com" 與 phone "0912345678"
      當 WooCommerce 呼叫 GetTokenbyTrade（ecpg domain，AES-128-CBC 加密 Data）
      那麼 RtnCode 為 1
      而且 回應 Data 含交易 token

  規則: 前置（參數）- AES Data 須以標準 Base64 alphabet 加密且雙層錯誤檢查

    場景: 解析 GetTokenbyTrade 回應時先查 TransCode 再查 RtnCode
      假設 系統中有訂單 #200
      當 WooCommerce 收到 GetTokenbyTrade 回應
      那麼 先檢查 TransCode 為 1（傳輸層）
      而且 再檢查 RtnCode 為 1（業務層）
      而且 兩者皆成功時才視為取 token 成功

  規則: 成功時以 token 與卡片資訊呼叫 CreatePayment

    場景: 前端收集卡片資訊後後端建立付款
      假設 訂單 #200 已取得交易 token
      而且 顧客在站內付元件（容器 id ECPayPayment）輸入測試卡號 "4311952222222222"
      當 WooCommerce 以 token + 卡片綁定結果呼叫 CreatePayment（ecpg domain）
      那麼 回應 TransCode 為 1

  規則: CreatePayment 回應含 ThreeDURL 時前端導向 3DS

    場景: 回應含 ThreeDURL 時導向 3D 驗證
      假設 訂單 #200 已呼叫 CreatePayment
      當 回應 data.ThreeDInfo.ThreeDURL 非空
      那麼 前端導向該 ThreeDURL 完成 3D 驗證
      而且 顧客以 3DS 驗證碼 "1234" 通過驗證

    場景: 回應不含 ThreeDURL 時等待 ReturnURL
      假設 訂單 #200 已呼叫 CreatePayment
      當 回應 data.ThreeDInfo.ThreeDURL 為空
      那麼 不導向 3D 驗證頁
      而且 等待 ReturnURL 幕後通知付款結果

  規則: 後置（狀態）- 建單時寫入冪等鍵 MerchantTradeNo

    場景: 建單後訂單保存 MerchantTradeNo
      假設 系統中有訂單 #200
      當 WooCommerce 呼叫 GetTokenbyTrade
      那麼 訂單 #200 的 _pc_ecpay_trade_no 有值
