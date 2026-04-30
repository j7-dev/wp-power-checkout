---
name: newebpay-mpg-v2
description: >
  NewebPay MPG Version 2.0 complete payment gateway reference for Taiwan.
  Covers AES-256-CBC encryption, SHA256 hashing, all TradeInfo parameters,
  payment methods (credit card/ATM/CVS/barcode/e-wallets), callback handling,
  query trade API, credit card refund API, periodic payment API, error codes.
  Use this skill whenever code involves newebpay, TradeInfo, TradeSha,
  MerchantID + HashKey + HashIV, spgateway.com or newebpay.com endpoints,
  MPG/mpg_gateway, NewebpayService, newebpay callback/notify handling,
  Taiwan payment gateway, AES-256-CBC trade encryption, TradeSha SHA256.
  MPG Version 2.0 only, not older versions (1.1-1.6).
---

# NewebPay MPG Version 2.0

> **Version**: MPG API v2.0 | **Sources**: Official docs + SDK cross-validation | **Updated**: 2026-04-30

Taiwan 3rd-party payment gateway with MPG multi-payment integration.

## Project Integration Specs

- **Code**: `apps/api-gateway/src/commerce/payments/newebpay/`
- **Payment**: `CREDIT=1` | **Response**: `RespondType=JSON`
- **Login**: `LoginType=0` | **Credentials**: `settings` table

## Core Payment Flow

```
1. Backend: Build TradeInfo -> AES-256-CBC encrypt -> SHA256 TradeSha
2. Frontend: POST form (MerchantID+TradeInfo+TradeSha+Version) -> NewebPay
3. Buyer: Complete payment on NewebPay page
4. NewebPay: POST to NotifyURL (background) + redirect ReturnURL (foreground)
5. Backend: Decrypt TradeInfo -> Verify TradeSha -> Update order
```

## Endpoint URLs

| Env | MPG | Query | Close/Refund |
|-----|-----|-------|--------------|
| **Test** | `https://ccore.spgateway.com/MPG/mpg_gateway` | `https://ccore.newebpay.com/API/QueryTradeInfo` | `https://ccore.newebpay.com/API/CreditCard/Close` |
| **Prod** | `https://core.spgateway.com/MPG/mpg_gateway` | `https://core.newebpay.com/API/QueryTradeInfo` | `https://core.newebpay.com/API/CreditCard/Close` |

> Test merchant: not starting with `M` or equals `MS154450763`

## AES-256-CBC Encryption

```typescript
import * as crypto from 'crypto';

function encryptTradeInfo(data: string, hashKey: string, hashIv: string): string {
  const key = hashKey.padEnd(32, '0').slice(0, 32);
  const iv = hashIv.padEnd(16, '0').slice(0, 16);
  const cipher = crypto.createCipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
  return cipher.update(data, 'utf8', 'hex') + cipher.final('hex');
}

function decryptTradeInfo(encrypted: string, hashKey: string, hashIv: string): string {
  const key = hashKey.padEnd(32, '0').slice(0, 32);
  const iv = hashIv.padEnd(16, '0').slice(0, 16);
  const decipher = crypto.createDecipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
  return decipher.update(encrypted, 'hex', 'utf8') + decipher.final('utf8');
}
```

- Algorithm: `aes-256-cbc`, Key: 32 bytes, IV: 16 bytes
- Padding: PKCS7 (Node.js auto), Input: `key=value&key=value`, Output: hex

## SHA256 Hash (TradeSha)

```typescript
function generateTradeSha(tradeInfo: string, hashKey: string, hashIv: string): string {
  const raw = `HashKey=${hashKey}&${tradeInfo}&HashIV=${hashIv}`;
  return crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
}
```

**Formula**: `SHA256("HashKey={K}&{hex}&HashIV={IV}")` -> UPPERCASE

## POST Form Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `TradeInfo` | string | Y | AES-encrypted trade data (hex) |
| `TradeSha` | string | Y | SHA256 hash (uppercase hex) |
| `Version` | string | Y | Fixed `"2.0"` |

