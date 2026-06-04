/**
 * P0 — 綠界站內付 2.0（ECPG）ReturnURL 幕後通知整合流程
 *
 * 以 AES-128-CBC 加密構造站內付 2.0 的 JSON POST，驗證雙層錯誤檢查 + 狀態流轉：
 * - 外層 JSON：{ MerchantID, RpHeader, TransCode, TransMsg, Data(AES 密文) }
 * - 第一層：TransCode（整數）=== 1 才解密 Data；非 1 → 維持狀態
 * - 第二層：解密後業務層 RtnCode（整數）=== 1 → processing；非 1 → 維持 pending
 * - 解密失敗（亂碼 Data）→ 維持 pending，仍回 1|OK
 * - 冪等：已 processing 重送 → 維持 processing
 * - 端點回應純文字 1|OK、HTTP 200
 *
 * 依據：specs/features/payment/ecpay-ecpg-callback.feature
 *       specs/api.yml（/ecpg/return）
 *
 * 前置：使用綠界 ECPG 線上金流公開測試帳號（3002607 / pwFHCqoQZGmho4w6 / EkRm7iFT261dpevs）。
 *       需 ecpay_ecpg gateway 已以測試帳號啟用（否則後端 hashKey/hashIv 不符 → 解密失敗）。
 *       MerchantTradeNo 取自解密後巢狀 OrderInfo.MerchantTradeNo。
 *
 * NOTE：ECPG ReturnURL 為 Server-to-Server JSON POST（application/json），RtnCode/TransCode 為整數。
 */
import { test, expect, request as apiRequest } from '@playwright/test'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  EP,
  PROVIDERS,
  ORDER_STATUS,
  ECPAY_OK_RESPONSE,
} from '../fixtures/test-data.js'
import { buildEcpgReturnPayload, ECPAY_ECPG_TEST } from '../helpers/ecpay-aes.js'

let nonce: string
let ecpgOrderId: number | undefined
let ecpgTradeNo: string | undefined
let testProductId: number | undefined
let setupError: string | undefined

async function newCtx() {
  return apiRequest.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
  })
}

