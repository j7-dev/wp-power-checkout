/**
 * 綠界全方位物流 AES-JSON 三層 Payload 工具 — E2E 測試用
 *
 * 全方位物流 v2 使用與站內付 2.0（ECPG）相同的 AES-128-CBC 加解密核心，
 * 但 RqHeader 多了 **Revision: "1.0.0"** 這個必填欄位，且：
 *
 *   - 請求方向（我們送給 ECPay）：RqHeader
 *   - Callback 方向（ECPay 送給我們）：RpHeader
 *   - 回應方向（我們回給 ECPay）：RqHeader（依 SDK 範例，非 RpHeader）
 *
 * 三層結構（外層）：
 *   { MerchantID, RqHeader/RpHeader: { Timestamp, [Revision] }, TransCode, TransMsg, Data }
 *
 * 其中 Data 為對業務資料物件做 AES-128-CBC 加密後的 Base64 字串。
 *
 * 加解密算法與 `ecpay-aes.ts` 完全一致，本檔案直接 re-export 並擴充物流專用 helper。
 *
 * @see guides/07-logistics-allinone.md — AES-JSON 三層結構說明
 * @see helpers/ecpay-aes.ts — 底層 aesEncrypt / aesDecrypt（相同算法）
 */
import { aesEncrypt, aesDecrypt } from './ecpay-aes.js'

export { aesEncrypt, aesDecrypt }

// ─── 測試帳號 ─────────────────────────────────────────────────────────────────

/**
 * 全方位物流 B2C 公開測試帳號
 * MerchantID: 2000132 / HashKey: 5294y06JbISpM5x9 / HashIV: v77hoKGq4kWxNNIS
 * ⚠️ 帳號與國內物流相同，但協議不同（AES-JSON vs CMV-MD5）
 */
export const LOGISTICS_B2C_TEST = {
  merchantId: '2000132',
  hashKey: '5294y06JbISpM5x9',
  hashIv: 'v77hoKGq4kWxNNIS',
} as const

/**
 * 全方位物流 C2C 公開測試帳號
 * MerchantID: 2000933 / HashKey: XBERn1YOvpM9nfZc / HashIV: h1ONHk4P4yqbl5LK
 * ⚠️ C2C 帳號與 B2C 帳號不同，不可互換
 */
export const LOGISTICS_C2C_TEST = {
  merchantId: '2000933',
  hashKey: 'XBERn1YOvpM9nfZc',
  hashIv: 'h1ONHk4P4yqbl5LK',
} as const

// ─── 貨態碼常數 ───────────────────────────────────────────────────────────────

/**
 * 全方位物流 LogisticsStatus 貨態碼（B2C 超商取貨）
 * 來源：guides/07-logistics-allinone.md + ECPay 官方 API 文件
 */
export const LOGISTICS_STATUS = {
  /** 物流訂單建立成功（暫存訂單成立） */
  ORDER_CREATED: '300',
  /** 貨品已送達門市 */
  ARRIVED_STORE: '3003',
  /** 消費者已取件 */
  PICKED_UP: '3018',
  /** 超過期限未取件，退回中 */
  RETURNING: '3022',
  /** 退回完成 */
  RETURNED: '3024',
  /** 門市拒收 */
  REJECTED_BY_STORE: '3032',
  /** 物流異常 */
  EXCEPTION: '9500',
} as const

/** LogisticsSubType — 超商物流子類型 */
export const LOGISTICS_SUB_TYPE = {
  FAMI: 'FAMI',            // 全家超商 B2C
  UNIMART: 'UNIMART',      // 統一超商 B2C
  HILIFE: 'HILIFE',        // 萊爾富 B2C
  FAMI_C2C: 'FAMIC2C',     // 全家 C2C
  UNIMART_C2C: 'UNIMARTC2C', // 統一 C2C
  HOME: 'TCAT',            // 宅配（黑貓）
} as const

/** LogisticsType */
export const LOGISTICS_TYPE = {
  CVS: 'CVS',   // 超商取貨
  HOME: 'HOME', // 宅配
} as const

// ─── Payload 建構 Helper ──────────────────────────────────────────────────────

/**
 * 組裝全方位物流 ServerReplyURL callback 模擬 payload（三層 AES-JSON）
 *
 * ECPay 發給我們的 callback body 結構：
 *   { MerchantID, RpHeader: { Timestamp }, TransCode, TransMsg, Data(AES密文) }
 *
 * @param merchantId   綠界特店編號
 * @param dataPlain    解密後的業務資料（含 LogisticsID, LogisticsStatus 等）
 * @param hashKey      HashKey（對應 merchantId）
 * @param hashIv       HashIV（對應 merchantId）
 * @param transCode    外層傳輸碼（1=成功，0=失敗）
 */
