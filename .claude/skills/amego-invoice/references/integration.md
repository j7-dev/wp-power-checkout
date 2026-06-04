# Amego 整合 — NestJS / TypeScript

本檔提供光貿電子發票在 zenbu-site（NestJS 11 + TypeORM 0.3）的整合範式。
zenbu-site 第三方整合慣例位於 `apps/api-gateway/src/commerce/`；光貿是電子發票加值中心，
非金流，建議獨立成 `commerce/invoicing/amego/` 子模組（與 `payments/` 平行）。

## 1. sign 簽章函式（最關鍵）

`sign = md5(data_json_string + time_string + app_key)`。三段直接相接做 MD5，輸出小寫 hex。
**算 sign 用的 `data` 字串必須與送出 body 的 `data` 字串完全相同**。

```typescript
import { createHash } from 'node:crypto';

/**
 * 組光貿 API 的 POST body（4 欄）。
 * dataObject 在這裡序列化一次，sign 與 body 共用同一份字串——
 * 絕不可「算 sign 一次、送 body 又序列化一次」，否則 code 16。
 */
export function buildAmegoForm(params: {
  invoice: string;          // 公司統編
  appKey: string;
  dataObject: unknown;      // 業務參數（Object 或 Array，依 API 而定）
  time?: number;            // 預設 now（秒）
}): URLSearchParams {
  const time = params.time ?? Math.floor(Date.now() / 1000);
  // JS JSON.stringify 預設不跳脫非 ASCII，符合光貿「中文不跳脫」慣例
  const dataString = JSON.stringify(params.dataObject);
  const sign = createHash('md5')
    .update(dataString + String(time) + params.appKey, 'utf8')
    .digest('hex'); // 小寫 hex

  const form = new URLSearchParams();
  form.set('invoice', params.invoice);
  form.set('data', dataString);   // URLSearchParams 會自動 url encode
  form.set('time', String(time));
  form.set('sign', sign);
  return form;
}
```

> `URLSearchParams` + `fetch` 的 body 會自動帶 `application/x-www-form-urlencoded`
> 並 url encode 各欄位值，符合光貿「data 需 url encode、Content-Type 須為 form-urlencoded」要求。
> **不要**手動設 `Content-Type: application/json`。

## 2. 低階呼叫函式