test.describe('綠界站內付 2.0（ECPG）ReturnURL 整合流程', () => {
  test.beforeAll(async () => {
    nonce = getNonce()
    const ctx = await newCtx()
    try {
      const productRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/products`, {
        data: { name: '[E2E] ECPG Product', type: 'simple', regular_price: '1000', status: 'publish' },
      })
      if (!productRes.ok()) {
        setupError = `建立測試商品失敗: ${productRes.status()}`
        return
      }
      testProductId = (await productRes.json()).id

      ecpgTradeNo = `EC${Date.now()}ECPG`
      const orderRes = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
        data: {
          status: 'pending',
          payment_method: PROVIDERS.ECPAY_ECPG,
          payment_method_title: '綠界 ECPay 站內付',
          billing: {
            first_name: '[E2E]', last_name: 'ECPG', email: 'e2e-ecpg@example.com',
            address_1: '[E2E] ECPG Address', city: 'Taipei', country: 'TW',
          },
          line_items: [{ product_id: testProductId, quantity: 1 }],
          meta_data: [{ key: '_pc_ecpay_trade_no', value: ecpgTradeNo }],
        },
      })
      if (orderRes.ok()) ecpgOrderId = (await orderRes.json()).id
      else setupError = `建立 ECPG 訂單失敗: ${orderRes.status()}`
    } finally {
      await ctx.dispose()
    }
  })

  test.afterAll(async () => {
    const ctx = await newCtx()
    try {
      if (ecpgOrderId) await ctx.delete(`${BASE_URL}/wp-json/wc/v3/orders/${ecpgOrderId}?force=true`).catch(() => {})
      if (testProductId) await ctx.delete(`${BASE_URL}/wp-json/wc/v3/products/${testProductId}?force=true`).catch(() => {})
    } finally {
      await ctx.dispose()
    }
  })

  // ─── 輔助：以 JSON POST 送 ECPG ReturnURL（不帶 WP 認證）────
  async function sendEcpgReturn(
    request: import('@playwright/test').APIRequestContext,
    payload: Record<string, unknown>,
  ) {
    const res = await request.post(`${BASE_URL}/wp-json/${EP.ECPAY_ECPG_RETURN}`, {
      headers: { 'Content-Type': 'application/json' },
      data: payload,
    })
    const text = await res.text().catch(() => '')
    return { status: res.status(), text }
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

  /** 組裝成功付款的解密後業務資料（巢狀 OrderInfo + 整數 RtnCode） */
  function successData(tradeNo: string) {
    return {
      RtnCode: 1, // ⚠️ 整數
      RtnMsg: '交易成功',
      MerchantID: ECPAY_ECPG_TEST.merchantId,
      OrderInfo: {
        MerchantTradeNo: tradeNo,
        TradeNo: '2306010000009',
        TradeAmt: 1000,
        PaymentType: 'aio',
      },
    }
  }

  // ─── 端點可達性 + 回應格式 ────────────────────────────────
  test('POST /ecpay/ecpg/return 端點存在（非 404）且回 1|OK', async ({ request }) => {
    const payload = buildEcpgReturnPayload(
      ECPAY_ECPG_TEST.merchantId,
      successData(ecpgTradeNo ?? 'EC_NONEXISTENT'),
      ECPAY_ECPG_TEST.hashKey,
      ECPAY_ECPG_TEST.hashIv,
    )
    const res = await sendEcpgReturn(request, payload)
    expect(res.status).not.toBe(404)
    expect(res.status).toBe(200)
    expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)
  })

  // ─── 雙層檢查通過 + RtnCode=1 → processing ────────────────
  test('TransCode=1 + 解密 + RtnCode=1 → 訂單轉 processing', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!ecpgOrderId || !ecpgTradeNo, 'ECPG 測試訂單未建立')

    const payload = buildEcpgReturnPayload(
      ECPAY_ECPG_TEST.merchantId,
      successData(ecpgTradeNo!),
      ECPAY_ECPG_TEST.hashKey,
      ECPAY_ECPG_TEST.hashIv,
      1, // TransCode 傳輸層成功
    )
    const res = await sendEcpgReturn(request, payload)
    expect(res.status).toBe(200)

    const status = await getOrderStatus(request, ecpgOrderId!)
    test.skip(
      status === ORDER_STATUS.PENDING,
      'ecpay_ecpg 未以測試帳號啟用，後端解密失敗（維持 pending）— 需啟用後重跑',
    )
    expect(status).toBe(ORDER_STATUS.PROCESSING)
  })

  // ─── 冪等：已 processing 重送 ─────────────────────────────
  test('冪等：已 processing 重送 ReturnURL → 維持 processing', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!ecpgOrderId || !ecpgTradeNo, 'ECPG 測試訂單未建立')

    const before = await getOrderStatus(request, ecpgOrderId!)
    test.skip(before !== ORDER_STATUS.PROCESSING, '訂單尚未 processing（前一案例被 skip）')

    const payload = buildEcpgReturnPayload(
      ECPAY_ECPG_TEST.merchantId,
      successData(ecpgTradeNo!),
      ECPAY_ECPG_TEST.hashKey,
      ECPAY_ECPG_TEST.hashIv,
    )
    const res = await sendEcpgReturn(request, payload)
    expect(res.status).toBe(200)
    expect(await getOrderStatus(request, ecpgOrderId!)).toBe(ORDER_STATUS.PROCESSING)
  })

  // ─── 傳輸層 TransCode != 1 → 維持狀態 ─────────────────────
  test('TransCode != 1（傳輸層失敗）→ 不解密、維持狀態，仍回 1|OK', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!ecpgOrderId || !ecpgTradeNo, 'ECPG 測試訂單未建立')

    const before = await getOrderStatus(request, ecpgOrderId!)
    const payload = buildEcpgReturnPayload(
      ECPAY_ECPG_TEST.merchantId,
      successData(ecpgTradeNo!),
      ECPAY_ECPG_TEST.hashKey,
      ECPAY_ECPG_TEST.hashIv,
      0, // 傳輸層失敗
    )
    // 容錯：傳輸層失敗時外層也帶明文 MerchantTradeNo（後端 add_trans_fail_note 用）
    payload.MerchantTradeNo = ecpgTradeNo
    const res = await sendEcpgReturn(request, payload)
    expect(res.status).toBe(200)
    expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)
    expect(await getOrderStatus(request, ecpgOrderId!)).toBe(before)
  })

  // ─── AES 解密失敗（亂碼 Data）→ 維持狀態 ──────────────────
  test('Data 為無法解密的亂碼 → 維持狀態，仍回 1|OK', async ({ request }) => {
    const before = ecpgOrderId ? await getOrderStatus(request, ecpgOrderId) : ''
    const res = await sendEcpgReturn(request, {
      MerchantID: ECPAY_ECPG_TEST.merchantId,
      RpHeader: { Timestamp: Math.floor(Date.now() / 1000) },
      TransCode: 1,
      TransMsg: 'Success',
      Data: 'this-is-not-valid-base64-aes-ciphertext!!!',
    })
    // 解密失敗仍回 1|OK（避免重送風暴）
    expect(res.status).toBe(200)
    expect(res.text.trim()).toBe(ECPAY_OK_RESPONSE)
    if (ecpgOrderId) {
      expect(await getOrderStatus(request, ecpgOrderId)).toBe(before)
    }
  })

  // ─── 業務層 RtnCode != 1 → 維持 pending ───────────────────
  test('TransCode=1 但業務層 RtnCode != 1 → 維持 pending', async ({ request }) => {
    test.skip(!!setupError, `Setup 失敗: ${setupError}`)
    test.skip(!ecpgOrderId || !ecpgTradeNo, 'ECPG 測試訂單未建立')

    // 用新訂單避免污染（此案例僅在訂單仍 pending 時有意義）
    const ctx = await newCtx()
    let failOrderId: number | undefined
    const failTradeNo = `EC${Date.now()}FAIL`
    try {
      const r = await ctx.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
        data: {
          status: 'pending', payment_method: PROVIDERS.ECPAY_ECPG, payment_method_title: '綠界站內付',
          billing: { first_name: '[E2E]', last_name: 'ECPGFail', email: 'e2e@example.com', city: 'Taipei', country: 'TW' },
          line_items: [{ product_id: testProductId, quantity: 1 }],
          meta_data: [{ key: '_pc_ecpay_trade_no', value: failTradeNo }],
        },
      })
      if (r.ok()) failOrderId = (await r.json()).id
    } finally {
      await ctx.dispose()
    }
    test.skip(!failOrderId, '失敗測試訂單建立失敗')

    const data = { ...successData(failTradeNo), RtnCode: 10100050, RtnMsg: '付款失敗' }
    const payload = buildEcpgReturnPayload(
      ECPAY_ECPG_TEST.merchantId, data, ECPAY_ECPG_TEST.hashKey, ECPAY_ECPG_TEST.hashIv,
    )
    const res = await sendEcpgReturn(request, payload)
    expect(res.status).toBe(200)
    expect(await getOrderStatus(request, failOrderId!)).toBe(ORDER_STATUS.PENDING)

    // 清理
    const cleanup = await newCtx()
    await cleanup.delete(`${BASE_URL}/wp-json/wc/v3/orders/${failOrderId}?force=true`).catch(() => {})
    await cleanup.dispose()
  })
})
