# Conventions

## Provider system
- `ProviderUtils::is_enabled(id)` reads `enabled` key from `woocommerce_{id}_settings`
- `ProviderUtils::get_option_name(id)` → `woocommerce_{id}_settings`
- `ProviderUtils::update_option(id, settings)` writes whole array
- `ProviderUtils::$container` — holds enabled provider instances
- Enable in tests: `$this->enable_provider(ID, [...])` then optionally `ProviderUtils::$container[ID] = Provider::instance()`

## Invoice providers
- Interface: `IInvoiceService` (issue/cancel/get_invoice_number/get_settings)
- Optional: `ISupportsAllowance` (issue_allowance/invalid_allowance), `ISupportsQuery` (query_invoice)
- Meta via `Invoice\Shared\Helpers\MetaKeys`: get/update issued_data, cancelled_data, allowance_data, provider_id, issue_params
- All catch `\Throwable` → log + order note → return `[]`
- Idempotent: if issued_data exists, skip API call, return existing data

## PayNow Invoice specific (R5 — critical)
- Provider ID: `paynow_invoice` (NOT `paynow` — avoids collision with payment gateway)
- Option key: `woocommerce_paynow_invoice_settings` (NOT `woocommerce_paynow_settings`)
- SettingsDTO: `PaynowInvoiceSettingsDTO::ID = 'paynow_invoice'`
- API auth: Bearer JWT-Token (no symmetric crypto)
- Allowance meta key: `allowance_number` (NOT `allowance_no` — unlike ezPay)
- tax_amount: B2C=0, B2B=actual tax (total − round(total/1.05))
- Carrier/donate mutual exclusive → throw (IssueParams::create validates)
- ZeroTax requires zero_tax_rate_reason → throw
- Full refund (remaining ≤ 0): ProviderRegister does NOT call issue_allowance

## Payment gateways (separate from invoice)
- PayNow gateway ID: `paynow`; option: `woocommerce_paynow_settings`
- Webhook verification: HMAC-SHA256 on raw body (`X-Payment-Center-Hmac-Sha256`)
- Primary lookup key: `_pc_paynow_payment_intent_id` (NOT trade_no)
