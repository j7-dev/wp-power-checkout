# Amego API 錯誤碼表

回應最外層 `code` 為 `0` 代表成功，其他為錯誤。錯誤訊息在 `msg`。
官方來源：`invoice.amego.tw/info_detail?mid=71`。

> **重要**：部分 API（發票查詢 / 發票列表 / 發票檔案 / 折讓檔案 / 中獎 / 字軌等）
> 使用「短碼」錯誤碼（`31`/`32`/`51`/`71`/`99`…），與通用錯誤碼數字範圍重疊。
> 判讀時必須結合「呼叫的是哪支 API」與 `msg` 文字一起看，不要只看數字。

## 通用錯誤碼（所有 API 共用）

| code | 描述 |
|------|------|
| 0 | 成功 |
| 10 | 系統停機維護中 |
| 11 | invoice（統編）不可為空 |
| 12 | invoice（統編）錯誤 |
| 13 | status 未啟用 |
| 14 | IP 錯誤 |
| 15 | Time 錯誤（時間戳記與伺服器誤差超過 ±60 秒） |
| 16 | 簽名驗證錯誤（sign 不符——最常見，多為 data 字串不一致） |
| 17 | 資料不可為空 |
| 18 | 無法建立資料庫連線 |
| 19 | 公司停權，無法使用 API 開立發票 |
| 20 | data 非 JSON 字串格式 |
| 21 | 人數過多，請稍後 |
| 22 | 尚未申請 API 串接 |
| 23 | 此 API 支援多筆資料，data 欄位應為陣列字串（用了 Object 卻該用 Array） |

## 開立發票（自動配號 `/json/f0401`）

| code | 描述 |
|------|------|
| 3040111 | 字軌不足 |
| 3040112 | 此 API 只支援單張，data 應為物件字串（用了 Array 卻該用 Object） |
| 3040121 | BuyerIdentifier 字數錯誤 |
| 3040122 | BuyerIdentifier 格式錯誤 |
| 3040123 | BuyerName 不可為空或過長 |
| 3040124 | BuyerAddress 過長 |
| 3040125 | BuyerTelephoneNumber 過長 |
| 3040126 | BuyerEmailAddress 過長 |
| 3040127 | MainRemark 過長 |
| 3040128 | CarrierType 過長 |
| 3040129 | CarrierId1 過長 |
| 3040130 | CarrierId2 過長 |
| 3040131 | 載具顯碼、隱碼不相符 |
| 3040132 | 載具號碼不存在 |
| 3040133 | 載具顯碼、隱碼不相符 |
| 3040134 | CarrierId1 載具顯碼錯誤 |
| 3040135 | 載具顯碼或隱碼不可為空 |
| 3040136 | NPOBAN 格式錯誤 |
| 3040137 | NPOBAN 不存在 |
| 3040138 | ProductItem 不可為空或過多 |
| 3040139 | 第 n 品項 Description 不可為空或過長 |
| 3040140 | 第 n 品項 Quantity 格式錯誤 |
| 3040141 | 第 n 品項 UnitPrice 格式錯誤 |
| 3040142 | 第 n 品項 Amount 格式錯誤 |
| 3040143 | 第 n 品項 Remark 過長 |
| 3040144 | 第 n 品項 TaxType 錯誤 |
| 3040145 | SalesAmount 格式錯誤 |
| 3040146 | FreeTaxSalesAmount 格式錯誤 |
| 3040147 | ZeroTaxSalesAmount 格式錯誤 |
| 3040148 | TaxType 格式錯誤 |
| 3040149 | TaxRate 格式錯誤 |
| 3040150 | TaxAmount 格式錯誤 |
| 3040151 | TotalAmount 格式錯誤 |
| 3040152 | TotalAmount 不可為負數 |
| 3040153 | OrderId 不可為空或過長 |
| 3040154 | 載具類別不可為空 |
| 3040155 | 載具類別應為 6 碼 |
| 3040160 | BrandName 不可為空或過長 |
| 3040161 | DetailVat 格式錯誤 |
| 3040162 | 只有打統編發票才可以用未稅單價及小計 |
| 3040163 | DetailAmountRound 格式錯誤 |
| 3040171 | OrderId 重複（或：該訂單編號正在註銷重開中） |
| 3040172 | 第 n 品項 Amount 金額錯誤 |
| 3040173 | TaxType 設定錯誤 |
| 3040174 | SalesAmount 計算錯誤 |
| 3040175 | ZeroTaxSalesAmount 計算錯誤 |
| 3040176 | FreeTaxSalesAmount 計算錯誤 |
| 3040177 | TaxAmount 計算錯誤 |
| 3040178 | TotalAmount 計算錯誤 |
| 3040179 | 若為零稅率發票，通關方式註記必填 |
| 3040180 | 通關方式註記錯誤 |
| 3040181 | SalesAmount 不應該為負數 |
| 3040182 | ZeroTaxSalesAmount 不應該為負數 |
| 3040183 | FreeTaxSalesAmount 不應該為負數 |
| 3040184 | TotalAmount 不應該為負數 |
| 3040191 | 無法取得下一張發票 |
| 3040192 | 取得發票列印格式錯誤 |

