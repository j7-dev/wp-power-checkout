---
name: ecpay-aio-v5
description: >
  ECPay (綠界科技) 全方位金流 AIO V5 完整技術參考。涵蓋所有 AIO V5 API 參數、
  CheckMacValue SHA256 產生機制、所有付款方式（信用卡/ATM/CVS/BARCODE/WebATM/TWQR/BNPL/ApplePay）
  的專屬參數、付款結果通知（ReturnURL callback）、取號結果通知、查詢訂單 API、
  信用卡請退款 API、定期定額訂單作業、錯誤代碼對照表、測試環境資訊。
  當程式碼涉及以下任何情況時，必須使用此 skill：
  ECPay、綠界、ecpay、CheckMacValue、AioCheckOut、MerchantTradeNo、
  ChoosePayment、ReturnURL、PaymentInfoURL、OrderResultURL、
  HashKey、HashIV、EncryptType、PeriodAmount、CreditInstallment。
  即使用戶沒有明確說出「ECPay」，只要任務涉及台灣金流串接、綠界付款、
  超商代碼繳費、ATM 虛擬帳號、信用卡定期定額等，也應使用此 skill。
  此 skill 專為 AIO V5 撰寫，不適用於 V2 或站內付（Embedded Checkout）。
---

# ECPay AIO V5 (綠界全方位金流)

> **適用版本**: AIO V5 (EncryptType=1, SHA256) | **文件來源**: https://developers.ecpay.com.tw/?p=2509 | **最後更新**: 2026-04-30

ECPay 全方位金流 (All-in-One) 提供多種支付方式的統一介接介面。透過 HTML Form POST 將消費者導轉至綠界付款頁面完成交易，付款結果以 Server-to-Server POST 回傳至商家 ReturnURL。

## 核心概念

- **交易流程**: 商家 Form POST -> ECPay 付款頁 -> 消費者付款 -> ECPay POST 回傳 ReturnURL (Server 端) + 導轉 OrderResultURL (Client 端)
- **所有 API 皆使用 POST + application/x-www-form-urlencoded**
- **僅支援 TLS 1.2**，API 呼叫過快會收到 HTTP 403
- **HashKey/HashIV 絕對不可放在前端程式碼**

## API 端點一覽

| 功能 | 測試環境 | 正式環境 |
|------|---------|---------|
| 建立訂單 (AIO) | `https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5` | `https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5` |
| 查詢訂單 | `https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5` | `https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5` |
| 信用卡請退款 | N/A (需真實授權) | `https://payment.ecpay.com.tw/CreditDetail/DoAction` |
| 定期定額訂單作業 | `https://payment-stage.ecpay.com.tw/Cashier/CreditCardPeriodAction` | `https://payment.ecpay.com.tw/Cashier/CreditCardPeriodAction` |
| 定期定額訂單查詢 | `https://payment-stage.ecpay.com.tw/Cashier/QueryCreditCardPeriodInfo` | `https://payment.ecpay.com.tw/Cashier/QueryCreditCardPeriodInfo` |
| 信用卡單筆明細查詢 | N/A | `https://payment.ecpay.com.tw/CreditDetail/QueryTrade/V2` |
| ATM/CVS/BARCODE 取號查詢 | `https://payment-stage.ecpay.com.tw/Cashier/QueryPaymentInfo` | `https://payment.ecpay.com.tw/Cashier/QueryPaymentInfo` |

## AIO 建立訂單 -- 必填參數

