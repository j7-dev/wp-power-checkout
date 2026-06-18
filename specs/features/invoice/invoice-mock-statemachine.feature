# language: zh-TW
功能: 有狀態的發票測試替身（MockProvider 狀態機）
  作為 開發者
  我想要 測試用的發票 provider 是有 in-memory 狀態的 fake 而非固定回 fixture 的 stub
  以便 能驗證發票狀態流（開立 → 作廢 → 衝突）與冪等行為，而不需打真實第三方 API

  # einvoice 導入重點 #3：有狀態 MockProvider。einvoice 的 mock 是 in-memory 狀態機（issue→void→CONFLICT、
  #   雙索引支援 orderId 查詢、真跑 schema 驗證），是 fake 不是 stub。
  # PC 現況：tests/Integration/Invoice/ 走 API_MODE=mock 回 fixture stub（非狀態機）。
  # 改造後：新增有狀態 MockProvider（實作 IInvoiceService + ISupportsAllowance + ISupportsQuery），
  #   維護 in-memory 發票狀態、真跑統一驗證層、以正規化 code 回 WP_Error。不更動既有 API_MODE 管線。

  背景:
    假設 啟用有狀態的 MockProvider
    而且 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |
      | 200     | 2      | 2000  | processing |

  規則: 後置（狀態）- 開立後 provider 內部記錄該訂單已開立

    場景: 首次開立後狀態為已開立
      假設 訂單 #100 尚未開立發票
      當 MockProvider 對訂單 #100 開立發票
      那麼 開立成功並回傳發票號碼
      而且 MockProvider 內部記錄訂單 #100 狀態為已開立

  規則: 後置（狀態）- 重複開立同一訂單回傳 CONFLICT

    場景: 對已開立訂單再次開立時回傳 CONFLICT
      假設 訂單 #100 已由 MockProvider 開立發票
      當 MockProvider 對訂單 #100 再次開立發票
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "CONFLICT"

  規則: 後置（狀態）- 作廢後狀態轉為已作廢

    場景: 作廢已開立的發票後狀態為已作廢
      假設 訂單 #100 已由 MockProvider 開立發票
      當 MockProvider 對訂單 #100 作廢發票
      那麼 作廢成功
      而且 MockProvider 內部記錄訂單 #100 狀態為已作廢

  規則: 前置（狀態）- 作廢未開立的發票回傳 NOT_FOUND

    場景: 對尚未開立的訂單作廢時回傳 NOT_FOUND
      假設 訂單 #200 尚未開立發票
      當 MockProvider 對訂單 #200 作廢發票
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "NOT_FOUND"

  規則: 後置（狀態）- 重複作廢回傳 CONFLICT

    場景: 對已作廢的發票再次作廢時回傳 CONFLICT
      假設 訂單 #100 已由 MockProvider 開立並作廢發票
      當 MockProvider 對訂單 #100 再次作廢發票
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "CONFLICT"

  規則: 後置（回應）- 支援以發票號碼與訂單號雙索引查詢

    場景: 以訂單號查詢已開立發票
      假設 訂單 #100 已由 MockProvider 開立發票
      當 MockProvider 以訂單 #100 查詢發票
      那麼 查詢成功
      而且 查詢結果包含該發票號碼

    場景: 以發票號碼查詢回推訂單
      假設 訂單 #100 已由 MockProvider 開立發票且發票號碼為 "MK00000001"
      當 MockProvider 以發票號碼 "MK00000001" 查詢
      那麼 查詢成功
      而且 查詢結果對應訂單 #100

  規則: 前置（參數）- MockProvider 開立前真跑統一驗證層

    場景: 不合法參數在 MockProvider 也被驗證攔截
      假設 訂單 #100 的發票參數同時帶載具與捐贈碼
      當 MockProvider 對訂單 #100 開立發票
      那麼 回傳值是 WP_Error
      而且 WP_Error 的 code 為 "VALIDATION"
      而且 MockProvider 內部未記錄訂單 #100 為已開立
