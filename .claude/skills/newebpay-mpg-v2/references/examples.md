# NewebPay MPG v2.0 TypeScript/NestJS Examples

> Complete runnable TypeScript examples for NewebPay MPG integration.

## Table of Contents
1. Encryption Utilities
2. Create Payment Form
3. Handle Callback (NotifyURL)
4. Handle Return (ReturnURL)
5. Verify CheckCode
6. Frontend Auto-Submit Form
7. Full NestJS Service Reference

## Encryption Utilities

```typescript
// newebpay-crypto.ts
import * as crypto from 'crypto';

export class NewebPayCrypto {
  constructor(
    private readonly hashKey: string,
    private readonly hashIv: string,
  ) {}

  /** AES-256-CBC encrypt. Input: URL-encoded string. Output: hex. */
  encrypt(data: string): string {
    const key = this.hashKey.padEnd(32, '0').slice(0, 32);
    const iv = this.hashIv.padEnd(16, '0').slice(0, 16);
    const cipher = crypto.createCipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
    return cipher.update(data, 'utf8', 'hex') + cipher.final('hex');
  }

  /** AES-256-CBC decrypt. Input: hex. Output: JSON string. */
  decrypt(encrypted: string): string {
    const key = this.hashKey.padEnd(32, '0').slice(0, 32);
    const iv = this.hashIv.padEnd(16, '0').slice(0, 16);
    const decipher = crypto.createDecipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
    return decipher.update(encrypted, 'hex', 'utf8') + decipher.final('utf8');
  }

  /** SHA256("HashKey={key}&{encrypted}&HashIV={iv}") -> UPPERCASE */
  generateSha(encryptedTradeInfo: string): string {
    const raw = `HashKey=${this.hashKey}&${encryptedTradeInfo}&HashIV=${this.hashIv}`;
    return crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
  }

  /** Verify CheckCode from callback */
  verifyCheckCode(
    result: { Amt: number; MerchantID: string; MerchantOrderNo: string; TradeNo: string },
    receivedCheckCode: string,
  ): boolean {
    const raw = `HashIV=${this.hashIv}&Amt=${result.Amt}&MerchantID=${result.MerchantID}&MerchantOrderNo=${result.MerchantOrderNo}&TradeNo=${result.TradeNo}&HashKey=${this.hashKey}`;
    const computed = crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
    return computed === receivedCheckCode;
  }
}
```

## Create Payment Form

```typescript
import { NewebPayCrypto } from './newebpay-crypto';

interface CreatePaymentParams {
  merchantId: string;
  orderNo: string;
  amount: number;
  itemDesc: string;
  email: string;
  returnUrl?: string;
  notifyUrl?: string;
  clientBackUrl?: string;
}

function createPaymentForm(
  params: CreatePaymentParams,
  hashKey: string,
  hashIv: string,
): { formUrl: string; formData: Record<string, string> } {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);
  const timestamp = Math.floor(Date.now() / 1000);

  const tradeParams: Record<string, string | number> = {
    MerchantID: params.merchantId,
    RespondType: 'JSON',
    TimeStamp: timestamp,
    Version: '2.0',
    MerchantOrderNo: params.orderNo,
    LoginType: 0,
    Amt: params.amount,
    ItemDesc: params.itemDesc,
    Email: params.email,
    CREDIT: 1,
  };
  if (params.returnUrl) tradeParams.ReturnURL = params.returnUrl;
  if (params.notifyUrl) tradeParams.NotifyURL = params.notifyUrl;
  if (params.clientBackUrl) tradeParams.ClientBackURL = params.clientBackUrl;

  const tradeString = Object.entries(tradeParams)
    .map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`)
    .join('&');

  const tradeInfo = npCrypto.encrypt(tradeString);
  const tradeSha = npCrypto.generateSha(tradeInfo);

  const isTest = !params.merchantId.startsWith('M') || params.merchantId === 'MS154450763';

  return {
    formUrl: isTest
      ? 'https://ccore.spgateway.com/MPG/mpg_gateway'
      : 'https://core.spgateway.com/MPG/mpg_gateway',
    formData: {
      MerchantID: params.merchantId,
      TradeInfo: tradeInfo,
      TradeSha: tradeSha,
      Version: '2.0',
    },
  };
}
```

## Handle Callback (NotifyURL)

```typescript
interface NewebPayResult {
  Status: string;
  Message: string;
  Result: {
    MerchantID: string;
    Amt: number;
    TradeNo: string;
    MerchantOrderNo: string;
    PaymentType: string;
    RespondCode: string;
    CheckCode: string;
    Card6No?: string;
    Card4No?: string;
    AuthCode?: string;
  };
}

