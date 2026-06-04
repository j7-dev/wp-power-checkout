/**
 * Test Data — power-checkout E2E 測試常數與測試資料
 *
 * 此檔案為所有測試的唯一常數來源，涵蓋：
 * - API 端點
 * - Provider ID
 * - 邊界值字串與數字
 * - 發票類型
 * - SLP 狀態碼
 */

// ─── Base URL ────────────────────────────────────────────────
export const BASE_URL = 'https://local-turbo.powerhouse.tw'

// ─── API Endpoints ───────────────────────────────────────────
export const EP = {
  // Settings (namespace: power-checkout/v1)
  SETTINGS_ALL: 'power-checkout/v1/settings',
  SETTINGS_SINGLE: (id: string) => `power-checkout/v1/settings/${id}`,
  SETTINGS_TOGGLE: (id: string) => `power-checkout/v1/settings/${id}/toggle`,
  SETTINGS_UPDATE: (id: string) => `power-checkout/v1/settings/${id}`,

  // Refund (namespace: power-checkout/v1)
  REFUND: 'power-checkout/v1/refund',
  REFUND_MANUAL: 'power-checkout/v1/refund/manual',

  // Invoice (namespace: power-checkout/v1/invoices)
  INVOICE_ISSUE: (orderId: number | string) =>
    `power-checkout/v1/invoices/issue/${orderId}`,
  INVOICE_CANCEL: (orderId: number | string) =>
    `power-checkout/v1/invoices/cancel/${orderId}`,

  // Webhook (namespace: power-checkout/slp)
  WEBHOOK: 'power-checkout/slp/webhook',

  // 綠界 ECPay 幕後通知 / 前端 API（namespace: power-checkout/ecpay）
  ECPAY_AIO_RETURN: 'power-checkout/ecpay/aio/return',
  ECPAY_AIO_PAYMENT_INFO: 'power-checkout/ecpay/aio/payment-info',
  ECPAY_ECPG_RETURN: 'power-checkout/ecpay/ecpg/return',
  ECPAY_ECPG_CREATE_PAYMENT: 'power-checkout/ecpay/ecpg/create-payment',

  // WooCommerce 內建（建立/操作測試訂單）
  WC_ORDERS: 'wc/v3/orders',
  WC_ORDER: (id: number | string) => `wc/v3/orders/${id}`,
  WC_ORDER_REFUNDS: (orderId: number | string) => `wc/v3/orders/${orderId}/refunds`,
} as const

// ─── Known Provider IDs ──────────────────────────────────────
export const PROVIDERS = {
  SLP: 'shopline_payment_redirect',
  AMEGO: 'amego',
  // 綠界 ECPay
  ECPAY_AIO: 'ecpay_aio',     // 全方位金流（導轉式，CheckMacValue SHA256）
  ECPAY_ECPG: 'ecpay_ecpg',   // 站內付 2.0（內嵌式，AES-128-CBC）
  ECPAY_INVOICE: 'ecpay',     // 綠界電子發票（B2C/B2B，AES；option_name=woocommerce_ecpay_settings）
} as const

// ─── 綠界 ECPay 付款方式（ChoosePayment，依 erm.dbml Enum ecpay_payment_method）──
export const ECPAY_PAYMENT_METHODS = {
  CREDIT: 'Credit',
  CREDIT_INSTALLMENT: 'CreditInstallment',
  PERIOD: 'Period',
  WEB_ATM: 'WebATM',
  ATM: 'ATM',                 // 取號後繳費，無 API 退款
  CVS: 'CVS',                 // 取號後繳費，無 API 退款
  BARCODE: 'BARCODE',         // 取號後繳費，無 API 退款
  APPLE_PAY: 'ApplePay',
} as const

// ─── 綠界 AIO RtnCode（CMV 類，一律字串）──────────────────────
export const ECPAY_AIO_RTN_CODE = {
  PAID_SUCCESS: '1',          // 付款成功 → processing
  ATM_GET_CODE: '2',          // ATM 取號成功（尚未付款）→ 不改狀態
  CVS_GET_CODE: '10100073',   // CVS/BARCODE 取號成功（尚未付款）→ 不改狀態
} as const

// ─── 綠界付款結果通知純文字回應 ──────────────────────────────
export const ECPAY_OK_RESPONSE = '1|OK'

// ─── Invoice Types（依 specs/features/invoice）──────────────
export const INVOICE_TYPE = {
  INDIVIDUAL: 'individual',
  COMPANY: 'company',
  DONATE: 'donate',
} as const

export const INDIVIDUAL_TYPE = {
  CLOUD: 'cloud',
  BARCODE: 'barcode',
  MOICA: 'moica',
  PAPER: 'paper',
} as const

// ─── Payment Methods（依 specs/erm.dbml）────────────────────
export const PAYMENT_METHODS = {
  CREDIT_CARD: 'CreditCard',
  VIRTUAL_ACCOUNT: 'VirtualAccount',   // ATM，不支援退款
  JKO_PAY: 'JKOPay',
  APPLE_PAY: 'ApplePay',
  LINE_PAY: 'LinePay',
  CHAILEASE: 'ChaileaseBNPL',          // 中租零卡，僅全額退款
} as const

