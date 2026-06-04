---
name: amego-invoice
description: >
  光貿電子發票（Amego e-invoice）加值中心 API 完整技術參考，對應官方文件
  invoice.amego.tw/api_doc（MIG 4.0，2025 年升級版）。台灣電子發票服務商，
  涵蓋 B2C / B2B 發票全生命週期：開立發票（自動配號 f0401 / API 配號 f0401_custom）、
  作廢發票（f0501）、開立折讓（g0401）、作廢折讓（g0501）、發票與折讓的
  狀態 / 查詢 / 列表 / 檔案下載 / 熱感應機列印、中獎查詢、手機條碼查詢、
  公司名稱查詢、字軌取號與狀態。包含 md5 簽章機制（sign = md5(data + time + AppKey)）、
  載具規則（手機條碼 3J0002 / 自然人憑證 CQ0001 / 光貿會員載具 amego）、
  捐贈碼、課稅別與零稅率原因、含稅 / 未稅金額計算邏輯、完整錯誤碼表。
  Use this skill whenever code or tasks involve 光貿、Amego、amego、amego invoice、
  電子發票、invoice.amego.tw、invoice-api.amego.tw、開立發票、發票作廢、折讓單、
  開立折讓、作廢折讓、發票查詢、發票列表、中獎發票、手機條碼、捐贈碼、載具、
  字軌取號、TrackApiCode、CarrierType、NPOBAN、ProductItem、TaxType、
  /json/f0401、/json/f0501、/json/g0401、/json/g0501、/json/f0401_custom、
  /json/invoice_query、/json/allowance_query、台灣電子發票串接、e-invoice integration、
  或在 zenbu-site apps/api-gateway/src/commerce/ 下整合台灣電子發票開立。
  本 SKILL 為唯一官方 API reference 來源——不要再去翻 invoice.amego.tw。
---

# 光貿電子發票（Amego e-invoice）API

光貿電子發票加值中心提供台灣財政部電子發票（MIG 4.0）的開立、作廢、折讓、查詢服務。
所有 API 採 **POST + `application/x-www-form-urlencoded`**（伺服器時間查詢例外，走 GET），
回傳 JSON。本專案 zenbu-site 為 NestJS 11 + TypeORM 0.3 後端，金流 / 第三方整合慣例位於
`apps/api-gateway/src/commerce/`。

## 環境與認證

```
API 網址（測試 + 正式同一個）：https://invoice-api.amego.tw
測試後台：https://invoice.amego.tw/  帳號 test@amego.tw  密碼 12345678
測試統編：12345678   測試 App Key：sHeq7t8G1wiQvhAuIM27
正式統編 / App Key：向光貿客服申請
```

- **無 sandbox 子網域**——測試與正式共用同一個 API 網址。切換環境只換「統編 + App Key」，
  不換 URL。正式上線只需把 `invoice` 與 `App Key` 從測試值改成貴公司的值。
- 測試環境**不會主動寄發票通知信**；要收測試信件需登入測試後台手動按「補發發票開立通知」。

### 每支 API 的 4 個必備傳輸參數

所有業務 API（除 `/json/time`）的 `Content-Type` **必須**是
`application/x-www-form-urlencoded`，**禁止** `application/json`。POST body 帶 4 欄：

| 欄位 | 類型 | 說明 |
|------|------|------|
| `invoice` | String | 統一編號（你的公司統編，不是買方統編） |
| `data` | String | 業務參數編成的 JSON 字串（B2C/B2B 欄位放這裡），需 url encode |
| `time` | Number | Unix 時間戳記（10 位數，不含毫秒）；與伺服器時間誤差須 ±60 秒內 |
| `sign` | String | md5 簽章，見下方 |

### sign 簽章規則（最容易出錯）

```
sign = md5( data_json_string + time_string + app_key )
```

三段**直接字串相接**（無分隔符、無換行）後做 MD5，輸出 32 字元小寫 hex：

1. `data_json_string` — 業務參數 `JSON.stringify` 後的字串。**簽章用的字串必須與
   實際送出 `data` 欄位的字串一字不差**（同樣的 key 順序、同樣的空白、同樣的跳脫）。
2. `time_string` — `time` 轉成字串（與 body 的 `time` 同值）。
3. `app_key` — App Key 原文。

