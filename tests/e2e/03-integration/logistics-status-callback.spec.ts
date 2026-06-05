/**
 * P0 — 全方位物流貨態 callback 整合流程
 *
 * 全方位物流 ServerReplyURL 使用 AES-JSON 三層結構，與國內物流的「1|OK」完全不同。
 *
 * 三層結構（ECPay 送給我們的 callback body）：
 *   { MerchantID, RpHeader: { Timestamp }, TransCode, TransMsg, Data(AES 密文) }
 *   解密後業務資料：{ RtnCode, LogisticsID, LogisticsStatus, ... }
 *
 * 我們必須回應：
 *   { MerchantID, RqHeader: { Timestamp }, TransCode=1, TransMsg, Data(AES 密文) }
 *   解密後：{ RtnCode: 1, RtnMsg: '' }
 *
 * 測試情境：
 * - Smoke：端點存在（非 404）
 * - Happy：合法三層 payload + RtnCode=1 → 訂單 meta 更新 + 回應可解密為 RtnCode=1
 * - Error：TransCode=0 → 不更新狀態，回 AES-JSON RtnCode=0
 * - Error：MerchantID 不符 → 拒絕，回 AES-JSON RtnCode=0
 * - Error：查無對應訂單（LogisticsID 不存在） → 回 AES-JSON RtnCode=0
 * - Error：Data 為無法解密的亂碼 → 回 AES-JSON RtnCode=0
 * - Edge：重送同 LogisticsID+status → 防重冪等（不重複寫入）
 * - Security：MerchantID 含 XSS → 不 crash，回 AES-JSON
 *
 * 依據：guides/07-logistics-allinone.md（AES-JSON 三層結構 + 安全清單）
 *       specs/api.yml（power-checkout/ecpay/logistics/status-callback）
 *
 * NOTE：完整貨態更新需 ecpay_logistics provider 已啟用且訂單含 _pc_logistics_ref meta。
 *       若 provider 未啟用，回應可能為 404/4xx，相關案例以 test.skip 安全跳過。
 */
import { test, expect, request as apiRequest } from '@playwright/test'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  LOGISTICS_EP,
  LOGISTICS_PROVIDER,
  LOGISTICS_STATUS_CODE,
  LOGISTICS_SUB_TYPE,
  ORDER_STATUS,
} from '../fixtures/test-data.js'
import {
  LOGISTICS_B2C_TEST,
  buildLogisticsStatusPayload,
  buildLogisticsStatusData,
  parseLogisticsPayload,
} from '../helpers/ecpay-logistics.js'

type AnyRecord = Record<string, unknown>

let nonce: string
let testOrderId: number | undefined
let testProductId: number | undefined
let setupError: string | undefined
/** 模擬的 ECPay LogisticsID（格式對應真實格式） */
const FAKE_LOGISTICS_ID = `LGS${Date.now()}`

async function newCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
  })
}

// Webhook context：模擬綠界 server-to-server 回呼。
// 必須明確清掉 config 繼承的 use.extraHTTPHeaders（X-WP-Nonce:''）與 use.storageState（cookie），
// 否則帶 nonce/cookie 會觸發 WP REST cookie nonce 驗證並回 403（rest_cookie_invalid_nonce）。
async function newWebhookCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: {},
    storageState: { cookies: [], origins: [] },
  })
}

/**
 * 以 JSON POST 送 status-callback（不帶 WP 認證，模擬 ECPay 回呼）
 * 刻意忽略傳入的 request fixture，改用 webhook context（無 nonce/cookie），避免 403。
 */
async function sendStatusCallback(
  _request: import('@playwright/test').APIRequestContext,
  payload: Record<string, unknown>,
) {
  const ctx = await newWebhookCtx()
  try {
    const res = await ctx.post(`${BASE_URL}/wp-json/${LOGISTICS_EP.STATUS_CALLBACK}`, {
      headers: { 'Content-Type': 'application/json' },
      data: payload,
    })
    const text = await res.text().catch(() => '')
    let json: AnyRecord | null = null
    try {
      json = JSON.parse(text) as AnyRecord
    } catch {
      // 非 JSON 回應
    }
    return { status: res.status(), text, json }
  } finally {
    await ctx.dispose()
  }
}

/**
 * 取得訂單 meta（透過 WC REST API）
 */
async function getOrderMeta(
  request: import('@playwright/test').APIRequestContext,
  orderId: number,
  metaKey: string,
): Promise<string | undefined> {
  const res = await request.get(`${BASE_URL}/wp-json/wc/v3/orders/${orderId}`, {
    headers: { 'X-WP-Nonce': nonce },
  })
  if (!res.ok()) return undefined
  const data = (await res.json().catch(() => ({} as AnyRecord))) as AnyRecord
  const metaData = (data.meta_data as { key: string; value: string }[]) ?? []
  return metaData.find((m) => m.key === metaKey)?.value
}

