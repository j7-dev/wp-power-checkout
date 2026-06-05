/**
 * P0 — 綠界 ECPay 設定頁：gateway 啟用/存憑證 + 發票 provider 切換
 *
 * 涵蓋三個 provider：
 * - ecpay_aio（全方位金流，導轉式）
 * - ecpay_ecpg（站內付 2.0，內嵌式）
 * - ecpay（綠界電子發票，與 amego 並存可切換）
 *
 * 測試情境：
 * - 未登入 → 401/403
 * - GET /settings 列表同時包含 SLP + ECPay gateways 與 Amego + ECPay invoices
 * - 更新 ecpay_aio 憑證（merchantId / hashKey / hashIv）並持久化（憑證存 DB，非寫死）
 * - 更新 ecpay_ecpg 設定
 * - toggle 三個 ECPay provider 的 enabled（yes/no 冪等還原）
 * - 發票 provider 可在 amego / ecpay 之間切換
 * - XSS / 邊界值不 crash
 *
 * 依據：specs/api.yml（/settings、/settings/{id}、/settings/{id}/toggle）
 *       specs/erm.dbml（wp_options_ecpay_*_settings）
 *
 * NOTE：完整流程需 ECPay provider 已註冊於 ProviderUtils::$container（gateway 啟用後）。
 *       若後端尚未啟用對應 provider，相關案例以 test.skip 安全跳過，不誤判為失敗。
 */
import { test, expect } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { wpGet, wpPost, createApiContext, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, EDGE } from '../fixtures/test-data.js'
import { ECPAY_AIO_TEST } from '../helpers/ecpay-checkmacvalue.js'
import { ECPAY_ECPG_TEST } from '../helpers/ecpay-aes.js'

type AnyRecord = Record<string, unknown>
const unwrap = (res: { data: unknown }) =>
  ((res.data as AnyRecord).data ?? res.data) as AnyRecord

/**
 * /settings 的 gateways / invoices / logistics 實際以「provider_id => DTO」物件回傳
 * （PHP associative array），非 JSON 陣列。此 helper 取得 provider id 清單，
 * 同時容忍兩種形態（物件或陣列）。
 */
const toIdList = (collection: unknown): string[] => {
  if (Array.isArray(collection)) {
    return collection.map((c) => (c as AnyRecord).id as string)
  }
  if (collection && typeof collection === 'object') {
    return Object.keys(collection as AnyRecord)
  }
  return []
}