> **關鍵陷阱**：先決定 `data` 的最終 JSON 字串，再用「同一份字串」算 sign。
> 不要用物件算 sign、再用另一次序列化送出——兩次序列化若有差異（空白 / Unicode 跳脫 /
> key 順序）簽章就驗不過（錯誤碼 16）。中文建議用 `JSON_UNESCAPED_UNICODE`（PHP）/
> `ensure_ascii=False`（Python）/ JS `JSON.stringify` 預設不跳脫非 ASCII，三端要一致。

NestJS / TypeScript 簽章範例與完整呼叫流程見 `references/integration.md`。

## API 端點總覽

API 網址前綴一律 `https://invoice-api.amego.tw`。路徑欄位為 `data` JSON 內容的形狀提示。

### 發票

| 功能 | Method + Path | `data` 形狀 | 說明 |
|------|---------------|-------------|------|
| 開立發票（自動配號） | POST `/json/f0401` | Object | 系統自動配發票號碼，回傳號碼 / 時間 / 隨機碼 |
| 作廢發票 | POST `/json/f0501` | Array | 可一次作廢多張 |
| 發票狀態 | POST `/json/invoice_status` | Array | 查上傳財政部的進度 |
| 發票查詢 | POST `/json/invoice_query` | Object | 查單張完整內容（含明細） |
| 發票列表 | POST `/json/invoice_list` | Object | 依日期區間分頁查主檔 |
| 發票檔案 | POST `/json/invoice_file` | Object | 下載 PDF（連結 10 分鐘有效） |
| 發票列印 | POST `/json/invoice_print` | Object | 產出熱感應機 base64 列印字串 |

### 折讓

| 功能 | Method + Path | `data` 形狀 | 說明 |
|------|---------------|-------------|------|
| 開立折讓 | POST `/json/g0401` | Array | 折讓已開立的發票（一張折讓對一張原發票） |
| 作廢折讓 | POST `/json/g0501` | Array | 可一次作廢多張 |
| 折讓狀態 | POST `/json/allowance_status` | Array | 查上傳進度 |
| 折讓查詢 | POST `/json/allowance_query` | Object | 查單張折讓完整內容 |
| 折讓列表 | POST `/json/allowance_list` | Object | 依日期區間分頁 |
| 折讓檔案 | POST `/json/allowance_file` | Object | 下載 PDF（可無限次） |
| 折讓列印 | POST `/json/allowance_print` | Object | 產出熱感應機 base64 列印字串 |

### 中獎 / 其他 / 自行配號

| 功能 | Method + Path | 說明 |
|------|---------------|------|
| 獎項定義 | POST `/json/lottery_type` | 中獎類型代碼對照（`data` 不需內容） |
| 中獎發票 | POST `/json/lottery_status` | 依年 / 期別查中獎發票（建議雙月 1 號後查） |
| 手機條碼查詢 | POST `/json/barcode` | 驗證手機條碼是否真實存在 |
| 公司名稱查詢 | POST `/json/ban_query` | 統編 → 公司名稱（資料來源財政部） |
| 所有字軌資料 | POST `/json/track_all` | 三層字軌結構（財政部配給 / 給光貿 / 字軌列表） |
| 伺服器時間 | **GET** `/json/time` | 取伺服器時間校正 `time`，無需 sign |
| 字軌取號 | POST `/json/track_get` | 取「API 配號」字軌（1 本 = 50 張） |
| 字軌狀態 | POST `/json/track_status` | 查「API 配號」字軌的配號進度 |
| 開立發票（API 配號） | POST `/json/f0401_custom` | 自行指定發票號碼 / 日期 / 隨機碼 |

> **MIG 4.0 路徑異動（2025-01-01）**：舊路徑 `c0401`/`c0501`/`d0401`/`d0501`/`c0401_custom`
> 已分別改為 `f0401`/`f0501`/`g0401`/`g0501`/`f0401_custom`。舊路徑暫可續用，新整合一律用新路徑。

完整逐欄位 request / response 參數表見 `references/api-reference.md`。

## 自動配號 vs API 配號（兩種開立方式）

