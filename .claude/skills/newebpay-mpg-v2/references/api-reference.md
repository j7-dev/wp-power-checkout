# NewebPay MPG v2.0 API Reference

> Complete parameter reference for TradeInfo fields, payment methods, and callback response fields.

## Table of Contents
1. TradeInfo Request Parameters
2. Payment Method Parameters
3. Credit Card Parameters
4. Offline Payment Parameters
5. E-Wallet Parameters
6. Callback Response Parameters
7. Payment-Type-Specific Response Fields
8. CheckCode Verification

## TradeInfo Request Parameters

Joined as `key=value&key=value`, then AES-256-CBC encrypted to produce `TradeInfo`.

### Required

| Parameter | Type | MaxLen | Description |
|-----------|------|--------|-------------|
| `MerchantID` | string | 15 | Merchant ID from NewebPay |
| `RespondType` | string | 6 | `"JSON"` or `"String"` |
| `TimeStamp` | string | 10 | Unix timestamp (seconds). Must be within +/- 120s of server time |
| `Version` | string | 5 | Fixed `"2.0"` |
| `MerchantOrderNo` | string | 30 | Order number. Alphanumeric. Unique per MerchantID |
| `Amt` | int | 10 | Amount in TWD (integer, no decimals) |
| `ItemDesc` | string | 50 | Product description. Comma-separated for multiple |
| `Email` | string | 50 | Payer email |

### Optional - URLs

| Parameter | Type | MaxLen | Description |
|-----------|------|--------|-------------|
| `ReturnURL` | string | 255 | Foreground redirect after payment |
| `NotifyURL` | string | 255 | Background server-to-server POST notification |
| `CustomerURL` | string | 255 | Offline payment: display payment instructions (ATM account, CVS code) |
| `ClientBackURL` | string | 255 | "Return to Store" button URL on NewebPay page |

### Optional - Display/Behavior

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `LangType` | string | `"zh-tw"` | `"zh-tw"`, `"en"`, `"jp"` |
| `LoginType` | int | 0 | `0`=no login, `1`=require NewebPay login |
| `TradeLimit` | int | 0 | Timeout 60-900 seconds. `0`=no limit |
| `ExpireDate` | string | 7 days | Offline deadline `YYYYMMDD`. Max 180 days |
| `EmailModify` | int | 1 | `0`=read-only, `1`=editable |
| `OrderComment` | string | - | Notes on payment page (max 300 chars) |

## Payment Method Parameters

Set `1` to enable, `0` or omit to disable.

### Credit Card
| Parameter | Type | Description |
|-----------|------|-------------|
| `CREDIT` | int | One-time payment |
| `InstFlag` | string | Installment periods comma-separated: `"3,6,12,18,24,30"` |
| `CreditRed` | int | Reward points redemption |
| `CREDITAE` | int | American Express |
| `UNIONPAY` | int | UnionPay |

### Bank Transfer
| Parameter | Type | Description |
|-----------|------|-------------|
| `WEBATM` | int | WebATM (card reader required) |
| `VACC` | int | ATM virtual account (generates 16-digit account) |

### Convenience Store
| Parameter | Type | Description |
|-----------|------|-------------|
| `CVS` | int | Code payment (kiosk at 7-11, FamilyMart, etc.) |
| `BARCODE` | int | Barcode payment (3-segment barcode) |
| `CVSCOM` | int | Pickup and pay |

### E-Wallets
| Parameter | Type | Description |
|-----------|------|-------------|
| `LINEPAY` | int | LINE Pay |
| `ESUNWALLET` | int | E.SUN Wallet |
| `TAIWANPAY` | int | Taiwan Pay |
| `ANDROIDPAY` | int | Google Pay |
| `SAMSUNGPAY` | int | Samsung Pay |

## Credit Card Parameters

### Installment
| Parameter | Type | Description |
|-----------|------|-------------|
| `InstFlag` | string | Periods: `"3,6,12,18,24,30"` |

Fee rates (typical): 3p=3%, 6p=3.5%, 12p=7%, 18p=9%, 24p=12%, 30p=15%

### Card Token
| Parameter | Type | Description |
|-----------|------|-------------|
| `TokenTerm` | string | Cardholder ID for card binding |
| `TokenTermDemand` | int | `0`=none, `1`=CVV, `2`=CVV+expiry |
| `TokenLife` | string | Token expiration `YYMM` |

