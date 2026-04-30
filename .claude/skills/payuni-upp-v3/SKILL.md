---
name: payuni-upp-v3
description: >
  PAYUNi UPP V3 整合支付頁完整技術參考。涵蓋 AES-256-GCM 加解密、SHA256 HashInfo、
  UPP 請求回傳參數、所有付款方式、交易查詢退款取消 API、Sandbox 測試。
  當程式碼涉及 PAYUNi、統一金流、payuni、UPP、UNiPaypage、台灣金流、
  EncryptInfo HashInfo、信用卡分期、超商代碼、ATM虛擬帳號、LINE Pay、街口支付時使用。
  V3 專用（AES-256-GCM），不適用 V1/V2（AES-256-CBC）。
---

# PAYUNi UPP V3

> **適用版本**: V3 (AES-256-GCM) | **Version**: `2.0` | **來源**: https://docs.payuni.com.tw/web/ | **更新**: 2026-04-30

PAYUNi 台灣第三方支付，UPP (UNiPaypage) 整合式支付頁模式，導向 PAYUNi 頁面完成付款。V3 用 AES-256-GCM，與 V1/V2 (AES-256-CBC) 完全不同。

## 端點

| 環境 | URL |
|------|-----|
| 正式 | `https://api.payuni.com.tw/api/upp` |
| 測試 | `https://sandbox-api.payuni.com.tw/api/upp` |

Form POST，TLS v1.2+。

## 加解密 (V3 核心)

**金鑰**: HashKey (32字元), HashIV (16字元)，從後台 > 會員 > 商店清單 > 串接設定取得。不可含空白。

### Encrypt

```typescript
import * as crypto from 'crypto';
import * as querystring from 'querystring';

function encrypt(params: Record<string, string | number>, key: string, iv: string): string {
  const plaintext = querystring.stringify(params as Record<string, string>);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, Buffer.from(iv));
  let encrypted = cipher.update(plaintext, 'utf8', 'base64');
  encrypted += cipher.final('base64');
  const tag = cipher.getAuthTag().toString('base64');
  return Buffer.from(`${encrypted}:::${tag}`).toString('hex').trim();
}
```

格式: `hex( base64(ciphertext) + ":::" + base64(authTag) )`

### Decrypt

```typescript
function decrypt(encryptStr: string, key: string, iv: string): Record<string, string> {
  const [encData, tag] = Buffer.from(encryptStr, 'hex').toString().split(':::');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(iv));
  decipher.setAuthTag(Buffer.from(tag, 'base64'));
  let decrypted = decipher.update(encData, 'base64', 'utf8');
  decrypted += decipher.final('utf8');
  const result: Record<string, string> = {};
  new URLSearchParams(decrypted).forEach((v, k) => { result[k] = v; });
  return result;
}
```

### HashInfo (SHA256)

```typescript
function hashInfo(encryptStr: string, key: string, iv: string): string {
  return crypto.createHash('sha256').update(`${key}${encryptStr}${iv}`).digest('hex').toUpperCase();
}
```

`HashInfo = SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()`

## 請求參數

