# language: zh-TW
@ignore @query
功能: ezPay 電子發票查詢
  作為 系統 / 網站管理員
  我想要 查詢已開立 ezPay 發票的明細與上傳狀態
  以便 確認發票內容與財政部上傳結果（唯讀，不變更任何狀態）
  # ezPay invoice_search Version=1.3；走統一 ISupportsQuery 介面（與綠界發票對等）。
  # SearchType=0 用 InvoiceNumber+RandomNum；SearchType=1 用 MerchantOrderNo+TotalAmt。
  # 重點：Status=SUCCESS 只代表 ezPay 平台收件成功，財政部上傳結果須看 UploadStatus。

  背景:
    假設 "ezpay" 已啟用
    而且 ezPay 發票設定如下：
      | key         | value                            |
      | merchant_id | 3757976                          |
      | hash_key    | abcdefghijklmnopqrstuvwxyz123456 |
      | hash_iv     | 1234567890abcdef                 |
      | mode        | test                             |
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1400  | processing |

  規則: 前置（狀態）- 查詢前訂單必須已開立 ezPay 發票

    場景: 訂單尚未開立發票時查詢回傳空陣列
      假設 訂單 #100 尚未開立發票
      當 系統查詢訂單 #100 的發票明細
      那麼 查詢回傳空陣列
      而且 不呼叫 ezPay 查詢 API

  規則: 後置（回應）- 查詢成功回傳發票明細與上傳狀態

    場景: 查詢已開立發票回傳完整明細
      假設 訂單 #100 已開立 ezPay 發票：
        | key            | value      |
        | invoice_number | BA00000007 |
        | random_num     | 4234       |
      而且 ezPay API 查詢回傳成功
      當 系統查詢訂單 #100 的發票明細
      那麼 查詢結果應包含：
        | 欄位           | 值         |
        | invoice_number | BA00000007 |
        | invoice_status | 1          |
        | upload_status  | 1          |
        | total_amt      | 1400       |

    場景: 查詢已上傳財政部成功的發票
      # UploadStatus: 0=未上傳 1=已上傳成功 2=上傳中 3=上傳失敗 4=上傳逾時
      假設 訂單 #100 已開立 ezPay 發票
      而且 ezPay API 查詢回傳 UploadStatus 為 "1"
      當 系統查詢訂單 #100 的發票明細
      那麼 查詢結果的 upload_status 為 "1"

    場景: 查詢尚未上傳財政部的發票
      假設 訂單 #100 已開立 ezPay 發票
      而且 ezPay API 查詢回傳 UploadStatus 為 "0"
      當 系統查詢訂單 #100 的發票明細
      那麼 查詢結果的 upload_status 為 "0"

  規則: 後置（回應）- 查詢回應須通過 CheckCode 驗章

    場景: 查詢回應 CheckCode 不符時回傳空陣列
      假設 訂單 #100 已開立 ezPay 發票
      而且 ezPay API 查詢回應的 CheckCode 與本地計算不符
      當 系統查詢訂單 #100 的發票明細
      那麼 查詢回傳空陣列

  規則: 唯讀 - 查詢不變更訂單或發票狀態

    場景: 查詢不寫入任何 order meta
      假設 訂單 #100 已開立 ezPay 發票
      而且 ezPay API 查詢回傳成功
      當 系統查詢訂單 #100 的發票明細
      那麼 訂單 #100 的 _pc_issued_invoice_data 維持不變
      而且 訂單 #100 的狀態維持 "processing"