### Recurring
| Parameter | Type | Description |
|-----------|------|-------------|
| `CREDITAGREEMENT` | int | `1`=first auth (get token), `2`=subsequent charge |
| `TokenValue` | string | Token from first auth |

### 3D Secure
| Parameter | Type | Description |
|-----------|------|-------------|
| `P3D` | int | `0`=skip, `1`=enforce |

## Offline Payment Parameters

### ATM (VACC)
- Generates 16-digit virtual account
- `ExpireDate`: deadline `YYYYMMDD`, default 7d, max 180d

### CVS (Convenience Store Code)
- Generates code for 7-11/FamilyMart/Hi-Life/OK Mart kiosk
- `ExpireDate`: deadline `YYYYMMDD`
- Amount range: TWD 30 - TWD 20,000

### BARCODE
- Generates 3-segment barcode for store counter
- `ExpireDate`: deadline `YYYYMMDD`
- Amount range: TWD 30 - TWD 20,000

## E-Wallet Parameters

### LINE Pay
| Parameter | Type | Description |
|-----------|------|-------------|
| `LINEPAY` | int | `1` to enable |
| `ImageUrl` | string | Product image URL for LINE Pay checkout |

### CVSCOM
| Parameter | Type | Description |
|-----------|------|-------------|
| `CVSCOM` | int | `1`=pickup only, `2`=pickup+pay |
| `LgsType` | string | `"B2C"` or `"C2C"` |

## Callback Response Parameters

### Top-level
| Field | Type | Description |
|-------|------|-------------|
| `Status` | string | `"SUCCESS"` or error code |
| `Message` | string | Status description |

### Result Object
| Field | Type | Description |
|-------|------|-------------|
| `MerchantID` | string | Merchant ID |
| `Amt` | int | Amount |
| `TradeNo` | string | NewebPay transaction number |
| `MerchantOrderNo` | string | Merchant order number |
| `PaymentType` | string | `"CREDIT"`,`"VACC"`,`"WEBATM"`,`"CVS"`,`"BARCODE"`,`"LINEPAY"`,etc. |
| `RespondCode` | string | Bank code (`"00"`=success) |
| `AuthBank` | string | Acquiring bank |
| `EscrowBank` | string | Escrow bank |
| `PayTime` | string | `"yyyy-MM-dd HH:mm:ss"` |
| `IP` | string | Payer IP |
| `CheckCode` | string | Verification hash |

## Payment-Type-Specific Response Fields

### Credit Card
| Field | Type | Description |
|-------|------|-------------|
| `Card6No` | string | First 6 digits |
| `Card4No` | string | Last 4 digits |
| `AuthCode` | string | Authorization code |
| `Inst` | int | Installment periods (0=one-time) |
| `InstFirst` | int | First installment amount |
| `InstEach` | int | Each installment amount |
| `ECI` | string | 3D Secure ECI |
| `RedAmt` | int | Reward points amount |
| `TokenValue` | string | Card token |
| `TokenLife` | string | Token expiry `"YYMM"` |

### ATM (VACC/WEBATM)
| Field | Type | Description |
|-------|------|-------------|
| `PayBankCode` | string | Payer bank code |
| `PayerAccount5Code` | string | Last 5 digits |
| `BankCode` | string | Virtual account bank (VACC) |
| `CodeNo` | string | Virtual account number 16-digit (VACC) |

### CVS
| Field | Type | Description |
|-------|------|-------------|
| `CodeNo` | string | Payment code |
| `StoreType` | int | Store channel |
| `StoreName` | string | Store name |

### Barcode
| Field | Type | Description |
|-------|------|-------------|
| `Barcode_1` | string | Segment 1 |
| `Barcode_2` | string | Segment 2 |
| `Barcode_3` | string | Segment 3 |

## CheckCode Verification

```typescript
const checkStr = [
  `HashIV=${hashIv}`,
  `Amt=${result.Amt}`,
  `MerchantID=${result.MerchantID}`,
  `MerchantOrderNo=${result.MerchantOrderNo}`,
  `TradeNo=${result.TradeNo}`,
  `HashKey=${hashKey}`,
].join('&');

const checkCode = crypto.createHash('sha256')
  .update(checkStr).digest('hex').toUpperCase();

const isValid = checkCode === result.CheckCode;
```

**Parameter order**: HashIV, Amt, MerchantID, MerchantOrderNo, TradeNo, HashKey