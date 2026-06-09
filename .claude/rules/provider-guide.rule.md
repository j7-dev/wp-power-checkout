---
globs:
  - "inc/classes/Domains/**/*.php"
  - "js/src/pages/**/*.vue"
  - "js/src/router/**/*.ts"
  - "inc/assets/blocks/**/*.tsx"
---

# Adding New Providers Guide

## Adding a New Payment Provider

Payment 領域有顯式統一介面 `IPaymentProvider`（`Domains/Payment/Shared/Interfaces/IPaymentProvider.php`），extends `IGateway`，與 Logistics `ILogisticsProvider` / Invoice `IInvoiceService` 的設計哲學一致。

`AbstractPaymentGateway implements IPaymentProvider`，提供 `query_trade` / `capture` / `void_auth` / `get_supported_payment_methods` 的安全預設（no-op 或空陣列）；不支援該能力的金流無需覆寫，支援者自行覆寫。

1. Create domain folder: `Domains/Payment/NewProvider/`
2. Create gateway class extending `Shared\Abstracts\AbstractPaymentGateway` (already implements `IPaymentProvider`)
3. Define `const ID` and implement `get_settings()`, `before_process_payment()`
4. Override capability methods as needed: `process_refund()`, `query_trade()`, `capture()`, `void_auth()`, `get_supported_payment_methods()`
5. Create `DTOs/NewProviderSettingsDTO.php` (extend `BaseSettingsDTO` or `DTO` depending on mode handling needs)
6. Register in `Payment\ProviderRegister::$gateway_services`
7. Create block checkout entry: `inc/assets/blocks/new_provider_id.tsx`
8. Add Vue settings page: `js/src/pages/Payments/NewProvider/index.vue`
9. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`

## Adding a New Invoice Provider

1. Create domain folder: `Domains/Invoice/NewProvider/`
2. Create provider class extending `BaseService` and implementing `IInvoiceService`
3. Define `const ID` and implement `issue()`, `cancel()`, `get_invoice_number()`, `get_settings()`
4. Create settings DTO extending `BaseSettingsDTO`
5. Register in `Invoice\ProviderRegister::$invoice_providers`
6. Add Vue settings page: `js/src/pages/Invoices/NewProvider/index.vue`
7. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`

## Adding a New Logistics Provider

`ILogisticsProvider` is the unified abstraction (mirrors `IInvoiceService`). Designed to accommodate future providers (e.g. PAYUNi logistics).

1. Create domain folder: `Domains/Logistics/NewProvider/`
2. Create provider class implementing `ILogisticsProvider` (10 methods):
   - `get_settings(static)` — return settings array
   - `get_store_selection()` — phase A: build store picker redirect (RWD HTML)
   - `parse_store_selection()` — decode ClientReplyURL POST, write store meta to order
   - `create_shipment()` — phase B: create shipment from TempLogisticsID, return `logistics_id`
   - `query_shipment()`, `print_document()`, `cancel_shipment()` — query / print / cancel
   - `create_return()` — reserved, throw `\Exception('尚未實作')`
   - `handle_status_callback()` — parse ServerReplyURL POST; **must** return AES-JSON 3-layer response
   - `get_supported_methods()` — return enabled sub-type list
3. All failures must `throw` (REST layer catches and maps to HTTP code)
4. Status callbacks: all paths (including exceptions) must return AES-JSON 3-layer — never throw HTTP 500
5. Create settings DTO extending `BaseSettingsDTO`
6. Create WC_Shipping_Method subclass if needed (classic checkout)
7. Register in `Logistics\ProviderRegister::$logistics_providers`
8. Add Vue settings page: `js/src/pages/Logistics/NewProvider/index.vue`
9. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`
