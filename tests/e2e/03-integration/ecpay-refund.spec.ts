/**
 * P1 — 綠界 ECPay 退款分流（DoAction 信用卡退款 vs 非信用卡人工）
 *
 * 驗證退款限制（D5）：
 * - 信用卡（AIO / ECPG，payment_type=Credit_CreditCard）→ 支援 API 退款
 * - 非信用卡（ATM/CVS/BARCODE/ApplePay）→ 不支援 API 退款，提示綠界後台人工
 *
 * 依據：specs/features/payment/ecpay-refund.feature
 *       specs/api.yml（/refund）
 *
 * NOTE：真實 DoAction 退款需綠界沙箱已付款交易（無法 E2E 自動產生真實 TradeNo）。
 *       本檔聚焦「退款分流契約」：以 /refund 端點對不同 payment_type 訂單發起退款，
 *       驗證非信用卡被正確拒絕、信用卡進入退款路徑（不誤判付款方式）。
 *       完整 DoAction 成功路徑需真實沙箱交易，於 PHP Integration 測試（EcpayRefundTest）覆蓋。
 */
import { test, expect, request as apiRequest } from '@playwright/test'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, ECPAY_PAYMENT_METHODS } from '../fixtures/test-data.js'

let nonce: string
let testProductId: number | undefined
const createdOrders: number[] = []
let setupError: string | undefined

async function newCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
  })
}

/**
 * 建立一筆「已付款（processing）」綠界訂單，帶 payment_type meta，供退款分流測試。
 * _pc_ecpay_payment_detail.PaymentType 決定退款是否走 API（信用卡）或人工（非信用卡）。
 */
async function createPaidEcpayOrder(
  ctx: import('@playwright/test').APIRequestContext,
  paymentMethod: string,
  paymentType: string,
): Promise<number | undefined> {
  const tradeNo = `EC${Date.now()}${Math.floor(Math.random() * 1000)}`
  const res = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
    data: {
      status: 'processing',
      payment_method: paymentMethod,
      payment_method_title: '綠界 ECPay',
      set_paid: true,
      billing: {
        first_name: '[E2E]', last_name: 'Refund', email: 'e2e-refund@example.com',
        address_1: '[E2E] Refund Address', city: 'Taipei', country: 'TW',
      },
      line_items: [{ product_id: testProductId, quantity: 1 }],
      meta_data: [
        { key: '_pc_ecpay_trade_no', value: tradeNo },
        // 後端依 payment_detail.PaymentType 判斷退款分流
        { key: '_pc_ecpay_payment_detail', value: { PaymentType: paymentType, TradeNo: '2306010000099', RtnCode: '1' } },
      ],
    },
  })
  if (!res.ok()) {
    setupError = `建立退款測試訂單失敗 (${paymentMethod}/${paymentType}): ${res.status()}`
    return undefined
  }
  const id = (await res.json()).id as number
  createdOrders.push(id)
  return id
}

test.describe('綠界 ECPay 退款分流', () => {
  let opts: { request: import('@playwright/test').APIRequestContext }

  test.beforeAll(async () => {
    nonce = getNonce()
    const ctx = await newCtx()
    try {
      const productRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/products`, {
        data: { name: '[E2E] ECPay Refund Product', type: 'simple', regular_price: '1000', status: 'publish' },
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

  test.beforeEach(async ({ request }) => {
    opts = { request }
  })

  async function refund(request: import('@playwright/test').APIRequestContext, orderId: number) {
    const res = await request.post(`${BASE_URL}/wp-json/${EP.REFUND}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: { order_id: orderId },
    })
    return { status: res.status(), data: (await res.json().catch(() => ({}))) as Record<string, unknown> }
  }

  // ─── 非信用卡 → 拒絕 API 退款（提示人工）─────────────────
  test('AIO ATM 訂單退款 → 提示綠界後台人工處理', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    const ctx = await newCtx()
    let orderId: number | undefined
    try {
      orderId = await createPaidEcpayOrder(ctx, PROVIDERS.ECPAY_AIO, 'ATM_TAISHIN')
    } finally {
      await ctx.dispose()
    }
    test.skip(!orderId, 'ATM 退款測試訂單未建立')

    const res = await refund(request, orderId!)
    // 非信用卡不支援 API 退款 → 錯誤（5xx）且訊息含人工提示
    expect(res.status).toBeGreaterThanOrEqual(400)
    const msg = String(res.data.message ?? '')
    // 後端訊息：「此付款方式不支援 API 退款，請至綠界商家後台人工處理」
    expect(msg).toMatch(/人工|不支援|綠界商家後台/)
  })

  // ─── 信用卡 → 進入退款路徑（不被付款方式拒絕）────────────
  test('AIO 信用卡訂單退款 → 進入 DoAction 路徑（非「不支援」錯誤）', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    const ctx = await newCtx()
    let orderId: number | undefined
    try {
      orderId = await createPaidEcpayOrder(ctx, PROVIDERS.ECPAY_AIO, ECPAY_PAYMENT_METHODS.CREDIT + '_CreditCard')
    } finally {
      await ctx.dispose()
    }
    test.skip(!orderId, '信用卡退款測試訂單未建立')

    const res = await refund(request, orderId!)
    const msg = String(res.data.message ?? '')
    // 信用卡訂單不應因「不支援付款方式」被擋；
    // 真實沙箱無對應已付款交易時 DoAction 可能回綠界 API 錯誤（合理），但訊息不應是付款方式不支援。
    expect(msg).not.toMatch(/此付款方式不支援 API 退款/)
  })

  // ─── ECPG 信用卡 → 退款路徑（ecpayment domain）───────────
  test('ECPG 信用卡訂單退款 → 進入退款路徑（不被付款方式拒絕）', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    const ctx = await newCtx()
    let orderId: number | undefined
    try {
      orderId = await createPaidEcpayOrder(ctx, PROVIDERS.ECPAY_ECPG, ECPAY_PAYMENT_METHODS.CREDIT + '_CreditCard')
    } finally {
      await ctx.dispose()
    }
    test.skip(!orderId, 'ECPG 信用卡退款測試訂單未建立')

    const res = await refund(request, orderId!)
    const msg = String(res.data.message ?? '')
    expect(msg).not.toMatch(/此付款方式不支援 API 退款/)
  })

  // ─── 非綠界訂單不由本 gateway 處理 ────────────────────────
  test('非綠界（SLP）訂單退款不走綠界 gateway', async ({ request }) => {
    // 建一筆 SLP 訂單，退款應由 SLP gateway 處理（非綠界錯誤訊息）
    const ctx = await newCtx()
    let orderId: number | undefined
    try {
      const r = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
        data: {
          status: 'processing', payment_method: PROVIDERS.SLP, payment_method_title: 'SLP', set_paid: true,
          billing: { first_name: '[E2E]', last_name: 'SLP', email: 'e2e@example.com', city: 'Taipei', country: 'TW' },
          line_items: [{ product_id: testProductId, quantity: 1 }],
        },
      })
      if (r.ok()) { orderId = (await r.json()).id; createdOrders.push(orderId!) }
    } finally {
      await ctx.dispose()
    }
    test.skip(!orderId, 'SLP 測試訂單未建立')

    const res = await refund(request, orderId!)
    const msg = String(res.data.message ?? '')
    // 不應出現綠界專屬的人工退款提示（代表未誤入綠界 gateway）
    expect(msg).not.toContain('綠界商家後台人工處理')
  })
})