## 作廢發票（`/json/f0501`）

| code | 描述 |
|------|------|
| 3050111 | 第 n 筆 CancelInvoiceNumber 錯誤 |
| 3050112 | 此 API 支援多張，data 應為陣列字串 |
| 3050121 | 指定發票號碼 開立中 |
| 3050122 | 指定發票號碼 已存在開立作廢 |
| 3050123 | 指定發票號碼 已存在註銷 |
| 3050124 | 指定發票號碼 發票類型錯誤 |
| 3050125 | 指定發票號碼 發票不存在 |
| 3050126 | 指定發票號碼 已超過修改期限 |
| 3050131 | 指定發票號碼 等待 開立/作廢/註銷 |
| 3050141 | 指定發票號碼 已存在折讓單（須先作廢折讓才能作廢發票） |

## 發票狀態（`/json/invoice_status`）

| code | 描述 |
|------|------|
| 99 | 第 n 筆 InvoiceNumber 錯誤 |

## 發票查詢（`/json/invoice_query`）

| code | 描述 |
|------|------|
| 31 | type 查詢類型不存在 |
| 32 | order_id 不可為空或過長 |
| 33 | invoice_number 不可為空或格式錯誤 |
| 34 | type 查詢類型錯誤 |
| 51 | 該發票超過查詢期限（180 天） |
| 71 | 查無資料 |

## 發票列表（`/json/invoice_list`）

| code | 描述 |
|------|------|
| 31 | date_select 參數錯誤 |
| 32 | date_start 不是 YYYYMMDD 格式 |
| 33 | date_end 不是 YYYYMMDD 格式 |
| 34 | limit 只能帶入 20 ~ 500 |
| 35 | page 必須 >= 1 |
| 36 | date_start 不可大於 date_end |

## 發票檔案（`/json/invoice_file`）

| code | 描述 |
|------|------|
| 31 | type 查詢類型不存在 |
| 32 | order_id 不可為空或過長 |
| 33 | invoice_number 不可為空或格式錯誤 |
| 34 | type 查詢類型錯誤 |
| 51 | 該發票超過查詢期限 |
| 52 | 該發票有等待異動的排程 |
| 53 | 載具發票，中獎後才可以下載（或：該發票類型不可下載） |
| 55 | 該發票不符合下載條件 |
| 71 | 查無資料 |

## 發票列印（`/json/invoice_print`）

| code | 描述 |
|------|------|
| 31 | type 查詢類型不存在 |
| 32 | order_id 不可為空或過長 |
| 33 | invoice_number 不可為空或格式錯誤 |
| 34 | type 查詢類型錯誤 |
| 35 | printer_type 熱感應機型號代碼錯誤（或：該發票不符合列印條件） |
| 36 | Star mC-Print3 不支援單印明細 |
| 51 | 該發票超過查詢期限 |
| 52 | 該發票有等待異動的排程 |
| 53 | 載具發票，中獎後才可以列印（或：該發票類型不可列印） |
| 55 | 該發票不符合列印條件 |
| 56 | 0 元發票，無法列印發票正本及發票補印 |
| 71 | 查無資料 |
| 72 | 取得發票列印格式錯誤 |