## TradeInfo Core Parameters

> Full parameter table in `references/api-reference.md`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `RespondType` | string | Y | `"JSON"` or `"String"` |
| `TimeStamp` | string | Y | Unix timestamp (seconds) |
| `Version` | string | Y | `"2.0"` |
| `MerchantOrderNo` | string | Y | Order number (alphanumeric, max 30 chars) |
| `Amt` | int | Y | Amount (integer, TWD) |
| `ItemDesc` | string | Y | Item description (max 50 chars) |
| `Email` | string | Y | Payer email |
| `ReturnURL` | string | N | Foreground redirect after payment |
| `NotifyURL` | string | N | Background server-to-server notification |
| `ClientBackURL` | string | N | Cancel/back URL |
| `CREDIT` | int | N | Enable credit card 1/0 |
| `VACC` | int | N | Enable ATM virtual account 1/0 |
| `CVS` | int | N | Enable convenience store code 1/0 |
| `BARCODE` | int | N | Enable convenience store barcode 1/0 |
| `LoginType` | int | N | Require NewebPay login 0/1 |

## Payment Methods

| Param | Value | Method | Type |
|-------|-------|--------|------|
| `CREDIT` | 1 | Credit card | Realtime |
| `InstFlag` | "3,6,12,18,24,30" | Installment | Realtime |
| `CreditRed` | 1 | Reward points | Realtime |
| `UNIONPAY` | 1 | UnionPay | Realtime |
| `WEBATM` | 1 | WebATM | Realtime |
| `VACC` | 1 | ATM virtual account | Offline |
| `CVS` | 1 | Convenience store code | Offline |
| `BARCODE` | 1 | Convenience store barcode | Offline |
| `CVSCOM` | 1 | Convenience store pickup | Offline |
| `LINEPAY` | 1 | LINE Pay | Realtime |
| `ESUNWALLET` | 1 | E.SUN Wallet | Realtime |
| `TAIWANPAY` | 1 | Taiwan Pay | Realtime |
| `SAMSUNGPAY` | 1 | Samsung Pay | Realtime |
| `ANDROIDPAY` | 1 | Google Pay | Realtime |

## Callback Response Structure (decrypted JSON)

```typescript
interface NewebPayCallback {
  Status: string;       // "SUCCESS" or error code
  Message: string;
  Result: {
    MerchantID: string;
    Amt: number;
    TradeNo: string;           // NewebPay transaction number
    MerchantOrderNo: string;   // Merchant order number
    PaymentType: string;       // "CREDIT"|"VACC"|"CVS"|"BARCODE"|...
    RespondCode: string;       // "00" = success
    AuthBank: string;
    EscrowBank: string;
    PayTime: string;           // "yyyy-MM-dd HH:mm:ss"
    IP: string;
    Card6No?: string;
    Card4No?: string;
    AuthCode?: string;
    Inst?: number;
    InstFirst?: number;
    InstEach?: number;
    PayBankCode?: string;
    PayerAccount5Code?: string;
    CodeNo?: string;
    Barcode_1?: string;
    Barcode_2?: string;
    Barcode_3?: string;
    StoreType?: string;
    StoreName?: string;
  };
}
```

## Pitfalls

1. TradeSha MUST be UPPERCASE
2. TradeInfo: URL-encode Chinese values
3. Version is string "2.0"
4. Use NotifyURL (not ReturnURL) for order updates
5. Test: ccore prefix; Prod: core
6. Amt: integer TWD only
7. MerchantOrderNo: unique per merchant
8. PHP: manual PKCS7; Node.js: auto
9. ItemDesc max 50 chars
10. TradeLimit: 60-900 seconds

## References

| Need | File |
|------|------|
| Full parameters | `references/api-reference.md` |
| Code examples | `references/examples.md` |
| Backend APIs + errors | `references/backend-apis.md` |