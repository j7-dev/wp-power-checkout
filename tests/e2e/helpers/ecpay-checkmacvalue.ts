/**
 * 綠界 ECPay CheckMacValue（SHA256）產生工具 — E2E callback 測試用
 *
 * 演算法與後端 `Payment\EcpayAIO\Shared\Helpers\CheckMacValueService` + `UrlEncoder` 完全一致：
 *   1. 移除 CheckMacValue 欄位（避免用舊值生成）
 *   2. 依 key 字母排序（不分大小寫，對齊 PHP ksort SORT_STRING|SORT_FLAG_CASE）
 *   3. 開頭加 HashKey、結尾加 HashIV，以 & 連接所有 key=value
 *   4. 套用綠界 .NET 風格 urlencode（PHP urlencode → 還原 11 個特殊字元）
 *   5. 轉小寫
 *   6. SHA256
 *   7. 轉大寫（hex）
 *
 * ⚠️ AIO（CMV 類）服務的 RtnCode 一律為「字串」（'1' / '2' / '10100073'）。
 *
 * 演算法已對綠界官方 test vector 驗證相符（見本檔 selfTest / scripts，
 * 以及 .claude/skills/ECPay-API-Skill/test-vectors/checkmacvalue.json 向量 1）：
 *   MerchantID=3002607 ... ChoosePayment=ALL, EncryptType=1
 *   → 291CBA324D31FB5A4BBBFDF2CFE5D32598524753AFD4959C3BF590C5B2F57FB2
 *
 * @see inc/classes/Domains/Payment/EcpayAIO/Shared/Helpers/CheckMacValueService.php
 * @see inc/classes/Domains/Payment/EcpayAIO/Shared/Helpers/UrlEncoder.php
 */
import { createHash } from 'crypto'

/**
 * 綠界 .NET 風格 urlencode（對齊後端 UrlEncoder::encode）
 *
 * PHP urlencode 規則：A-Za-z0-9_-. 不編碼，空格→`+`，其餘 → 大寫百分比編碼。
 * 接著把 11 個序列（%2D %5F %2E %2A %21 %28 %29，含大小寫）還原為原字元，
 * 與 .NET HttpUtility.UrlEncode 輸出一致。
 *
 * 注意：JS encodeURIComponent 與 PHP urlencode 行為不同（空格、`~`、`!*'()` 等），
 * 因此這裡逐 byte 自行實作 PHP urlencode 語意，確保與後端 100% 相同。
 *
 * @param str 要編碼的字串
 * @returns   編碼後字串
 */
export function ecpayUrlEncode(str: string): string {
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
  // 還原 .NET 不編碼的字元（與後端 str_replace 對齊；- _ . 已不會被 PHP urlencode 編碼，
  // 故實際只剩 %2A %21 %28 %29 會被還原，但保留全部替換以與後端逐字一致）
  return out
    .replace(/%2D/gi, '-')
    .replace(/%5F/gi, '_')
    .replace(/%2E/gi, '.')
    .replace(/%2A/gi, '*')
    .replace(/%21/gi, '!')
    .replace(/%28/gi, '(')
    .replace(/%29/gi, ')')
}

/** CheckMacValue 計算可接受的參數型別（與後端僅取 string|int 一致） */
export type CmvParams = Record<string, string | number>

/**
 * 產生綠界 CheckMacValue（SHA256，大寫 hex）
 *
 * @param params  綠界參數（CheckMacValue 欄位會被自動忽略）
 * @param hashKey 綠界 HashKey
 * @param hashIv  綠界 HashIV
 * @returns       CheckMacValue（大寫 hex）
 */
export function generateCheckMacValue(
  params: CmvParams,
  hashKey: string,
  hashIv: string,
): string {
  const args: CmvParams = { ...params }
  delete (args as Record<string, unknown>).CheckMacValue

  const sortedKeys = Object.keys(args).sort((a, b) => {
    const al = a.toLowerCase()
    const bl = b.toLowerCase()
    return al < bl ? -1 : al > bl ? 1 : 0
  })

  const parts: string[] = [`HashKey=${hashKey}`]
  for (const key of sortedKeys) {
    parts.push(`${key}=${args[key]}`)
  }
  parts.push(`HashIV=${hashIv}`)

  const joined = parts.join('&')
  const encoded = ecpayUrlEncode(joined)
  const lowered = encoded.toLowerCase()
  return createHash('sha256').update(lowered, 'utf8').digest('hex').toUpperCase()
}

/**
 * 以已知 HashKey/HashIV 對一組參數附加正確 CheckMacValue
 *
 * @param params  綠界參數（不含 CheckMacValue）
 * @param hashKey 綠界 HashKey
 * @param hashIv  綠界 HashIV
 * @returns       含合法 CheckMacValue 的新參數物件
 */
export function withCheckMacValue(
  params: CmvParams,
  hashKey: string,
  hashIv: string,
): CmvParams & { CheckMacValue: string } {
  return {
    ...params,
    CheckMacValue: generateCheckMacValue(params, hashKey, hashIv),
  }
}

/**
 * 綠界 AIO 公開測試帳號（金流 AIO，SHA256）
 * Source: ECPay-API-Skill SKILL.md §測試帳號 金流 AIO；與後端 AioSettingsDTO test 預設一致。
 * ⚠️ 公開共用測試帳號，禁用於正式環境。
 */
export const ECPAY_AIO_TEST = {
  merchantId: '3002607',
  hashKey: 'pwFHCqoQZGmho4w6',
  hashIv: 'EkRm7iFT261dpevs',
} as const
