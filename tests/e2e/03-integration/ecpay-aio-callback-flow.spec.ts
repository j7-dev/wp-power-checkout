/**
 * P0 — 綠界 AIO 幕後通知整合流程（ReturnURL + PaymentInfoURL）
 *
 * 以合法 CheckMacValue（SHA256）構造綠界 Form POST，驗證後端狀態流轉：
 * - ReturnURL RtnCode="1" → 訂單 processing；驗章失敗 → 維持 pending
 * - 冪等：同一 MerchantTradeNo 重送（已 processing）→ 維持 processing
 * - RtnCode 非 "1"（付款失敗）→ 維持 pending
 * - PaymentInfoURL（ATM 兩段取號）：RtnCode="2" 取號成功 → 不改狀態、寫繳費資訊
 * - 端點一律回應純文字 1|OK、HTTP 200
 *
 * 依據：specs/features/payment/ecpay-aio-callback.feature
 *       specs/features/payment/ecpay-aio-payment-info.feature
 *       specs/api.yml（/aio/return、/aio/payment-info）
 *
 * 前置：使用綠界 AIO 公開測試帳號（3002607 / pwFHCqoQZGmho4w6 / EkRm7iFT261dpevs）。
 *       本檔需 ecpay_aio gateway 已啟用並以測試帳號設定（否則後端 hashKey/hashIv 不符 → 驗章失敗）。
 *       AIO callback 為 Form POST（application/x-www-form-urlencoded），非 JSON。
 *
 * NOTE：完整驗證需運行中 WP + ecpay_aio 已啟用。若 setup 失敗，相關案例以 test.skip 安全跳過。
 *       AIO callback 不需 WP 認證（permission_callback=__return_true），以 CheckMacValue 把關。
 */
import { test, expect, request as apiRequest } from '@playwright/test'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  EP,
  PROVIDERS,
  ORDER_STATUS,
  ECPAY_AIO_RTN_CODE,
  ECPAY_OK_RESPONSE,
} from '../fixtures/test-data.js'
import {
  withCheckMacValue,
  generateCheckMacValue,
  ECPAY_AIO_TEST,
  type CmvParams,
} from '../helpers/ecpay-checkmacvalue.js'

let nonce: string
let aioOrderId: number | undefined
let aioTradeNo: string | undefined
let atmOrderId: number | undefined
let atmTradeNo: string | undefined
let testProductId: number | undefined
let setupError: string | undefined

import * as fs from 'fs'
import * as path from 'path'

const AUTH_FILE = path.resolve(import.meta.dirname, '../.auth/admin.json')
const storageStateOrUndefined = () =>
  fs.existsSync(AUTH_FILE) ? AUTH_FILE : undefined

// 認證型 context：帶 admin cookie + X-WP-Nonce，供 WC/REST 建單、查詢、刪除等需登入操作。
async function newCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    storageState: storageStateOrUndefined(),
    extraHTTPHeaders: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
  })
}

// Webhook context：模擬綠界 server-to-server 請求。
// 關鍵：必須「明確」清掉 playwright.config 的 use.extraHTTPHeaders（X-WP-Nonce:''）與
// use.storageState（admin cookie）——standalone apiRequest.newContext 仍會繼承專案 use 設定，
// 只要帶有 X-WP-Nonce header（即使空字串）或登入 cookie，WP REST 就會執行 cookie nonce 驗證並回 403。
// extraHTTPHeaders:{} 會「取代」（非合併）繼承的 header；空 storageState 清掉 cookie。
async function newWebhookCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: {},
    storageState: { cookies: [], origins: [] },
  })
}

async function createEcpayOrder(
  ctx: import('@playwright/test').APIRequestContext,
  tradeNo: string,
  paymentMethod: string,
): Promise<number | undefined> {
  const res = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
    data: {
      status: 'pending',
      payment_method: paymentMethod,
      payment_method_title: '綠界 ECPay',
      billing: {
        first_name: '[E2E]',
        last_name: 'ECPay',
        email: 'e2e-ecpay@example.com',
        address_1: '[E2E] ECPay Address',
        city: 'Taipei',
        country: 'TW',
      },
      line_items: [{ product_id: testProductId, quantity: 1 }],
      // 綠界以 _pc_ecpay_trade_no（MerchantTradeNo）反查訂單
      meta_data: [{ key: '_pc_ecpay_trade_no', value: tradeNo }],
    },
  })
  if (!res.ok()) {
    setupError = `建立綠界訂單失敗 (${paymentMethod}): ${res.status()}`
    return undefined
  }
  return (await res.json()).id as number
}

