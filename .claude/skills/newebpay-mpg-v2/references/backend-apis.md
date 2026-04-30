# NewebPay Backend APIs, Periodic Payment and Error Codes

> Query trade, credit card close/refund/cancel, periodic payment, error codes.

## Table of Contents
1. Query Trade Info API
2. Credit Card Close (Capture) API
3. Credit Card Cancel Authorization API
4. Credit Card Refund API
5. E-Wallet Refund API
6. Periodic Payment API
7. Error Codes
8. Test Environment

## Query Trade Info API

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/QueryTradeInfo` |
| Prod | `https://core.newebpay.com/API/QueryTradeInfo` |

### Request (POST form-encoded)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `Version` | string | Y | `"1.3"` |
| `RespondType` | string | Y | `"JSON"` |
| `CheckValue` | string | Y | SHA256 verification hash |
| `TimeStamp` | string | Y | Unix timestamp |
| `MerchantOrderNo` | string | Y | Order number to query |
| `Amt` | int | Y | Original amount |

### CheckValue Generation

```typescript
function generateCheckValue(hashKey: string, hashIv: string, merchantId: string, orderNo: string, amt: number): string {
  const raw = `IV=${hashIv}&Amt=${amt}&MerchantID=${merchantId}&MerchantOrderNo=${orderNo}&Key=${hashKey}`;
  return crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
}
```

### TradeStatus Values
| Value | Meaning |
|-------|---------|
| `"0"` | Unpaid |
| `"1"` | Paid |
| `"2"` | Failed |
| `"3"` | Cancelled |
| `"6"` | Refunded |

### Response Result Fields
| Field | Type | Description |
|-------|------|-------------|
| `TradeStatus` | string | See above |
| `CloseStatus` | string | `"0"`=not closed, `"1"`=waiting, `"2"`=closed, `"3"`=refunded |
| `CloseAmt` | int | Captured amount |
| `BackBalance` | int | Refunded amount |
| `BackStatus` | string | `"0"`=not refunded, `"1"`=refunded |
| `FundTime` | string | Settlement date |

## Credit Card Close (Capture) API

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/CreditCard/Close` |
| Prod | `https://core.newebpay.com/API/CreditCard/Close` |

### Request (POST)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID_` | string | Y | Merchant ID |
| `PostData_` | string | Y | AES-encrypted request data |

### PostData (before encryption)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `RespondType` | string | Y | `"JSON"` |
| `Version` | string | Y | `"1.1"` |
| `Amt` | int | Y | Amount |
| `MerchantOrderNo` | string | Y* | Merchant order number |
| `TradeNo` | string | Y* | NewebPay trade number |
| `IndexType` | int | Y | `1`=MerchantOrderNo, `2`=TradeNo |
| `CloseType` | int | Y | `1`=capture, `2`=refund |
| `Cancel` | int | N | `1`=cancel previous request |

### Example

```typescript
async function capturePayment(merchantId: string, hashKey: string, hashIv: string, tradeNo: string, amount: number) {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);
  const postStr = `RespondType=JSON&Version=1.1&Amt=${amount}&TradeNo=${tradeNo}&IndexType=2&CloseType=1`;
  const encrypted = npCrypto.encrypt(postStr);

  return fetch('https://core.newebpay.com/API/CreditCard/Close', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `MerchantID_=${merchantId}&PostData_=${encrypted}`,
  }).then(r => r.json());
}
```

## Credit Card Cancel Authorization API

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/CreditCard/Cancel` |
| Prod | `https://core.newebpay.com/API/CreditCard/Cancel` |

### PostData
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `RespondType` | string | Y | `"JSON"` |
| `Version` | string | Y | `"1.0"` |
| `Amt` | int | Y | Authorization amount |
| `MerchantOrderNo` | string | Y* | Merchant order number |
| `TradeNo` | string | Y* | NewebPay trade number |
| `IndexType` | int | Y | `1`=MerchantOrderNo, `2`=TradeNo |

## Credit Card Refund API

Same endpoint as Close API with `CloseType=2`.

```typescript
// Refund
const postStr = `RespondType=JSON&Version=1.1&Amt=${amount}&TradeNo=${tradeNo}&IndexType=2&CloseType=2`;

// Cancel a pending refund
const postStr = `RespondType=JSON&Version=1.1&Amt=${amount}&TradeNo=${tradeNo}&IndexType=2&CloseType=2&Cancel=1`;
```

