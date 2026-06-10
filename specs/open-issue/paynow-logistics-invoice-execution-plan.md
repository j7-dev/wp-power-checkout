# Execution Plan — PayNow（立吉富）物流 + 電子發票（統一介面）

> Phase 01 Discovery 產出（2026-06-10）。後續 Phase 02-08 以此為 scope 依據。
> 由 clarifier（sub-agent）產出；3 個 scope 澄清題以「合理預設」推進（見 §澄清裁決），用戶可覆核。
>
> 硬約束：
>  - PayNow 物流必須 `implements ILogisticsProvider`（與 ECPay `ecpay_logistics` / PAYUNi `payuni_logistics` 平行）
>  - PayNow 電子發票必須 `implements IInvoiceService`（與 Amego / ECPay `ecpay` / ezPay `ezpay` 平行）
>  - 不破壞任何既有介面；API 能力落差用既有慣例（throw `\Exception('尚未實作')` / `WP_Error`）

## 澄清裁決（2026-06-10，預設值，待用戶覆核）

| # | 澄清題 | 裁決（預設） |
|---|--------|------------|
| Q1 | PayNow 物流 API 知識來源（paynow skill 無物流 API） | **以 woomp（../woomp/includes/paynow-shipping/）既有可運作實作反推**；標 `ASM(依 woomp 反推)`；Example 具體值待官方文件 + sandbox（GAP） |
| Q2 | 物流選店流程對映 ILogisticsProvider 三段式 | **沿用三段式介面**（與 ECPay/PAYUNi 對齊，零破壞）；create_return throw 尚未實作；~~handle_status_callback 退化為查詢補單（PayNow 無 webhook 推送證據）~~ → **R1 更正**：PayNow 確實推送貨態（woomp L34 實證），handle_status_callback 已實作為推送解析，query_shipment 保留為補單手段 |
| Q3 | 發票能力範圍 | **IInvoiceService + ISupportsAllowance + ISupportsQuery**（ezPay 等級）；POS 取號/開立排除（GAP） |

## 知識來源與第一性原理

PayNow（立吉富）官方有三套金流體系 + 電子發票 + 物流，但本專案 `paynow` skill **只收錄金流（體系1/2）+ 電子發票（體系3）**，**無物流 API**。

- **發票**：`paynow` skill `references/invoice-api.md` 完整（Bearer JWT-Token，issue/cancel/allowance/cancel-allowance/query/POS）→ 直接 grounding。
- **物流**：skill 無 → 改用 woomp（MorePower Addon v3.5.8）`includes/paynow-shipping/` 既有可運作實作反推 API contract（端點/加密/欄位皆有程式碼實證）。這是「不腦補」的合法 grounding：以實證程式碼為準，而非憑空編造。

## 統一介面對映

### 物流 → ILogisticsProvider（10 methods）

| 介面方法 | PayNow 對映（grounded from woomp） | 備註 |
|---------|-----------------------------------|------|
| `get_settings` | `woocommerce_paynow_logistics_settings` | user_account / apicode / 寄件人 / enabled_methods / mode |
| `get_store_selection` | 結帳頁 form-POST 導轉 `{api_url}/Member/Order/Choselogistics` 地圖頁（returnUrl callback） | 非 ECPay 的 AES RedirectToLogisticsSelection；apicode TripleDES 加密 |
| `parse_store_selection` | 收 returnUrl callback → 寫 storeid/storename/storeaddress meta | 黑貓宅配（TCAT）跳過選店 |
| `create_shipment` | `POST /api/Orderapi/Add_Order`（JsonOrder=base64(TripleDES(JSON))） | 回 LogisticNumber + paymentno + validationno |
| `query_shipment` | `GET /api/Orderapi/Get_Order_Info?LogisticNumber=&sno=` | 回 Status / Delivery_Status / PayNowLogisticCode |
| `print_document` | per-service：Order711 / OrderFamiC2C / OrderHiLife / PrintBlackCatLabel / Print711bulkLabel / Print711Freezing*Label ... | 回 label URL 或 PDF |
| `cancel_shipment` | `DELETE /api/Orderapi/CancelOrder`（LogisticNumber + sno + PassCode） | 不限 C2C（與 ECPay 僅 C2C 不同） |
| `create_return` | **throw `\Exception('尚未實作')`** | woomp 無逆物流 API 證據（GAP/BDY） |
| `handle_status_callback` | ~~退化為查詢補單~~ → **R1 更正：已實作貨態推送接收**（POST /wp-json/power-checkout/paynow/logistics/status-callback；orderno+LogisticCode 冪等；恆 200） | R1：woomp class-paynow-shipping-response.php L34 實證有 webhook 推送 |
| `get_supported_methods` | enabled_methods 子集（SEVEN/FAMI/HILIFE/TCAT + 冷凍變體） | 服務代碼 01-06 / 21-24 |