### 外層 (Form POST body)

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Version` | Y | 固定 `2.0` |
| `EncryptInfo` | Y | AES-256-GCM 加密字串 |
| `HashInfo` | Y | SHA256 雜湊 |

### EncryptInfo 內層

| 參數 | 必要 | 型別 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `MerTradeNo` | Y | string | 訂單編號 | <=25, `[A-Za-z0-9_-]`, 10分鐘不重複 |
| `TradeAmt` | Y | int | 金額 | |
| `Timestamp` | Y | int | Unix 時間戳 | |
| `ProdDesc` | Y | string | 商品說明 | <=550, `;` 分隔多項 |
| `ReturnURL` | C | string | 前景通知 URL | Form POST 回傳 |
| `NotifyURL` | C | string | 背景通知 URL | 僅 port 80/443 |
| `BackURL` | C | string | 返回按鈕 URL | |
| `DeepLinkURL` | C | string | 深層連結 | 有值不觸發 ReturnURL, 僅 icash/LINE/街口/AFTEE |
| `UsrMail` | C | string | 消費者信箱 | |
| `UsrMailFix` | C | int | 信箱固定 | `1`=不可改 |
| `Cardholder` | C | int | 持卡人驗證 | `1`=啟用 |
| `ExpireDate` | C | string | 繳費期限 | `YYYY-MM-DD`, 預設+7天, ATM最大+180天 |
| `AtmBankType` | C | string | 指定ATM銀行 | 逗號分隔代碼 |
| `TradeLExpireSec` | C | int | 頁面截止秒 | 60-600, 預設600 |
| `API3D` | C | int | 指定3D | `1`=啟用 |
| `Lang` | C | string | 語系 | `zh-tw`/`en` |

### 付款方式開關 (1=啟用, 未帶則依後台設定)

| 參數 | 型別 | 說明 |
|------|------|------|
| `Credit` | int | 信用卡一次付清 |
| `CreditInst` | string | 分期 (`"3,6,9,12"`, 支援3/6/9/12/18/24/30) |
| `CreditRed` | int | 紅利 |
| `CreditUnionPay` | int | 銀聯 |
| `ATM` | int | 虛擬帳號 |
| `CVS` | int | 超商代碼 |
| `ICash` | int | icash Pay |
| `LinePay` | int | LINE Pay |
| `JKoPay` | int | 街口支付 |
| `Aftee` | int | AFTEE |
| `ApplePay` | int | Apple Pay (不支援銀聯) |
| `GooglePay` | int | Google Pay (不支援銀聯) |
| `SamsungPay` | int | Samsung Pay (不支援銀聯/JCB) |
| `Ship` | int | 貨到付款 |
| `TradeInvoice` | int | 電子發票 |

### Token 參數 (信用卡約定/記憶卡號)

| 參數 | 型別 | 說明 |
|------|------|------|
| `CreditToken` | string | 綁定識別 (會員編號/Email等, <=150) |
| `UseTokenType` | int | `1`=約定(可取消), `2`=記憶卡號, `3`=強制約定 |
| `CreditShowType` | int | 記憶卡號顯示 `1`=卡號, `2`=卡號+到期日(預設) |
| `CreditTokenType` | int | `1`=會員級(預設), `2`=商店級 |
| `CreditTokenExpired` | string | 有效期間 `MMYY` |

### 買方 Token 參數

| 參數 | 型別 | 說明 |
|------|------|------|
| `BuyerToken` | string | 買方會員綁定識別 (<=150) |
| `BuyerTokenType` | int | `1`=會員級(預設), `2`=商店級 |
| `BuyerHash` | string | 買方 Hash (首次交易後取得, 後續帶入) |

### 優惠券參數

| 參數 | 型別 | 說明 |
|------|------|------|
| `Coupon` | int | `1`=啟用, `2`=停用 |
| `CouponNotifyURL` | string | 發券背景通知 URL |

## 回傳參數

### 通用 (EncryptInfo 解密後)

| 參數 | 說明 |
|------|------|
| `Status` | `SUCCESS`/`UNKNOWN`/`UNAPPROVED`/錯誤代碼 |
| `Message` | 狀態說明 |
| `MerTradeNo` | 商店訂單編號 |
| `TradeNo` | PAYUNi 序號 |
| `TradeAmt` | 金額 |
| `TradeStatus` | `0`=取號成功, `1`=已付款, `2`=失敗, `3`=取消, `8`=待確認 |
| `PaymentType` | 支付工具代碼 |
| `Gateway` | 固定 `2` (UPP) |

### PaymentType

| 值 | 工具 |
|----|------|
| 1 | 信用卡 (含分期/紅利/銀聯/Apple/Google/Samsung Pay) |
| 2 | ATM |
| 3 | 超商代碼 |
| 5 | 貨到付款 |
| 6 | icash Pay |
| 7 | AFTEE |
| 9 | LINE Pay |
| 10 | 宅配到付 |
| 11 | JKoPay |

### 信用卡回傳 (PaymentType=1)

| 參數 | 說明 |
|------|------|
| `Card6No`/`Card4No` | 卡號前6/後4碼 |
| `CardInst`/`FirstAmt`/`EachAmt` | 分期數/首期/每期金額 |
| `AuthType` | `1`=一次, `2`=分期, `4`=Apple, `5`=Google, `6`=Samsung, `7`=銀聯 |
| `AuthCode` | 授權碼 |
| `AuthBank`/`AuthBankName` | 授權銀行代碼/名稱 |
| `AuthDay`/`AuthTime` | YYYYMMDD / HHIISS |
| `ResCode`/`ResCodeMsg` | 回應碼/敘述 |
| `CardBank` | 發卡銀行 (國內3碼, 國外`-`) |
| `CreditHash`/`CreditLife` | Token Hash / 有效日期 MMYY |
| `CoBrandCode` | 聯名卡代號 |

### ATM 回傳 (PaymentType=2)

`BankType`(銀行代碼), `PayNo`(虛擬帳號), `PaySet`(`1`=一次性), `ExpireDate`(截止 `YYYY-MM-DD HH:II:SS`)

### CVS 回傳 (PaymentType=3)

`Store`(超商 7-ELEVEN), `PayNo`(繳費代碼), `ExpireDate`(截止)

### icash/Aftee/LinePay 回傳

`PayNo`(交易序號), `PayTime`(付款時間) 或 `CreateDT`(建立時間)

## 注意事項

1. V3 (GCM) 與 V1/V2 (CBC) 加密不相容，混用必定失敗
2. EncryptInfo = `hex(base64(cipher) + ":::" + base64(tag))`
3. 收到回傳必須驗證 HashInfo，不一致表示資料被竄改
4. NotifyURL 只接受 port 80/443
5. MerTradeNo 10分鐘內不可重複
6. 交易結果以 NotifyURL 為準 (ReturnURL 不可靠)
7. CVS 效期最大7天 (超過則支付頁不顯示超商)，ATM 最大180天
8. UNKNOWN 狀態: 60秒無銀行回應，後續透過 NotifyURL 通知，建議15分鐘後查詢
9. 測試環境忽略 DeepLinkURL，務必同時提供 ReturnURL
10. 電子發票不支援舊版 API (1.0/1.1)
11. 信用卡支援 Visa/MasterCard/JCB/銀聯，分期支援 3/6/9/12/18/24/30 期

## Sandbox

| 項目 | 值 |
|------|-----|
| 註冊 | https://sandbox.payuni.com.tw/signup |
| 後台 | https://sandbox-admin.payuni.com.tw |
| API | `https://sandbox-api.payuni.com.tw/api/upp` |
| 一次付清 | `4147631000000001`, `3560511000000001` |
| 模擬3D取消 | `4147631000000002`, `3560511000000002` |
| 分期 | `4147632000000001`, `3560512000000001` (不支援9期) |
| 銀聯 | `6200000000000001` |
| 到期日/CVC | 任意 |
| Apple/Google/Samsung Pay | 任意卡號皆成功 |
| LINE Pay | Channel ID/Secret Key 填任意數字 |
| ATM/CVS 完成 | 後台 > 交易動態 > 模擬繳費 |

## References

| 需求 | 檔案 |
|------|------|
| 查詢/退款/取消/Token/續期 等所有 API 端點 | `references/api-reference.md` |
| TypeScript/NestJS 完整整合範例 | `references/examples.md` |