| 維度 | 自動配號 `/json/f0401` | API 配號 `/json/f0401_custom` |
|------|------------------------|-------------------------------|
| 發票號碼 | 系統自動配發，回傳給你 | 你自己指定 `InvoiceNumber` |
| 發票日期 / 時間 | 系統用開立當下 | 你自己指定 `InvoiceDate` / `InvoiceTime` |
| 隨機碼 | 系統產生，回傳 `random_number` | 你自己指定 `RandomNumber`（必填） |
| 字軌來源 | 後台「發票字軌列表」排序或 `TrackApiCode` 指定 | 須先用 `/json/track_get` 取「API 配號」字軌 |
| 適用情境 | 一般電商 / POS，最常用 | 需自管號碼池、離線開立後補上傳 |
| 一次筆數 | 單張（`data` 是 Object） | 可多張（`data` 是 Array）；但帶 `PrinterType` 時限單張 |

**絕大多數整合用自動配號（`f0401`）即可。** API 配號適用「自己持有財政部字軌、要自管號碼」的進階場景。

## 課稅別、零稅率與金額計算

### TaxType 課稅別

- 商品層級 `ProductItem[].TaxType`：`1` 應稅、`2` 零稅率、`3` 免稅。
- 發票層級 `TaxType`：`1` 應稅、`2` 零稅率、`3` 免稅、`4` 應稅（特種稅率）、
  `9` 混合應稅與免稅或零稅率（僅 C0401 訊息可用）。

### 零稅率（TaxType=2）必填欄位

`CustomsClearanceMark`（通關方式：1 非經海關出口 / 2 經海關出口）與
`ZeroTaxRateReason`（71–79 九款原因）。完整 71–79 對照見 `references/api-reference.md`。

### 金額計算邏輯（順序敏感，開立前先在後端算好）

`f0401` / `f0401_custom` 的 `SalesAmount` / `TaxAmount` / `TotalAmount` 必須自行算出並傳入，
**且通過光貿端的計算校驗**（否則錯誤碼 3040174–3040178）。

含稅商品（`DetailVat = 1`，預設）：

```
SalesAmount          = Round( 所有 TaxType=1 的 ProductItem Amount 加總 )
FreeTaxSalesAmount   = Round( 所有 TaxType=3 的 ProductItem Amount 加總 )
ZeroTaxSalesAmount   = Round( 所有 TaxType=2 的 ProductItem Amount 加總 )

# 不打統編（買方 BuyerIdentifier = 0000000000）→ 不分拆稅額
TaxAmount   = 0

# 打統編 → 須從含稅銷售額分拆出 5% 稅額
TaxAmount   = SalesAmount - Round( SalesAmount / 1.05 )
SalesAmount = SalesAmount - TaxAmount        # SalesAmount 改存「未稅銷售額」

TotalAmount = SalesAmount + FreeTaxSalesAmount + ZeroTaxSalesAmount + TaxAmount
```

未稅商品（`DetailVat = 0`，**只有打統編發票可用**）：明細單價 / 小計即未稅價，
`SalesAmount` 直接為未稅小計加總，`TaxAmount = Round(SalesAmount * 0.05)`。

> **核心規則**：沒打統編（B2C 一般消費者）→ `TaxAmount` 一律帶 `0`；打統編（B2B）→ 必須分拆 5% 稅額。
> 金額計算與校驗、折讓金額計算的完整範例見 `references/api-reference.md`「金額計算」章節。

## 載具與捐贈

開立發票時 `CarrierType` / `CarrierId1`（顯碼）/ `CarrierId2`（隱碼）三者搭配；
捐贈用 `NPOBAN`。**載具與捐贈互斥，列印發票（PrintMark=Y）也不可帶載具 / 捐贈。**

| 載具種類 | `CarrierType` | 顯碼 / 隱碼規則 |
|----------|---------------|------------------|
| 手機條碼 | `3J0002` | 條碼正規式 `^\/[0-9A-Z\+\-\.]{7}$`；可用 `/json/barcode` 驗存在性 |
| 自然人憑證條碼 | `CQ0001` | 正規式 `^TP[0-9]{14}$` |
| 光貿會員載具 | `amego` | 顯碼 / 隱碼為 `a+手機號碼`（`^a09[0-9]{8}$`）或電子信箱 |
| 自家會員載具 | 自申請代碼 | 可帶貴公司自行向光貿申請的會員載具代碼 |