### 發票 → IInvoiceService + ISupportsAllowance + ISupportsQuery

| 介面方法 | PayNow 對映（grounded from skill invoice-api） | 端點 |
|---------|----------------------------------------------|------|
| `issue` | 開立發票 | `POST /api/invoices/issue` |
| `cancel` | 作廢發票 | `POST /api/invoices/cancel` |
| `get_invoice_number` | 從 `_pc_issued_invoice_data` 讀號碼 | — |
| `get_settings` | `woocommerce_paynow_invoice_settings` | jwt_token / seller 設定 / mode |
| `issue_allowance` | 開立折讓 | `POST /api/invoices/allowance` |
| `invalid_allowance` | 作廢折讓 | `POST /api/invoices/cancel-allowance` |
| `query_invoice` | 查詢發票 | `GET /api/invoices?InvoiceNumber=&OrderNo=` |

## 概覽

| 類型 | 數量 |
|------|------|
| Create | activity ×2、feature ×9、actor 更新 ×1、ui ×1 |
| Modify | 1（PayNow.md actor 補物流+發票角色） |
| Delete | 0 |

## Phase 02: Entity Modeling（erm.dbml — Phase 02 reconciler 處理）

### 物流 order meta（前綴 `_pc_paynow_logistics_`，與金流 `_pc_paynow_` 區隔）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `_pc_paynow_logistics_provider_id` | `paynow_logistics`（哪個物流 provider） |
| create | `_pc_paynow_logistics_service_id` | 物流服務代碼（01-06 / 21-24） |
| create | `_pc_paynow_logistics_store_id/name/addr` | 選店門市資訊 |
| create | `_pc_paynow_logistics_ref` | LogisticNumber（PayNow 物流單號；callback/query 反查主鍵） |
| create | `_pc_paynow_logistics_payment_no` | 物流商託運單號 paymentno |
| create | `_pc_paynow_logistics_validation_no` | 物流商驗證碼 validationno |
| create | `_pc_paynow_logistics_status` | 0=成立中 1=無效 + Delivery_Status 描述 |
| create | `_pc_paynow_logistics_delivery_type` | 黑貓溫層（常溫/冷藏/冷凍 0003） |
| create | Enum | LogisticService（SEVEN/FAMI/HILIFE/TCAT + 冷凍 C2C/大宗 變體）、DeliverMode（01 COD / 02 取貨不付款）、Status（0/1）、DeliveryType（溫層） |

### 發票 order meta（沿用 Invoice domain 共用 key）

| 操作 | 目標 | 說明 |
|------|------|------|
| reuse | `_pc_issued_invoice_data` | 開立回應（含 invoice_number；折讓 allowance_number） |
| reuse | `_pc_cancelled_invoice_data` | 作廢回應 |
| reuse | `_pc_invoice_provider_id` | `paynow`（哪個發票 provider） |
| reuse | `_pc_issue_invoice_params` | 結帳填寫的發票資訊 |
| create | Enum | carrier_type（None/PhoneBarCodeCarrier/EasyCardCarrier/CitizenDigitalCardNo/BuyerSno）、tax_type（SaleTax/FreeTax/ZeroTax/MixTax）、zero_tax_rate_reason |

## Phase 03: BDD Analysis（features — 本 Phase 01 產出骨架/Rules，Phase 03 補具體 Example）

### 物流（features/logistics/）

