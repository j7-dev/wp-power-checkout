# language: zh-TW
@ignore @command
功能: ezPay 電子發票折讓
  作為 系統 / 網站管理員
  我想要 對已開立的 ezPay 發票開立或作廢折讓單
  以便 處理部分退貨退款的稅務扣抵
  # ezPay = 藍新 NewebPay 旗下品牌。折讓走統一 ISupportsAllowance 介面（與綠界發票對等）。
  # allowance_issue Version=1.3、allowanceInvalid Version=1.0；AES-256-CBC PostData_ + CheckCode SHA256。
  # 開立折讓平台檢核：折讓總金額 TotalAmt = 折讓商品小計合計 + 折讓商品稅額合計。

  背景:
    假設 "ezpay" 已啟用
    而且 ezPay 發票設定如下：
      | key                       | value                            |
      | merchant_id               | 3622183                          |
      | hash_key                  | abcdefghijklmnopqrstuvwxyz123456 |
      | hash_iv                   | 1234567890abcdef                 |
      | mode                      | test                             |
      | auto_allowance_on_refund  | yes                              |
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |
    而且 訂單 #100 已開立 ezPay 發票：
      | key            | value      |
      | invoice_number | DS12223139 |
      | random_num     | 4253       |

  規則: 前置（狀態）- 開立折讓前訂單必須已開立 ezPay 發票

    場景: 訂單尚未開立發票時開折讓失敗
      假設 訂單 #100 尚未開立發票
      當 系統對訂單 #100 開立金額 500 的折讓
      那麼 折讓失敗，回傳空陣列

  規則: 前置（狀態）- provider 必須支援折讓能力

    場景: provider 未實作 ISupportsAllowance 時不開折讓
      假設 訂單 #100 使用的 provider 不支援折讓
      當 系統嘗試對訂單 #100 開立折讓
      那麼 不呼叫 ezPay 折讓 API

  規則: 前置（參數）- 折讓金額必須大於 0

    場景: 折讓金額為 0 時不開折讓
      當 系統對訂單 #100 開立金額 0 的折讓
      那麼 折讓失敗，回傳空陣列
      而且 不呼叫 ezPay 折讓 API

  規則: 後置（狀態）- 成功開立折讓時儲存折讓資料

    場景: 部分退款觸發自動開立折讓成功
      # 退款 hook maybe_issue_allowance_on_refund：部分退款 + 已開發票 + provider 支援折讓 + 設定開關開 → 自動開折讓
      假設 訂單 #100 已開立 ezPay 發票
      而且 ezPay API 開立折讓回傳成功，AllowanceNo 為 "A151015111705007"
      當 訂單 #100 發生部分退款金額 500
      那麼 ezPay Provider 的 issue_allowance 方法被呼叫
      而且 訂單 #100 的 _pc_allowance_data 有值
      而且 _pc_allowance_data 包含 "allowance_no" 為 "A151015111705007"

    場景: 開立折讓送出的折讓總金額等於商品小計加稅額
      # 平台檢核 TotalAmt = Σ ItemAmt + Σ ItemTaxAmt
      假設 訂單 #100 已開立 ezPay 發票
      而且 ezPay API 開立折讓回傳成功
      當 系統對訂單 #100 開立金額 500 的折讓
      那麼 送出的 PostData 中 TotalAmt 等於折讓商品小計合計加折讓商品稅額合計

  規則: 後置（回應）- 折讓回應須通過 CheckCode 驗章

    場景: 折讓回應 CheckCode 不符時不寫入折讓資料
      假設 訂單 #100 已開立 ezPay 發票
      而且 ezPay API 折讓回應的 CheckCode 與本地計算不符
      當 系統對訂單 #100 開立金額 500 的折讓
      那麼 折讓失敗，回傳空陣列
      而且 訂單 #100 的 _pc_allowance_data 未被寫入

  規則: 作廢折讓只能作廢已確認的折讓
    # ezPay allowanceInvalid 只能作廢已確認折讓；以 AllowanceNo + InvalidReason 作廢

    場景: 作廢已開立的 ezPay 折讓成功
      假設 訂單 #100 已開立 ezPay 折讓，AllowanceNo 為 "A151015111705007"
      而且 ezPay API 作廢折讓回傳成功
      當 系統對訂單 #100 作廢折讓
      那麼 訂單 #100 的 _pc_allowance_data 已被清除

    場景: 無折讓資料時作廢折讓失敗
      假設 訂單 #100 沒有 _pc_allowance_data
      當 系統對訂單 #100 作廢折讓
      那麼 作廢折讓失敗，回傳空陣列
      而且 不呼叫 ezPay 作廢折讓 API