## 開立折讓（`/json/g0401`）

| code | 描述 |
|------|------|
| 4040112 | 此 API 支援多張，data 應為陣列字串 |
| 4040121 | 第 n 筆 AllowanceNumber 不可為空值或超過 16 字 |
| 4040122 | 第 n 筆 AllowanceDate 錯誤 |
| 4040123 | 第 n 筆 AllowanceType 錯誤 |
| 4040124 | 第 n 筆 OriginalInvoiceNumber 錯誤 |
| 4040125 | 第 n 筆 OriginalInvoiceDate 錯誤 |
| 4040126 | 第 n 筆 BuyerIdentifier 錯誤 |
| 4040127 | 第 n 筆 BuyerName 錯誤 |
| 4040128 | 第 n 筆 BuyerAddress 錯誤 |
| 4040129 | 第 n 筆 BuyerTelephoneNumber 錯誤 |
| 4040130 | 第 n 筆 BuyerEmailAddress 錯誤 |
| 4040131 | 第 n 筆 ProductItem 錯誤 |
| 4040132 | 第 n 筆 第 n 品項 目前僅支援一張折讓單折讓同一張發票 |
| 4040133 | 第 n 筆 第 n 品項 目前僅支援一張折讓單折讓同一張發票 |
| 4040134 | 第 n 筆 第 n 品項 OriginalDescription 錯誤 |
| 4040135 | 第 n 筆 第 n 品項 Quantity 錯誤 |
| 4040136 | 第 n 筆 第 n 品項 UnitPrice 錯誤 |
| 4040137 | 第 n 筆 第 n 品項 Amount 錯誤 |
| 4040138 | 第 n 筆 第 n 品項 Tax 錯誤 |
| 4040139 | 第 n 筆 第 n 品項 Tax 必須為整數 |
| 4040140 | 第 n 筆 TaxType 錯誤 |
| 4040141 | 第 n 筆 TaxAmount 錯誤 |
| 4040142 | 第 n 筆 TotalAmount 錯誤 |
| 4040151 | 指定發票號碼 原發票日期錯誤 |
| 4040152 | 指定發票號碼 開立中 |
| 4040153 | 指定發票號碼 已存在開立作廢 |
| 4040154 | 指定發票號碼 已註銷 |
| 4040155 | 指定發票號碼 原發票類型錯誤 |
| 4040156 | 指定發票號碼 原發票不存在 |
| 4040161 | 指定折讓單號 已存在折讓開立 |
| 4040162 | 指定折讓單號 已存在作廢折讓 |
| 4040163 | 指定折讓單號 折讓類型錯誤 |
| 4040171 | 第 n 筆 此折讓單折讓金額已大於原發票的開立金額 |
| 4040173 | 第 n 筆 此折讓單加上其他同發票折讓單的折讓金額已大於原發票的開立金額 |

## 作廢折讓（`/json/g0501`）

| code | 描述 |
|------|------|
| 4050112 | 此 API 支援多張，data 應為陣列字串 |
| 4050121 | 第 n 筆 CancelAllowanceNumber 錯誤 |
| 4050131 | 指定折讓單號 折讓開立中 |
| 4050132 | 指定折讓單號 已存在作廢折讓 |
| 4050133 | 指定折讓單號 折讓類型錯誤 |
| 4050134 | 指定折讓單號 折讓單不存在 |
| 4050135 | 指定折讓單號 已超過修改期限 |
| 4050141 | 指定折讓單號 等待 折讓開立/作廢折讓 |

## 折讓狀態（`/json/allowance_status`）

| code | 描述 |
|------|------|
| 99 | 第 n 筆 AllowanceNumber 錯誤 |

## 折讓檔案（`/json/allowance_file`）

| code | 描述 |
|------|------|
| 33 | allowance_number 不可為空 |
| 34 | download_style 參數錯誤 |
| 52 | 該折讓單有等待異動的排程 |
| 53 | 該折讓單類型不可下載 |
| 71 | 查無資料 |

## 手機條碼查詢（`/json/barcode`）