| 操作 | 目標 | 類型 | 外部觸發動作 |
|------|------|------|-------------|
| create | `paynow-logistics-store-selection.feature` | @command | 顧客結帳選 PayNow 物流 → 導轉 Choselogistics 地圖選店（階段 A） |
| create | `paynow-logistics-selection-callback.feature` | @command | PayNow 選店 callback 回門市 → 寫 store meta（階段 A.2） |
| create | `paynow-logistics-create-shipment.feature` | @command | 管理員出貨 → Add_Order 建單取 LogisticNumber（階段 B） |
| create | `paynow-logistics-query.feature` | @query | 管理員查物流單狀態（Get_Order_Info）+ 補單 |
| create | `paynow-logistics-print-document.feature` | @command | 管理員列印託運單（per-service 端點） |
| create | `paynow-logistics-cancel.feature` | @command | 管理員取消物流單（CancelOrder DELETE + PassCode） |

> create_return（逆物流）不出 feature → throw 尚未實作（GAP）。
> handle_status_callback 收進 query feature（PayNow 為主動查詢補單，非 webhook 推送）。

### 發票（features/invoice/）

| 操作 | 目標 | 類型 | 外部觸發動作 |
|------|------|------|-------------|
| create | `paynow-invoice-issue.feature` | @command | 開立/作廢發票（B2C/B2B/載具/捐贈/零稅率/混稅）— 沿用 /invoices/issue\|cancel 端點，provider=paynow |
| create | `paynow-invoice-allowance.feature` | @command | 折讓開立/作廢（退款觸發）— ISupportsAllowance |
| create | `paynow-invoice-query.feature` | @query | 查詢發票明細 — ISupportsQuery |

## Phase 04: API Contract（api.yml — Phase 04 reconciler 處理）

| 操作 | 目標 | 說明 |
|------|------|------|
| reuse | REST `/logistics/{order_id}/store-selection` `/create-shipment` `/{order_id}`(GET) `/print` `/cancel` | 沿用既有 LogisticsApiService 5 端點；gateway 內部分流 PayNow |
| create | `POST /paynow/logistics/selection-callback`（returnUrl） | PayNow 選店地圖 callback（permission `__return_true`，內部驗 user_account/PassCode） |
| reuse | REST `/invoices/issue/{order_id}` `/cancel/{order_id}` | 沿用既有 InvoiceApiService；provider=paynow 分流 |
| create | admin order action（發票折讓 / 折讓作廢 / 查詢；物流補查） | 後台訂單頁手動操作（比照既有 provider 慣例） |

> ⚠️ PayNow 物流**無 ServerReplyURL 貨態推送證據**（woomp 是主動 query Get_Order_Info）→ 不建 status-callback 端點，貨態靠查詢補單（BDY，待官方文件確認是否有 webhook 推送）。

## Phase 05-08: Implementation（TDD）

### 物流

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Domains/Logistics/Paynow/` domain folder | 比照 `Logistics/Payuni/` 結構 |
| create | `Services/PaynowLogisticsProvider.php` | implements ILogisticsProvider 10 methods；const ID='paynow_logistics' |
| create | `Services/WC_PaynowLogisticsShipping.php` | extends WC_Shipping_Method（classic 結帳，per-service 多運送方式） |
| create | `Http/LogisticsApiClient.php` | Add_Order / ReNewOrder / CancelOrder / Get_Order_Info（TripleDES + PassCode） |
| create | `Http/LogisticsCallback.php` | selection-callback（returnUrl 收門市） |
| create | `Shared/Helpers/TripleDesCrypto.php` | ~~DES-EDE3 CBC~~ → **R2 實作更正：兩種獨立模式**：(a) `encrypt_order_json` = DES-EDE3 ECB（OpenSSL 無 -CBC 後綴 → IV 忽略）+ NO_PADDING + 手動 \0 pad；(b) `encrypt_apicode` = DES-EDE3-ECB + ZERO_PADDING + 空格轉 +；固定 key/IV；兩者不可互換 |
| create | `Shared/Helpers/PassCodeService.php` | sha1(user_account+OrderNo+TotalAmount+apicode) strtoupper |
| create | `Shared/Helpers/PaynowLogisticsMetaKeys.php` | meta key 前綴 `_pc_paynow_logistics_` |
| create | `Shared/Enums/PaynowLogisticService.php`、`PaynowDeliverMode.php`、`PaynowLogisticsStatus.php` | 服務代碼 / 取貨付款 / 貨態 |
| create | `DTOs/PaynowLogisticsSettingsDTO.php`、`CreateShipmentParams.php`、`StoreSelectionParams.php` | extends BaseSettingsDTO + request 組裝 |
| register | `Logistics\ProviderRegister::$logistics_providers` | 加 `PaynowLogisticsProvider::ID => ::class` |
| create | Vue 設定頁 `js/src/pages/Logistics/Paynow/index.vue` + router + ROUTER_MAPPER | |

### 發票

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Domains/Invoice/Paynow/` domain folder | 比照 `Invoice/Ezpay/` 結構 |
| create | `Services/PaynowInvoiceProvider.php` | implements IInvoiceService + ISupportsAllowance + ISupportsQuery；const ID='paynow' |
| create | `Http/InvoiceApiClient.php` | issue/cancel/allowance/cancel-allowance/query（Bearer JWT-Token） |
| create | `DTOs/PaynowInvoiceSettingsDTO.php`、`IssueParams.php`、`AllowanceParams.php`、`QueryParams.php` | extends BaseSettingsDTO + request 組裝 |
| create | `Shared/Enums/ECarrierType.php`、`ETaxType.php`、`EZeroTaxReason.php` | 載具 / 課稅別 / 零稅率原因 |
| register | `Invoice\ProviderRegister::$invoice_providers` | 加 `PaynowInvoiceProvider::ID => ::class`（⚠️ ID 衝突檢查，見 GAP） |
| create | Vue 設定頁 `js/src/pages/Invoices/Paynow/index.vue` + router + ROUTER_MAPPER | |

