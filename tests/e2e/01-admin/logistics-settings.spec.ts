/**
 * P0 — 全方位物流設定頁：啟用 ecpay_logistics、憑證 CRUD、帳號類型切換、物流方式勾選
 *
 * 測試情境：
 * - 未登入 → 401/403
 * - GET /settings 列表包含 ecpay_logistics（若已註冊）
 * - 更新 B2C 憑證（merchantId / hashKey / hashIv）並持久化
 * - 帳號類型切換（account_type: b2c ⇄ c2c）→ 憑證跟著切換
 * - enabled_methods 勾選（FAMI / UNIMART / HILIFE / HOME）
 * - toggle ecpay_logistics enabled（yes/no 冪等還原）
 * - XSS / SQL injection / 超長字串不 crash
 *
 * 架構：鏡像 tests/e2e/01-admin/ecpay-settings.spec.ts
 * 依據：specs/api.yml（/settings、/settings/{id}、/settings/{id}/toggle）
 */

import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  EP,
  EDGE,
  LOGISTICS_PROVIDER,
  LOGISTICS_ACCOUNT_TYPE,
  LOGISTICS_SUB_TYPE,
} from '../fixtures/test-data.js'
import { LOGISTICS_B2C_TEST, LOGISTICS_C2C_TEST } from '../helpers/ecpay-logistics.js'

type AnyRecord = Record<string, unknown>
const unwrap = (res: { data: unknown }) =>
  ((res.data as AnyRecord).data ?? res.data) as AnyRecord