function handleNotify(
  body: { TradeInfo: string; TradeSha: string },
  hashKey: string,
  hashIv: string,
): { success: boolean; result?: NewebPayResult } {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);

  // 1. Verify TradeSha
  if (npCrypto.generateSha(body.TradeInfo) !== body.TradeSha) {
    return { success: false };  // tampering detected
  }

  // 2. Decrypt
  const parsed: NewebPayResult = JSON.parse(npCrypto.decrypt(body.TradeInfo));

  // 3. Check status
  if (parsed.Status !== 'SUCCESS') {
    return { success: false, result: parsed };
  }

  // 4. Verify CheckCode
  if (!npCrypto.verifyCheckCode(parsed.Result, parsed.Result.CheckCode)) {
    return { success: false, result: parsed };
  }

  return { success: true, result: parsed };
}
```

## Handle Return (ReturnURL)

```typescript
// NestJS controller for foreground redirect
@Post('return')
async handleReturn(@Body() body: any, @Res() res: Response) {
  const creds = await this.loadCredentials();
  const npCrypto = new NewebPayCrypto(creds.hashKey, creds.hashIv);

  try {
    const parsed = JSON.parse(npCrypto.decrypt(body.TradeInfo));
    if (parsed.Status === 'SUCCESS') {
      res.redirect(`/checkout/success?order=${parsed.Result.MerchantOrderNo}`);
    } else {
      res.redirect(`/checkout/failed?reason=${encodeURIComponent(parsed.Message)}`);
    }
  } catch {
    res.redirect('/checkout/failed');
  }
}
```

## Verify CheckCode

```typescript
function verifyCheckCode(
  hashKey: string, hashIv: string,
  result: { Amt: number; MerchantID: string; MerchantOrderNo: string; TradeNo: string },
  received: string,
): boolean {
  // Order: HashIV, Amt, MerchantID, MerchantOrderNo, TradeNo, HashKey
  const str = `HashIV=${hashIv}&Amt=${result.Amt}&MerchantID=${result.MerchantID}&MerchantOrderNo=${result.MerchantOrderNo}&TradeNo=${result.TradeNo}&HashKey=${hashKey}`;
  return crypto.createHash('sha256').update(str).digest('hex').toUpperCase() === received;
}
```

## Frontend Auto-Submit Form

```typescript
// React/Next.js: redirect to NewebPay payment page
function redirectToNewebPay(formUrl: string, params: Record<string, string>) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = formUrl;
  form.style.display = 'none';
  Object.entries(params).forEach(([key, value]) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = value;
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
}

// Usage:
const { formUrl, formData } = await fetch(
  `/v1/commerce/payments/newebpay/form/${token}`,
  { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ clientBackUrl: window.location.href }) }
).then(r => r.json());
redirectToNewebPay(formUrl, formData);
```

## Full NestJS Service Reference

The project implementation is at `apps/api-gateway/src/commerce/payments/newebpay/newebpay.service.ts`.

Key integration points:
- `CheckoutService.verifySession(token)` - validates checkout session
- `CheckoutService.markCompleted(token)` - marks session as paid
- `OrdersService.createFromPayment(data)` - creates order record
- `CheckoutService.recordUsageFromSession(token, orderId)` - records discount usage
- Credentials loaded from `settings` table via `SettingsService`