| code | 描述 |
|------|------|
| 9000111 | 手機條碼不可為空 |
| 9000112 | 手機條碼格式錯誤 |
| 9000113 | 手機條碼不存在 |

## 公司名稱查詢（`/json/ban_query`）

| code | 描述 |
|------|------|
| 99 | 第 n 筆 統一編號長度錯誤 |
| 99 | 第 n 筆 統一編號格式錯誤 |

## 所有字軌資料 / 字軌取號 / 字軌狀態

| code | 描述 |
|------|------|
| 99 | Period 錯誤 |
| 99 | Book 錯誤（字軌取號） |
| 99 | 無字軌可取（字軌取號） |
| 99 | 剩餘字軌本數不足（字軌取號） |
| 99 | 字軌取號錯誤，請聯絡客服人員 |
| 99 | 字軌取號的訖號錯誤，請聯絡客服人員 |
| 99 | 資料庫更新資料錯誤，請稍後重試 |

## 開立發票（API 配號 `/json/f0401_custom`）

此 API 的錯誤統一回 `code: 99`，依 `msg` 文字區分。常見 `msg`：

- 此 API 支援多張，data 欄位應為陣列字串
- 有指定熱感應機型號代碼，只能一次傳輸一張
- 第 n 筆 InvoiceNumber 錯誤 / 非當期字軌 / 超過開立期限 / 號碼不在字軌起訖內 /
  字軌類別設定錯誤 / 字軌類型設定錯誤
- 第 n 筆 InvoiceDate 錯誤 / InvoiceTime 錯誤 / RandomNumber 錯誤
- 第 n 筆 BuyerIdentifier / BuyerName / BuyerAddress / BuyerTelephoneNumber /
  BuyerEmailAddress / MainRemark / CarrierType / CarrierId1 / CarrierId2 / PrintMark 錯誤
- 第 n 筆 載具顯碼、隱碼不相符 / 載具號碼不存在 / CarrierId1 載具顯碼錯誤 /
  載具類型錯誤 / 載具顯碼錯誤 / 載具隱碼錯誤
- 第 n 筆 NPOBAN 錯誤
- 第 n 筆 ProductItem 錯誤；第 n 品項 Description / Quantity / UnitPrice / Amount /
  Remark / TaxType 錯誤
- 第 n 筆 若為零稅率發票，通關方式註記必填；通關方式註記錯誤
- 第 n 筆 SalesAmount / FreeTaxSalesAmount / ZeroTaxSalesAmount / TaxType / TaxRate /
  TaxAmount / TotalAmount 錯誤
- 第 n 筆 order_id 錯誤
- 第 n 筆 列印發票不可設定載具 / 列印發票不可設定捐贈碼 / 捐贈發票不可設定載具
- 指定發票號碼 已存在開立 / 已存在開立作廢 / 註銷中 / 發票類型錯誤

## 錯誤排查速查

| 症狀 | 最可能原因 |
|------|-----------|
| `code: 16` 簽名驗證錯誤 | 算 sign 的 data 字串與送出的 data 字串不一致（key 順序 / 空白 / Unicode 跳脫）；App Key 用錯（測試 vs 正式） |
| `code: 15` Time 錯誤 | `time` 用了毫秒、或本地時鐘漂移超過 ±60 秒；先打 `/json/time` 校正 |
| `code: 12` invoice 錯誤 | `invoice`（公司統編）填錯，或測試 / 正式統編混用 |
| `code: 22` 尚未申請 API 串接 | 該統編未開通 API，需向光貿客服申請 |
| `code: 23` / `3040112` | data 該用 Array 卻用 Object，或反之（見各 API 的「`data` 形狀」） |
| `code: 3040171` OrderId 重複 | 同一 `OrderId` 已開過——開立前先 `invoice_query` 查 |
| `code: 3040174`–`3040178` | 金額計算與光貿端校驗不符——重新檢查含稅 / 未稅與分拆稅額邏輯 |
| `code: 3050141` | 要作廢的發票已有折讓單——須先 `g0501` 作廢折讓 |
| `code: 51` 查詢類 API | 發票超過 180 天查詢期限 |