捐贈碼 `NPOBAN`：受捐贈機關 / 團體的愛心碼，需為財政部清單內有效碼。
載具規則、捐贈碼來源、統編檢查邏輯見 `references/concepts.md`。

## 回應格式與狀態碼

所有 API 回傳 JSON，最外層固定有 `code`（`0` = 成功，其他為錯誤）與 `msg`（錯誤訊息）。
成功時依 API 附帶 `data` / `invoice_number` / `page_total` 等欄位。

`invoice_status`（上傳財政部狀態）共用語意：`1` 待處理、`2` 上傳中、`3` 已上傳、
`31` 處理中、`32` 處理完成／待確認、`91` 錯誤、`99` 完成。

> **重要**：`f0401` 回 `code: 0` 只代表「光貿成功收件並配號」，**不代表已上傳財政部成功**。
> 真正的上傳結果要靠 `invoice_status` / `invoice_query` 輪詢 `invoice_status` 欄位
> 直到 `99`（完成）或 `91`（錯誤）。作廢 / 折讓同理。

通用錯誤碼（如 `16` 簽名驗證錯誤、`15` Time 錯誤、`12` 統編錯誤、`22` 尚未申請 API 串接）
與各 API 專屬錯誤碼完整表見 `references/error-codes.md`。

## 整合工作流程（典型電商）

```
1. 結帳完成 → 後端組 ProductItem + 算 SalesAmount/TaxAmount/TotalAmount
2. 決定載具 / 捐贈 / 統編（互斥規則）→ 組 data JSON
3. 算 sign = md5(data + time + AppKey) → POST /json/f0401
4. 回 code:0 → 存下 invoice_number / random_number，標記「開立中」
5. （非同步）輪詢 /json/invoice_status → invoice_status=99 標記「已完成」
6. 退貨 → POST /json/g0401 開折讓（一張折讓對一張原發票）
7. 整張取消 → POST /json/f0501 作廢（須在當期、且尚未有折讓）
8. 需給客戶 PDF → /json/invoice_file 拿 10 分鐘有效連結
```

作廢 vs 折讓的選擇、idempotency（`OrderId` / `AllowanceNumber` 不可重複）、
NestJS service pattern 見 `references/integration.md`。

## 禁止事項與常見陷阱

- ❌ 不要用 `Content-Type: application/json`（伺服器只吃 form-urlencoded，會失敗）。
- ❌ 不要用「物件算 sign、另一次序列化送 data」——兩份字串必須完全相同（否則 code 16）。
- ❌ 不要把 `time` 用毫秒（必須 10 位數秒級；誤差超過 ±60 秒回 code 15）。
- ❌ `OrderId`（f0401）/ `AllowanceNumber`（g0401）不可重複——重送會回重複錯誤，需先查再開。
- ❌ 沒打統編的發票不要自己算稅額——`TaxAmount` 一律 `0`，打統編才分拆 5%。
- ❌ 不要對「已有折讓」的發票作廢（須先作廢折讓）；不要對跨期發票作廢（須在當期）。
- ❌ 不要假設 `f0401` 回 `code:0` = 財政部上傳成功——要輪詢 `invoice_status` 到 `99`。
- ❌ 列印發票（`PrintMark=Y` / 自動配號預設列印）不可帶載具或捐贈碼。
- ❌ 發票檔案 / 列印只能查 180 天內、以發票日期為準的發票。

## Reference 檔案導覽

| 檔案 | 內容 |
|------|------|
| `references/api-reference.md` | 24 支 API 逐欄位 request / response 參數表、JSON 範例、金額計算、零稅率原因 71–79、發票 / 折讓類型代碼（C0401/D0401/A0401…）、字軌狀態、中獎獎項 |
| `references/error-codes.md` | 通用錯誤碼 + 每支 API 專屬錯誤碼完整對照表 |
| `references/concepts.md` | 載具規則、捐贈碼、統編檢查、字軌與配號、發票生命週期、MIG 4.0 異動、台灣電子發票背景知識 |
| `references/integration.md` | NestJS / TypeScript 整合：sign 簽章函式、AmegoService pattern、ConfigService、錯誤處理、輪詢策略、zenbu-site commerce 對齊建議 |