// ─── SLP Response Statuses（依 specs/erm.dbml）──────────────
export const SLP_STATUS = {
  CREATED: 'CREATED',
  CUSTOMER_ACTION: 'CUSTOMER_ACTION',
  PROCESSING: 'PROCESSING',
  PENDING: 'PENDING',
  SUCCEEDED: 'SUCCEEDED',
  EXPIRED: 'EXPIRED',
  FAILED: 'FAILED',
  CANCELLED: 'CANCELLED',
} as const

// ─── Provider Mode ───────────────────────────────────────────
export const PROVIDER_MODE = {
  TEST: 'test',
  PROD: 'prod',
} as const

// ─── Order Status（依 WooCommerce 標準）──────────────────────
export const ORDER_STATUS = {
  PENDING: 'pending',
  PROCESSING: 'processing',
  ON_HOLD: 'on-hold',
  COMPLETED: 'completed',
  CANCELLED: 'cancelled',
  REFUNDED: 'refunded',
  FAILED: 'failed',
} as const

// ─── Edge Case Strings（邊緣值測試用）───────────────────────
export const EDGE = {
  // XSS 注入
  XSS_SCRIPT: '<script>alert("xss")</script>',
  XSS_IMG: '<img src=x onerror=alert(1)>',
  XSS_SVG: '<svg/onload=alert(1)>',
  XSS_ENCODED: '&lt;script&gt;alert(1)&lt;/script&gt;',

  // SQL Injection
  SQL_DROP: "'; DROP TABLE wp_options; --",
  SQL_OR: "1' OR '1'='1",
  SQL_SELECT: "1; SELECT * FROM wp_users --",
  SQL_UNION: "1 UNION SELECT * FROM wp_users --",

  // Path traversal
  PATH_TRAVERSAL: '../../wp-config.php',

  // Unicode 與多語言
  UNICODE_CJK: '測試中文字串',
  UNICODE_JAPANESE: 'テスト日本語',
  UNICODE_KOREAN: '한국어 테스트',
  RTL_ARABIC: 'مرحبا بالعالم',

  // Emoji
  EMOJI_SIMPLE: '🎉🚀💰',
  EMOJI_COMPLEX: '👨‍👩‍👧‍👦🏳️‍🌈',
  EMOJI_PAYMENT: '💳💴🏦',

  // 空值與空白
  EMPTY_STRING: '',
  WHITESPACE_ONLY: '   ',
  NEWLINE_ONLY: '\n\r\n',
  NULL_BYTE: 'test\x00null',

  // 超長字串
  VERY_LONG_STRING: 'A'.repeat(10_000),
  LONG_UNICODE: '中'.repeat(5_000),

  // 特殊符號
  SPECIAL_CHARS: '!@#$%^&*()_+-=[]{}|;:\'",.<>?/`~',
  HTML_ENTITIES: '&amp;&lt;&gt;&quot;&#39;',

  // 數值邊界
  ZERO: 0,
  NEGATIVE: -1,
  NEGATIVE_LARGE: -999,
  FLOAT_TINY: 0.001,
  FLOAT_HALF: 0.5,
  MAX_SAFE_INT: Number.MAX_SAFE_INTEGER,
  MAX_INT32: 2_147_483_647,
  HUGE_NUMBER: 999_999_999_999,
} as const

// ─── Test Order Constants ────────────────────────────────────
export const TEST_ORDER = {
  TOTAL: '1000',
  TOTAL_NUM: 1000,
  STATUS_PROCESSING: 'processing',
  STATUS_PENDING: 'pending',
  NONEXISTENT_ID: 9_999_999,   // 不存在的訂單 ID
  NEGATIVE_ID: -1,
  ZERO_ID: 0,
  FLOAT_ID: 1.5,
  STRING_ID: 'abc',
} as const

// ─── Test IDs File ───────────────────────────────────────────
import * as path from 'path'
export const TEST_IDS_FILE = path.resolve(
  import.meta.dirname,
  '../.auth/test-ids.json',
)

export interface TestIds {
  // 基本退款測試訂單（processing 狀態，SLP 付款）
  orderId?: number
  // 手動退款測試訂單（processing 狀態）
  orderIdForManualRefund?: number
  // 發票測試訂單（processing 狀態，尚未開立發票）
  orderIdForInvoice?: number
  // 已開立發票的訂單（含 _pc_issued_invoice_data meta）
  orderIdWithInvoice?: number
  // tradeOrderId（Webhook 測試用，與 orderId 對應）
  tradeOrderId?: string
  // LINE Pay 成功付款測試訂單（pending 狀態）
  linePayOrderId?: number
  linePayTradeOrderId?: string
  // LINE Pay 失敗付款測試訂單（pending 狀態）
  linePayFailedOrderId?: number
  linePayFailedTradeOrderId?: string
  // 綠界 AIO 信用卡訂單（pending，含 _pc_ecpay_trade_no）— callback / 退款測試用
  ecpayAioOrderId?: number
  ecpayAioTradeNo?: string
  // 綠界 ECPG 信用卡訂單（pending，含 _pc_ecpay_trade_no）
  ecpgOrderId?: number
  ecpgTradeNo?: string
  [key: string]: unknown
}

import * as fs from 'fs'

export function loadTestIds(): TestIds {
  try {
    return JSON.parse(fs.readFileSync(TEST_IDS_FILE, 'utf-8'))
  } catch {
    return {}
  }
}
