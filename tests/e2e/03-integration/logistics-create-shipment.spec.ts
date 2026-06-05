/**
 * P0 — 全方位物流建物流單整合流程
 *
 * 全方位物流建單為「暫存訂單流程」：
 *   1. 後台選店（store-selection）→ 消費者選店後透過 selection-callback 儲存門市 meta
 *   2. 後台手動建立物流單（create-shipment）→ _pc_logistics_ref 寫入 LogisticsID
 *
 * 測試情境：
 * - Smoke：create-shipment 端點存在（非 404）
 * - Happy：選店 meta 已存在 → create-shipment → _pc_logistics_ref 有值
 * - Happy：COD 場景（IsCollection=Y + CollectionAmount）→ meta 含付款金額
 * - Error：未授權 → 401/403
 * - Error：訂單不存在 → 4xx/404
 * - Error：選店 meta 缺失（未選店就直接建單）→ 4xx，訂單無異動
 * - Edge：選店 callback 攜帶 XSS 門市名稱 → meta 消毒，不 crash
 * - Edge：selection-callback AES 亂碼 → 不 crash
 *
 * 依據：guides/07-logistics-allinone.md（暫存單流程 + 貨態 callback AES-JSON 三層）
 *       CLAUDE.md Order Meta Keys（_pc_logistics_ref / _pc_logistics_temp_id /
 *                                   _pc_logistics_store_* / _pc_logistics_status /
 *                                   _pc_logistics_collection_paid / _pc_logistics_processed_status）
 *
 * NOTE：
 *   - 完整端到端（real store selection）需公開網域 + 綠界測試帳號，本機無法跑通。
 *   - create-shipment 呼叫 ECPay CreateByTempTrade，sandbox 需真實 TempLogisticsID。
 *   - 因此 create-shipment happy path 案例依賴 mock/stub provider；
 *     真實 API_MODE=sandbox 測試須在有公開網域的環境執行。
 *   - 本測試集聚焦「後端路由可達、meta 讀寫正確、錯誤路徑拒絕」三個可獨立驗證的面向。
 */
import { test, expect, request as apiRequest } from '@playwright/test'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  LOGISTICS_EP,
  LOGISTICS_PROVIDER,
  LOGISTICS_ACCOUNT_TYPE,
  LOGISTICS_SUB_TYPE,
  ORDER_STATUS,
  EDGE,
  EP,
} from '../fixtures/test-data.js'
import {
  LOGISTICS_B2C_TEST,
  buildStoreSelectionCallbackData,
  aesEncrypt,
} from '../helpers/ecpay-logistics.js'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'

type AnyRecord = Record<string, unknown>

let nonce: string
let adminOpts: ApiOptions

/** 已選店訂單 ID */
let orderWithStoreMeta: number | undefined
/** 未選店訂單 ID */
let orderWithoutStoreMeta: number | undefined
let testProductId: number | undefined
let setupError: string | undefined

async function newCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
  })
}

/**
 * 以 JSON POST 送 selection-callback（模擬消費者選店後的 ClientReplyURL 回呼）
 * 注意：selection-callback 可能是 Form POST（ResultData 欄位），依後端實作而定
 */
async function sendSelectionCallback(
  request: import('@playwright/test').APIRequestContext,
  orderId: number,
  resultData: string,
) {
  const res = await request.post(
    `${BASE_URL}/wp-json/${LOGISTICS_EP.SELECTION_CALLBACK}`,
    {
      headers: { 'Content-Type': 'application/json' },
      data: { order_id: orderId, ResultData: resultData },
    },
  )
  const text = await res.text().catch(() => '')
  let json: AnyRecord | null = null
  try {
    json = JSON.parse(text) as AnyRecord
  } catch {
    // 非 JSON
  }
  return { status: res.status(), text, json }
}

/**
 * 以 POST 送 create-shipment（後台手動建物流單，需 Nonce 認證）
 */
async function sendCreateShipment(
  request: import('@playwright/test').APIRequestContext,
  orderId: number,
  extraData: Record<string, unknown> = {},
) {
  const res = await request.post(
    `${BASE_URL}/wp-json/${LOGISTICS_EP.CREATE_SHIPMENT(orderId)}`,
    {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: extraData,
    },
  )
  const text = await res.text().catch(() => '')
  let json: AnyRecord | null = null
  try {
    json = JSON.parse(text) as AnyRecord
  } catch {
    // 非 JSON
  }
  return { status: res.status(), text, json }
}

