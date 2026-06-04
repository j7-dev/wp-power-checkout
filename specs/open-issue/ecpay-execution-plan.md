# Execution Plan — 綠界 ECPay 金流 + 發票整合（決策定案版）

> Phase 01 Discovery 產出。後續 Phase 02-08 的 scope 依據。
> 起始狀態：**existing**（既有 SLP/Amego specs + ECPay 半成品骨架程式碼）。
> ✅ 全部窄門決策已拍板（D1-D6 + D-cred）。本版為定案 scope，無殘留 blocker。

## 決策定案摘要

| # | 決策 | 定案 |
|---|------|------|
| D1 | 舊 code 去留 | **A：砍掉舊 EcpayAIO，重建於 `Shared\Abstracts\AbstractPaymentGateway`**。搶救：`Service::get_check_value()`（CheckMacValue SHA256）、`Utils\Base::urlencode()`（綠界 .NET urlencode）、`RequestParams` AIO 參數結構、6 個 block tsx 可改寫 |
| D2+D3 | 架構 + 整合模式 | **AIO 導轉 + ECPG 站內付「兩者並存」，每整合模式一個 gateway**：`ecpay_aio`（導轉，CheckMacValue SHA256）+ `ecpay_ecpg`（站內付 2.0，AES-128-CBC + 3DS ThreeDURL）。付款方式作為 gateway 內設定，不切成獨立 gateway |
| D4 | 付款方式範圍 | **完整**：信用卡（一次付清 / 分期 CreditInstallment / 定期定額 PeriodAmount）、ATM、WebATM、CVS、BARCODE、ApplePay |
| D5 | 退款範圍 | **僅信用卡 API 退款**：AIO 走 DoAction（Action=R 退刷 / N 取消授權）；ECPG 走對應退款 API。ATM/WebATM/CVS/BARCODE/ApplePay 無 API 退款 → 標「需綠界後台人工」+ WC 手動退款 |
| D6 | 發票整合 | **Amego + 綠界發票兩者並存**。綠界電子發票（B2C/B2B）新增為 Invoice domain 新 provider，與 Amego 並列 `$invoice_providers`，後台可切換 |
| D-cred | 憑證 | 測試用綠界公開測試帳號（merchant 3002599…）；正式 MerchantID/HashKey/HashIV 由後台 Vue 設定頁填入存 `woocommerce_{gateway_id}_settings`，**勿寫死於 code**（修掉舊 `set_properties()` 反模式） |

---

## 概覽

| 類型 | 數量 |
|------|------|
| Create | Activity 2（AIO 流程 + ECPG 流程）+ 付款 Feature 7 + Actor 1 + UI 2 |
| Modify | erm.dbml（ECPay meta + enum + 發票 provider enum）+ api.yml（ECPay callback endpoints + 發票 provider enum）+ 既有 invoice-issue/cancel.feature（加綠界 provider 情境） |
| Delete | 舊 `EcpayAIO/` 整個資料夾（重建，實作階段確認）+ 6 個舊 `pc_ecpayaio_*.tsx` |

---

## Phase 02: Entity Modeling（erm.dbml）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | order meta keys | 新增 ECPay 專用 meta：`pc_ecpay_trade_no`（MerchantTradeNo 冪等鍵）、`pc_ecpay_payment_detail`（綠界回傳付款明細）、`pc_ecpay_payment_info`（ATM/CVS/BARCODE 取號資訊：虛擬帳號/超商代碼/條碼/繳費期限） |
| modify | enum | 新增 `ecpay_payment_method`（Credit / CreditInstallment / Period / ATM / WebATM / CVS / BARCODE / ApplePay）、`ecpay_integration_mode`（aio / ecpg） |
| modify | invoice provider enum | 既有發票 provider 值域擴充：`amego` + `ecpay`（綠界發票） |

## Phase 03: BDD Analysis（features Examples）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | payment/ecpay-aio-checkout | AIO 建單 + CheckMacValue + auto-form 跳轉 Cashier V5 |
| create | payment/ecpay-aio-callback | AIO ReturnURL 幕後通知（CheckMacValue 驗證 + 冪等 + 1\|OK） |
| create | payment/ecpay-aio-payment-info | AIO PaymentInfoURL 取號通知（ATM/CVS/BARCODE） |
| create | payment/ecpay-ecpg-checkout | ECPG 站內付 token + CreatePayment + ThreeDURL 3DS 導向 |
| create | payment/ecpay-ecpg-callback | ECPG ReturnURL（JSON POST + AES 解密 + 1\|OK） |
| create | payment/ecpay-refund | 信用卡 DoAction 退款（AIO/ECPG）；非信用卡標人工 |
| create | payment/ecpay-credit-variants | 信用卡分期 / 定期定額 參數規則 |
| modify | invoice/invoice-issue | 加「綠界 provider 開立」情境（B2C/B2B），沿用既有 endpoint |
| modify | invoice/invoice-cancel | 加「綠界 provider 作廢」情境 |

## Phase 04: API Contract（api.yml）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | POST /power-checkout/ecpay/aio/return | AIO ReturnURL 幕後通知（Form POST，CheckMacValue，回 `1\|OK`） |
| create | POST /power-checkout/ecpay/aio/payment-info | AIO PaymentInfoURL 取號通知（Form POST，回 `1\|OK`） |
| create | POST /power-checkout/ecpay/ecpg/return | ECPG ReturnURL（JSON POST，AES 解密，回 `1\|OK`） |
| modify | /invoices/issue/{order_id} | request `provider` enum 加 `ecpay` |
| modify | /invoices/cancel/{order_id} | 同上 |