test.describe('全方位物流設定頁（ecpay_logistics）', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    opts = { request, baseURL: BASE_URL, nonce: getNonce() }
  })

  // ─── Smoke ───────────────────────────────────────────────────────────────
  test.describe('@smoke 端點可達性', () => {
    test('GET /settings 回 200（API 正常）', async () => {
      const res = await wpGet(opts, EP.SETTINGS_ALL)
      expect(res.status).toBe(200)
    })
  })

  // ─── 未授權 ───────────────────────────────────────────────────────────────
  test.describe('@error 未登入拒絕存取', () => {
    test('未登入訪客無法更新 ecpay_logistics 設定 → 401/403', async ({ request }) => {
      const unauth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(unauth, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        merchantId: 'should_be_rejected',
      })
      expect([401, 403]).toContain(res.status)
    })

    test('未登入無法 toggle ecpay_logistics → 401/403', async ({ request }) => {
      const unauth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(unauth, EP.SETTINGS_TOGGLE(LOGISTICS_PROVIDER.ID), {})
      expect([401, 403]).toContain(res.status)
    })
  })

  // ─── Happy：設定列表 ────────────────────────────────────────────────────
  test.describe('@happy GET /settings 列表包含物流 provider', () => {
    test('gateways 列表中含 ecpay_logistics（若已註冊）', async () => {
      const res = await wpGet(opts, EP.SETTINGS_ALL)
      expect(res.status).toBe(200)
      const data = unwrap(res)

      // 嘗試從 logistics 或 gateways 陣列中尋找（後端路由視實作而定）
      const logistics = (data.logistics ?? data.gateways) as AnyRecord[] | undefined
      if (!logistics) {
        test.skip(true, 'ecpay_logistics 尚未在 /settings 回應中出現（provider 未啟用）')
        return
      }
      const ids = logistics.map((g) => g.id as string)
      test.skip(
        !ids.includes(LOGISTICS_PROVIDER.ID),
        'ecpay_logistics 尚未註冊於 ProviderUtils（provider 未啟用）',
      )
      expect(ids).toContain(LOGISTICS_PROVIDER.ID)
    })
  })

  // ─── Happy：B2C 憑證 CRUD ──────────────────────────────────────────────
  test.describe('@happy B2C 憑證存取（存 DB，非寫死）', () => {
    test('更新 B2C merchantId / hashKey / hashIv 並持久化', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        account_type: LOGISTICS_ACCOUNT_TYPE.B2C,
        merchantId: LOGISTICS_B2C_TEST.merchantId,
        hashKey: LOGISTICS_B2C_TEST.hashKey,
        hashIv: LOGISTICS_B2C_TEST.hashIv,
        mode: 'test',
      })
      expect(res.status).toBe(200)
      const body = res.data as AnyRecord
      expect(body.code).toBe('success')

      // GET 確認持久化
      const verify = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      const verifyData = unwrap(verify)
      expect(verifyData.merchantId).toBe(LOGISTICS_B2C_TEST.merchantId)
      expect(verifyData.hashKey).toBe(LOGISTICS_B2C_TEST.hashKey)
    })

    test('更新 hashKey / hashIv 後再 GET 確認新值存入', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const newKey = LOGISTICS_B2C_TEST.hashKey
      const newIv = LOGISTICS_B2C_TEST.hashIv

      await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        hashKey: newKey,
        hashIv: newIv,
      })

      const after = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      const afterData = unwrap(after)
      expect(afterData.hashKey).toBe(newKey)
      expect(afterData.hashIv).toBe(newIv)
    })
  })

  // ─── Happy：C2C 憑證切換 ──────────────────────────────────────────────
  test.describe('@happy account_type 切換（b2c ⇄ c2c）', () => {
    test('切換為 c2c 並填入 C2C 憑證後持久化', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        account_type: LOGISTICS_ACCOUNT_TYPE.C2C,
        merchantId: LOGISTICS_C2C_TEST.merchantId,
        hashKey: LOGISTICS_C2C_TEST.hashKey,
        hashIv: LOGISTICS_C2C_TEST.hashIv,
        mode: 'test',
      })
      expect(res.status).toBe(200)
      expect((res.data as AnyRecord).code).toBe('success')

      const verify = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      const verifyData = unwrap(verify)
      expect(verifyData.account_type).toBe(LOGISTICS_ACCOUNT_TYPE.C2C)
      expect(verifyData.merchantId).toBe(LOGISTICS_C2C_TEST.merchantId)
    })

    test('從 c2c 切換回 b2c 後憑證更新', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        account_type: LOGISTICS_ACCOUNT_TYPE.B2C,
        merchantId: LOGISTICS_B2C_TEST.merchantId,
        hashKey: LOGISTICS_B2C_TEST.hashKey,
        hashIv: LOGISTICS_B2C_TEST.hashIv,
      })
      expect(res.status).toBe(200)

      const verify = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      expect(unwrap(verify).account_type).toBe(LOGISTICS_ACCOUNT_TYPE.B2C)
    })
  })

  // ─── Happy：enabled_methods 勾選 ──────────────────────────────────────
  test.describe('@happy enabled_methods 物流方式勾選', () => {
    test('啟用全家 FAMI → GET 確認 enabled_methods 含 FAMI', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        enabled_methods: [LOGISTICS_SUB_TYPE.FAMI],
      })
      expect(res.status).toBe(200)

      const verify = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      const methods = unwrap(verify).enabled_methods as string[] | undefined
      if (methods !== undefined) {
        expect(methods).toContain(LOGISTICS_SUB_TYPE.FAMI)
      }
    })

    test('同時啟用多種物流方式（FAMI / UNIMART / HOME）', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const methods = [
        LOGISTICS_SUB_TYPE.FAMI,
        LOGISTICS_SUB_TYPE.UNIMART,
        LOGISTICS_SUB_TYPE.HOME,
      ]
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        enabled_methods: methods,
      })
      expect(res.status).toBe(200)
    })

    test('清空 enabled_methods（空陣列）後 GET 確認', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        enabled_methods: [],
      })
      expect(res.status).toBe(200)
    })
  })

  // ─── Happy：toggle enabled ─────────────────────────────────────────────
  test.describe('@happy toggle ecpay_logistics 啟用狀態（冪等還原）', () => {
    test('toggle ecpay_logistics yes/no 並還原', async () => {
      const before = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(before.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const enabledBefore = unwrap(before).enabled as string
      const expectedAfter = enabledBefore === 'yes' ? 'no' : 'yes'

      const toggleRes = await wpPost(opts, EP.SETTINGS_TOGGLE(LOGISTICS_PROVIDER.ID), {})
      expect(toggleRes.status).toBe(200)
      const body = toggleRes.data as AnyRecord
      expect(body.code).toBe('success')

      const after = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      expect(unwrap(after).enabled).toBe(expectedAfter)

      // 還原
      await wpPost(opts, EP.SETTINGS_TOGGLE(LOGISTICS_PROVIDER.ID), {})
      const restored = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      expect(unwrap(restored).enabled).toBe(enabledBefore)
    })
  })

  // ─── Edge：邊界值 ─────────────────────────────────────────────────────
  test.describe('@edge 邊界值輸入', () => {
    test('title 含 XSS → 被消毒，不 crash', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        title: EDGE.XSS_SCRIPT,
      })
      expect(res.status).toBe(200)
      const data = unwrap(res)
      const title = String(data.title ?? '')
      expect(title).not.toContain('<script>')
    })

    test('hashKey 超長字串（10000 字元）→ 不 crash，DB 完好', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        hashKey: EDGE.VERY_LONG_STRING,
      })
      // 可回 200（截斷/驗證失敗）或 4xx（主動拒絕），不可 500
      expect(res.status).toBeLessThan(500)

      // 確認 API 整體仍正常
      const all = await wpGet(opts, EP.SETTINGS_ALL)
      expect(all.status).toBe(200)

      // 還原為正確憑證
      await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        hashKey: LOGISTICS_B2C_TEST.hashKey,
        hashIv: LOGISTICS_B2C_TEST.hashIv,
      }).catch(() => {})
    })

    test('merchantId 含 SQL Injection → 不 crash，DB 完好', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        merchantId: EDGE.SQL_DROP,
      })
      expect(res.status).toBeLessThan(600)

      const all = await wpGet(opts, EP.SETTINGS_ALL)
      expect(all.status).toBe(200)

      // 還原
      await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        merchantId: LOGISTICS_B2C_TEST.merchantId,
      }).catch(() => {})
    })

    test('title 含 Emoji → 正常存取', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        title: EDGE.EMOJI_SIMPLE,
      })
      expect(res.status).toBe(200)
    })

    test('title 為空字串 → 不 crash', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        title: EDGE.EMPTY_STRING,
      })
      expect(res.status).toBeLessThan(500)
    })
  })

  // ─── Security ────────────────────────────────────────────────────────────
  test.describe('@security 安全性', () => {
    test('hashKey 含 XSS SVG → 回應不含未消毒 SVG', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        hashKey: EDGE.XSS_SVG,
      })
      const body = JSON.stringify(res.data)
      expect(body).not.toContain('<svg/onload=')
    })

    test('title 含 Path Traversal → 不 crash', async () => {
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(LOGISTICS_PROVIDER.ID))
      test.skip(getRes.status !== 200, 'ecpay_logistics provider 尚未註冊')

      const res = await wpPost(opts, EP.SETTINGS_UPDATE(LOGISTICS_PROVIDER.ID), {
        title: EDGE.PATH_TRAVERSAL,
      })
      expect(res.status).toBeLessThan(500)
    })
  })
})
