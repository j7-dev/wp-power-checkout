# ECPay AIO V5 -- TypeScript/NestJS 整合範例

> 完整可執行的程式碼範例，適用於 NestJS 10 後端。

## 目錄

- [CheckMacValue 產生與驗證](#checkmacvalue-產生與驗證)
- [建立 AIO 訂單 (生成 Form HTML)](#建立-aio-訂單)
- [處理付款結果通知 (ReturnURL)](#處理付款結果通知)
- [處理取號結果通知 (PaymentInfoURL)](#處理取號結果通知)
- [查詢訂單狀態](#查詢訂單狀態)
- [信用卡請退款](#信用卡請退款)
- [定期定額訂單建立](#定期定額訂單建立)

---

## CheckMacValue 產生與驗證

```typescript
// ecpay-crypto.util.ts
import crypto from 'crypto';

/**
 * ECPay AIO V5 CheckMacValue 產生函式
 * 演算法: SHA256 (EncryptType=1)
 */
export function generateCheckMacValue(
  params: Record<string, string | number>,
  hashKey: string,
  hashIV: string,
): string {
  // 1. 排除 CheckMacValue，依參數名稱 A-Z 排序（不分大小寫）
  const sorted = Object.keys(params)
    .filter((key) => key !== 'CheckMacValue')
    .sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()))
    .map((key) => `${key}=${params[key]}`)
    .join('&');

  // 2. 前加 HashKey，後加 HashIV
  const raw = `HashKey=${hashKey}&${sorted}&HashIV=${hashIV}`;

  // 3. URL Encode（以 .NET HttpUtility.UrlEncode 為基準）
  let encoded = dotNetUrlEncode(raw);

  // 4. 轉小寫
  encoded = encoded.toLowerCase();

  // 5. SHA256 雜湊 -> 轉大寫
  return crypto.createHash('sha256').update(encoded).digest('hex').toUpperCase();
}

/**
 * 模擬 .NET HttpUtility.UrlEncode 行為
 * Node.js encodeURIComponent 編碼後需替換部分字元以對齊 .NET 標準
 */
export function dotNetUrlEncode(str: string): string {
  return encodeURIComponent(str)
    .replace(/%20/g, '+')
    .replace(/%2d/gi, '-')
    .replace(/%5f/gi, '_')
    .replace(/%2e/gi, '.')
    .replace(/%21/g, '!')
    .replace(/%2a/gi, '*')
    .replace(/%28/g, '(')
    .replace(/%29/g, ')')
    .replace(/%7e/gi, '~');
}

/**
 * 驗證 ECPay 回傳的 CheckMacValue
 */
export function verifyCheckMacValue(
  params: Record<string, string | number>,
  hashKey: string,
  hashIV: string,
): boolean {
  const receivedMac = params.CheckMacValue as string;
  const calculatedMac = generateCheckMacValue(params, hashKey, hashIV);
  return receivedMac === calculatedMac;
}
```

---

## 建立 AIO 訂單

```typescript
// ecpay.service.ts
import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { generateCheckMacValue } from './ecpay-crypto.util';

interface CreateEcpayOrderDto {
  merchantTradeNo: string;
  totalAmount: number;
  tradeDesc: string;
  itemName: string;
  returnUrl: string;
  orderResultUrl?: string;
  choosePayment?: string;
  customField1?: string;
}

@Injectable()
export class EcpayService {
  private readonly merchantId: string;
  private readonly hashKey: string;
  private readonly hashIV: string;
  private readonly apiUrl: string;

  constructor(private configService: ConfigService) {
    this.merchantId = this.configService.get('ECPAY_MERCHANT_ID');
    this.hashKey = this.configService.get('ECPAY_HASH_KEY');
    this.hashIV = this.configService.get('ECPAY_HASH_IV');

    const isProduction = this.configService.get('NODE_ENV') === 'production';
    this.apiUrl = isProduction
      ? 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5'
      : 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5';
  }

  /**
   * 產生 AIO 訂單的 HTML Form（前端直接 render 後自動 submit）
   */
  createOrderForm(dto: CreateEcpayOrderDto): string {
    const now = new Date();
    const merchantTradeDate = this.formatDate(now);

    const params: Record<string, string | number> = {
      MerchantID: this.merchantId,
      MerchantTradeNo: dto.merchantTradeNo,
      MerchantTradeDate: merchantTradeDate,
      PaymentType: 'aio',
      TotalAmount: dto.totalAmount,
      TradeDesc: dto.tradeDesc,
      ItemName: dto.itemName,
      ReturnURL: dto.returnUrl,
      ChoosePayment: dto.choosePayment || 'ALL',
      EncryptType: 1,
      NeedExtraPaidInfo: 'Y',
    };

    if (dto.orderResultUrl) {
      params.OrderResultURL = dto.orderResultUrl;
    }
    if (dto.customField1) {
      params.CustomField1 = dto.customField1;
    }

    // 產生 CheckMacValue
    params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

    // 產生 HTML Form
    const inputs = Object.entries(params)
      .map(([key, value]) => `<input type="hidden" name="${key}" value="${value}">`)
      .join('\n');

    return `
      <form id="ecpay-form" method="POST" action="${this.apiUrl}">
        ${inputs}
      </form>
      <script>document.getElementById('ecpay-form').submit();</script>
    `;
  }

  private formatDate(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return (
      `${date.getFullYear()}/${pad(date.getMonth() + 1)}/${pad(date.getDate())} ` +
      `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    );
  }
}
```

---

## 處理付款結果通知

```typescript
// ecpay-callback.controller.ts
import { Controller, Post, Body, Res, HttpStatus } from '@nestjs/common';
import { Response } from 'express';
import { verifyCheckMacValue } from './ecpay-crypto.util';
import { OrdersService } from '../orders/orders.service';
import { ConfigService } from '@nestjs/config';

@Controller('webhooks/ecpay')
export class EcpayCallbackController {
  constructor(
    private ordersService: OrdersService,
    private configService: ConfigService,
  ) {}

  /**
   * ECPay ReturnURL callback handler
   * ECPay 以 POST application/x-www-form-urlencoded 回傳
   * 商家必須回應純文字 "1|OK"
   */
  @Post('return')
  async handlePaymentResult(
    @Body() body: Record<string, string>,
    @Res() res: Response,
  ) {
    const hashKey = this.configService.get('ECPAY_HASH_KEY');
    const hashIV = this.configService.get('ECPAY_HASH_IV');

    // 1. 驗證 CheckMacValue
    if (!verifyCheckMacValue(body, hashKey, hashIV)) {
      // CheckMacValue 驗證失敗，可能是偽造的請求
      return res.status(HttpStatus.OK).send('0|ErrorMessage');
    }

    // 2. 檢查是否為模擬付款
    const isSimulated = body.SimulatePaid === '1';

    // 3. 判斷付款結果
    const rtnCode = parseInt(body.RtnCode, 10);
    if (rtnCode === 1) {
      // 付款成功 - 更新訂單狀態
      await this.ordersService.updatePaymentStatus(body.MerchantTradeNo, {
        status: 'paid',
        tradeNo: body.TradeNo,
        paymentDate: body.PaymentDate,
        paymentType: body.PaymentType,
        tradeAmt: parseInt(body.TradeAmt, 10),
        isSimulated,
      });
    } else {
      // 付款失敗 - 記錄失敗原因
      await this.ordersService.updatePaymentStatus(body.MerchantTradeNo, {
        status: 'failed',
        rtnCode,
        rtnMsg: body.RtnMsg,
      });
    }

    // 4. 回應 ECPay
    return res.status(HttpStatus.OK).send('1|OK');
  }
}
```

---

## 處理取號結果通知

```typescript
// ecpay-callback.controller.ts (續)

/**
 * ATM/CVS/BARCODE 取號結果通知
 * RtnCode: ATM 成功=2, CVS/BARCODE 成功=10100073
 */
@Post('payment-info')
async handlePaymentInfo(
  @Body() body: Record<string, string>,
  @Res() res: Response,
) {
  const hashKey = this.configService.get('ECPAY_HASH_KEY');
  const hashIV = this.configService.get('ECPAY_HASH_IV');

  if (!verifyCheckMacValue(body, hashKey, hashIV)) {
    return res.status(HttpStatus.OK).send('0|ErrorMessage');
  }

  const paymentType = body.PaymentType;

  if (paymentType.startsWith('ATM_')) {
    // ATM: 儲存銀行代碼和虛擬帳號
    await this.ordersService.savePaymentInfo(body.MerchantTradeNo, {
      type: 'ATM',
      bankCode: body.BankCode,
      vAccount: body.vAccount,
      expireDate: body.ExpireDate,
    });
  } else if (paymentType.startsWith('CVS_')) {
    // CVS: 儲存繳費代碼
    await this.ordersService.savePaymentInfo(body.MerchantTradeNo, {
      type: 'CVS',
      paymentNo: body.PaymentNo,
      expireDate: body.ExpireDate,
    });
  } else if (paymentType.startsWith('BARCODE_')) {
    // BARCODE: 儲存三段條碼
    await this.ordersService.savePaymentInfo(body.MerchantTradeNo, {
      type: 'BARCODE',
      barcode1: body.Barcode1,
      barcode2: body.Barcode2,
      barcode3: body.Barcode3,
      expireDate: body.ExpireDate,
    });
  }

  return res.status(HttpStatus.OK).send('1|OK');
}
```

---

## 查詢訂單狀態

```typescript
// ecpay.service.ts (續)
import axios from 'axios';

/**
 * 查詢訂單狀態
 * TimeStamp 驗證區間為 3 分鐘
 */
async queryTradeInfo(merchantTradeNo: string): Promise<Record<string, string>> {
  const isProduction = this.configService.get('NODE_ENV') === 'production';
  const url = isProduction
    ? 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5'
    : 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5';

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: merchantTradeNo,
    TimeStamp: Math.floor(Date.now() / 1000),
  };

  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const formData = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    formData.append(key, String(value));
  }

  const response = await axios.post(url, formData.toString(), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });

  // 回應為 key=value&key=value 格式
  const result: Record<string, string> = {};
  response.data.split('&').forEach((pair: string) => {
    const [key, ...valueParts] = pair.split('=');
    result[key] = valueParts.join('=');
  });

  return result;
}
```

---

## 信用卡請退款

```typescript
// ecpay.service.ts (續)

/**
 * 信用卡請退款作業
 * Action: C=請款, R=退款, E=取消, N=放棄
 * 注意: 僅正式環境可用
 */
async creditCardAction(
  merchantTradeNo: string,
  tradeNo: string,
  action: 'C' | 'R' | 'E' | 'N',
  totalAmount: number,
): Promise<{ rtnCode: number; rtnMsg: string }> {
  const url = 'https://payment.ecpay.com.tw/CreditDetail/DoAction';

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: merchantTradeNo,
    TradeNo: tradeNo,
    Action: action,
    TotalAmount: totalAmount,
  };

  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const formData = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    formData.append(key, String(value));
  }

  const response = await axios.post(url, formData.toString(), {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });

  const result: Record<string, string> = {};
  response.data.split('&').forEach((pair: string) => {
    const [key, ...valueParts] = pair.split('=');
    result[key] = valueParts.join('=');
  });

  return {
    rtnCode: parseInt(result.RtnCode, 10),
    rtnMsg: result.RtnMsg,
  };
}
```

---

## 定期定額訂單建立

```typescript
// ecpay.service.ts (續)

