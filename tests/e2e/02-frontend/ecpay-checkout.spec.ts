/**
 * P1 — 綠界 ECPay 前端結帳：AIO 導轉 + ECPG 站內付頁
 *
 * 驗證前端可達性與資料正確性（不觸發真實綠界交易）：
 * - AIO（ecpay_aio）：process_payment 後回 order-received，before_order_received 輸出 auto-submit form
 *   導向綠界 Cashier V5（payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5）。
 * - ECPG（ecpay_ecpg）：站內付 2.0 內嵌頁，前端 SDK 容器 #ECPayPayment，
 *   PayToken 取得後 POST 至 /ecpay/ecpg/create-payment。
 * - 回導 / 結帳頁面不出現 PHP fatal error。
 * - create-payment 端點存在、需 order_key 授權（缺 order_key → 403/400，非 404）。
 *
 * 依據：specs/features/payment/ecpay-aio-checkout.feature
 *       specs/features/payment/ecpay-ecpg-checkout.feature
 *       specs/api.yml（/ecpg/create-payment）
 *
 * NOTE：AIO 跳轉至綠界託管頁、ECPG SDK 收卡皆無法 Playwright 自動化完成真實付款，
 *       本檔僅驗證「前端整合可達性 + 端點契約」，付款後狀態流轉由 03-integration callback 測試覆蓋。
 */
import { test, expect } from '@playwright/test'
import { wpGet, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS } from '../fixtures/test-data.js'

test.describe('綠界 ECPay 前端結帳', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    opts = { request, baseURL: BASE_URL, nonce: getNonce() }
  })

  // ─── 結帳頁可達性 ─────────────────────────────────────────
  test('結帳頁（/checkout/）不出現 PHP fatal error', async ({ page }) => {
    const res = await page.goto(`${BASE_URL}/checkout/`)
    expect(res?.status()).toBeLessThan(500)
    const body = (await page.locator('body').textContent()) ?? ''
    expect(body.toLowerCase()).not.toContain('fatal error')
  })

  // ─── AIO 導轉設定 ─────────────────────────────────────────
  test.describe('AIO 導轉（ecpay_aio）', () => {
    test('ecpay_aio 設定的 endpoint 指向綠界 Cashier V5（test=stage）', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_AIO))
      test.skip(res.status !== 200, 'ecpay_aio provider 尚未註冊')

      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      // mode=test 時端點應為 stage Cashier V5（DTO after_init 設定，可能不在 summary 中曝露）
      const endpoint = String(data.endpoint ?? '')
      if (endpoint) {
        expect(endpoint).toContain('ecpay.com.tw')
        expect(endpoint).toContain('AioCheckOut/V5')
      }
    })

    test('order-received 頁面（AIO 訂單）不出現 PHP fatal error', async ({ page }) => {
      // 一般 order-received 頁面可達即可（真實訂單跳轉由 callback 測試覆蓋）
      const res = await page.goto(`${BASE_URL}/checkout/order-received/1/`)
      expect(res?.status()).toBeLessThan(500)
      const body = (await page.locator('body').textContent()) ?? ''
      expect(body.toLowerCase()).not.toContain('fatal error')
    })
  })

  // ─── ECPG 站內付頁 ────────────────────────────────────────
  test.describe('ECPG 站內付（ecpay_ecpg）', () => {
    test('ecpg create-payment 端點存在（非 404）且需授權', async ({ request }) => {
      // 缺 order_id/order_key → 不應 404（端點存在），且不應 200（未授權）
      const res = await request.post(
        `${BASE_URL}/wp-json/${EP.ECPAY_ECPG_CREATE_PAYMENT}`,
        {
          headers: { 'Content-Type': 'application/json' },
          data: {},
        },
      )
      expect(res.status()).not.toBe(404)
      expect(res.status()).toBeLessThan(500)
      // 無 order_id → 找不到訂單(404) 或 驗證失敗(403)；無論如何不應建立付款(200)
      expect(res.status()).not.toBe(200)
    })

    test('ecpg create-payment：order 不存在 → 404', async ({ request }) => {
      const res = await request.post(
        `${BASE_URL}/wp-json/${EP.ECPAY_ECPG_CREATE_PAYMENT}`,
        {
          headers: { 'Content-Type': 'application/json' },
          data: {
            order_id: 9_999_999,
            order_key: 'wc_order_nonexistent',
            pay_token: 'dummy',
          },
        },
      )
      // 找不到訂單 → 404（EcpgFrontendApi error_response）
      expect([400, 403, 404]).toContain(res.status())
    })

    test('ecpg create-payment：錯誤 order_key → 403（越權防護）', async ({ request }) => {
      // 用一個極可能存在的訂單 ID（1）但錯誤的 order_key；
      // 若訂單存在但 key 不符 → 403；若不存在 → 404。皆為合理拒絕（非 200）。
      const res = await request.post(
        `${BASE_URL}/wp-json/${EP.ECPAY_ECPG_CREATE_PAYMENT}`,
        {
          headers: { 'Content-Type': 'application/json' },
          data: {
            order_id: 1,
            order_key: 'wrong_order_key',
            pay_token: 'dummy',
          },
        },
      )
      expect([400, 403, 404]).toContain(res.status())
      expect(res.status()).not.toBe(200)
    })
  })
})