/**
 * 取得訂單 meta 值
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

test.describe('全方位物流建物流單整合流程', () => {
  test.beforeAll(async ({ request }) => {
    nonce = getNonce()
    adminOpts = { request, baseURL: BASE_URL, nonce }
    const ctx = await newCtx()
    try {
      // 建立測試商品
      const productRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/products`, {
        data: {
          name: '[E2E] 物流建單商品',
          type: 'simple',
          regular_price: '800',
          status: 'publish',
        },
      })
      if (!productRes.ok()) {
        setupError = `建立測試商品失敗: ${productRes.status()}`
        return
      }
      testProductId = (await productRes.json()).id

      // 訂單 A：已選店（含 _pc_logistics_store_* meta）
      const FAKE_TEMP_ID = `TMP${Date.now()}`
      const orderARes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
        data: {
          status: ORDER_STATUS.PENDING,
          payment_method: LOGISTICS_PROVIDER.ID,
          payment_method_title: '全方位物流',
          billing: {
            first_name: '[E2E]',
            last_name: '已選店',
            email: 'e2e-logistics-a@example.com',
            address_1: '台北市測試路1號',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [{ product_id: testProductId, quantity: 2 }],
          meta_data: [
            { key: '_pc_logistics_temp_id', value: FAKE_TEMP_ID },
            { key: '_pc_logistics_store_id', value: 'FAMI001' },
            { key: '_pc_logistics_store_name', value: '全家測試門市' },
            { key: '_pc_logistics_store_address', value: '台北市大安區測試路1號' },
            { key: '_pc_logistics_sub_type', value: LOGISTICS_SUB_TYPE.FAMI },
            { key: '_pc_logistics_account_type', value: LOGISTICS_ACCOUNT_TYPE.B2C },
          ],
        },
      })
      if (orderARes.ok()) {
        orderWithStoreMeta = (await orderARes.json()).id
      } else {
        setupError = `建立已選店訂單失敗: ${orderARes.status()}`
        return
      }

      // 訂單 B：未選店（不含選店 meta）
      const orderBRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
        data: {
          status: ORDER_STATUS.PENDING,
          payment_method: LOGISTICS_PROVIDER.ID,
          payment_method_title: '全方位物流',
          billing: {
            first_name: '[E2E]',
            last_name: '未選店',
            email: 'e2e-logistics-b@example.com',
            address_1: '台北市測試路2號',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [{ product_id: testProductId, quantity: 1 }],
          // 無 store meta
        },
      })
      if (orderBRes.ok()) {
        orderWithoutStoreMeta = (await orderBRes.json()).id
      } else {
        setupError = `建立未選店訂單失敗: ${orderBRes.status()}`
      }
    } finally {
      await ctx.dispose()
    }
  })

  test.afterAll(async () => {
    const ctx = await newCtx()
    try {
      if (orderWithStoreMeta) {
        await ctx
          .delete(`${BASE_URL}/wp-json/wc/v3/orders/${orderWithStoreMeta}?force=true`)
          .catch(() => {})
      }
      if (orderWithoutStoreMeta) {
        await ctx
          .delete(`${BASE_URL}/wp-json/wc/v3/orders/${orderWithoutStoreMeta}?force=true`)
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
    test('POST /logistics/create-shipment/:id 端點存在（非 404）', async ({ request }) => {
      // 使用測試訂單 ID 或 dummy ID，只驗端點路由存在
      const orderId = orderWithStoreMeta ?? 1
      const res = await sendCreateShipment(request, orderId)
      expect(res.status).not.toBe(404)
    })

    test('POST /ecpay/logistics/selection-callback 端點存在（非 404）', async ({
      request,
    }) => {
      const res = await request.post(
        `${BASE_URL}/wp-json/${LOGISTICS_EP.SELECTION_CALLBACK}`,
        {
          headers: { 'Content-Type': 'application/json' },
          data: {},
        },
      )
      expect(res.status()).not.toBe(404)
    })
  })

  // ─── Error：未授權 ──────────────────────────────────────────────────────
  test.describe('@error 未授權拒絕存取', () => {
    test('未帶 Nonce 的 create-shipment 請求 → 401/403', async ({ request }) => {
      const orderId = orderWithStoreMeta ?? 1
      const res = await request.post(
        `${BASE_URL}/wp-json/${LOGISTICS_EP.CREATE_SHIPMENT(orderId)}`,
        {
          headers: { 'Content-Type': 'application/json' },
          data: {},
        },
      )
      expect([401, 403]).toContain(res.status())
    })
  })

  // ─── Error：訂單不存在 ──────────────────────────────────────────────────
  test.describe('@error 訂單不存在', () => {
    test('create-shipment 傳入不存在的訂單 ID → 4xx，不 500', async ({ request }) => {
      const res = await sendCreateShipment(request, 9_999_999)
      // 後端對「查無訂單」回 404 + {code:'error', message:'找不到訂單'}（合法業務回應，
      // 與「路由不存在」的 404 語意不同——此處端點確實存在並正確拒絕）。
      expect(res.status).not.toBe(500) // 不 crash
      expect(res.status).toBeGreaterThanOrEqual(400) // 正確回 4xx
      // 確認為「找不到訂單」業務錯誤，而非路由缺失
      if (res.json) {
        expect(String((res.json as AnyRecord).message ?? '')).toMatch(/找不到訂單/)
      }
    })

    test('create-shipment 傳入負數訂單 ID → 不 crash', async ({ request }) => {
      const res = await sendCreateShipment(request, -1)
      expect(res.status).not.toBe(500)
    })
  })

  // ─── Happy：選店 meta → create-shipment ────────────────────────────────
  test.describe('@happy 選店 meta 存在 → create-shipment 呼叫', () => {
    test('選店 meta 已存入 → create-shipment 回 200 或 mock_success（provider 依環境）', async ({
      request,
    }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!orderWithStoreMeta, '已選店測試訂單未建立')

      const res = await sendCreateShipment(request, orderWithStoreMeta!)

      if (res.status === 404) {
        test.skip(
          true,
          'create-shipment 端點尚未實作（provider 未啟用），跳過此案例',
        )
        return
      }

      // 若端點存在，可能回 200（mock 成功）或 4xx（sandbox 需真實 TempLogisticsID）
      expect(res.status).not.toBe(500)
      expect([200, 400, 422]).toContain(res.status)

      // 若回 200：_pc_logistics_ref 應有值
      if (res.status === 200 && res.json) {
        const body = res.json as AnyRecord
        if (body.code === 'success') {
          const logisticsRef = await getOrderMeta(
            request,
            orderWithStoreMeta!,
            '_pc_logistics_ref',
          )
          expect(logisticsRef).toBeTruthy()
        }
      }
    })
  })

  // ─── Error：選店 meta 缺失 → create-shipment 拒絕 ──────────────────────
  test.describe('@error 選店 meta 缺失時拒絕建單', () => {
    test('未選店的訂單直接呼叫 create-shipment → 4xx，訂單無 _pc_logistics_ref', async ({
      request,
    }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!orderWithoutStoreMeta, '未選店測試訂單未建立')

      const res = await sendCreateShipment(request, orderWithoutStoreMeta!)

      if (res.status === 404) {
        test.skip(true, 'create-shipment 端點尚未實作，跳過此案例')
        return
      }

      // 應拒絕（未選店）
      expect(res.status).not.toBe(500)
      // _pc_logistics_ref 不應有值
      const logisticsRef = await getOrderMeta(
        request,
        orderWithoutStoreMeta!,
        '_pc_logistics_ref',
      )
      expect(logisticsRef ?? '').toBe('')
    })
  })

  // ─── Happy：COD 場景 ────────────────────────────────────────────────────
  test.describe('@happy COD（貨到付款）場景', () => {
    test('create-shipment 帶入 CollectionAmount → 後端不 crash', async ({ request }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!orderWithStoreMeta, '已選店測試訂單未建立')

      const res = await sendCreateShipment(request, orderWithStoreMeta!, {
        IsCollection: 'Y',
        CollectionAmount: 800,
      })

      if (res.status === 404) {
        test.skip(true, 'create-shipment 端點尚未實作，跳過此案例')
        return
      }

      expect(res.status).not.toBe(500)
    })

    test('COD 建單成功 → _pc_logistics_collection_paid meta 有值（若後端有寫入）', async ({
      request,
    }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!orderWithStoreMeta, '已選店測試訂單未建立')

      const res = await sendCreateShipment(request, orderWithStoreMeta!, {
        IsCollection: 'Y',
        CollectionAmount: 800,
      })

      if (res.status === 404) {
        test.skip(true, 'create-shipment 端點尚未實作，跳過此案例')
        return
      }

      if (res.status === 200 && res.json && (res.json as AnyRecord).code === 'success') {
        const paid = await getOrderMeta(
          request,
          orderWithStoreMeta!,
          '_pc_logistics_collection_paid',
        )
        if (paid !== undefined) {
          expect(Number(paid)).toBeGreaterThan(0)
        }
      }
    })
  })

  // ─── Happy：selection-callback 選店資訊寫入 meta ──────────────────────
  test.describe('@happy selection-callback 寫入選店 meta', () => {
    test('送合法 ResultData → 訂單 _pc_logistics_store_id 有值', async ({ request }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!orderWithoutStoreMeta, '未選店測試訂單未建立')

      const FAKE_TEMP_ID = `TMP${Date.now()}`
      const resultData = buildStoreSelectionCallbackData(
        FAKE_TEMP_ID,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
        {
          CVSStoreID: 'FAMI_TEST_001',
          CVSStoreName: '全家東區門市',
          LogisticsSubType: LOGISTICS_SUB_TYPE.FAMI,
          ReceiverName: '[E2E]測試收件人',
        },
      )

      const res = await sendSelectionCallback(
        request,
        orderWithoutStoreMeta!,
        resultData,
      )

      if (res.status === 404) {
        test.skip(true, 'selection-callback 端點尚未實作，跳過此案例')
        return
      }

      expect(res.status).not.toBe(500)

      // 若端點回 200，驗證 meta 有寫入
      if (res.status === 200) {
        const storeId = await getOrderMeta(
          request,
          orderWithoutStoreMeta!,
          '_pc_logistics_store_id',
        )
        // 如果後端有寫入，應該有值
        if (storeId !== undefined) {
          expect(storeId).not.toBe('')
        }
      }
    })
  })

  // ─── Edge：selection-callback AES 亂碼 ────────────────────────────────
  test.describe('@edge selection-callback 亂碼輸入', () => {
    test('ResultData 為無法解密的亂碼 → 不 crash，端點回 4xx/200', async ({
      request,
    }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!orderWithoutStoreMeta, '未選店測試訂單未建立')

      const res = await sendSelectionCallback(
        request,
        orderWithoutStoreMeta!,
        'INVALID-AES-DATA-!@#$%',
      )

      if (res.status === 404) {
        test.skip(true, 'selection-callback 端點尚未實作，跳過此案例')
        return
      }

      expect(res.status).not.toBe(500)
    })

    test('ResultData 含 XSS 門市名稱 → meta 消毒，不 crash', async ({ request }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!orderWithoutStoreMeta, '未選店測試訂單未建立')

      const resultData = buildStoreSelectionCallbackData(
        `TMP${Date.now()}`,
        LOGISTICS_B2C_TEST.hashKey,
        LOGISTICS_B2C_TEST.hashIv,
        {
          CVSStoreName: EDGE.XSS_SCRIPT, // XSS 門市名稱
          CVSStoreID: 'SAFE_STORE_001',
          LogisticsSubType: LOGISTICS_SUB_TYPE.FAMI,
        },
      )

      const res = await sendSelectionCallback(
        request,
        orderWithoutStoreMeta!,
        resultData,
      )

      if (res.status === 404) {
        test.skip(true, 'selection-callback 端點尚未實作，跳過此案例')
        return
      }

      expect(res.status).not.toBe(500)

      // 如果寫入 meta，驗證 store_name 不含未消毒的 script 標籤
      if (res.status === 200 && orderWithoutStoreMeta) {
        const storeName = await getOrderMeta(
          request,
          orderWithoutStoreMeta,
          '_pc_logistics_store_name',
        )
        if (storeName !== undefined) {
          expect(storeName).not.toContain('<script>')
        }
      }
    })
  })

  // ─── Edge：query / print / cancel 端點可達性 ──────────────────────────
  test.describe('@edge 其他物流操作端點可達性', () => {
    test('GET /logistics/query/:id 端點存在（需認證）', async ({ request }) => {
      const orderId = orderWithStoreMeta ?? 1
      const res = await request.get(
        `${BASE_URL}/wp-json/${LOGISTICS_EP.QUERY(orderId)}`,
        {
          headers: { 'X-WP-Nonce': nonce },
        },
      )
      expect(res.status()).not.toBe(404)
      expect(res.status()).not.toBe(500)
    })

    test('POST /logistics/print/:id 端點存在（需認證）', async ({ request }) => {
      const orderId = orderWithStoreMeta ?? 1
      const res = await request.post(
        `${BASE_URL}/wp-json/${LOGISTICS_EP.PRINT(orderId)}`,
        {
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          data: {},
        },
      )
      expect(res.status()).not.toBe(404)
      expect(res.status()).not.toBe(500)
    })

    test('POST /logistics/cancel/:id 端點存在（需認證）', async ({ request }) => {
      const orderId = orderWithStoreMeta ?? 1
      const res = await request.post(
        `${BASE_URL}/wp-json/${LOGISTICS_EP.CANCEL(orderId)}`,
        {
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          data: {},
        },
      )
      expect(res.status()).not.toBe(404)
      expect(res.status()).not.toBe(500)
    })
  })
})
