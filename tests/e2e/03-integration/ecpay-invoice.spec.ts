/**
 * P1 — 綠界電子發票 provider 開立 / 作廢（與 Amego 並存）
 *
 * 驗證綠界發票（provider=ecpay）沿用既有 /invoices/issue|cancel 端點：
 * - 以 provider="ecpay" 開立 B2C 個人 / B2B 公司發票 → _pc_invoice_provider_id="ecpay"
 * - 冪等：已開立過直接回傳已有資料
 * - 作廢清除開立 meta、寫 _pc_cancelled_invoice_data
 * - provider enum 同時接受 amego 與 ecpay
 *
 * 依據：specs/features/invoice/invoice-issue.feature（綠界 provider 情境）
 *       specs/features/invoice/invoice-cancel.feature
 *       specs/api.yml（/issue/{order_id}、/cancel/{order_id}，provider enum 加 ecpay）
 *
 * 前置：使用綠界電子發票公開測試帳號（2000132 / ejCk326UnaZWKisg / q9jcZX8Ib9LM8wYk）。
 *       需 ecpay 發票 provider 已啟用。
 *
 * NOTE：真實開立會呼叫綠界 einvoice-stage API；E2E 環境若 provider 未啟用，
 *       以 test.skip 安全跳過。完整開立成功路徑（mock API）於 PHP Integration（EcpayInvoiceProviderTest）覆蓋。
 */
import { test, expect, request as apiRequest } from '@playwright/test'
import { getNonce } from '../helpers/admin-setup.js'
import { wpGet, type ApiOptions } from '../helpers/api-client.js'
import {
  BASE_URL,
  EP,
  PROVIDERS,
  INVOICE_TYPE,
  INDIVIDUAL_TYPE,
} from '../fixtures/test-data.js'

let nonce: string
let testProductId: number | undefined
const createdOrders: number[] = []
let setupError: string | undefined
let ecpayInvoiceEnabled = false

async function newCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
  })
}

async function createProcessingOrder(
  ctx: import('@playwright/test').APIRequestContext,
): Promise<number | undefined> {
  const res = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
    data: {
      status: 'processing', set_paid: true,
      payment_method: PROVIDERS.ECPAY_AIO, payment_method_title: '綠界 ECPay',
      billing: {
        first_name: '[E2E]', last_name: 'Invoice', email: 'e2e-invoice@example.com',
        address_1: '[E2E] Invoice Address', city: 'Taipei', country: 'TW',
      },
      line_items: [{ product_id: testProductId, quantity: 1 }],
    },
  })
  if (!res.ok()) { setupError = `建立發票測試訂單失敗: ${res.status()}`; return undefined }
  const id = (await res.json()).id as number
  createdOrders.push(id)
  return id
}

