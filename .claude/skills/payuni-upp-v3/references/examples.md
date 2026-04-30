# PAYUNi TypeScript/NestJS Integration Examples

> Complete TypeScript examples for NestJS 10 backend.

## TOC

- [Crypto Utility Class](#crypto-utility-class)
- [NestJS Service](#nestjs-service)
- [NestJS Controller](#nestjs-controller)
- [Environment Variables](#environment-variables)
- [Verification Test Values](#verification-test-values)

---

## Crypto Utility Class

```typescript
// payuni-crypto.util.ts
import * as crypto from "crypto";
import * as querystring from "querystring";

export class PayuniCrypto {
  constructor(private readonly hashKey: string, private readonly hashIV: string) {}

  // AES-256-GCM Encrypt: hex( base64(ciphertext) + ":::" + base64(authTag) )
  encrypt(params: Record<string, string | number>): string {
    const plaintext = querystring.stringify(params as Record<string, string>);
    const cipher = crypto.createCipheriv("aes-256-gcm", this.hashKey, Buffer.from(this.hashIV));
    let enc = cipher.update(plaintext, "utf8", "base64");
    enc += cipher.final("base64");
    const tag = cipher.getAuthTag().toString("base64");
    return Buffer.from(enc + ":::" + tag).toString("hex").trim();
  }

  // AES-256-GCM Decrypt
  decrypt(encryptStr: string): Record<string, string> {
    const [encData, tag] = Buffer.from(encryptStr, "hex").toString().split(":::");
    const decipher = crypto.createDecipheriv("aes-256-gcm", this.hashKey, Buffer.from(this.hashIV));
    decipher.setAuthTag(Buffer.from(tag, "base64"));
    let dec = decipher.update(encData, "base64", "utf8");
    dec += decipher.final("utf8");
    const result: Record<string, string> = {};
    new URLSearchParams(dec).forEach((v, k) => { result[k] = v; });
    return result;
  }

  // SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()
  generateHashInfo(encryptInfo: string): string {
    return crypto.createHash("sha256")
      .update(this.hashKey + encryptInfo + this.hashIV).digest("hex").toUpperCase();
  }

  verifyHashInfo(encryptInfo: string, hashInfo: string): boolean {
    return this.generateHashInfo(encryptInfo) === hashInfo;
  }
}
```

---

## NestJS Service

```typescript
// apps/api-gateway/src/commerce/payments/payuni/payuni.service.ts
import { Injectable } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import axios from "axios";
import { PayuniCrypto } from "./payuni-crypto.util";

@Injectable()
export class PayuniService {
  private readonly crypto: PayuniCrypto;
  private readonly merID: string;
  private readonly baseUrl: string;

  constructor(private configService: ConfigService) {
    this.merID = this.configService.get<string>("PAYUNI_MER_ID");
    this.crypto = new PayuniCrypto(
      this.configService.get<string>("PAYUNI_HASH_KEY"),
      this.configService.get<string>("PAYUNI_HASH_IV"),
    );
    this.baseUrl = this.configService.get("PAYUNI_SANDBOX") === "true"
      ? "https://sandbox-api.payuni.com.tw" : "https://api.payuni.com.tw";
  }

  createPayment(p: {
    merTradeNo: string; tradeAmt: number; prodDesc: string;
    returnUrl: string; notifyUrl: string; email?: string;
  }) {
    const enc: Record<string, string | number> = {
      MerID: this.merID, MerTradeNo: p.merTradeNo, TradeAmt: p.tradeAmt,
      Timestamp: Math.floor(Date.now() / 1000), ProdDesc: p.prodDesc,
      ReturnURL: p.returnUrl, NotifyURL: p.notifyUrl,
      Credit: 1, ATM: 1, CVS: 1,
    };
    if (p.email) enc.UsrMail = p.email;
    const encryptInfo = this.crypto.encrypt(enc);
    return {
      action: this.baseUrl + "/api/upp",
      fields: { MerID: this.merID, Version: "2.0", EncryptInfo: encryptInfo, HashInfo: this.crypto.generateHashInfo(encryptInfo) },
    };
  }

  processNotify(body: Record<string, string>) {
    if (!body.EncryptInfo || !body.HashInfo) throw new Error("Missing fields");
    if (!this.crypto.verifyHashInfo(body.EncryptInfo, body.HashInfo)) throw new Error("Hash mismatch");
    return this.crypto.decrypt(body.EncryptInfo);
  }

  async queryTrade(tradeNo: string) { return this.callApi("/api/trade/query", { TradeNo: tradeNo }); }
  async refund(tradeNo: string, amt?: number) {
    const p: Record<string, string | number> = { TradeNo: tradeNo, CloseType: 2 };
    if (amt) p.TradeAmt = amt;
    return this.callApi("/api/trade/close", p);
  }
  async cancelAuth(tradeNo: string) { return this.callApi("/api/trade/cancel", { TradeNo: tradeNo }); }

  private async callApi(path: string, params: Record<string, string | number>, version = "1.0") {
    const enc = { MerID: this.merID, Timestamp: Math.floor(Date.now() / 1000), ...params };
    const encryptInfo = this.crypto.encrypt(enc);
    const { data } = await axios.post(this.baseUrl + path, {
      MerID: this.merID, Version: version,
      EncryptInfo: encryptInfo, HashInfo: this.crypto.generateHashInfo(encryptInfo),
    });
    if (data.Status === "ERROR") throw new Error("PAYUNi error");
    if (!this.crypto.verifyHashInfo(data.EncryptInfo, data.HashInfo)) throw new Error("Hash mismatch");
    return this.crypto.decrypt(data.EncryptInfo);
  }
}
```

---

## Environment Variables

```env
PAYUNI_MER_ID=your_merchant_id
PAYUNI_HASH_KEY=your_32_char_hash_key_here_____
PAYUNI_HASH_IV=your_16char_iv__
PAYUNI_SANDBOX=true
```

---

## Verification Test Values

Official test values from PAYUNi docs:

- merKey: `12345678901234567890123456789012`
- merIV: `1234567890123456`
- testData: `{ MerID: "AAA", MerTradeNO: "BBB", Prod: "product" }`
- Expected SHA256: `E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7`
