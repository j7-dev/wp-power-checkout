# Amego API Reference — 逐欄位參數表

API 網址前綴：`https://invoice-api.amego.tw`。所有 API（除 `/json/time`）為 POST +
`application/x-www-form-urlencoded`，body 帶 `invoice` / `data` / `time` / `sign`。
本檔的「參數」指 `data` JSON 字串的內容；「回應」指 response body JSON。

## 目錄

- [發票 — 開立發票（自動配號）`/json/f0401`](#開立發票自動配號-jsonf0401)
- [發票 — 作廢發票 `/json/f0501`](#作廢發票-jsonf0501)
- [發票 — 發票狀態 `/json/invoice_status`](#發票狀態-jsoninvoice_status)
- [發票 — 發票查詢 `/json/invoice_query`](#發票查詢-jsoninvoice_query)
- [發票 — 發票列表 `/json/invoice_list`](#發票列表-jsoninvoice_list)
- [發票 — 發票檔案 `/json/invoice_file`](#發票檔案-jsoninvoice_file)
- [發票 — 發票列印 `/json/invoice_print`](#發票列印-jsoninvoice_print)
- [折讓 — 開立折讓 `/json/g0401`](#開立折讓-jsong0401)
- [折讓 — 作廢折讓 `/json/g0501`](#作廢折讓-jsong0501)
- [折讓 — 折讓狀態 `/json/allowance_status`](#折讓狀態-jsonallowance_status)
- [折讓 — 折讓查詢 `/json/allowance_query`](#折讓查詢-jsonallowance_query)
- [折讓 — 折讓列表 `/json/allowance_list`](#折讓列表-jsonallowance_list)
- [折讓 — 折讓檔案 `/json/allowance_file`](#折讓檔案-jsonallowance_file)
- [折讓 — 折讓列印 `/json/allowance_print`](#折讓列印-jsonallowance_print)
- [中獎 — 獎項定義 `/json/lottery_type`](#獎項定義-jsonlottery_type)
- [中獎 — 中獎發票 `/json/lottery_status`](#中獎發票-jsonlottery_status)
- [其他 — 手機條碼查詢 `/json/barcode`](#手機條碼查詢-jsonbarcode)
- [其他 — 公司名稱查詢 `/json/ban_query`](#公司名稱查詢-jsonban_query)
- [其他 — 所有字軌資料 `/json/track_all`](#所有字軌資料-jsontrack_all)
- [其他 — 伺服器時間 `/json/time`](#伺服器時間-jsontime)
- [自行配號 — 字軌取號 `/json/track_get`](#字軌取號-jsontrack_get)
- [自行配號 — 字軌狀態 `/json/track_status`](#字軌狀態-jsontrack_status)
- [自行配號 — 開立發票（API 配號）`/json/f0401_custom`](#開立發票api-配號-jsonf0401_custom)
- [共用代碼對照表](#共用代碼對照表)
- [金額計算](#金額計算)

---

## 開立發票（自動配號） `/json/f0401`

開立發票後回傳發票號碼、發票時間、隨機碼；若傳入 `PrinterType` 額外回傳列印格式字串。
`data` 為**單一 Object**（不是陣列）。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `OrderId` | 必填 | String | 訂單編號，不可重複，≤ 40 字 |
| `TrackApiCode` | 選填 | String | 指定字軌開立。需在後台「發票字軌列表」設定 API 指定代碼；不指定則依字軌列表排序 |
| `BuyerIdentifier` | 必填 | String | 買方統一編號；無統編填 `0000000000` |
| `BuyerName` | 必填 | String | 買方名稱。不打統編可填「客人」「消費者」；打統編填公司名稱（不能填則填統編）；不可填 0/00/000/0000 |
| `BuyerAddress` | 選填 | String | 買方地址 |
| `BuyerTelephoneNumber` | 選填 | String | 買方電話 |
| `BuyerEmailAddress` | 選填 | String | 買方信箱（寄通知信用）；留空不寄。測試環境不主動寄信 |
| `MainRemark` | 選填 | String | 總備註，≤ 200 字 |
| `CarrierType` | 選填 | String | 載具類別：手機條碼 `3J0002`、自然人憑證 `CQ0001`、光貿會員載具 `amego`，或自家會員載具代碼 |
| `CarrierId1` | 選填 | String | 載具顯碼 |
| `CarrierId2` | 選填 | String | 載具隱碼 |
| `NPOBAN` | 選填 | String | 捐贈碼 |
| `ProductItem` | 必填 | Object[] | 商品陣列，最多 9999 筆（見下表） |
| `SalesAmount` | 必填 | Number | 應稅銷售額合計 |
| `FreeTaxSalesAmount` | 必填 | Number | 免稅銷售額合計 |
| `ZeroTaxSalesAmount` | 必填 | Number | 零稅率銷售額合計 |
| `TaxType` | 必填 | Number | 發票課稅別：1 應稅 / 2 零稅率 / 3 免稅 / 4 應稅(特種稅率) / 9 混合(限 C0401) |
| `TaxRate` | 必填 | String | 稅率，5% 時填 `0.05` |
| `TaxAmount` | 必填 | Number | 營業稅額。打統編才算 5%；沒打統編一律 `0` |
| `TotalAmount` | 必填 | Number | 總計 |
| `CustomsClearanceMark` | 選填 | Number | 通關方式註記。零稅率發票必填：1 非經海關出口 / 2 經海關出口 |
| `ZeroTaxRateReason` | 選填 | Number | 零稅率原因 71–79。零稅率發票必填（見共用代碼對照表） |
| `BrandName` | 選填 | String | 品牌名稱 |
| `DetailVat` | 選填 | Number | 明細單價 / 小計為含稅或未稅：0 未稅 / 1 含稅（預設）。0 只有打統編發票可用 |
| `DetailAmountRound` | 選填 | Number | 明細小計處理：0 小數 7 位（預設） / 1 一律四捨五入到整數 |
| `PrinterType` | 選填 | Number | 熱感應機型號代碼（見機型代碼） |
| `PrinterLang` | 選填 | Number | 熱感應機編碼：1 BIG5 / 2 GBK / 3 UTF-8 |
| `PrintDetail` | 選填 | Number | 熱感應機是否列印明細：1 列印（預設） / 0 不列印。打統編一律列印；目前僅 `PrinterType=2` 可設 |

`ProductItem[]` 子欄位：

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `Description` | 必填 | String | 品名，≤ 256 字 |
| `Quantity` | 必填 | Number | 數量，小數精準到 7 位 |
| `Unit` | 選填 | String | 單位，≤ 6 字 |
| `UnitPrice` | 必填 | Number | 單價，預設含稅，小數精準到 7 位。打統編用未稅價時 `DetailVat` 設 0 |
| `Amount` | 必填 | Number | 小計，小數精準到 7 位。`DetailAmountRound=1` 時一律四捨五入到整數 |
| `Remark` | 選填 | String | 備註，≤ 120 字 |
| `TaxType` | 必填 | Number | 課稅別：1 應稅 / 2 零稅率 / 3 免稅 |

### 回應

| 欄位 | 類型 | 說明 |
|------|------|------|
| `code` | Number | 0 成功，其他為錯誤 |
| `msg` | String | 錯誤訊息 |
| `invoice_number` | String | 發票號碼（成功才回） |
| `invoice_time` | Number | 發票開立時間，Unix timestamp（成功才回） |
| `random_number` | String | 隨機碼（成功才回） |
| `barcode` | String | 電子發票條碼內容 |
| `qrcode_left` | String | 左側 QRCODE 內容（0 元發票回空字串） |
| `qrcode_right` | String | 右側 QRCODE 內容（0 元發票回空字串） |
| `base64_data` | String | base64 列印格式字串。`PrinterType=1` 為 XML(mC-Print3)；`>=2` 為 ESC/POS。0 元發票不回此欄位 |

### `data` JSON 範例（一般開立，不打統編）

```json
{
    "OrderId": "A20200817101021",
    "BuyerIdentifier": "0000000000",
    "BuyerName": "客人",
    "BuyerAddress": "",
    "BuyerTelephoneNumber": "",
    "BuyerEmailAddress": "",
    "MainRemark": "",
    "CarrierType": "",
    "CarrierId1": "",
    "CarrierId2": "",
    "NPOBAN": "",
    "ProductItem": [
        { "Description": "測試商品1", "Quantity": "1", "UnitPrice": "170", "Amount": "170", "Remark": "", "TaxType": "1" },
        { "Description": "會員折抵", "Quantity": "1", "UnitPrice": "-2", "Amount": "-2", "Remark": "", "TaxType": "1" }
    ],
    "SalesAmount": "168",
    "FreeTaxSalesAmount": "0",
    "ZeroTaxSalesAmount": "0",
    "TaxType": "1",
    "TaxRate": "0.05",
    "TaxAmount": "0",
    "TotalAmount": "168"
}
```

打統編（含稅價）範例：把 `BuyerIdentifier` 設成買方統編、`BuyerName` 設成公司名稱，
`TaxAmount` 須分拆出 5%（見金額計算章節）。
手機載具：`CarrierType:"3J0002"`、`CarrierId1`/`CarrierId2` 填手機條碼。
捐贈：`NPOBAN` 填捐贈碼，`CarrierType`/`CarrierId*` 留空。

---

## 作廢發票 `/json/f0501`

作廢已開立發票。`data` 為**陣列**，可一次作廢多張。

### 參數（`data` Array）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `CancelInvoiceNumber` | 必填 | String | 發票號碼 |

```json
[ { "CancelInvoiceNumber": "AB00001111" } ]
```

### 回應

`code`（0 成功）+ `msg`。

> 作廢限制：須在當期、發票未超過修改期限、且該發票尚無折讓單（否則回 3050121–3050141 系列）。

---

## 發票狀態 `/json/invoice_status`

查發票上傳財政部的狀態。`data` 為**陣列**。

### 參數（`data` Array）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `InvoiceNumber` | 必填 | String | 發票號碼 |

### 回應

`code` + `msg` + `data`（Array）：

| 欄位 | 類型 | 說明 |
|------|------|------|
| `invoice_number` | String | 發票號碼 |
| `type` | String | `NOT_FOUND` 查無 / `C0401` 開立 / `C0501` 作廢 / `C0701` 註銷 / `TYPE_ERROR` 類型錯誤 |
| `status` | Number | 1 待處理 / 2 上傳中 / 3 已上傳 / 31 處理中 / 32 處理完成待確認 / 91 錯誤 / 99 完成 / -1 查無 |
| `total_amount` | Number | 發票金額 |

```json
{
  "code": 0, "msg": "",
  "data": [
    { "invoice_number": "AB00001112", "type": "C0401", "status": 99, "total_amount": 1580 },
    { "invoice_number": "AB00001111", "type": "NOT_FOUND", "status": -1, "total_amount": 0 }
  ]
}
```

---

## 發票查詢 `/json/invoice_query`

查單張發票完整內容（含明細）。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `type` | 必填 | String | 查詢類型：`order` 訂單編號 / `invoice` 發票號碼 |
| `order_id` | 必填* | String | 訂單編號，≤ 40 字。以發票日期為主，只能查 180 天內 |
| `invoice_number` | 必填* | String | 發票號碼，≤ 10 字。只能查 180 天內 |

`order_id` / `invoice_number` 依 `type` 擇一帶入。

### 回應 `data`（Object）主要欄位

`invoice_number` / `invoice_type`（見發票類型代碼）/ `invoice_status`（上傳狀態）/
`invoice_date`(YYYYMMDD) / `invoice_time`(HH:mm:ss) / `buyer_identifier` / `buyer_name` /
`buyer_zip` / `buyer_address` / `buyer_telephone_number` / `buyer_email_address` /
`sales_amount` / `free_tax_sales_amount` / `zero_tax_sales_amount` / `tax_type` /
`tax_rate` / `tax_amount` / `total_amount` / `print_mark` / `random_number` /
`main_remark` / `customs_clearance_mark` / `carrier_type` / `carrier_id1` / `carrier_id2` /
`npoban` / `cancel_date`(Unix) / `invoice_lottery`（中獎獎項代碼）/ `order_id` /
`detail_vat` / `detail_amount_round` / `create_date`(Unix)。

- `product_item` Object[]：`tax_type` / `description` / `unit_price` / `quantity` / `unit` / `amount` / `remark`
- `wait` Object[]：未處理的排程（例如等待改成「發票作廢」）：`invoice_type` / `create_date`
- `allowance` Object[]（2024-10-24 新增）：該發票的折讓單：`invoice_type` / `invoice_status` /
  `allowance_type` / `allowance_number` / `allowance_date` / `tax_amount` / `total_amount`

錯誤碼：`31` type 不存在 / `32` order_id 空或過長 / `33` invoice_number 空或格式錯 /
`34` type 錯誤 / `51` 超過查詢期限 / `71` 查無資料。

---

## 發票列表 `/json/invoice_list`

依日期區間分頁查發票主檔。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `date_select` | 必填 | Number | 1 發票日期 / 2 建立日期 |
| `date_start` | 必填 | String | 開始日期 YYYYMMDD |
| `date_end` | 必填 | String | 結束日期 YYYYMMDD |
| `limit` | 選填 | Number | 每頁筆數 20~500，預設 20 |
| `page` | 選填 | Number | 頁數，預設 1 |

### 回應

`code` + `msg` + `page_total` + `page_now` + `data_total` + `data`（Object[]）。
`data` 每筆欄位與發票查詢的 `data` 主檔欄位相同（另含 `zero_tax_rate_reason`），不含 `product_item`。

錯誤碼：`31` date_select 錯 / `32` date_start 非 YYYYMMDD / `33` date_end 非 YYYYMMDD /
`34` limit 須 20~500 / `35` page 須 >= 1 / `36` date_start 不可大於 date_end。

---

## 發票檔案 `/json/invoice_file`

下載發票 PDF。載具發票須中獎後才可下載；非載具發票可無限次下載。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `type` | 必填 | String | `order` 訂單編號 / `invoice` 發票號碼 |
| `order_id` | 必填* | String | 訂單編號，≤ 40 字，限 180 天內 |
| `invoice_number` | 必填* | String | 發票號碼，≤ 10 字，限 180 天內 |
| `download_style` | 選填 | Number | 下載樣式，預設 0 |

`download_style`（打統編 4 種）：0 A4 整張 / 1 A4(地址+A5) / 2 A4(A5x2) / 3 A5 / 5 QRcode_A4。
沒打統編：只有 0（A4 整張，背面兌獎聯需雙面列印）。

### 回應

`code` + `msg` + `data.file_url`（檔案連結，**僅 10 分鐘有效**）。

錯誤碼：`51` 超過查詢期限 / `52` 有等待異動排程 / `53` 載具中獎後才可下載 / `55` 不符下載條件 / `71` 查無。

---

## 發票列印 `/json/invoice_print`

產出熱感應機列印格式字串。0 元發票無法產生正本 / 補印。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `type` | 必填 | String | `order` / `invoice` |
| `order_id` | 必填* | String | 訂單編號，限 180 天內 |
| `invoice_number` | 必填* | String | 發票號碼，限 180 天內 |
| `printer_type` | 必填 | Number | 熱感應機型號代碼 |
| `printer_lang` | 選填 | Number | 1 BIG5 / 2 GBK / 3 UTF-8 |
| `print_invoice_type` | 必填 | Number | 1 發票正本 / 2 發票補印 / 3 單印明細（限 Xprinter 芯燁通用後機型 B2C） |
| `print_invoice_detail` | 選填 | Number | 列印正本 / 補印時是否印明細：1 印（預設） / 0 不印。打統編一律印；限 Xprinter 芯燁 |

### 回應

`code` + `msg` + `data.base64_data`（base64 列印格式字串；`printer_type=1` XML / `>=2` ESC/POS；0 元發票不回）。

---

## 開立折讓 `/json/g0401`

折讓已開立的發票。`data` 為**陣列**。一張折讓單目前僅支援折讓同一張原發票。

### 參數（`data` Array）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `AllowanceNumber` | 必填 | String | 折讓單編號，不可重複，≤ 16 字 |
| `AllowanceDate` | 必填 | String | 折讓單日期 YYYYMMDD |
| `AllowanceType` | 必填 | Number | 1 買方開立折讓證明單 / 2 賣方折讓證明通知單。自 114/1/1 起經雙方合意之退回或折讓，賣方營業人應開立 |
| `BuyerIdentifier` | 必填 | String | 買方統編；無填 `0000000000` |
| `BuyerName` | 必填 | String | 買方名稱 |
| `BuyerAddress` | 選填 | String | 買方地址 |
| `BuyerTelephoneNumber` | 選填 | String | 買方電話 |
| `BuyerEmailAddress` | 選填 | String | 買方信箱 |
| `ProductItem` | 必填 | Object[] | 商品陣列，最多 9999 筆 |
| `TaxAmount` | 必填 | Number | 營業稅額 |
| `TotalAmount` | 必填 | Number | 金額合計（不含稅） |

`ProductItem[]` 子欄位：

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `OriginalInvoiceNumber` | 必填 | String | 原發票號碼 |
| `OriginalInvoiceDate` | 必填 | Number | 原發票日期 YYYYMMDD |
| `OriginalDescription` | 必填 | String | 原品名，≤ 256 字 |
| `Quantity` | 必填 | Number | 數量 |
| `UnitPrice` | 必填 | Number | 單價（**不含稅**） |
| `Amount` | 必填 | Number | 小計（**不含稅**） |
| `Tax` | 必填 | Number | 稅金（整數） |
| `TaxType` | 必填 | Number | 1 應稅 / 2 零稅率 / 3 免稅 |

### 回應

`code`（0 成功）+ `msg`。

### `data` JSON 範例

```json
[
  {
    "AllowanceNumber": "3821061800001",
    "AllowanceDate": "20210618",
    "AllowanceType": "2",
    "BuyerIdentifier": "0000000000",
    "BuyerName": "蕭XX",
    "ProductItem": [
      {
        "OriginalInvoiceDate": 20210520,
        "OriginalInvoiceNumber": "NW93016392",
        "OriginalDescription": "超聲波清洗機",
        "Quantity": 2, "UnitPrice": "2180", "Amount": "4360", "Tax": 218, "TaxType": 1
      }
    ],
    "TaxAmount": "218",
    "TotalAmount": "4360"
  }
]
```

> 折讓金額不可大於原發票開立金額；同一張原發票多筆折讓加總也不可超過（錯誤碼 4040171 / 4040173）。

---

## 作廢折讓 `/json/g0501`

作廢已開立折讓單。`data` 為**陣列**。

### 參數（`data` Array）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `CancelAllowanceNumber` | 必填 | String | 折讓單編號 |

```json
[ { "CancelAllowanceNumber": "3821061800001" } ]
```

### 回應

`code`（0 成功）+ `msg`。

---

## 折讓狀態 `/json/allowance_status`

查折讓單上傳狀態。`data` 為**陣列**。

### 參數（`data` Array）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `AllowanceNumber` | 必填 | String | 折讓單編號 |

### 回應 `data`（Array）

| 欄位 | 類型 | 說明 |
|------|------|------|
| `allowance_number` | String | 折讓單編號 |
| `type` | String | `NOT_FOUND` / `D0401` 開立 / `D0501` 作廢 / `TYPE_ERROR` |
| `status` | Number | 1/2/3/31/32/91/99（語意同發票狀態）/ -1 查無 |
| `tax_amount` | Number | 營業稅額 |
| `total_amount` | Number | 未稅總計 |

---

## 折讓查詢 `/json/allowance_query`

查單張折讓完整內容。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `allowance_number` | 必填 | String | 折讓單編號，≤ 16 字 |

### 回應 `data`（Object）主要欄位

`allowance_number` / `invoice_type`（折讓類型 D0401/D0501/B0401…）/ `invoice_status` /
`allowance_date`(YYYYMMDD) / `allowance_type`（1 買方 / 2 賣方）/ `buyer_identifier` /
`buyer_name` / `buyer_zip` / `buyer_address` / `buyer_telephone_number` / `buyer_email_address` /
`tax_amount` / `total_amount`（不含稅）/ `cancel_date`(Unix) / `detail_vat` / `create_date`(Unix)。

- `product_item` Object[]：`original_invoice_number` / `original_invoice_date` / `tax_type` /
  `description` / `unit_price`(不含稅) / `quantity` / `unit` / `amount`(不含稅) / `tax`
- `wait` Object[]：未處理排程 `invoice_type` / `create_date`

---

## 折讓列表 `/json/allowance_list`

依日期區間分頁查折讓主檔。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `date_select` | 必填 | Number | 1 折讓單日期 / 2 建立日期 |
| `date_start` | 必填 | String | YYYYMMDD |
| `date_end` | 必填 | String | YYYYMMDD |
| `limit` | 選填 | Number | 20~500，預設 20 |
| `page` | 選填 | Number | 預設 1 |

### 回應

`code` + `msg` + `page_total` + `page_now` + `data_total` + `data`（Object[]）。
每筆含 `allowance_number` / `invoice_type` / `invoice_status` / `allowance_date` /
`allowance_type` / `buyer_*` / `tax_amount` / `total_amount` / `cancel_date` / `create_date` /
`product_item`（`original_invoice_date` / `original_invoice_number` / `tax_type` /
`description` / `unit_price` / `quantity` / `unit` / `amount` / `tax`）。

---

## 折讓檔案 `/json/allowance_file`

下載折讓單 PDF，可無限次下載。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `allowance_number` | 必填 | String | 折讓單編號，≤ 16 字 |
| `download_style` | 選填 | Number | 0 A4 整張（預設）/ 1 A4(地址+A5) / 3 A5 |

### 回應

`code` + `msg` + `data.file_url`（連結僅 10 分鐘有效）。
錯誤碼：`33` allowance_number 空 / `34` download_style 錯 / `52` 有等待異動排程 / `53` 類型不可下載 / `71` 查無。

---

## 折讓列印 `/json/allowance_print`

產出折讓單熱感應機列印格式字串。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `allowance_number` | 必填 | String | 折讓單編號，≤ 16 字 |
| `printer_type` | 必填 | Number | 熱感應機型號代碼 |
| `printer_lang` | 選填 | Number | 1 BIG5 / 2 GBK / 3 UTF-8 |

### 回應

`code` + `msg` + `data.base64_data`（`printer_type >= 2` ESC/POS）。

---

## 獎項定義 `/json/lottery_type`

中獎發票類型定義。`data` 不需傳入內容。POST `/json/lottery_type`。

### 回應 `data`（Object[]）

| 欄位 | 類型 | 說明 |
|------|------|------|
| `type` | Number | 獎項代碼 |
| `name` | String | 獎項名稱（例：11 特別獎(1,000萬)、12 特獎(200萬元)…） |

---

## 中獎發票 `/json/lottery_status`

查中獎發票。建議雙月 1 號後查（例：9-10 月發票 11/25 開獎，建議 12/1 後查）。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `Year` | 必填 | Number | 西元年 |
| `Period` | 必填 | Number | 期別 0~5：0 (1-2月) / 1 (3-4月) / 2 (5-6月) / 3 (7-8月) / 4 (9-10月) / 5 (11-12月) |

### 回應 `data`（Object[]）

`invoice_date`(YYYYMMDD) / `invoice_number` / `type`（獎項代碼，參考獎項定義 API）。

---

## 手機條碼查詢 `/json/barcode`

驗證手機條碼是否正確。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `barCode` | 必填 | String | 手機條碼 |

### 回應

`code`（0 正確）+ `msg`。錯誤碼：`9000111` 空 / `9000112` 格式錯 / `9000113` 不存在。

---

## 公司名稱查詢 `/json/ban_query`

統編 → 公司名稱（資料來源：財政部財政資訊中心）。`data` 為**陣列**。

### 參數（`data` Array）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `ban` | 必填 | String | 統一編號，數字 8 碼 |

### 回應 `data`（Object[]）

| 欄位 | 類型 | 說明 |
|------|------|------|
| `ban` | String | 傳入的統編 |
| `name` | String | 查到的公司名稱；查不到回空字串（不代表統編錯誤或不存在，管委會 / 會計事務所等不在資料集內） |

---

## 所有字軌資料 `/json/track_all`

該公司在加值中心的所有字軌資料（三層巢狀）。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `Year` | 必填 | Number | 西元年 |
| `Period` | 必填 | Number | 期別 0~5 |

### 回應 `data`（Object[]，三層 `layer` 巢狀）

`layer`（1 財政部配給 / 2 給光貿用 / 3 發票字軌列表內容）/ `category`（1 自動配號 / 2 API 配號）/
`code`（字軌名稱，英文 2 碼）/ `start` / `end` / `now`（目前配號）/ `total_booklet` / `remark` /
`TrackApiCode` / `source`（1 系統匯入 / 2 人工輸入）/ `status`（1 使用 / 2 停用 / 3 過期 / 9 用畢）。
第 1、2 層 entry 內含 `data` 子陣列。

---

## 伺服器時間 `/json/time`

查伺服器時間。**GET** 請求，不需 `invoice` / `data` / `time` / `sign`，直接打網址即可。
用途：校正本地時鐘，確保開立 API 的 `time` 與伺服器誤差在 ±60 秒內。

### 回應

`timestamp`(Unix) / `text`(年/月/日 時:分:秒) / `year` / `month` / `day` / `hour` / `minute` / `second`。

---

## 字軌取號 `/json/track_get`

取發票字軌，只能取「API 配號」類型字軌。`data` 為 Object。

### 參數（`data` Object）

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `Year` | 必填 | Number | 西元年 |
| `Period` | 必填 | Number | 期別 0~5 |
| `Book` | 必填 | Number | 本數，1 本 = 50 張發票 |

### 回應 `data`（Object）

`code`（字軌名稱，英文 2 碼）/ `start`（字軌起號）/ `end`（字軌訖號）。

---

## 字軌狀態 `/json/track_status`

查「API 配號」字軌配號狀態。`data` 為 Object。

### 參數（`data` Object）

`Year`（西元年）/ `Period`（期別 0~5）。

### 回應 `data`（Object[]）

`code` / `start` / `end` / `now`（目前配號）/ `total_booklet` / `used_booklet` /
`status`（1 使用 / 2 停用 / 3 過期 / 9 用畢）。

---

## 開立發票（API 配號） `/json/f0401_custom`

自行指定發票號碼 / 日期 / 隨機碼。`data` 為**陣列**（可多張）；帶 `PrinterType` 時限單張。

### 參數（`data` Array，每筆 Object）

與 `f0401` 大致相同，差異與新增欄位：

| 欄位 | 必填 | 類型 | 說明 |
|------|------|------|------|
| `InvoiceNumber` | 必填 | String | 發票號碼（自行指定，須在已取的 API 配號字軌起訖內） |
| `InvoiceDate` | 必填 | String | 發票日期 YYYYMMDD |
| `InvoiceTime` | 必填 | String | 發票時間 hh:mm:ss |
| `RandomNumber` | 必填 | String | 隨機碼（自行指定） |
| `PrintMark` | 必填 | String | 列印註記：Y 列印 / N 不列印 |
| `SellerPersonInCharge` | 選填 | String | 賣方負責人，≤ 30 字 |
| `order_id` | 選填 | String | 訂單編號，不可重複，≤ 40 字（注意：此處欄位名為小寫 `order_id`） |
| `ExchangeRate` | 選填 | Number | 匯率，小數精準到 3 位 |
| `Currency` | 選填 | String | 幣別，3 字，參考 MIG v4.1 CurrencyCodeEnum |
| `GroupMark` | 選填 | String | 彙開註記，`*` 或空值 |
| `BondedAreaConfirm` | 選填 | Number | 買受人簽署適用零稅率註記（零稅率發票可填）：1~4 |

其餘 `BuyerIdentifier` / `BuyerName` / `CarrierType` / `NPOBAN` / `ProductItem` /
`SalesAmount` / `TaxType` / `TaxRate` / `TaxAmount` / `TotalAmount` / `CustomsClearanceMark` /
`ZeroTaxRateReason` / `DetailVat` / `DetailAmountRound` / `PrinterType` / `PrinterLang` /
`MainRemark` 同 `f0401`。`ProductItem[]` 另可帶 `RelateNumber`（相關號碼，≤ 50 字）。

`f0401_custom` 的 `PrinterType`：1 Star mC-Print3 / 2 芯燁 XP-Q90EC。

### 回應 `data`（Object[]）

每筆：`invoice_number` / `barcode` / `qrcode_left` / `qrcode_right` / `base64_data`。

---

## 共用代碼對照表

### 發票類型代碼（`invoice_type`）

| 代碼 | 意義 |
|------|------|
| C0401 | B2C 存證發票開立 |
| C0501 | B2C 存證發票作廢 |
| C0701 | B2C 存證發票註銷 |
| A0401 | B2B 存證發票開立 |
| A0501 | B2B 存證發票作廢 |
| A0101 | B2B 交換發票開立 |
| A0102 | B2B 交換發票開立（接收確認） |
| A0201 | B2B 交換發票作廢 |
| A0202 | B2B 交換發票作廢（接收確認） |
| A0301 | B2B 交換發票退回 |
| A0302 | B2B 交換發票退回（接收確認） |

### 折讓單類型代碼

| 代碼 | 意義 |
|------|------|
| D0401 | B2C 存證折讓單開立 |
| D0501 | B2C 存證折讓單作廢 |
| B0401 | B2B 存證折讓單開立 |
| B0501 | B2B 存證折讓單作廢 |
| B0101 | B2B 交換折讓單開立 |
| B0102 | B2B 交換折讓單開立（接收確認） |
| B0201 | B2B 交換折讓單作廢 |
| B0202 | B2B 交換折讓單作廢（接收確認） |

### 上傳財政部狀態（`invoice_status` / `status`）

`1` 待處理、`2` 上傳中、`3` 已上傳、`31` 處理中、`32` 處理完成／待確認、
`91` 錯誤、`99` 完成。狀態 API 查無資料時回 `-1`。

### 課稅別（`TaxType`）

- 商品層級：`1` 應稅 / `2` 零稅率 / `3` 免稅
- 發票層級：`1` 應稅 / `2` 零稅率 / `3` 免稅 / `4` 應稅(特種稅率) / `9` 混合(限 C0401)

### 零稅率原因（`ZeroTaxRateReason`，零稅率發票必填）

| 代碼 | 條款 | 說明 |
|------|------|------|
| 71 | 第一款 | 外銷貨物 |
| 72 | 第二款 | 與外銷有關之勞務，或在國內提供而在國外使用之勞務 |
| 73 | 第三款 | 依法設立之免稅商店銷售與過境或出境旅客之貨物 |
| 74 | 第四款 | 銷售與保稅區營業人供營運之貨物或勞務 |
| 75 | 第五款 | 國際間之運輸（外國運輸事業須有相等待遇或免徵類似稅捐） |
| 76 | 第六款 | 國際運輸用之船舶、航空器及遠洋漁船 |
| 77 | 第七款 | 銷售與國際運輸用之船舶、航空器及遠洋漁船所使用之貨物或修繕勞務 |
| 78 | 第八款 | 保稅區營業人銷售與課稅區營業人未輸往課稅區而直接出口之貨物 |
| 79 | 第九款 | 保稅區營業人銷售與課稅區營業人存入自由港區事業 / 保稅倉庫 / 物流中心以供外銷之貨物 |

### 通關方式註記（`CustomsClearanceMark`）

零稅率發票必填：`1` 非經海關出口、`2` 經海關出口。非零稅率發票查詢時回 `0`。

### 期別（`Period`）

`0` 1-2月、`1` 3-4月、`2` 5-6月、`3` 7-8月、`4` 9-10月、`5` 11-12月。

### 熱感應機型號代碼（`PrinterType`）

`1` Star mC-Print3（base64 為 XML，僅支援 BIG5）；
`2` 芯燁 XP-Q90EC / Xprinter 芯燁通用（base64 為 ESC/POS，支援 BIG5、GBK 預設）。
更高代碼為其他機型，可支援功能詳見光貿後台「機型支援功能表」。
`PrinterLang`：`1` BIG5 / `2` GBK / `3` UTF-8。

---

## 金額計算

### 含稅商品（`DetailVat = 1`，預設）

```
SalesAmount        = Round( Σ ProductItem[TaxType=1].Amount )
FreeTaxSalesAmount = Round( Σ ProductItem[TaxType=3].Amount )
ZeroTaxSalesAmount = Round( Σ ProductItem[TaxType=2].Amount )

# 不打統編（BuyerIdentifier = 0000000000）
TaxAmount = 0

# 打統編（須分拆稅額）
TaxAmount   = SalesAmount - Round( SalesAmount / 1.05 )
SalesAmount = SalesAmount - TaxAmount      # SalesAmount 改為「未稅」

TotalAmount = SalesAmount + FreeTaxSalesAmount + ZeroTaxSalesAmount + TaxAmount
```

### 未稅商品（`DetailVat = 0`，僅打統編可用）

明細 `UnitPrice` / `Amount` 即未稅價。`SalesAmount` 為未稅小計加總，
`TaxAmount = Round(SalesAmount * 0.05)`，`TotalAmount = SalesAmount + TaxAmount + 其他稅別`。

### 折讓金額（`g0401`）

折讓的 `ProductItem` 單價 / 小計均為**不含稅**，`Tax` 為該品項稅金（整數）。
`TaxAmount` = 各品項 `Tax` 加總；`TotalAmount` = 各品項 `Amount`（不含稅）加總。
折讓金額不可大於原發票開立金額。

### 校驗錯誤碼

光貿端會重算並校驗，計算不符時回：`3040174` SalesAmount 計算錯誤 /
`3040175` ZeroTaxSalesAmount / `3040176` FreeTaxSalesAmount / `3040177` TaxAmount /
`3040178` TotalAmount。各銷售額不可為負（`3040181`–`3040184`）。
