/**
 * Admin Setup — 管理員登入與認證狀態管理
 */
import { chromium, type FullConfig } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { extractNonce } from './api-client.js'

export const AUTH_FILE = path.resolve(import.meta.dirname, '../.auth/admin.json')
export const NONCE_FILE = path.resolve(import.meta.dirname, '../.auth/nonce.txt')

export function getNonce(): string {
  return fs.readFileSync(NONCE_FILE, 'utf-8').trim()
}

export async function loginAsAdmin(baseURL: string): Promise<string> {
  const browser = await chromium.launch()
  const context = await browser.newContext({ ignoreHTTPSErrors: true })
  const page = await context.newPage()

  await context.addCookies([
    {
      name: 'wordpress_test_cookie',
      value: 'WP+Cookie+check',
      domain: new URL(baseURL).hostname,
      path: '/',
    },
  ])

  // 帳密可用 E2E_ADMIN_USER / E2E_ADMIN_PASS 覆寫（wp-env 預設 admin / password）
  const adminUser = process.env.E2E_ADMIN_USER || 'test'
  const adminPass = process.env.E2E_ADMIN_PASS || 'YRjUdar!k^HRMwacf!@09X87'

  await page.goto(`${baseURL}/wp-login.php`)
  await page.fill('#user_login', adminUser)
  await page.fill('#user_pass', adminPass)
  await page.click('#wp-submit')
  await page.waitForURL('**/wp-admin/**', { timeout: 60_000 })

  await context.storageState({ path: AUTH_FILE })

  const nonce = await extractNonce(page, baseURL)
  fs.writeFileSync(NONCE_FILE, nonce)

  await browser.close()
  return nonce
}