## E-Wallet Refund API

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/EWallet/Refund` |
| Prod | `https://core.newebpay.com/API/EWallet/Refund` |

### Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `TradeNo` | string | Y | NewebPay trade number |
| `Amt` | int | Y | Refund amount |
| `PaymentType` | string | Y | `"LINEPAY"`, `"TAIWANPAY"`, `"ESUNWALLET"` |

## Periodic Payment API

### Create Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/MPG/period` |
| Prod | `https://core.newebpay.com/MPG/period` |

### Key Parameters
| Parameter | Type | Description |
|-----------|------|-------------|
| `PeriodAmt` | int | Amount per period |
| `PeriodType` | string | `"D"`=daily, `"W"`=weekly, `"M"`=monthly, `"Y"`=yearly |
| `PeriodPoint` | string | Day of week (1-7), day of month (01-31), or MMDD |
| `PeriodStartType` | int | `1`=auth TWD 10 test, `2`=full first charge, `3`=validate only |
| `PeriodTimes` | int | Number of billing cycles |

### Manage
| Action | Endpoint | Key Param |
|--------|----------|-----------|
| Suspend | `POST /API/PeriodAPI/AlterStatus` | `AlterType=suspend` |
| Restart | `POST /API/PeriodAPI/AlterStatus` | `AlterType=restart` |
| Terminate | `POST /API/PeriodAPI/AlterStatus` | `AlterType=terminate` |
| Modify | `POST /API/PeriodAPI/AlterAmt` | new `Amt` |

## Error Codes

### Prefixes
| Prefix | Category |
|--------|----------|
| `MPG` | MPG gateway |
| `TRA` | Transaction |
| `PER` | Periodic payment |

### MPG Errors
| Code | Description |
|------|-------------|
| `MPG01001` | Merchant ID does not exist |
| `MPG01002` | Merchant is disabled |
| `MPG01003` | IP not in whitelist |
| `MPG03002` | Duplicate MerchantOrderNo |
| `MPG03007` | Merchant ID format incorrect |
| `MPG03009` | Transaction failed (general) |
| `MPG03010` | Amount format error |
| `MPG03011` | TradeInfo decryption failed |
| `MPG03012` | TradeSha verification failed |
| `MPG03014` | TimeStamp expired (beyond 120s) |
| `MPG03015` | Version not supported |
| `MPG05001` | Credit card auth failed |
| `MPG05002` | 3D Secure failed |
| `MPG05003` | Card expired |
| `MPG05004` | Insufficient balance |
| `MPG05005` | Amount exceeds limit |

### Transaction Errors
| Code | Description |
|------|-------------|
| `TRA10001` | Trade not found |
| `TRA10002` | Already cancelled |
| `TRA10003` | Already refunded |
| `TRA10012` | Refund exceeds original |
| `TRA10027` | Status does not allow operation |

### Periodic Payment Errors
| Code | Description |
|------|-------------|
| `PER10001` | Order not found |
| `PER10058` | Card auth failed |
| `PER10078` | Config error |

### Bank Response Codes (RespondCode)
| Code | Description |
|------|-------------|
| `00` | Approved |
| `01` | Refer to issuer |
| `05` | Do not honor |
| `12` | Invalid transaction |
| `14` | Invalid card number |
| `33` | Expired card |
| `41` | Lost card |
| `43` | Stolen card |
| `51` | Insufficient funds |
| `54` | Expired card |
| `55` | Incorrect PIN |
| `61` | Exceeds limit |

## Test Environment

### Endpoints (all use `ccore`)
- MPG: `https://ccore.spgateway.com/MPG/mpg_gateway`
- Query: `https://ccore.newebpay.com/API/QueryTradeInfo`
- Close: `https://ccore.newebpay.com/API/CreditCard/Close`
- Cancel: `https://ccore.newebpay.com/API/CreditCard/Cancel`

### Test Dashboard
- URL: `https://cwww.newebpay.com/`
- Register test account for MerchantID, HashKey, HashIV

### Test Credit Cards
| Card Number | Result |
|-------------|--------|
| `4000-2211-1111-1111` | Successful |
| `4000-2222-2222-2222` | 3D Secure test |

### Detection (project code)

```typescript
const isTest = !credentials.merchantId.startsWith('M') || credentials.merchantId === 'MS154450763';
const endpoint = isTest ? 'https://ccore.spgateway.com/MPG/mpg_gateway' : 'https://core.spgateway.com/MPG/mpg_gateway';
```