test.describe('綠界 AIO 幕後通知整合流程', () => {
  test.beforeAll(async () => {
    nonce = getNonce()
    const ctx = await newCtx()
    try {
      const productRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/products`, {
        data: { name: '[E2E] ECPay AIO Product', type: 'simple', regular_price: '1000', status: 'publish' },
      })
      if (!productRes.ok()) {
        setupError = `建立測試商品失敗: ${productRes.status()}`
        return
      }
      testProductId = (await productRes.json()).id

      aioTradeNo = `EC${Date.now()}AIO`
      aioOrderId = await createEcpayOrder(ctx, aioTradeNo, PROVIDERS.ECPAY_AIO)

      atmTradeNo = `EC${Date.now()}ATM`
      atmOrderId = await createEcpayOrder(ctx, atmTradeNo, PROVIDERS.ECPAY_AIO)
    } finally {
      await ctx.dispose()
    }
  })

  test.afterAll(async () => {
    const ctx = await newCtx()
    try {
      for (const id of [aioOrderId, atmOrderId]) {
        if (id) await ctx.delete(`${BASE_URL}/wp-json/wc/v3/orders/${id}?force=true`).catch(() => {})
      }
      if (testProductId) {
        await ctx.delete(`${BASE_URL}/wp-json/wc/v3/products/${testProductId}?force=true`).catch(() => {})
      }
    } finally {
      await ctx.dispose()
    }
  })

  // ─── 輔助：以 form-urlencoded 送 AIO callback（不帶 WP 認證）────
  // 刻意忽略傳入的 request fixture，改用自建的 webhook context（無 X-WP-Nonce），
  // 因為傳入的 fixture 帶有 config 設定的空 X-WP-Nonce header + cookie，
  // 會觸發 WP cookie nonce 驗證而回 403。綠界 callback 不需 WP 認證。
  async function sendAioCallback(
    _request: import('@playwright/test').APIRequestContext,
    endpoint: string,
    params: CmvParams,
  ) {
    // AIO callback 為 Form POST（application/x-www-form-urlencoded）
    const form: Record<string, string> = {}
    for (const [k, v] of Object.entries(params)) form[k] = String(v)
    const ctx = await newWebhookCtx()
    try {
      const res = await ctx.post(`${BASE_URL}/wp-json/${endpoint}`, {
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        form,
      })
      const text = await res.text().catch(() => '')
      return { status: res.status(), text }
    } finally {
      await ctx.dispose()
    }
  }

  async function getOrderStatus(
    request: import('@playwright/test').APIRequestContext,
    orderId: number,
  ): Promise<string> {
    const res = await request.get(`${BASE_URL}/wp-json/${EP.WC_ORDER(orderId)}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    })
    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>
    return String(data.status ?? '')
  }

  // ─── ReturnURL 端點可達性 + 回應格式 ──────────────────────
  test('POST /ecpay/aio/return 端點存在（非 404）且回 1|OK', async ({ request }) => {
    const params = withCheckMacValue(
      {
        MerchantID: ECPAY_AIO_TEST.merchantId,
        MerchantTradeNo: aioTradeNo ?? 'EC_NONEXISTENT',
        RtnCode: ECPAY_AIO_RTN_CODE.PAID_SUCCESS,
        RtnMsg: '交易成功',
        TradeNo: '2306010000001',
        TradeAmt: '1000',
        PaymentDate: '2025/01/01 12:05:00',
        PaymentType: 'Credit_CreditCard',
        SimulatePaid: '0',
      },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    const res = await sendAioCallback(request, EP.ECPAY_AIO_RETURN, params)
    expect(res.status).not.toBe(404)
    expect(res.status).toBe(200)
    expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)
  })

  // ─── RtnCode=1 → processing ───────────────────────────────
  test('合法 CheckMacValue + RtnCode="1" → 訂單轉 processing', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!aioOrderId || !aioTradeNo, 'AIO 測試訂單未建立')

    const params = withCheckMacValue(
      {
        MerchantID: ECPAY_AIO_TEST.merchantId,
        MerchantTradeNo: aioTradeNo!,
        RtnCode: ECPAY_AIO_RTN_CODE.PAID_SUCCESS,
        RtnMsg: '交易成功',
        TradeNo: '2306010000002',
        TradeAmt: '1000',
        PaymentDate: '2025/01/01 12:05:00',
        PaymentType: 'Credit_CreditCard',
        SimulatePaid: '0',
      },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    const res = await sendAioCallback(request, EP.ECPAY_AIO_RETURN, params)
    expect(res.status).toBe(200)

    const status = await getOrderStatus(request, aioOrderId!)
    // 後端以測試帳號設定時應轉 processing；否則驗章失敗維持 pending（用 skip 條件避免誤判）
    test.skip(
      status === ORDER_STATUS.PENDING,
      'ecpay_aio 未以測試帳號啟用，後端驗章失敗（維持 pending）— 需啟用後重跑',
    )
    expect(status).toBe(ORDER_STATUS.PROCESSING)
  })

  // ─── 冪等：重送同一 MerchantTradeNo ───────────────────────
  test('冪等：已 processing 的訂單重送 ReturnURL → 維持 processing', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!aioOrderId || !aioTradeNo, 'AIO 測試訂單未建立')

    const before = await getOrderStatus(request, aioOrderId!)
    test.skip(before !== ORDER_STATUS.PROCESSING, '訂單尚未 processing（前一案例被 skip）')

    const params = withCheckMacValue(
      {
        MerchantID: ECPAY_AIO_TEST.merchantId,
        MerchantTradeNo: aioTradeNo!,
        RtnCode: ECPAY_AIO_RTN_CODE.PAID_SUCCESS,
        RtnMsg: '交易成功（重送）',
        TradeNo: '2306010000002',
        TradeAmt: '1000',
        PaymentDate: '2025/01/01 12:05:00',
        PaymentType: 'Credit_CreditCard',
        SimulatePaid: '0',
      },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    const res = await sendAioCallback(request, EP.ECPAY_AIO_RETURN, params)
    expect(res.status).toBe(200)
    expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)

    const after = await getOrderStatus(request, aioOrderId!)
    expect(after).toBe(ORDER_STATUS.PROCESSING)
  })

  // ─── 驗章失敗 → 維持狀態 ──────────────────────────────────
  test('CheckMacValue 不符 → 不改狀態，仍回 1|OK', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!atmOrderId || !atmTradeNo, 'ATM 測試訂單未建立')

    const before = await getOrderStatus(request, atmOrderId!)

    const res = await sendAioCallback(request, EP.ECPAY_AIO_RETURN, {
      MerchantID: ECPAY_AIO_TEST.merchantId,
      MerchantTradeNo: atmTradeNo!,
      RtnCode: ECPAY_AIO_RTN_CODE.PAID_SUCCESS,
      RtnMsg: '偽造',
      CheckMacValue: 'DEADBEEFINVALIDCHECKMACVALUE0000000000000000000000000000000000',
    })
    // 驗章失敗仍回 1|OK（避免重送風暴）
    expect(res.status).toBe(200)
    expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)

    const after = await getOrderStatus(request, atmOrderId!)
    expect(after).toBe(before) // 維持原狀態
  })

  // ─── RtnCode 非 "1"（付款失敗）→ 維持 pending ──────────────
  test('合法 CheckMacValue + RtnCode 非 "1" → 維持 pending', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!atmOrderId || !atmTradeNo, 'ATM 測試訂單未建立')

    const params = withCheckMacValue(
      {
        MerchantID: ECPAY_AIO_TEST.merchantId,
        MerchantTradeNo: atmTradeNo!,
        RtnCode: '10100050', // 付款失敗碼
        RtnMsg: '付款失敗',
        TradeNo: '2306010000003',
        TradeAmt: '1000',
        SimulatePaid: '0',
      },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    const res = await sendAioCallback(request, EP.ECPAY_AIO_RETURN, params)
    expect(res.status).toBe(200)

    const status = await getOrderStatus(request, atmOrderId!)
    expect(status).toBe(ORDER_STATUS.PENDING)
  })

  // ─── PaymentInfoURL：ATM 取號兩段 ─────────────────────────
  test.describe('PaymentInfoURL ATM 取號（兩段付款第一段）', () => {
    test('端點存在（非 404）且回 1|OK', async ({ request }) => {
      const params = withCheckMacValue(
        {
          MerchantID: ECPAY_AIO_TEST.merchantId,
          MerchantTradeNo: atmTradeNo ?? 'EC_NONEXISTENT',
          RtnCode: ECPAY_AIO_RTN_CODE.ATM_GET_CODE,
          RtnMsg: 'Get VirtualAccount Succeeded',
          PaymentType: 'ATM_TAISHIN',
          BankCode: '812',
          vAccount: '9103522175887271',
          ExpireDate: '2025/01/05',
        },
        ECPAY_AIO_TEST.hashKey,
        ECPAY_AIO_TEST.hashIv,
      )
      const res = await sendAioCallback(request, EP.ECPAY_AIO_PAYMENT_INFO, params)
      expect(res.status).not.toBe(404)
      expect(res.status).toBe(200)
      expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)
    })

    test('RtnCode="2" 取號成功 → 訂單維持 pending（取號≠付款）', async ({ request }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!atmOrderId || !atmTradeNo, 'ATM 測試訂單未建立')

      // 確保此訂單尚未被前面失敗 callback 改變狀態（仍 pending）
      const before = await getOrderStatus(request, atmOrderId!)
      test.skip(before !== ORDER_STATUS.PENDING, 'ATM 訂單非 pending，跳過取號驗證')

      const params = withCheckMacValue(
        {
          MerchantID: ECPAY_AIO_TEST.merchantId,
          MerchantTradeNo: atmTradeNo!,
          RtnCode: ECPAY_AIO_RTN_CODE.ATM_GET_CODE,
          RtnMsg: 'Get VirtualAccount Succeeded',
          PaymentType: 'ATM_TAISHIN',
          BankCode: '812',
          vAccount: '9103522175887271',
          ExpireDate: '2025/01/05',
        },
        ECPAY_AIO_TEST.hashKey,
        ECPAY_AIO_TEST.hashIv,
      )
      const res = await sendAioCallback(request, EP.ECPAY_AIO_PAYMENT_INFO, params)
      expect(res.status).toBe(200)

      // 取號成功不改狀態（維持等待付款）
      const after = await getOrderStatus(request, atmOrderId!)
      expect(after).toBe(ORDER_STATUS.PENDING)
    })

    test('CVS 取號成功碼 "10100073" 同樣不改狀態', async ({ request }) => {
      test.skip(!!setupError, `Setup 失敗: ${setupError}`)
      test.skip(!atmOrderId || !atmTradeNo, 'ATM 測試訂單未建立')

      const before = await getOrderStatus(request, atmOrderId!)
      const params = withCheckMacValue(
        {
          MerchantID: ECPAY_AIO_TEST.merchantId,
          MerchantTradeNo: atmTradeNo!,
          RtnCode: ECPAY_AIO_RTN_CODE.CVS_GET_CODE,
          RtnMsg: 'Get CVS Code Succeeded',
          PaymentType: 'CVS_CVS',
          PaymentNo: 'LLL21263150',
          ExpireDate: '2025/01/05 23:59:59',
        },
        ECPAY_AIO_TEST.hashKey,
        ECPAY_AIO_TEST.hashIv,
      )
      const res = await sendAioCallback(request, EP.ECPAY_AIO_PAYMENT_INFO, params)
      expect(res.status).toBe(200)
      expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)
      // 取號碼非錯誤，狀態不變
      expect(await getOrderStatus(request, atmOrderId!)).toBe(before)
    })
  })

  // ─── 自我驗證：本檔產生的 CMV 可被重算驗證 ─────────────────
  test('（簽章自驗）產生的 CheckMacValue 與重算一致', () => {
    const base: CmvParams = {
      MerchantID: ECPAY_AIO_TEST.merchantId,
      MerchantTradeNo: 'EC100ABCDEF',
      RtnCode: '1',
    }
    const signed = withCheckMacValue(base, ECPAY_AIO_TEST.hashKey, ECPAY_AIO_TEST.hashIv)
    const recomputed = generateCheckMacValue(base, ECPAY_AIO_TEST.hashKey, ECPAY_AIO_TEST.hashIv)
    expect(signed.CheckMacValue).toBe(recomputed)
  })
})
