---
name: payuni-logistics-v3
description: >
  PAYUNi Logistics V3 (Taiwan convenience store + home delivery shipping) complete API reference.
  Covers all logistics endpoints: CVS (7-ELEVEN B2C/C2C) ship_map, trade, query, update,
  print_label, refund, c2c_to_home_delivery; Home Delivery (Tcat) trade, get_obt_number_pdf,
  download_pdf, call_cat, refund. Includes AES-256-GCM encryption (shared with PAYUNi payment),
  logistics status codes (ShipStatus), Notify callback handling, and store map integration.
  Use this skill whenever the task involves PAYUNi logistics, payuni-logistics, shipping integration,
  CVS pickup, convenience store shipping, Tcat home delivery, 7-ELEVEN B2C/C2C, store map,
  logistics status tracking, or any code importing from payuni-logistics or payuni-crypto.
  Also use when working on ZenbuSite shipping/logistics features, shippingRef, ShipTradeNo,
  or any order shipping status updates from PAYUNi notify callbacks.
---

# PAYUNi Logistics V3

> **Version**: V3 (AES-256-GCM) | **Source**: https://docs.payuni.com.tw/web/ | **Last updated**: 2026-04-30

PAYUNi Logistics provides CVS (convenience store) and home delivery (Tcat) shipping services for Taiwan e-commerce. Credentials and encryption mechanism are shared with PAYUNi Payment V3.

## Endpoints Overview

| API Path | Ver | Method | Description |
|---|---|---|---|
| `/api/logistics/ship_map` | 1.1 | POST (form redirect) | CVS store map (iframe/redirect for store selection) |
| `/api/logistics/trade` | 1.3 | POST | Create CVS shipment (B2C/C2C) |
| `/api/logistics/query` | 1.1 | POST | Query logistics status |
| `/api/logistics/update` | 1.1 | POST | Modify shipment recipient info |
| `/api/logistics/print_label` | 1.0 | POST (form redirect) | Print CVS shipping label |
| `/api/logistics/refund` | 1.0 | POST | Create CVS return code (C2B) |
| `/api/logistics/c2c_to_home_delivery` | 1.0 | POST | Convert C2C to home delivery |
| `/api/home_delivery/trade` | 1.2 | POST | Create Tcat home delivery shipment |
| `/api/home_delivery/get_obt_number_pdf` | 1.0 | POST (form redirect) | Generate Tcat consignment PDF |
| `/api/home_delivery/download_pdf` | 1.0 | POST (form redirect) | Download generated Tcat PDF |
| `/api/home_delivery/call_cat` | 1.0 | POST | Request Tcat driver pickup |
| `/api/home_delivery/refund` | 1.0 | POST | Create Tcat return shipment |

**Hosts**: Production `https://api.payuni.com.tw` | Sandbox `https://sandbox-api.payuni.com.tw`

## AES-256-GCM Encryption (Shared with Payment V3)

All requests use the same encryption as PAYUNi Payment V3. Credentials come from `payment.payuni_*` settings.

### Request Envelope (4 form fields)

| Field | Type | Description |
|---|---|---|
| `MerID` | String | Merchant ID |
| `Version` | String | API version (e.g. "1.3") |
| `EncryptInfo` | String | AES-256-GCM encrypted payload (hex-encoded) |
| `HashInfo` | String | SHA256 verification hash |

### Encrypt Flow

```
1. Build params object (MerID, Timestamp, ...business fields)
2. URL-encode as querystring: "MerID=X&Timestamp=Y&..."
3. AES-256-GCM encrypt(key=HashKey, iv=HashIV) -> ciphertextB64 + authTagB64
4. EncryptInfo = hex(ciphertextB64 + ":::" + authTagB64)
5. HashInfo = SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()
```

### TypeScript Implementation

```typescript
import * as crypto from 'crypto';

// HashKey = 32 bytes, HashIV = 16 bytes
function encryptPayuni(
  params: Record<string, string | number | undefined>,
  hashKey: string, hashIv: string,
): string {
  const plaintext = Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&');
  const cipher = crypto.createCipheriv('aes-256-gcm', Buffer.from(hashKey, 'utf8'), Buffer.from(hashIv, 'utf8'));
  let b64 = cipher.update(plaintext, 'utf8', 'base64');
  b64 += cipher.final('base64');
  const tag = cipher.getAuthTag().toString('base64');
  return Buffer.from(`${b64}:::${tag}`, 'utf8').toString('hex');
}

function decryptPayuni(encryptInfo: string, hashKey: string, hashIv: string): Record<string, string> {
  const combined = Buffer.from(encryptInfo, 'hex').toString('utf8');
  const [ciphertext, tagB64] = combined.split(':::');
  const decipher = crypto.createDecipheriv('aes-256-gcm', Buffer.from(hashKey, 'utf8'), Buffer.from(hashIv, 'utf8'));
  decipher.setAuthTag(Buffer.from(tagB64, 'base64'));
  let plain = decipher.update(ciphertext, 'base64', 'utf8');
  plain += decipher.final('utf8');
  return Object.fromEntries(new URLSearchParams(plain));
}

function hashInfoPayuni(hashKey: string, encryptInfo: string, hashIv: string): string {
  return crypto.createHash('sha256')
    .update(hashKey + encryptInfo + hashIv, 'utf8')
    .digest('hex').toUpperCase();
}
```

HTTP header must include `User-Agent: payuni`. Content-Type: `application/x-www-form-urlencoded`.

## Integration Notes

### Credentials (shared with payment)

Settings: `payment.payuni_mer_id`, `payment.payuni_hash_key` (32B), `payment.payuni_hash_iv` (16B), `payment.payuni_mode`.

### Project Code

- Crypto: `apps/api-gateway/src/commerce/payments/payuni/payuni-crypto.ts`
- Service: `apps/api-gateway/src/commerce/payments/payuni-logistics/payuni-logistics.service.ts`
- Controller: `apps/api-gateway/src/commerce/payments/payuni-logistics/payuni-logistics.controller.ts`

### Store Map Flow

1. Backend `buildCvsMapForm()` returns formUrl+params
2. Frontend renders hidden form, auto-submits
3. Customer selects store on PAYUNi map
4. PAYUNi POSTs encrypted `MapJson` to `MapReturnURL`
5. Backend decrypts StoreID, StoreName, Address
6. Redirect to checkout with store params

### Enable Gate

Outbound actions check `shipping.payuni_enabled`. Inbound notify NOT gated.

### Order Linkage

- `order.shippingRef = ShipTradeNo` (primary link)
- `order.shippingProvider = payuni`
- `order.shippingStoreId/Name/Addr` (CVS)
- `order.shippingOdno` (from Odno or OBTNumber)
- `order.shippingStatus` (mapped from ShipStatus)

### Sandbox

Admin: `https://sandbox-admin.payuni.com.tw/`
7-ELEVEN tracking: `https://tracking.shopmore.com.tw/`

## References

| Need | File |
|------|------|
| CVS (7-ELEVEN) API detailed parameters | `references/cvs-apis.md` |
| Home Delivery (Tcat) API detailed parameters | `references/home-delivery-apis.md` |
| ShipStatus codes, Enum reference, Notify callback | `references/status-codes-and-enums.md` |