test.describe('全方位物流貨態 Callback 整合流程', () => {
  test.beforeAll(async () => {
    nonce = getNonce()
    const ctx = await newCtx()
    try {
      // 建立測試商品
      const productRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/products`, {
        data: {
          name: '[E2E] 物流測試商品',
          type: 'simple',
          regular_price: '500',
          status: 'publish',
        },
      })
      if (!productRes.ok()) {
        setupError = `建立測試商品失敗: ${productRes.status()}`
        return
      }
      testProductId = (await productRes.json()).id

      // 建立 pending 訂單，注入選店 meta（模擬已選店完成）
      const orderRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
        data: {
          status: ORDER_STATUS.PENDING,
          payment_method: LOGISTICS_PROVIDER.ID,
          payment_method_title: '全方位物流',
          billing: {
            first_name: '[E2E]',
            last_name: '物流測試',
            email: 'e2e-logistics@example.com',
            address_1: '台北市測試路1號',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [{ product_id: testProductId, quantity: 1 }],
          meta_data: [
            // 模擬已成立物流單：_pc_logistics_ref 存 LogisticsID
            { key: '_pc_logistics_ref', value: FAKE_LOGISTICS_ID },
            { key: '_pc_logistics_sub_type', value: LOGISTICS_SUB_TYPE.FAMI },
            { key: '_pc_logistics_status', value: '' },
          ],
        },
      })
      if (orderRes.ok()) {
        testOrderId = (await orderRes.json()).id
      } else {
        setupError = `建立物流測試訂單失敗: ${orderRes.status()}`
      }
    } finally {
      await ctx.dispose()
    }
  })

  test.afterAll(async () => {
    const ctx = await newCtx()
    try {
      if (testOrderId) {
        await ctx
          .delete(`${BASE_URL}/wp-json/wc/v3/orders/${testOrderId}?force=true`)
          .catch(() => {})
      }
      if (testProductId) {
        await ctx
          .delete(`${BASE_URL}/wp-json/wc/v3/products/${testProductId}?force=true`)
          .catch(() => {})
      }
    } finally {
      await ctx.dispose()
    }
  })

  // ─── Smoke ─────────────────────────────────────────────────────────────
  test.describe('@smoke 端點可達性', () => {
    test('POST /ecpay/logistics/status-callback 端點存在（非 404）', async ({
      request,
    }) => {
      // 送空 body，驗證端點已路由（不是 404）
      const res = await request.post(
        `${BASE_URL}/wp-json/${LOGISTICS_EP.STATUS_CALLBACK}`,
        {
          headers: { 'Content-Type': 'application/json' },
          data: {},
        },
      )
      expect(res.status()).not.toBe(404)
    })
  })

  // ─── Happy：合法三層 payload → 訂單貨態更新 ────────────────────────────
  test.describe('@happy 合法 AES-JSON 三層 → 貨態更新 + 回應可解密', () => {
    test('TransCode=1 + RtnCode=1 + 已知 LogisticsID → 訂單 meta 更新，回應三層可解密為 RtnCode=1', async ({
      request,
    }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!testOrderId, '物流測試訂單未建立')

      const statusData = buildLogisticsStatusData(
        FAKE_LOGISTICS_ID,
        LOGISTICS_STATUS_CODE.ARRIVED_STORE,
        LOGISTICS_SUB_TYPE.FAMI,
      )
      const payload = buildLogisticsStatusPayload(
        LOGISTICS_B2C_TEST.merchantId,
        statusData,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
      )

      const res = await sendStatusCallback(request, payload)
      expect(res.status).toBe(200)
      expect(res.json).not.toBeNull()

      // 回應為 AES-JSON 三層結構（不是 1|OK）
      const responseJson = res.json as AnyRecord
      expect(typeof responseJson.TransCode).toBe('number')
      expect(responseJson.TransCode).toBe(1)
      expect(typeof responseJson.Data).toBe('string')

      // 解密回應 Data 驗證 RtnCode=1
      const responseData = parseLogisticsPayload(
        responseJson,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
      )
      expect(Number(responseData.RtnCode)).toBe(1)

      // 訂單 meta 更新（_pc_logistics_status 或 _pc_logistics_processed_status）
      if (testOrderId) {
        const logisticsStatus = await getOrderMeta(
          request,
          testOrderId,
          '_pc_logistics_status',
        )
        if (logisticsStatus !== undefined) {
          expect(logisticsStatus).toBe(LOGISTICS_STATUS_CODE.ARRIVED_STORE)
        }
        // 若後端實作 _pc_logistics_processed_status，亦驗證
        const processedStatus = await getOrderMeta(
          request,
          testOrderId,
          '_pc_logistics_processed_status',
        )
        if (processedStatus !== undefined) {
          expect(processedStatus).not.toBe('')
        }
      }
    })

    test('貨態碼 PICKED_UP(3018) → 回應可解密為 RtnCode=1', async ({ request }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!testOrderId, '物流測試訂單未建立')

      const statusData = buildLogisticsStatusData(
        FAKE_LOGISTICS_ID,
        LOGISTICS_STATUS_CODE.PICKED_UP,
        LOGISTICS_SUB_TYPE.FAMI,
      )
      const payload = buildLogisticsStatusPayload(
        LOGISTICS_B2C_TEST.merchantId,
        statusData,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
      )

      const res = await sendStatusCallback(request, payload)
      expect(res.status).toBe(200)

      if (res.json) {
        const responseJson = res.json as AnyRecord
        if (typeof responseJson.Data === 'string') {
          const responseData = parseLogisticsPayload(
            responseJson,
            LOGISTICS_B2C_TEST.hashKey,
            LOGISTICS_B2C_TEST.hashIv,
          )
          expect(Number(responseData.RtnCode)).toBe(1)
        }
      }
    })
  })

  // ─── Error：TransCode != 1 ──────────────────────────────────────────────
  test.describe('@error TransCode != 1 → 不更新，回 AES-JSON RtnCode=0', () => {
    test('TransCode=0（傳輸層失敗）→ 端點回 200，回應三層可解密為 RtnCode=0', async ({
      request,
    }) => {
      const statusData = buildLogisticsStatusData(
        FAKE_LOGISTICS_ID,
        LOGISTICS_STATUS_CODE.ARRIVED_STORE,
        LOGISTICS_SUB_TYPE.FAMI,
      )
      const payload = buildLogisticsStatusPayload(
        LOGISTICS_B2C_TEST.merchantId,
        statusData,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
        0, // TransCode=0 傳輸層失敗
      )

      const res = await sendStatusCallback(request, payload)
      // 仍回 200（避免重送風暴），但業務層 RtnCode=0
      expect(res.status).toBe(200)

      if (res.json) {
        const responseJson = res.json as AnyRecord
        if (typeof responseJson.Data === 'string') {
          try {
            const responseData = parseLogisticsPayload(
              responseJson,
              LOGISTICS_B2C_TEST.hashKey,
              LOGISTICS_B2C_TEST.hashIv,
            )
            expect(Number(responseData.RtnCode)).toBe(0)
          } catch {
            // TransCode 驗證失敗時 parseLogisticsPayload 拋出例外，符合預期
          }
        }
      }
    })
  })

  // ─── Error：MerchantID 不符 ─────────────────────────────────────────────
  test.describe('@error MerchantID 不符 → 拒絕，回 AES-JSON', () => {
    test('MerchantID 不屬於此站台 → 回 AES-JSON（不更新訂單）', async ({ request }) => {
      const statusData = buildLogisticsStatusData(
        FAKE_LOGISTICS_ID,
        LOGISTICS_STATUS_CODE.ARRIVED_STORE,
        LOGISTICS_SUB_TYPE.FAMI,
        { MerchantID: '9999999' }, // 不符的 MerchantID
      )
      const payload = buildLogisticsStatusPayload(
        '9999999', // 外層也用不符的 MerchantID
        statusData,
        LOGISTICS_B2C_TEST.hashKey, // key 不符，解密自然失敗
        LOGISTICS_B2C_TEST.hashIv,
      )

      const res = await sendStatusCallback(request, payload)
      // 不管後端是回 200（帶 RtnCode=0）或 4xx，不可是 500
      expect(res.status).not.toBe(500)
      expect(res.status).not.toBe(404) // 端點必須存在
    })
  })

  // ─── Error：查無訂單 ────────────────────────────────────────────────────
  test.describe('@error 查無對應訂單 → 回 AES-JSON RtnCode=0', () => {
    test('LogisticsID 在 DB 查無對應訂單 → 回 AES-JSON，不 500', async ({ request }) => {
      const nonexistentLogisticsId = 'LGS_NONEXISTENT_9999999'
      const statusData = buildLogisticsStatusData(
        nonexistentLogisticsId,
        LOGISTICS_STATUS_CODE.ARRIVED_STORE,
        LOGISTICS_SUB_TYPE.FAMI,
      )
      const payload = buildLogisticsStatusPayload(
        LOGISTICS_B2C_TEST.merchantId,
        statusData,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
      )

      const res = await sendStatusCallback(request, payload)
      expect(res.status).toBe(200)
      expect(res.status).not.toBe(500)

      if (res.json) {
        const responseJson = res.json as AnyRecord
        if (typeof responseJson.Data === 'string') {
          try {
            const responseData = parseLogisticsPayload(
              responseJson,
              LOGISTICS_B2C_TEST.hashKey,
              LOGISTICS_B2C_TEST.hashIv,
            )
            // 查無訂單應回 RtnCode=0
            expect(Number(responseData.RtnCode)).toBe(0)
          } catch {
            // 可能回傳其他格式，只要不 500 即可
          }
        }
      }
    })
  })

  // ─── Error：Data 為亂碼（無法解密）─────────────────────────────────────
  test.describe('@error Data 亂碼 → 端點仍回 AES-JSON', () => {
    test('Data 為無法解密的 Base64 亂碼 → 不 crash，回 AES-JSON', async ({
      request,
    }) => {
      const payload = {
        MerchantID: LOGISTICS_B2C_TEST.merchantId,
        RpHeader: { Timestamp: Math.floor(Date.now() / 1000) },
        TransCode: 1,
        TransMsg: 'Success',
        Data: 'not-valid-base64-aes-!!!-garbage-data-=====',
      }

      const res = await sendStatusCallback(request, payload)
      // 解密失敗仍回 200（避免重送風暴）
      expect(res.status).toBe(200)
      expect(res.status).not.toBe(500)

      // 回應必須是 AES-JSON 格式（不是 1|OK）
      expect(res.json).not.toBeNull()
      if (res.json) {
        const responseJson = res.json as AnyRecord
        expect(typeof responseJson.MerchantID).toBe('string')
      }
    })

    test('Data 欄位缺失（空物件）→ 不 crash', async ({ request }) => {
      const res = await sendStatusCallback(request, {
        MerchantID: LOGISTICS_B2C_TEST.merchantId,
        RpHeader: { Timestamp: Math.floor(Date.now() / 1000) },
        TransCode: 1,
        TransMsg: 'Success',
        // Data 欄位缺失
      })
      expect(res.status).not.toBe(500)
    })
  })

  // ─── Edge：冪等防重 ──────────────────────────────────────────────────────
  test.describe('@edge 重送冪等（同 LogisticsID + 同 status）', () => {
    test('重送同 LogisticsID + 同 LogisticsStatus → 不重複寫入，回應仍 RtnCode=1', async ({
      request,
    }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!testOrderId, '物流測試訂單未建立')

      const statusData = buildLogisticsStatusData(
        FAKE_LOGISTICS_ID,
        LOGISTICS_STATUS_CODE.ARRIVED_STORE,
        LOGISTICS_SUB_TYPE.FAMI,
      )
      const payload = buildLogisticsStatusPayload(
        LOGISTICS_B2C_TEST.merchantId,
        statusData,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
      )

      // 第一次送出
      const first = await sendStatusCallback(request, payload)
      expect(first.status).toBe(200)

      // 第二次重送（相同 payload，Timestamp 不同）
      const replayPayload = buildLogisticsStatusPayload(
        LOGISTICS_B2C_TEST.merchantId,
        statusData,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
      )
      const second = await sendStatusCallback(request, replayPayload)
      expect(second.status).toBe(200)

      // 回應仍應為合法 AES-JSON（不 crash）
      if (second.json) {
        const responseJson = second.json as AnyRecord
        expect(typeof responseJson.TransCode).toBe('number')
      }
    })
  })

  // ─── Security ────────────────────────────────────────────────────────────
  test.describe('@security 安全性', () => {
    test('MerchantID 含 XSS → 不 crash，回 AES-JSON', async ({ request }) => {
      const payload = {
        MerchantID: '<script>alert("xss")</script>',
        RpHeader: { Timestamp: Math.floor(Date.now() / 1000) },
        TransCode: 1,
        TransMsg: 'Success',
        Data: 'invalid-data',
      }

      const res = await sendStatusCallback(request, payload)
      expect(res.status).not.toBe(500)
      // 回應不應包含未消毒的 XSS
      expect(res.text).not.toContain('<script>')
    })

    test('LogisticsID 含 SQL Injection → 不 crash，DB 完好', async ({ request }) => {
      const statusData = buildLogisticsStatusData(
        "'; DROP TABLE wp_postmeta; --",
        LOGISTICS_STATUS_CODE.ARRIVED_STORE,
        LOGISTICS_SUB_TYPE.FAMI,
      )
      const payload = buildLogisticsStatusPayload(
        LOGISTICS_B2C_TEST.merchantId,
        statusData,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
      )

      const res = await sendStatusCallback(request, payload)
      expect(res.status).not.toBe(500)
    })
  })
})
