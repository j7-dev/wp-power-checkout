/**
 * 綠界 ECPay AES-128-CBC 加解密工具 — E2E ECPG / 發票測試用
 *
 * 演算法與後端 `Payment\Ecpg\Shared\Helpers\AesCrypto`
 * 與 `Invoice\Ecpay\Shared\Helpers\AesCrypto` 完全一致（兩者規則相同）：
 *
 *   加密：明文物件 → JSON（UNESCAPED_UNICODE/SLASHES）→ urlencode（aesUrlEncode）
 *        → AES-128-CBC（PKCS#7）→ base64（標準 alphabet +/=）
 *   解密：base64_decode → AES-128-CBC 解密 → urldecode → JSON.parse
 *
 * Key / IV 各取 HashKey / HashIV 的前 16 bytes。
 *
 * ⚠️ ECPG / 發票（AES-JSON 類）解密後的 RtnCode / TransCode 為「整數」（與 AIO CMV 字串不同）。
 * ⚠️ aesUrlEncode 與 CMV 的 ecpayUrlEncode 不同：只做 urlencode（空格→+、~→%7E），
 *    不做 toLowerCase、不做 .NET 字元還原（見 ECPay-API-Skill guides/14 對比表）。
 *
 * 站內付 2.0 ReturnURL（EcpgReturnPayload）外層為 { MerchantID, RpHeader, TransCode, TransMsg, Data }，
 * 其中 Data 為本工具 encrypt() 產生的密文；解密後含巢狀 OrderInfo.MerchantTradeNo 與整數 RtnCode。
 *
 * @see inc/classes/Domains/Payment/Ecpg/Shared/Helpers/AesCrypto.php
 * @see inc/classes/Domains/Invoice/Ecpay/Shared/Helpers/AesCrypto.php
 */
import { createCipheriv, createDecipheriv } from 'crypto'

const CIPHER = 'aes-128-cbc'

/**
 * 綠界 AES 用 urlencode（aesUrlEncode）
 *
 * 對齊後端 PHP `urlencode($json)`：A-Za-z0-9_-. 不編碼，空格→`+`，其餘 → 大寫百分比編碼。
 * 與 CMV 的 ecpayUrlEncode 差異：不做 toLowerCase、不做 .NET 字元還原。
 *
 * @param str 要編碼的字串
 */
function aesUrlEncode(str: string): string {
  let out = ''
  for (const byte of Buffer.from(str, 'utf8')) {
    const ch = String.fromCharCode(byte)
    if (/[A-Za-z0-9_\-.]/.test(ch)) {
      out += ch
    } else if (ch === ' ') {
      out += '+'
    } else {
      out += '%' + byte.toString(16).toUpperCase().padStart(2, '0')
    }
  }
  return out
}

/** PHP urldecode 反向（`+` → 空格、%XX → byte） */
function aesUrlDecode(str: string): string {
  const replaced = str.replace(/\+/g, ' ')
  // 逐個 %XX 還原為 byte，再以 utf8 解析
  const bytes: number[] = []
  for (let i = 0; i < replaced.length; i++) {
    const c = replaced[i]
    if (c === '%' && i + 2 < replaced.length) {
      bytes.push(parseInt(replaced.substr(i + 1, 2), 16))
      i += 2
    } else {
      bytes.push(c.charCodeAt(0))
    }
  }
  return Buffer.from(bytes).toString('utf8')
}

/** 取前 16 bytes 作為 key / iv（對齊後端 substr(..., 0, 16)） */
function take16(secret: string): Buffer {
  return Buffer.from(secret.slice(0, 16), 'utf8')
}

/**
 * 綠界 AES-128-CBC 加密
 *
 * @param data    明文物件
 * @param hashKey 綠界 HashKey
 * @param hashIv  綠界 HashIV
 * @returns       Base64 密文（標準 alphabet）
 */
export function aesEncrypt(
  data: Record<string, unknown>,
  hashKey: string,
  hashIv: string,
): string {
  const json = JSON.stringify(data)
  const urlEncoded = aesUrlEncode(json)
  const cipher = createCipheriv(CIPHER, take16(hashKey), take16(hashIv))
  cipher.setAutoPadding(true) // PKCS#7
  const encrypted = Buffer.concat([
    cipher.update(urlEncoded, 'utf8'),
    cipher.final(),
  ])
  return encrypted.toString('base64')
}

/**
 * 綠界 AES-128-CBC 解密
 *
 * @param cipherText Base64 密文
 * @param hashKey    綠界 HashKey
 * @param hashIv     綠界 HashIV
 * @returns          解密後物件
 */
export function aesDecrypt(
  cipherText: string,
  hashKey: string,
  hashIv: string,
): Record<string, unknown> {
  const raw = Buffer.from(cipherText, 'base64')
  const decipher = createDecipheriv(CIPHER, take16(hashKey), take16(hashIv))
  decipher.setAutoPadding(true)
  const decrypted = Buffer.concat([
    decipher.update(raw),
    decipher.final(),
  ]).toString('utf8')
  const json = aesUrlDecode(decrypted)
  return JSON.parse(json) as Record<string, unknown>
}

/**
 * 組裝完整 ECPG ReturnURL 外層 JSON（含 AES 加密的 Data）
 *
 * 外層三層結構：{ MerchantID, RpHeader:{ Timestamp }, TransCode, TransMsg, Data }。
 * Data 為對 dataPlain 物件加密的結果（站內付 2.0 解密後業務資料）。
 *
 * @param merchantId 綠界特店編號
 * @param dataPlain  解密後的業務資料（須含 OrderInfo.MerchantTradeNo、整數 RtnCode 等）
 * @param hashKey    綠界 HashKey
 * @param hashIv     綠界 HashIV
 * @param transCode  傳輸層回應碼（預設 1=成功）
 */
export function buildEcpgReturnPayload(
  merchantId: string,
  dataPlain: Record<string, unknown>,
  hashKey: string,
  hashIv: string,
  transCode = 1,
): Record<string, unknown> {
  return {
    MerchantID: merchantId,
    RpHeader: { Timestamp: Math.floor(Date.now() / 1000) },
    TransCode: transCode,
    TransMsg: transCode === 1 ? 'Success' : 'Fail',
    Data: aesEncrypt(dataPlain, hashKey, hashIv),
  }
}

/**
 * 綠界 ECPG 線上金流公開測試帳號（站內付 2.0 / 幕後授權共用，AES）
 * Source: ECPay-API-Skill SKILL.md §測試帳號；與後端 EcpgSettingsDTO test 預設一致。
 * ⚠️ 公開共用測試帳號，禁用於正式環境。
 */
export const ECPAY_ECPG_TEST = {
  merchantId: '3002607',
  hashKey: 'pwFHCqoQZGmho4w6',
  hashIv: 'EkRm7iFT261dpevs',
} as const

/**
 * 綠界電子發票公開測試帳號（B2C/B2B，AES）
 * Source: ECPay-API-Skill SKILL.md §測試帳號；與後端 EcpayInvoiceSettingsDTO test 預設一致。
 */
export const ECPAY_INVOICE_TEST = {
  merchantId: '2000132',
  hashKey: 'ejCk326UnaZWKisg',
  hashIv: 'q9jcZX8Ib9LM8wYk',
} as const