```typescript
const AMEGO_BASE = 'https://invoice-api.amego.tw';

export interface AmegoResponse<T = unknown> {
  code: number;       // 0 = 成功
  msg: string;
  data?: T;
  invoice_number?: string;
  invoice_time?: number;
  random_number?: string;
  [k: string]: unknown;
}

export async function callAmego<T = unknown>(
  path: string,                 // 例 '/json/f0401'
  form: URLSearchParams,
): Promise<AmegoResponse<T>> {
  const res = await fetch(`${AMEGO_BASE}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form.toString(),
  });
  if (!res.ok) {
    throw new Error(`Amego HTTP ${res.status} on ${path}`);
  }
  return (await res.json()) as AmegoResponse<T>;
}
```

## 3. ConfigService 設定（禁止直讀 process.env）

依 `.claude/rules/config-service.rule.md`，DI scope 內必須注入 `ConfigService`。
光貿憑證屬「功能性可選 vars」，**不放 envSchema**，由 service 內 `get` 配 fallback：

```
# .env
AMEGO_INVOICE_NUMBER=12345678          # 公司統編（測試用 12345678）
AMEGO_APP_KEY=sHeq7t8G1wiQvhAuIM27     # App Key（測試用此值，正式向客服申請）
```

> 光貿無 sandbox 子網域——「切換測試 / 正式」只換 `AMEGO_INVOICE_NUMBER` + `AMEGO_APP_KEY`
> 這兩個值，API 網址不變。憑證屬機密，logger 不可印 `AMEGO_APP_KEY`。

## 4. AmegoService pattern

```typescript
import { Injectable, Logger, UnprocessableEntityException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

@Injectable()
export class AmegoService {
  private readonly logger = new Logger(AmegoService.name);
  private readonly invoiceNumber: string;
  private readonly appKey: string;

  constructor(config: ConfigService) {
    // 缺值給空字串 → graceful degrade（未設定時開立會明確失敗，不在啟動時 fail）
    this.invoiceNumber = config.get<string>('AMEGO_INVOICE_NUMBER', '');
    this.appKey = config.get<string>('AMEGO_APP_KEY', '');
  }

  /** 開立 B2C/B2B 發票（自動配號）。回傳發票號碼。 */
  async issueInvoice(data: AmegoIssueInvoiceData): Promise<{
    invoiceNumber: string;
    invoiceTime: number;
    randomNumber: string;
  }> {
    const form = buildAmegoForm({
      invoice: this.invoiceNumber,
      appKey: this.appKey,
      dataObject: data,            // f0401 的 data 是「單一 Object」
    });
    const res = await callAmego(' /json/f0401'.trim(), form);

    if (res.code !== 0) {
      // logger 只記 metadata，不印 appKey / 完整 data
      this.logger.warn(`Amego f0401 failed code=${res.code} msg=${res.msg} orderId=${data.OrderId}`);
      throw new UnprocessableEntityException({
        kind: 'amego-issue-failed',
        code: res.code,
        message: res.msg,
      });
    }
    return {
      invoiceNumber: res.invoice_number!,
      invoiceTime: res.invoice_time!,
      randomNumber: res.random_number!,
    };
  }

  /** 作廢發票（可多張）。 */
  async voidInvoices(invoiceNumbers: string[]): Promise<void> {
    const form = buildAmegoForm({
      invoice: this.invoiceNumber,
      appKey: this.appKey,
      dataObject: invoiceNumbers.map((n) => ({ CancelInvoiceNumber: n })), // f0501 的 data 是 Array
    });
    const res = await callAmego('/json/f0501', form);
    if (res.code !== 0) {
      throw new UnprocessableEntityException({
        kind: 'amego-void-failed', code: res.code, message: res.msg,
      });
    }
  }

  /** 查發票狀態（輪詢用）。 */
  async queryInvoice(orderId: string): Promise<AmegoResponse> {
    const form = buildAmegoForm({
      invoice: this.invoiceNumber,
      appKey: this.appKey,
      dataObject: { type: 'order', order_id: orderId },
    });
    return callAmego('/json/invoice_query', form);
  }
}
```

## 5. 開立發票的金額組裝

開立前在後端把 `ProductItem` + 三個銷售額 + `TaxAmount` + `TotalAmount` 算好（光貿端會校驗）。

```typescript
interface AmegoProductItem {
  Description: string;
  Quantity: string | number;
  UnitPrice: string | number;   // 預設含稅
  Amount: string | number;
  Remark?: string;
  TaxType: '1' | '2' | '3';     // 1 應稅 / 2 零稅率 / 3 免稅
}

/** 含稅商品（DetailVat=1）+ B2C（不打統編）的金額組裝 */
function buildB2cTaxablePayload(orderId: string, items: AmegoProductItem[]): AmegoIssueInvoiceData {
  const round = (n: number) => Math.round(n);
  const salesAmount = round(
    items.filter((i) => i.TaxType === '1').reduce((s, i) => s + Number(i.Amount), 0),
  );
  return {
    OrderId: orderId,
    BuyerIdentifier: '0000000000',   // B2C：10 個 0
    BuyerName: '消費者',
    ProductItem: items,
    SalesAmount: String(salesAmount),
    FreeTaxSalesAmount: '0',
    ZeroTaxSalesAmount: '0',
    TaxType: '1',
    TaxRate: '0.05',
    TaxAmount: '0',                  // B2C 不打統編 → 稅額一律 0
    TotalAmount: String(salesAmount),
  };
}
```

打統編（B2B）時 `TaxAmount = SalesAmount - Round(SalesAmount / 1.05)`，
`SalesAmount` 再扣掉 `TaxAmount`。完整公式見 `api-reference.md` 金額計算章節。

## 6. 輪詢上傳狀態

`f0401` 回 `code:0` 只代表光貿收件並配號，**不代表上傳財政部成功**。
真正完成要看 `invoice_status` / `invoice_query` 的 `invoice_status` 欄位到 `99`（完成）。

在 zenbu-site 用 BullMQ（`@nestjs/bullmq`）排程一個延遲 job 輪詢：

```typescript
// 開立成功後丟一個延遲 job
await invoiceQueue.add(
  'poll-amego-status',
  { orderId, invoiceNumber },
  { delay: 60_000, attempts: 5, backoff: { type: 'fixed', delay: 120_000 } },
);

// Worker：查 invoice_query，status=99 標記完成、91 標記錯誤、其他丟 retry
@Processor('invoice')
export class InvoicePollProcessor extends WorkerHost {
  async process(job: Job<{ orderId: string }>) {
    const res = await this.amego.queryInvoice(job.data.orderId);
    const status = (res.data as { invoice_status?: number })?.invoice_status;
    if (status === 99) { /* 標記「已完成」 */ return; }
    if (status === 91) { /* 標記「上傳失敗」+ 告警 */ return; }
    throw new Error('still pending');   // 觸發 BullMQ retry
  }
}
```

BullMQ 用法詳見 `/zenbu-powers:bullmq-v5` SKILL。

## 7. Idempotency

光貿用 `OrderId`（f0401）/ `AllowanceNumber`（g0401）做唯一鍵：

- 同一 `OrderId` 重送會回 `3040171`（OrderId 重複）。**開立前先 `invoice_query`
  查該訂單是否已有發票**——已有就直接用既有發票號碼，不重開。
- 對齊 zenbu-site commerce 的 idempotency 慣例（`findOne by (orderId, type)` 已存在 → no-op）：
  建議在 `commerce_orders` 或新表記下 `(orderId → invoiceNumber)`，
  開票前先查本地表，避免每次都打光貿。

## 8. 錯誤處理對照

| 情況 | 處理 |
|------|------|
| HTTP 非 2xx | 視為暫時性錯誤，可 retry |
| `code: 16`（簽名錯） | 不可重試——檢查 sign 計算 / App Key；屬程式 bug |
| `code: 15`（Time 錯） | 用 `/json/time` 校正後重算 sign 再送 |
| `code: 10`（維護中）/ `21`（人數過多） | 暫時性，延遲後 retry |
| `code: 3040171`（OrderId 重複） | 不可重試——改查 `invoice_query` 取既有發票號碼 |
| `code: 3040174`–`3040178`（金額計算錯） | 不可重試——修正金額組裝邏輯 |
| `code: 19`（公司停權）/ `22`（未申請 API） | 不可重試——聯絡光貿客服 |

把「不可重試」的 code 在 service 層轉成 `UnprocessableEntityException`（422），
「暫時性」的留給 BullMQ retry。logger 只印 `code` / `msg` / `orderId`，
**不印 `appKey`、不印完整 `data` JSON**（含買方 email / 地址等 PII）。

## 9. zenbu-site 模組落點建議

```
apps/api-gateway/src/commerce/invoicing/amego/
  amego.module.ts          # AmegoModule（imports ConfigModule, BullModule.registerQueue）
  amego.service.ts         # AmegoService（issueInvoice / voidInvoice / issueAllowance / query…）
  amego.crypto.ts          # buildAmegoForm（sign 簽章）+ callAmego
  amego.types.ts           # AmegoIssueInvoiceData / AmegoProductItem / AmegoResponse…
  invoice-poll.processor.ts # BullMQ worker：輪詢 invoice_status
  amego.service.spec.ts    # 單元測試（mock fetch，驗 sign 計算）
```

- 開立發票的觸發點：訂單 `paid` 後（對齊 `OrdersService` 的訂單回饋金 idempotency 模式）。
- 退貨 / 折讓觸發點：`returns` 模組客服審核通過後呼叫 `issueAllowance`。
- 測試一律 mock `fetch`，**不打真實光貿 API**；用官方範例的 md5 值驗證 sign 計算正確。