interface CreatePeriodicOrderDto {
  merchantTradeNo: string;
  totalAmount: number;
  tradeDesc: string;
  itemName: string;
  returnUrl: string;
  periodReturnUrl?: string;
  periodType: 'D' | 'M' | 'Y';
  frequency: number;
  execTimes: number;
}

/**
 * 建立定期定額訂單
 * PeriodAmount 必須與 TotalAmount 相同
 * ChoosePayment 必須為 Credit
 */
createPeriodicOrderForm(dto: CreatePeriodicOrderDto): string {
  const now = new Date();

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: dto.merchantTradeNo,
    MerchantTradeDate: this.formatDate(now),
    PaymentType: 'aio',
    TotalAmount: dto.totalAmount,
    TradeDesc: dto.tradeDesc,
    ItemName: dto.itemName,
    ReturnURL: dto.returnUrl,
    ChoosePayment: 'Credit',
    EncryptType: 1,
    PeriodAmount: dto.totalAmount, // 必須與 TotalAmount 相同
    PeriodType: dto.periodType,
    Frequency: dto.frequency,
    ExecTimes: dto.execTimes,
  };

  if (dto.periodReturnUrl) {
    params.PeriodReturnURL = dto.periodReturnUrl;
  }

  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const inputs = Object.entries(params)
    .map(([key, value]) => `<input type="hidden" name="${key}" value="${value}">`)
    .join('\n');

  return `
    <form id="ecpay-form" method="POST" action="${this.apiUrl}">
      ${inputs}
    </form>
    <script>document.getElementById('ecpay-form').submit();</script>
  `;
}
```

---

## 環境變數範例

```env
# .env
ECPAY_MERCHANT_ID=3002607
ECPAY_HASH_KEY=pwFHCqoQZGmho4w6
ECPAY_HASH_IV=EkRm7iFT261dpevs

# 正式環境請替換為正式的 MerchantID / HashKey / HashIV
```
