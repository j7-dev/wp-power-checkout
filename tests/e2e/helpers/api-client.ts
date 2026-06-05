/**
 * REST API Client — 封裝 WordPress / WooCommerce API 操作
 */
import { request as apiRequest } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

export type ApiOptions = {
  request: APIRequestContext
  baseURL: string
  nonce: string
}

/**
 * 在 beforeAll 中建立專屬的 APIRequestContext。
 *
 * Playwright 禁止在 test 內重用 beforeAll 取得的 worker-scope `{ request }` fixture
 * （錯誤：Fixture { request } from beforeAll cannot be reused in a test），
 * 因此各 spec 的 beforeAll 應呼叫本函式自建 context，並於 afterAll dispose。
 *
 * 用法：
 *   let ctx: APIRequestContext
 *   test.beforeAll(async () => { ctx = await createApiContext(BASE_URL); opts = { request: ctx, baseURL: BASE_URL, nonce: getNonce() } })
 *   test.afterAll(async () => { await ctx.dispose() })
 */
export async function createApiContext(baseURL: string): Promise<APIRequestContext> {
  // 載入管理員 storageState（cookie），否則帶 X-WP-Nonce 的請求會被 WP 以
  // rest_cookie_invalid_nonce（403）拒絕——cookie nonce 驗證需 cookie + nonce 同時存在。
  const authFile = path.resolve(import.meta.dirname, '../.auth/admin.json')
  const storageState = fs.existsSync(authFile) ? authFile : undefined
  return apiRequest.newContext({ baseURL, ignoreHTTPSErrors: true, storageState })
}

const headers = (nonce: string) => ({
  'X-WP-Nonce': nonce,
  'Content-Type': 'application/json',
})

export async function wpGet<T = unknown>(
  opts: ApiOptions,
  endpoint: string,
  params?: Record<string, string>,
): Promise<{ data: T; status: number; headers: Record<string, string> }> {
  const url = new URL(`${opts.baseURL}/wp-json/${endpoint}`)
  if (params) Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))
  const res = await opts.request.get(url.toString(), { headers: headers(opts.nonce) })
  const data = res.status() < 300 ? await res.json() : await res.json().catch(() => ({}))
  return {
    data: data as T,
    status: res.status(),
    headers: Object.fromEntries(res.headersArray().map(h => [h.name.toLowerCase(), h.value])),
  }
}

export async function wpPost<T = unknown>(
  opts: ApiOptions,
  endpoint: string,
  data: Record<string, unknown>,
): Promise<{ data: T; status: number }> {
  const res = await opts.request.post(`${opts.baseURL}/wp-json/${endpoint}`, {
    headers: headers(opts.nonce),
    data,
  })
  const body = await res.json().catch(() => ({}))
  return { data: body as T, status: res.status() }
}

export async function wpPut<T = unknown>(
  opts: ApiOptions,
  endpoint: string,
  data: Record<string, unknown>,
): Promise<{ data: T; status: number }> {
  const res = await opts.request.put(`${opts.baseURL}/wp-json/${endpoint}`, {
    headers: headers(opts.nonce),
    data,
  })
  const body = await res.json().catch(() => ({}))
  return { data: body as T, status: res.status() }
}

export async function wpDelete<T = unknown>(
  opts: ApiOptions,
  endpoint: string,
): Promise<{ data: T; status: number }> {
  const res = await opts.request.delete(`${opts.baseURL}/wp-json/${endpoint}`, {
    headers: headers(opts.nonce),
  })
  const body = await res.json().catch(() => ({}))
  return { data: body as T, status: res.status() }
}

export async function extractNonce(page: import('@playwright/test').Page, baseURL: string): Promise<string> {
  await page.goto(`${baseURL}/wp-admin/`)
  await page.waitForLoadState('domcontentloaded')
  const nonce = await page.evaluate(() => (window as any).wpApiSettings?.nonce ?? '')
  if (!nonce) {
    throw new Error('無法提取 WP REST nonce，請確認管理員已登入')
  }
  return nonce
}