| 參數 | 型別 | 長度 | 說明 |
|------|------|------|------|
| MerchantID | String | 10 | 特店編號 |
| MerchantTradeNo | String | 20 | 特店訂單編號，唯一值，英數字大小寫混合 |
| MerchantTradeDate | String | 20 | 格式 `yyyy/MM/dd HH:mm:ss` |
| PaymentType | String | 20 | 固定值 `aio` |
| TotalAmount | Int | - | 整數，無小數點，僅新台幣 |
| TradeDesc | String | 200 | 交易描述，不可含特殊字元 |
| ItemName | String | 400 | 商品名稱，多筆用 `#` 分隔 |
| ReturnURL | String | 200 | Server 端付款結果通知 URL |
| ChoosePayment | String | 20 | `ALL` / `Credit` / `ATM` / `CVS` / `BARCODE` / `WebATM` / `ApplePay` / `TWQR` / `BNPL` / `WeiXin` / `DigitalPayment` |
| CheckMacValue | String | - | SHA256 檢查碼 |
| EncryptType | Int | - | 固定值 `1` (SHA256) |

## AIO 建立訂單 -- 選填參數

| 參數 | 型別 | 長度 | 說明 |
|------|------|------|------|
| StoreID | String | 10 | 特店旗下店舖代號 |
| ClientBackURL | String | 200 | Client 端返回商店按鈕連結 |
| ItemURL | String | 200 | 商品銷售網址 |
| Remark | String | 100 | 備註 |
| ChooseSubPayment | String | 20 | 付款子項目 |
| OrderResultURL | String | 200 | Client 端付款結果頁面 URL |
| NeedExtraPaidInfo | String | 1 | `Y` / `N` 是否回傳額外付款資訊 |
| IgnorePayment | String | 100 | 隱藏付款方式，用 `#` 分隔 |
| PlatformID | String | 10 | 平台商代號 |
| CustomField1~4 | String | 50 | 自訂欄位 (各 50 字) |
| Language | String | 3 | `ENG` / `KOR` / `JPN` / `CHI` (預設中文) |

## CheckMacValue 產生演算法 (SHA256)

```
Step 1: 取所有傳送參數（排除 CheckMacValue 本身）
Step 2: 依參數名稱 A-Z 排序（不分大小寫），用 & 串連 key=value
Step 3: 前面加上 HashKey=xxx&，後面加上 &HashIV=xxx
Step 4: 整串做 URL Encode（需處理特殊字元對照表）
Step 5: 轉小寫
Step 6: SHA256 雜湊 -> 轉大寫 = CheckMacValue
```

```typescript
import crypto from 'crypto';

function generateCheckMacValue(
  params: Record<string, string | number>,
  hashKey: string,
  hashIV: string,
): string {
  // Step 1-2: 排序參數（排除 CheckMacValue）
  const sorted = Object.keys(params)
    .filter((key) => key !== 'CheckMacValue')
    .sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()))
    .map((key) => `${key}=${params[key]}`)
    .join('&');

  // Step 3: 加上 HashKey / HashIV
  const raw = `HashKey=${hashKey}&${sorted}&HashIV=${hashIV}`;

  // Step 4: URL Encode + 特殊字元替換（ECPay 以 .NET HttpUtility.UrlEncode 為基準）
  let encoded = encodeURIComponent(raw)
    .replace(/%20/g, '+')
    .replace(/%2d/gi, '-')
    .replace(/%5f/gi, '_')
    .replace(/%2e/gi, '.')
    .replace(/%21/g, '!')
    .replace(/%2a/gi, '*')
    .replace(/%28/g, '(')
    .replace(/%29/g, ')')
    .replace(/%7e/gi, '~');

  // Step 5: 轉小寫
  encoded = encoded.toLowerCase();

  // Step 6: SHA256 -> 轉大寫
  return crypto.createHash('sha256').update(encoded).digest('hex').toUpperCase();
}
```

**URL Encode 特殊字元替換表** (ECPay 以 .NET 標準為基準):

| 字元 | 標準 URLEncode | ECPay 期望 (.NET) |
|------|---------------|-------------------|
| 空格 | `%20` | `+` |
| `-` | `%2d` | `-` |
| `_` | `%5f` | `_` |
| `.` | `%2e` | `.` |
| `!` | `%21` | `!` |
| `*` | `%2a` | `*` |
| `(` | `%28` | `(` |
| `)` | `%29` | `)` |
| `~` | `%7e` | `~` |

## 付款結果通知 (ReturnURL Callback)