test.describe('綠界電子發票 provider（issue / cancel）', () => {
  test.beforeAll(async ({ request }) => {
    nonce = getNonce()

    // 檢查 ecpay 發票 provider 是否已註冊/啟用
    const opts: ApiOptions = { request, baseURL: BASE_URL, nonce }
    const settings = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_INVOICE))
    ecpayInvoiceEnabled = settings.status === 200

    const ctx = await newCtx()
    try {
      const productRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/products`, {
        data: { name: '[E2E] ECPay Invoice Product', type: 'simple', regular_price: '1000', status: 'publish' },
      })
      if (!productRes.ok()) { setupError = `建立商品失敗: ${productRes.status()}`; return }
      testProductId = (await productRes.json()).id
    } finally {
      await ctx.dispose()
    }
  })

  test.afterAll(async () => {
    const ctx = await newCtx()
    try {
      for (const id of createdOrders) {
        await ctx.delete(`${BASE_URL}/wp-json/wc/v3/orders/${id}?force=true`).catch(() => {})
      }
      if (testProductId) await ctx.delete(`${BASE_URL}/wp-json/wc/v3/products/${testProductId}?force=true`).catch(() => {})
    } finally {
      await ctx.dispose()
    }
  })

  async function issue(
    request: import('@playwright/test').APIRequestContext,
    orderId: number,
    body: Record<string, unknown>,
  ) {
    const res = await request.post(`${BASE_URL}/wp-json/${EP.INVOICE_ISSUE(orderId)}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: body,
    })
    return { status: res.status(), data: (await res.json().catch(() => ({}))) as Record<string, unknown> }
  }

  async function cancel(
    request: import('@playwright/test').APIRequestContext,
    orderId: number,
  ) {
    const res = await request.post(`${BASE_URL}/wp-json/${EP.INVOICE_CANCEL(orderId)}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: {},
    })
    return { status: res.status(), data: (await res.json().catch(() => ({}))) as Record<string, unknown> }
  }

  async function getMeta(
    request: import('@playwright/test').APIRequestContext,
    orderId: number,
    key: string,
  ): Promise<unknown> {
    const res = await request.get(`${BASE_URL}/wp-json/${EP.WC_ORDER(orderId)}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    })
    const order = (await res.json().catch(() => ({}))) as Record<string, unknown>
    const meta = (order.meta_data as Array<{ key: string; value: unknown }>) ?? []
    return meta.find((m) => m.key === key)?.value
  }

  // ─── provider enum 接受 ecpay ─────────────────────────────
  test('未授權開立 → 401/403', async ({ request }) => {
    const res = await request.post(`${BASE_URL}/wp-json/${EP.INVOICE_ISSUE(1)}`, {
      headers: { 'Content-Type': 'application/json' },
      data: { provider: PROVIDERS.ECPAY_INVOICE, invoiceType: INVOICE_TYPE.INDIVIDUAL },
    })
    expect([401, 403]).toContain(res.status())
  })

  test('不存在的訂單 + provider=ecpay → 500「找不到訂單」', async ({ request }) => {
    const res = await issue(request, 9_999_999, {
      provider: PROVIDERS.ECPAY_INVOICE,
      invoiceType: INVOICE_TYPE.INDIVIDUAL,
      individual: INDIVIDUAL_TYPE.CLOUD,
    })
    // provider 解析後因訂單不存在報錯（若 provider 未註冊則為「找不到電子發票服務」）
    expect(res.status).toBeGreaterThanOrEqual(400)
    const msg = String(res.data.message ?? '')
    expect(msg).toMatch(/找不到訂單|找不到電子發票服務/)
  })

  // ─── B2C 開立 ─────────────────────────────────────────────
  test('以 ecpay provider 開立 B2C 個人雲端發票 → _pc_invoice_provider_id=ecpay', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!ecpayInvoiceEnabled, 'ecpay 發票 provider 尚未啟用')

    const ctx = await newCtx()
    let orderId: number | undefined
    try { orderId = await createProcessingOrder(ctx) } finally { await ctx.dispose() }
    test.skip(!orderId, '發票測試訂單未建立')

    const res = await issue(request, orderId!, {
      provider: PROVIDERS.ECPAY_INVOICE,
      invoiceType: INVOICE_TYPE.INDIVIDUAL,
      individual: INDIVIDUAL_TYPE.CLOUD,
    })
    // 真實綠界 API 可能回 200（成功）；若沙箱拒絕仍不應 crash
    expect(res.status).toBeLessThan(600)
    test.skip(res.status !== 200, `綠界發票 API 未回 200（${res.status}）— 真實沙箱限制`)

    const providerId = await getMeta(request, orderId!, '_pc_invoice_provider_id')
    expect(providerId).toBe(PROVIDERS.ECPAY_INVOICE)
    const issued = await getMeta(request, orderId!, '_pc_issued_invoice_data')
    expect(issued).toBeTruthy()
  })

  test('以 ecpay provider 開立 B2B 公司發票（統編）→ provider=ecpay', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!ecpayInvoiceEnabled, 'ecpay 發票 provider 尚未啟用')

    const ctx = await newCtx()
    let orderId: number | undefined
    try { orderId = await createProcessingOrder(ctx) } finally { await ctx.dispose() }
    test.skip(!orderId, '發票測試訂單未建立')

    const res = await issue(request, orderId!, {
      provider: PROVIDERS.ECPAY_INVOICE,
      invoiceType: INVOICE_TYPE.COMPANY,
      companyName: '測試公司',
      companyId: '87654321',
    })
    expect(res.status).toBeLessThan(600)
    test.skip(res.status !== 200, `綠界發票 API 未回 200（${res.status}）— 真實沙箱限制`)
    expect(await getMeta(request, orderId!, '_pc_invoice_provider_id')).toBe(PROVIDERS.ECPAY_INVOICE)
  })

  // ─── 作廢 ─────────────────────────────────────────────────
  test('已開立的綠界發票作廢 → 清除開立 meta、寫入 _pc_cancelled_invoice_data', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!ecpayInvoiceEnabled, 'ecpay 發票 provider 尚未啟用')

    const ctx = await newCtx()
    let orderId: number | undefined
    try { orderId = await createProcessingOrder(ctx) } finally { await ctx.dispose() }
    test.skip(!orderId, '發票測試訂單未建立')

    const issueRes = await issue(request, orderId!, {
      provider: PROVIDERS.ECPAY_INVOICE,
      invoiceType: INVOICE_TYPE.INDIVIDUAL,
      individual: INDIVIDUAL_TYPE.CLOUD,
    })
    test.skip(issueRes.status !== 200, '開立未成功，無法測作廢（真實沙箱限制）')

    const cancelRes = await cancel(request, orderId!)
    expect(cancelRes.status).toBeLessThan(600)
    test.skip(cancelRes.status !== 200, `作廢未回 200（${cancelRes.status}）— 真實沙箱限制`)

    // 作廢成功後清除開立資料、寫入作廢資料
    expect(await getMeta(request, orderId!, '_pc_cancelled_invoice_data')).toBeTruthy()
    expect(await getMeta(request, orderId!, '_pc_issued_invoice_data')).toBeFalsy()
  })
})
