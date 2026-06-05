/**
 * Global Setup — E2E 測試初始化
 *
 * 執行順序：
 * 1. 注入 LC bypass（繞過 Powerhouse 授權檢查）
 * 2. 以管理員帳號登入，儲存 Cookie 狀態與 Nonce
 * 3. 透過 WooCommerce REST API 建立測試訂單資料
 * 4. 透過 WC REST API 設定測試訂單的 order meta（_pc_payment_identity 等）
 * 5. 將測試 ID 儲存至 .auth/test-ids.json
 */
import { chromium, type FullConfig } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { applyLcBypass } from './helpers/lc-bypass.js'
import { loginAsAdmin, AUTH_FILE, NONCE_FILE, getNonce } from './helpers/admin-setup.js'
import { BASE_URL, TEST_IDS_FILE, type TestIds } from './fixtures/test-data.js'

async function globalSetup(_config: FullConfig) {
  console.log('\n--- E2E Global Setup ---')

  // ── 0. Ensure .auth directory exists ────────────────────────
  const authDir = path.dirname(AUTH_FILE)
  if (!fs.existsSync(authDir)) {
    fs.mkdirSync(authDir, { recursive: true })
  }

  // ── 1. Apply LC bypass ───────────────────────────────────────
  try {
    applyLcBypass()
  } catch (e) {
    console.warn('LC bypass 跳過:', (e as Error).message)
  }

  // ── 2. Login as admin ────────────────────────────────────────
  console.log('[Setup] 登入管理員...')
  const nonce = await loginAsAdmin(BASE_URL)
  console.log('[Setup] Nonce 已取得:', nonce.slice(0, 6) + '...')

  // ── 3. Create test data via REST API ─────────────────────────
  console.log('[Setup] 建立測試資料...')
  const testIds: TestIds = {}

  const browser = await chromium.launch()
  try {
    const context = await browser.newContext({ storageState: AUTH_FILE, ignoreHTTPSErrors: true })
    const apiContext = context.request

    const authHeaders = {
      'X-WP-Nonce': nonce,
      'Content-Type': 'application/json',
    }

    // ── 3a. 清除舊的 E2E 測試訂單 ──────────────────────────────
    console.log('[Setup] 清除舊 E2E 測試資料...')
    try {
      const oldOrders = await apiContext.get(
        `${BASE_URL}/wp-json/wc/v3/orders?search=[E2E]&per_page=20`,
        { headers: authHeaders },
      )
      if (oldOrders.ok()) {
        const orders = await oldOrders.json()
        for (const order of orders) {
          await apiContext.delete(
            `${BASE_URL}/wp-json/wc/v3/orders/${order.id}?force=true`,
            { headers: authHeaders },
          ).catch(() => {/* 忽略清除失敗 */})
        }
        console.log(`[Setup] 已清除 ${orders.length} 筆舊訂單`)
      }
    } catch {
      // 清除失敗不影響測試
    }

    // ── 3a-2. 建立共用測試商品（WC 10+ REST 建單要求 line_items 帶 product_id）──
    let productId: number | undefined
    try {
      const productRes = await apiContext.post(
        `${BASE_URL}/wp-json/wc/v3/products`,
        {
          headers: authHeaders,
          data: {
            name: '[E2E] Test Product',
            type: 'simple',
            regular_price: '1000',
            status: 'publish',
          },
        },
      )
      if (productRes.ok()) {
        productId = (await productRes.json()).id
        console.log(`[Setup] 共用測試商品已建立: #${productId}`)
      } else {
        console.warn('[Setup] 建立測試商品失敗:', productRes.status(), await productRes.text().catch(() => ''))
      }
    } catch (e) {
      console.warn('[Setup] 建立測試商品出錯:', (e as Error).message)
    }
    // line_items 需帶 product_id（WC 10+），fallback 保留 name/total 供舊站相容
    const lineItem = (name: string, total: string) =>
      productId
        ? { product_id: productId, quantity: 1, total, name }
        : { name, quantity: 1, total }

    // ── 3b. 建立 Gateway 退款測試訂單 ─────────────────────────
    const tradeOrderId = `e2e_trade_${Date.now()}`
    const orderRes = await apiContext.post(
      `${BASE_URL}/wp-json/wc/v3/orders`,
      {
        headers: authHeaders,
        data: {
          status: 'processing',
          payment_method: 'shopline_payment_redirect',
          payment_method_title: 'Shopline Payment 線上付款',
          billing: {
            first_name: '[E2E]',
            last_name: 'GatewayRefund',
            email: 'e2e-gateway-refund@example.com',
            address_1: '[E2E] Test Address',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [lineItem('[E2E] Gateway Refund Product', '1000')],
          meta_data: [
            { key: '_pc_identity', value: 'e2e_test_session_id' },
            { key: '_pc_payment_identity', value: tradeOrderId },
          ],
        },
      },
    )

    if (orderRes.ok()) {
      const order = await orderRes.json()
      testIds.orderId = order.id
      testIds.tradeOrderId = tradeOrderId
      console.log(`[Setup] Gateway 退款測試訂單已建立: #${order.id}（tradeOrderId: ${tradeOrderId}）`)
    } else {
      console.warn('[Setup] 建立 Gateway 退款測試訂單失敗:', orderRes.status(), await orderRes.text().catch(() => ''))
    }

    // ── 3c. 建立手動退款測試訂單 ──────────────────────────────
    const orderManualRes = await apiContext.post(
      `${BASE_URL}/wp-json/wc/v3/orders`,
      {
        headers: authHeaders,
        data: {
          status: 'processing',
          payment_method: 'shopline_payment_redirect',
          payment_method_title: 'Shopline Payment 線上付款',
          billing: {
            first_name: '[E2E]',
            last_name: 'ManualRefund',
            email: 'e2e-manual-refund@example.com',
            address_1: '[E2E] Test Address',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [lineItem('[E2E] Manual Refund Product', '500')],
        },
      },
    )

    if (orderManualRes.ok()) {
      const order2 = await orderManualRes.json()
      testIds.orderIdForManualRefund = order2.id
      console.log(`[Setup] 手動退款測試訂單已建立: #${order2.id}`)
    } else {
      console.warn('[Setup] 建立手動退款測試訂單失敗:', orderManualRes.status())
    }

    // ── 3d. 建立發票測試訂單（尚未開立發票）──────────────────
    const orderInvoiceRes = await apiContext.post(
      `${BASE_URL}/wp-json/wc/v3/orders`,
      {
        headers: authHeaders,
        data: {
          status: 'processing',
          payment_method: 'shopline_payment_redirect',
          payment_method_title: 'Shopline Payment 線上付款',
          billing: {
            first_name: '[E2E]',
            last_name: 'Invoice',
            email: 'e2e-invoice@example.com',
            address_1: '[E2E] Test Address',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [lineItem('[E2E] Invoice Test Product', '800')],
        },
      },
    )

    if (orderInvoiceRes.ok()) {
      const orderInv = await orderInvoiceRes.json()
      testIds.orderIdForInvoice = orderInv.id
      console.log(`[Setup] 發票測試訂單已建立: #${orderInv.id}`)
    } else {
      console.warn('[Setup] 建立發票測試訂單失敗:', orderInvoiceRes.status())
    }

    // ── 3e. 建立已開立發票的訂單（cancel 測試用）──────────────
    const orderWithInvoiceRes = await apiContext.post(
      `${BASE_URL}/wp-json/wc/v3/orders`,
      {
        headers: authHeaders,
        data: {
          status: 'processing',
          payment_method: 'shopline_payment_redirect',
          payment_method_title: 'Shopline Payment 線上付款',
          billing: {
            first_name: '[E2E]',
            last_name: 'CancelInvoice',
            email: 'e2e-cancel-invoice@example.com',
            address_1: '[E2E] Test Address',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [lineItem('[E2E] Cancel Invoice Product', '600')],
          // 預設帶有已開立的發票 meta（模擬已透過 Amego 開立）
          meta_data: [
            {
              key: '_pc_issued_invoice_data',
              value: JSON.stringify({ invoice_number: 'AB-E2E-TEST-001' }),
            },
            { key: '_pc_invoice_provider_id', value: 'amego' },
            {
              key: '_pc_issue_invoice_params',
              value: JSON.stringify({ invoiceType: 'individual', individual: 'cloud' }),
            },
          ],
        },
      },
    )

    if (orderWithInvoiceRes.ok()) {
      const orderWI = await orderWithInvoiceRes.json()
      testIds.orderIdWithInvoice = orderWI.id
      console.log(`[Setup] 已開立發票測試訂單已建立: #${orderWI.id}`)
    } else {
      console.warn('[Setup] 建立已開立發票測試訂單失敗:', orderWithInvoiceRes.status())
    }

    // ── 3f. 建立 LINE Pay 成功付款測試訂單（pending 狀態）───
    const linePayTradeOrderId = `e2e_linepay_${Date.now()}`
    const orderLinePayRes = await apiContext.post(
      `${BASE_URL}/wp-json/wc/v3/orders`,
      {
        headers: authHeaders,
        data: {
          status: 'pending',
          payment_method: 'shopline_payment_redirect',
          payment_method_title: 'Shopline Payment 線上付款',
          billing: {
            first_name: '[E2E]',
            last_name: 'LinePay',
            email: 'e2e-linepay@example.com',
            address_1: '[E2E] LINE Pay Test Address',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [lineItem('[E2E] LINE Pay Test Product', '1000')],
          meta_data: [
            { key: '_pc_payment_identity', value: linePayTradeOrderId },
          ],
        },
      },
    )

    if (orderLinePayRes.ok()) {
      const orderLP = await orderLinePayRes.json()
      testIds.linePayOrderId = orderLP.id
      testIds.linePayTradeOrderId = linePayTradeOrderId
      console.log(`[Setup] LINE Pay 成功付款測試訂單已建立: #${orderLP.id}（tradeOrderId: ${linePayTradeOrderId}）`)
    } else {
      console.warn('[Setup] 建立 LINE Pay 成功付款測試訂單失敗:', orderLinePayRes.status())
    }

    // ── 3g. 建立 LINE Pay 失敗付款測試訂單（pending 狀態）───
    const linePayFailedTradeOrderId = `e2e_linepay_fail_${Date.now()}`
    const orderLinePayFailedRes = await apiContext.post(
      `${BASE_URL}/wp-json/wc/v3/orders`,
      {
        headers: authHeaders,
        data: {
          status: 'pending',
          payment_method: 'shopline_payment_redirect',
          payment_method_title: 'Shopline Payment 線上付款',
          billing: {
            first_name: '[E2E]',
            last_name: 'LinePayFailed',
            email: 'e2e-linepay-failed@example.com',
            address_1: '[E2E] LINE Pay Failed Test Address',
            city: 'Taipei',
            country: 'TW',
          },
          line_items: [lineItem('[E2E] LINE Pay Failed Product', '1000')],
          meta_data: [
            { key: '_pc_payment_identity', value: linePayFailedTradeOrderId },
          ],
        },
      },
    )

    if (orderLinePayFailedRes.ok()) {
      const orderLPF = await orderLinePayFailedRes.json()
      testIds.linePayFailedOrderId = orderLPF.id
      testIds.linePayFailedTradeOrderId = linePayFailedTradeOrderId
      console.log(`[Setup] LINE Pay 失敗付款測試訂單已建立: #${orderLPF.id}（tradeOrderId: ${linePayFailedTradeOrderId}）`)
    } else {
      console.warn('[Setup] 建立 LINE Pay 失敗付款測試訂單失敗:', orderLinePayFailedRes.status())
    }

    await context.close()
  } catch (e) {
    console.warn('[Setup] 建立測試資料時出錯（非致命）:', (e as Error).message)
  } finally {
    await browser.close()
  }

  // ── 4. Save test IDs ─────────────────────────────────────────
  fs.writeFileSync(TEST_IDS_FILE, JSON.stringify(testIds, null, 2))
  console.log('[Setup] Test IDs 已儲存:', JSON.stringify(testIds))
  console.log('[Setup] Global Setup 完成\n')
}

export default globalSetup