## Phase 05-08: Implementation

### 砍除（D1=A，需實作階段確認）

| 操作 | 目標 |
|------|------|
| delete | `EcpayAIO/Abstracts/PaymentGateway.php`、`Core/{Atm,Barcode,Credit,CreditInstallment,CVS,WebAtm,Init}.php`、`Services/Service.php`（搶救演算法後）、`DTOs/{Settings,ParamsTrait,ResponseParams}.php` |
| delete | `inc/assets/blocks/pc_ecpayaio_{atm,barcode,credit,credit_installment,cvs,webatm}.tsx`（6 個） |

### 重建 — Payment（兩 gateway）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `EcpayAIO/Services/AioRedirectGateway.php` | extends Shared\AbstractPaymentGateway，`const ID='ecpay_aio'`；override before_order_received（auto-form submit）、process_refund、get_settings、process_admin_options、init、register_checkout_blocks |
| create | `Ecpg/Services/EcpgGateway.php` | extends Shared\AbstractPaymentGateway，`const ID='ecpay_ecpg'`；站內付 token + CreatePayment 流程 |
| migrate | `EcpayAIO/Shared/Helpers/CheckMacValueService.php` | 從舊 Service::get_check_value 搶救（SHA256） |
| migrate | `EcpayAIO/Shared/Helpers/UrlEncoder.php` | 從舊 Utils\Base::urlencode 搶救（綠界 .NET urlencode） |
| create | `Ecpg/Shared/Helpers/AesCrypto.php` | ECPG AES-128-CBC 加解密（ECPay-API-Skill guides/14） |
| create | `EcpayAIO/DTOs/AioSettingsDTO.php`、`Ecpg/DTOs/EcpgSettingsDTO.php` | extends BaseSettingsDTO；欄位：merchant_id/hash_key/hash_iv/mode/allowed_payments/installment_periods/period_config/min_amount/max_amount/expire_date。**憑證存 WC option，不寫死** |
| refactor | `EcpayAIO/DTOs/RequestParams.php` | 對齊新 Settings DTO + ProviderUtils；修 `Services`→`Service` typo；移除寫死憑證 |
| create | `EcpayAIO/Http/AioCallback.php`、`Ecpg/Http/EcpgCallback.php`（extends ApiBase） | ReturnURL + PaymentInfoURL REST 端點；CheckMacValue（AIO）/ AES（ECPG）驗證 |
| create | `EcpayAIO/Managers/StatusManager.php`（共用或各自） | RtnCode → WC 訂單狀態（對齊 SLP StatusManager） |
| modify | `Payment\ProviderRegister.php` | 取消註解；`$gateway_services` 加入 `ecpay_aio` + `ecpay_ecpg` |

### 重建 — Frontend（兩 gateway）

| 操作 | 目標 |
|------|------|
| create | `inc/assets/blocks/ecpay_aio.tsx`、`inc/assets/blocks/ecpay_ecpg.tsx`（取代 6 個舊 tsx） |
| create | `js/src/pages/Payments/EcpayAio/index.vue` + Shared/{types,enums}.ts |
| create | `js/src/pages/Payments/EcpayEcpg/index.vue` + Shared/{types,enums}.ts |
| modify | `js/src/router/index.ts` ROUTER_MAPPER（加 `/payments/ecpay_aio`、`/payments/ecpay_ecpg`） |

### 新增 — Invoice（D6：綠界發票 provider）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Invoice/Ecpay/EcpayInvoiceProvider.php` | extends BaseService implements IInvoiceService；`const ID='ecpay'`；實作 issue()/cancel()/get_invoice_number()/get_settings()（參考 Amego/AmegoProvider） |
| create | `Invoice/Ecpay/Http/InvoiceApiClient.php` + DTOs | 綠界 B2C/B2B 發票 API（AES-JSON，ECPay-API-Skill guides/04+05） |
| create | `Invoice/Ecpay/DTOs/EcpayInvoiceSettingsDTO.php` | extends BaseSettingsDTO |
| modify | `Invoice\ProviderRegister.php` | `$invoice_providers` 加入 `ecpay` |
| create | `js/src/pages/Invoices/Ecpay/index.vue` + Shared/{types,enums}.ts | 綠界發票 Vue 設定頁 |
| modify | `js/src/router/index.ts` ROUTER_MAPPER（加 `/invoices/ecpay`） |

---

## 待評估 Library

無新第三方 library：
- AIO CheckMacValue = PHP 內建 `hash('sha256', ...)`
- ECPG / 發票 AES-128-CBC = PHP 內建 `openssl_encrypt` / `openssl_decrypt`
- 不觸發 lib-skill-creator 流程。API reference 全程使用 `ECPay-API-Skill`。

## 殘留待決點

無業務 blocker。以下為 Phase 03-05 實作階段的細節（非業務窄門，實作者依 ECPay-API-Skill references 即時查證決定）：
- 信用卡分期期數預設值域（3/6/12/18/24/30）— 後台設定，預設全開
- 定期定額 PeriodType/Frequency/ExecTimes 細部欄位 — 依 ECPay-API-Skill guides/01 §定期定額
- ChoosePayment 組合策略（單一 vs ALL+IgnorePayment）— 建議 ALL+IgnorePayment 由後台勾選控制