export function buildLogisticsStatusPayload(
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
 * 組裝全方位物流「我們回給 ECPay」的 AES-JSON 三層回應
 *
 * ⚠️ 回應使用 RqHeader（不是 RpHeader），依 SDK 範例規範
 *
 * @param merchantId  綠界特店編號
 * @param hashKey     HashKey
 * @param hashIv      HashIV
 * @param rtnCode     業務回應碼（1=處理成功，0=處理失敗）
 * @param rtnMsg      業務回應訊息
 */
export function buildLogisticsAckPayload(
  merchantId: string,
  hashKey: string,
  hashIv: string,
  rtnCode = 1,
  rtnMsg = '',
): Record<string, unknown> {
  return {
    MerchantID: merchantId,
    RqHeader: { Timestamp: Math.floor(Date.now() / 1000) },
    TransCode: 1,
    TransMsg: '',
    Data: aesEncrypt({ RtnCode: rtnCode, RtnMsg: rtnMsg }, hashKey, hashIv),
  }
}

/**
 * 解析全方位物流 AES-JSON 三層結構
 *
 * 1. 驗證 TransCode === 1（外層傳輸碼）
 * 2. 解密 Data 取得業務資料
 * 3. 回傳解密後物件（含 RtnCode, LogisticsID, LogisticsStatus 等）
 *
 * @throws Error 若 TransCode !== 1 或解密失敗
 */
export function parseLogisticsPayload(
  payload: Record<string, unknown>,
  hashKey: string,
  hashIv: string,
): Record<string, unknown> {
  const transCode = Number(payload['TransCode'])
  if (transCode !== 1) {
    throw new Error(`全方位物流 TransCode 驗證失敗: ${transCode}（外層傳輸失敗）`)
  }
  const data = String(payload['Data'] ?? '')
  if (!data) {
    throw new Error('全方位物流 payload 缺少 Data 欄位')
  }
  return aesDecrypt(data, hashKey, hashIv)
}

/**
 * 組裝業務層成功的物流狀態通知資料（RtnCode=1）
 *
 * @param logisticsId   物流訂單號（ECPay LogisticsID）
 * @param status        貨態碼（LOGISTICS_STATUS 中的值）
 * @param subType       物流子類型（LOGISTICS_SUB_TYPE 中的值）
 * @param extras        額外覆寫欄位
 */
export function buildLogisticsStatusData(
  logisticsId: string,
  status: string,
  subType: string,
  extras: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    RtnCode: 1,
    RtnMsg: 'OK',
    LogisticsID: logisticsId,
    LogisticsStatus: status,
    LogisticsSubType: subType,
    MerchantID: LOGISTICS_B2C_TEST.merchantId,
    ...extras,
  }
}

/**
 * 選店 callback（ClientReplyURL）模擬 payload
 * 消費者在物流選擇頁面選好門市後，ECPay 以 Form POST 回傳 ResultData（AES 加密）
 *
 * @param tempLogisticsId  暫存物流 ID
 * @param hashKey          HashKey
 * @param hashIv           HashIV
 * @param storeData        門市資訊（覆寫預設）
 */
export function buildStoreSelectionCallbackData(
  tempLogisticsId: string,
  hashKey: string,
  hashIv: string,
  storeData: Record<string, unknown> = {},
): string {
  const defaultStore = {
    TempLogisticsID: tempLogisticsId,
    LogisticsType: 'CVS',
    LogisticsSubType: 'FAMI',
    CVSStoreID: 'FAMI001',
    CVSStoreName: '全家測試門市',
    CVSAddress: '台北市測試路1號',
    ReceiverName: '[E2E]收件人',
    ReceiverCellPhone: '0912345678',
    ReceiverZipCode: '106',
    ...storeData,
  }
  return aesEncrypt(defaultStore, hashKey, hashIv)
}

// ─── AES Round-Trip 驗證（自我測試用）──────────────────────────────────────

/**
 * AES round-trip 驗證：encrypt → decrypt 還原一致
 * 獨立腳本呼叫用（`node -e "..."` 或測試 helper）
 */
export function verifyAesRoundTrip(
  testData: Record<string, unknown>,
  hashKey: string,
  hashIv: string,
): { success: boolean; original: string; restored: string } {
  const encrypted = aesEncrypt(testData, hashKey, hashIv)
  const decrypted = aesDecrypt(encrypted, hashKey, hashIv)
  const original = JSON.stringify(testData)
  const restored = JSON.stringify(decrypted)
  return {
    success: original === restored,
    original,
    restored,
  }
}