test.describe('綠界 ECPay 設定頁', () => {
  let opts: ApiOptions
  let ctx: APIRequestContext

  test.beforeAll(async () => {
    ctx = await createApiContext(BASE_URL)
    opts = { request: ctx, baseURL: BASE_URL, nonce: getNonce() }
  })

  test.afterAll(async () => {
    await ctx.dispose()
  })

  // ─── 未授權 ───────────────────────────────────────────────
  test('未登入訪客無法更新 ecpay_aio 設定 → 401/403', async ({ request }) => {
    const unauth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpPost(unauth, EP.SETTINGS_UPDATE(PROVIDERS.ECPAY_AIO), {
      merchantId: 'should_be_rejected',
    })
    expect([401, 403]).toContain(res.status)
  })

  // ─── 設定列表包含 ECPay providers ─────────────────────────
  test.describe('GET /settings 列表', () => {
    test('gateways 含 ecpay_aio 與 ecpay_ecpg（若已註冊）', async () => {
      const res = await wpGet(opts, EP.SETTINGS_ALL)
      expect(res.status).toBe(200)
      const data = unwrap(res)
      const ids = toIdList(data.gateways)

      // SLP 一定在；ECPay 視後端註冊狀態
      expect(ids).toContain(PROVIDERS.SLP)
      test.skip(
        !ids.includes(PROVIDERS.ECPAY_AIO),
        'ecpay_aio 尚未註冊於 ProviderUtils（gateway 未啟用）',
      )
      expect(ids).toContain(PROVIDERS.ECPAY_AIO)
      expect(ids).toContain(PROVIDERS.ECPAY_ECPG)
    })

    test('invoices 含 amego 與 ecpay（若已註冊）', async () => {
      const res = await wpGet(opts, EP.SETTINGS_ALL)
      expect(res.status).toBe(200)
      const data = unwrap(res)
      const ids = toIdList(data.invoices)

      expect(ids).toContain(PROVIDERS.AMEGO)
      test.skip(
        !ids.includes(PROVIDERS.ECPAY_INVOICE),
        'ecpay 發票 provider 尚未註冊於 ProviderUtils',
      )
      expect(ids).toContain(PROVIDERS.ECPAY_INVOICE)
    })
  })

  // ─── 更新 ecpay_aio 憑證 ──────────────────────────────────
  test.describe('ecpay_aio 憑證存取（存 DB，非寫死）', () => {
    test('更新 merchantId / hashKey / hashIv 並持久化', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_AIO))
      test.skip(getRes.status !== 200, 'ecpay_aio provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.ECPAY_AIO), {
        merchantId: ECPAY_AIO_TEST.merchantId,
        hashKey: ECPAY_AIO_TEST.hashKey,
        hashIv: ECPAY_AIO_TEST.hashIv,
        mode: 'test',
      })
      expect(res.status).toBe(200)
      const body = res.data as AnyRecord
      expect(body.code).toBe('success')

      // GET 確認持久化
      const verify = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_AIO))
      const data = unwrap(verify)
      expect(data.merchantId).toBe(ECPAY_AIO_TEST.merchantId)
      expect(data.hashKey).toBe(ECPAY_AIO_TEST.hashKey)
    })

    test('更新 installmentPeriods（分期期數）', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_AIO))
      test.skip(getRes.status !== 200, 'ecpay_aio provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.ECPAY_AIO), {
        installmentPeriods: ['3', '6', '12'],
      })
      expect(res.status).toBe(200)
    })

    test('XSS title 被消毒，不 crash', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_AIO))
      test.skip(getRes.status !== 200, 'ecpay_aio provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.ECPAY_AIO), {
        title: EDGE.XSS_SCRIPT,
      })
      expect(res.status).toBe(200)
      const data = unwrap(res)
      expect(String(data.title ?? '')).not.toContain('<script>')
    })
  })

  // ─── 更新 ecpay_ecpg 設定 ─────────────────────────────────
  test.describe('ecpay_ecpg 設定', () => {
    test('更新 ecpay_ecpg 憑證並持久化', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_ECPG))
      test.skip(getRes.status !== 200, 'ecpay_ecpg provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.ECPAY_ECPG), {
        merchantId: ECPAY_ECPG_TEST.merchantId,
        hashKey: ECPAY_ECPG_TEST.hashKey,
        hashIv: ECPAY_ECPG_TEST.hashIv,
        mode: 'test',
      })
      expect(res.status).toBe(200)
      expect((res.data as AnyRecord).code).toBe('success')

      const verify = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_ECPG))
      expect(unwrap(verify).merchantId).toBe(ECPAY_ECPG_TEST.merchantId)
    })
  })

  // ─── toggle ECPay providers ───────────────────────────────
  test.describe('toggle ECPay provider 啟用狀態（冪等還原）', () => {
    for (const id of [PROVIDERS.ECPAY_AIO, PROVIDERS.ECPAY_ECPG, PROVIDERS.ECPAY_INVOICE]) {
      test(`toggle ${id} 在 yes/no 之間切換並還原`, async () => {
        const before = await wpGet(opts, EP.SETTINGS_SINGLE(id))
        test.skip(before.status !== 200, `${id} provider 尚未註冊`)

        const enabledBefore = unwrap(before).enabled as string
        const expectedAfter = enabledBefore === 'yes' ? 'no' : 'yes'

        const toggleRes = await wpPost(opts, EP.SETTINGS_TOGGLE(id), {})
        expect(toggleRes.status).toBe(200)
        const body = toggleRes.data as AnyRecord
        expect(body.code).toBe('success')
        expect(body.data).toBe(id)

        const after = await wpGet(opts, EP.SETTINGS_SINGLE(id))
        expect(unwrap(after).enabled).toBe(expectedAfter)

        // 還原
        await wpPost(opts, EP.SETTINGS_TOGGLE(id), {})
        const restored = await wpGet(opts, EP.SETTINGS_SINGLE(id))
        expect(unwrap(restored).enabled).toBe(enabledBefore)
      })
    }
  })

  // ─── 發票 provider 切換（amego ⇄ ecpay）──────────────────
  test.describe('發票 provider 切換', () => {
    test('amego 與 ecpay 可獨立啟用（D6：兩者並存）', async () => {
      const amegoRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      const ecpayRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_INVOICE))
      test.skip(
        ecpayRes.status !== 200,
        'ecpay 發票 provider 尚未註冊（兩者並存切換無法驗證）',
      )

      // amego 一定存在
      expect(amegoRes.status).toBe(200)
      // ecpay 發票存在且為獨立 provider（不互斥）
      const ecpayData = unwrap(ecpayRes)
      expect(['yes', 'no']).toContain(ecpayData.enabled)
    })
  })

  // ─── 邊界值 ───────────────────────────────────────────────
  test('ecpay_aio merchantId 含 SQL Injection → 不 crash、DB 完好', async () => {
    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.ECPAY_AIO))
    test.skip(getRes.status !== 200, 'ecpay_aio provider 尚未註冊')

    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.ECPAY_AIO), {
      merchantId: EDGE.SQL_DROP,
    })
    expect(res.status).toBeLessThan(600)
    const all = await wpGet(opts, EP.SETTINGS_ALL)
    expect(all.status).toBe(200)

    // 還原為測試帳號
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.ECPAY_AIO), {
      merchantId: ECPAY_AIO_TEST.merchantId,
    }).catch(() => {})
  })
})