ECPay 以 POST 傳送至商家的 ReturnURL，**商家必須回應純文字 `1|OK`**。

| 回傳參數 | 型別 | 說明 |
|---------|------|------|
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| StoreID | String(20) | 店舖代號 |
| RtnCode | Int | **1=付款成功**，其餘為失敗 |
| RtnMsg | String(200) | 交易訊息 |
| TradeNo | String(20) | 綠界交易編號 |
| TradeAmt | Int | 交易金額 |
| PaymentDate | String(20) | 付款時間 yyyy/MM/dd HH:mm:ss |
| PaymentType | String(50) | 付款方式代碼 (如 `Credit_CreditCard`) |
| PaymentTypeChargeFee | Number | 交易手續費 |
| TradeDate | String(20) | 訂單成立時間 |
| SimulatePaid | Int | 0=真實付款, 1=模擬付款 |
| CustomField1~4 | String(50) | 自訂欄位 |
| CheckMacValue | String | 檢查碼 (商家必須驗證) |

**重要**: 收到通知後，必須用回傳參數重新計算 CheckMacValue 比對，驗證通過才處理訂單。ECPay 重新通知機制為 5~15 分鐘一次，共 4 次。

## 測試環境

| 項目 | 值 |
|------|-----|
| 特店編號 | `3002607` |
| 後台帳號 | `stagetest3` |
| 後台密碼 | `test1234` |
| HashKey | `pwFHCqoQZGmho4w6` |
| HashIV | `EkRm7iFT261dpevs` |
| 廠商後台 | `https://vendor-stage.ecpay.com.tw/` |
| 測試信用卡 | `4311-9511-1111-1111` |
| 海外測試卡 | `4000-2011-1111-1111` |
| 美國運通測試卡 | `3403-532780-80900` |
| 永豐30期測試卡 | `4938-1777-7777-7777` |
| 3D 驗證簡訊 | `1234` (固定值) |
| 平台商 PlatformID | `3002599` |
| 平台商 HashKey | `spPjZn66i0OhqJsQ` |
| 平台商 HashIV | `hT5OJckN45isQTTs` |

## 注意事項與陷阱

1. **Form 提交必須是真實 HTML Form POST**，不可用 AJAX/fetch -- ECPay 需要瀏覽器導轉
2. **ReturnURL 必須是 Server 端 URL**，不可是前端頁面 URL
3. **ReturnURL 僅支援 HTTP(80) 和 HTTPS(443)**，不可指定其他 port
4. **ItemName 超過 400 字元會被截斷**，截斷處容易產生亂碼，建議用固定字樣
5. **API 呼叫過快（非建立訂單）會收到 HTTP 403**，需等待 30 分鐘
6. **TotalAmount 必須為正整數**，不可有小數
7. **MerchantTradeNo 不可重複使用**，長度上限 20 字元
8. **PlatformID 使用時，CheckMacValue 需用 PlatformID 對應的 HashKey/HashIV 產生**
9. **CDN 安全過濾**: ECPay 的 CDN 會封鎖含有 shell 指令關鍵字的參數值
10. **Postman 無法測試 AIO**: 需要瀏覽器環境處理導轉和 3D 驗證
11. **主機時間同步**: 商家伺服器必須做時間同步，避免 TimeStamp 驗證失敗
12. **防火牆設定**: 出站需開放 payment.ecpay.com.tw:443，入站需開放 postgate.ecpay.com.tw:443
13. **參數不支援 HTML 標籤**
14. **非英文域名需 punycode 編碼**

## References 導引

| 需求 | 參閱檔案 |
|------|---------|
| 各付款方式專屬參數 | `references/payment-methods.md` |
| 完整通知/回調參數 | `references/notifications.md` |
| 查詢/請退款/訂單作業 API | `references/query-and-actions.md` |
| 錯誤代碼 + PaymentType 對照表 | `references/codes-and-tables.md` |
| TypeScript/NestJS 完整整合範例 | `references/examples.md` |