## GAP / 風險登記

| 項目 | 狀態 | 處理 |
|------|------|------|
| PayNow 物流官方 API 文件 | **GAP** | paynow skill 無物流；本規格依 woomp 反推。**需向 PayNow 索取物流 API 官方文件**核對端點/加密/欄位/錯誤碼；sandbox 端到端待憑證 |
| PayNow 物流 sandbox 憑證（user_account / apicode） | **GAP** | 用戶尚未申請；以 API_MODE=mock 跑綠為驗收主軸 |
| PayNow 發票 sandbox 憑證（商家 JWT-Token） | **GAP** | 需向 PayNow 申請商家 JWT-Token；以 mock 為主，憑證到位補 sandbox |
| 逆物流（create_return） | **GAP/BDY** | woomp 無 PayNow 逆物流 API 證據 → throw `\Exception('尚未實作')`（既有慣例）；待官方文件確認 |
| 物流貨態通知機制 | **BDY** | woomp 為主動查詢（Get_Order_Info）非 webhook 推送 → handle_status_callback 退化為查詢補單；待官方文件確認是否有 ServerReplyURL webhook |
| ⚠️ 發票 provider ID `paynow` 與金流 gateway ID `paynow` **同名** | **CON（須裁決）** | 金流 gateway 已用 `paynow`；發票 provider 也想用 `paynow`。Invoice 與 Payment 是不同 domain 不同 register，技術上 option key 不衝突（`woocommerce_paynow_settings` 金流 vs 發票需另命名）。**建議發票 provider ID 用 `paynow_invoice`、option `woocommerce_paynow_invoice_settings`**，避免與金流 `woocommerce_paynow_settings` 撞 option。實作階段須確認 ProviderUtils 容器 key 唯一性 |
| 物流 provider ID | 已定 | `paynow_logistics`（與金流 `paynow` 區隔，與 ecpay_logistics 命名一致） |
| TripleDES 固定 key/IV | 須注意 | woomp 用 key=`123456789070828783123456`(24B) iv=`12345678`；**R2 實作更正**：OpenSSL `DES-EDE3`（不帶 -CBC）實為 ECB 變體（IV 被忽略），非 CBC；prod 須向 PayNow 確認是否換鑰（GAP） |
| POS 取號/開立 | 排除 | 一般 WooCommerce 電商不走 POS（Q3 裁決排除，GAP 後續） |
| 冷凍交貨便（SEVENFROZEN/FAMIFROZEN 等） | 範圍內但複雜 | woomp 有完整實作（含 woomp-paynow-shipping 子模組）；首期可先做常溫超商+黑貓，冷凍列第二期（實作階段裁決） |
| Example 具體資料 | 待補 | Phase 03 以 paynow skill（發票）+ woomp（物流）+ sandbox 驗證補充；NotifyURL payload / LogisticNumber 具體值待 sandbox |
| lib 評估 | 不需要 | 無新增第三方 library（TripleDES 用 PHP openssl 內建；Bearer 用 wp_remote_*）；不觸發 lib-skill-creator |
