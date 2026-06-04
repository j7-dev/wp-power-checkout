/**
 * P0 — 綠界 ECPay 加密 helper 對官方 test vector 驗證（無需 WP，純單元驗證）
 *
 * 確保 E2E callback 測試所依賴的簽章 helper 與綠界後端產出一致：
 * - CheckMacValue（SHA256）：對 ECPay-API-Skill 官方 test vector 比對
 * - AES-128-CBC：對官方 AES test vector 比對 + 加解密往返 + 巢狀 OrderInfo / 整數 RtnCode 保真
 *
 * 來源：.claude/skills/ECPay-API-Skill/test-vectors/checkmacvalue.json
 *       .claude/skills/ECPay-API-Skill/test-vectors/aes-encryption.json
 *
 * 此檔不呼叫任何 WP API，可在無運行中 WordPress 的環境直接執行，
 * 作為其餘 ECPay E2E callback 測試的「簽章正確性前置保證」。
 */
import { test, expect } from '@playwright/test'
import {
  generateCheckMacValue,
  withCheckMacValue,
  ECPAY_AIO_TEST,
} from '../helpers/ecpay-checkmacvalue.js'
import {
  aesEncrypt,
  aesDecrypt,
  ECPAY_INVOICE_TEST,
  ECPAY_ECPG_TEST,
} from '../helpers/ecpay-aes.js'

test.describe('綠界 CheckMacValue helper（對官方 vector）', () => {
  test('官方 vector 1：標準 AIO 金流基線', () => {
    const cmv = generateCheckMacValue(
      {
        MerchantID: '3002607',
        MerchantTradeNo: 'Test1234567890',
        MerchantTradeDate: '2025/01/01 12:00:00',
        PaymentType: 'aio',
        TotalAmount: '100',
        TradeDesc: '測試',
        ItemName: '測試商品',
        ReturnURL: 'https://example.com/notify',
        ChoosePayment: 'ALL',
        EncryptType: '1',
      },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    expect(cmv).toBe(
      '291CBA324D31FB5A4BBBFDF2CFE5D32598524753AFD4959C3BF590C5B2F57FB2',
    )
  })

  test("官方 vector：特殊字元 '（單引號）", () => {
    const cmv = generateCheckMacValue(
      { MerchantID: '3002607', ItemName: "Tom's Shop", TotalAmount: '100' },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    expect(cmv).toBe(
      'CF0A3D4901D99459D8641516EC57210700E8A5C9AB26B1D021301E9CB93EF78D',
    )
  })

  test('官方 vector：特殊字元 ~（波浪號）', () => {
    const cmv = generateCheckMacValue(
      { MerchantID: '3002607', ItemName: 'Test~Product', TotalAmount: '200' },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    expect(cmv).toBe(
      'CEEAE01D2F9A8E74D4AC0DCE7735B046D73F35A5EC99558A31A2EE03159DA1C9',
    )
  })

  test('官方 vector：空格須編碼為 +（非 %20）', () => {
    const cmv = generateCheckMacValue(
      { MerchantID: '3002607', ItemName: 'My Test Product', TotalAmount: '300' },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    expect(cmv).toBe(
      '7712A5E6EDC3B57086063C88568084C66CE882A21D40E74DE5ACA3B478C6F316',
    )
    // 若誤用 %20 會得到錯誤值
    expect(cmv).not.toBe(
      '13F7A6B69BF856B5203212AC5F3202B6140D8E2B4316A62851712BF2AF7812D0',
    )
  })

  test('官方 vector：模擬付款完成 callback 驗章', () => {
    const cmv = generateCheckMacValue(
      {
        MerchantID: '3002607',
        MerchantTradeNo: 'Test1234567890',
        RtnCode: '1',
        RtnMsg: 'Succeeded',
        TradeNo: '2301011234567890',
        TradeAmt: '100',
        PaymentDate: '2025/01/01 12:05:00',
        PaymentType: 'Credit_CreditCard',
        TradeDate: '2025/01/01 12:00:00',
        SimulatePaid: '0',
      },
      ECPAY_AIO_TEST.hashKey,
      ECPAY_AIO_TEST.hashIv,
    )
    expect(cmv).toBe(
      '2AB536D86AFF8E1086744D59175040A32538C96B1C28C4135B551BD728E913B8',
    )
  })

  test('withCheckMacValue 附加之 CMV 可被自身重新計算驗證（round-trip）', () => {
    const params = {
      MerchantID: '3002607',
      MerchantTradeNo: 'EC100ABCDEF',
      RtnCode: '1',
      RtnMsg: '交易成功',
    }
    const signed = withCheckMacValue(params, ECPAY_AIO_TEST.hashKey, ECPAY_AIO_TEST.hashIv)
    const { CheckMacValue, ...rest } = signed
    const recomputed = generateCheckMacValue(rest, ECPAY_AIO_TEST.hashKey, ECPAY_AIO_TEST.hashIv)
    expect(recomputed).toBe(CheckMacValue)
  })
})

test.describe('綠界 AES-128-CBC helper（對官方 vector）', () => {
  test('官方 vector 1：發票帳號加密（插入順序 JSON）', () => {
    const enc = aesEncrypt(
      { MerchantID: '2000132', BarCode: '/1234567' },
      ECPAY_INVOICE_TEST.hashKey,
      ECPAY_INVOICE_TEST.hashIv,
    )
    expect(enc).toBe(
      'XeEOdHpTRvxKEqs/JD9RSd16s7VtpyWVCN6AV44pKTW3DVa6yI7vKmjBRp2eulDhXoru/qBqFDBH3fEqlkMn3bbJfJBfGAq+v+SvttutYnc=',
    )
  })

  test('官方 vector：解密反向驗證', () => {
    const dec = aesDecrypt(
      'XeEOdHpTRvxKEqs/JD9RSd16s7VtpyWVCN6AV44pKTW3DVa6yI7vKmjBRp2eulDhXoru/qBqFDBH3fEqlkMn3bbJfJBfGAq+v+SvttutYnc=',
      ECPAY_INVOICE_TEST.hashKey,
      ECPAY_INVOICE_TEST.hashIv,
    )
    expect(dec).toEqual({ MerchantID: '2000132', BarCode: '/1234567' })
  })

  test('官方 vector：ECPG 金流帳號加密（GetTokenbyTrade 請求格式）', () => {
    const enc = aesEncrypt(
      { MerchantID: '3002607', RespondType: 'JSON' },
      ECPAY_ECPG_TEST.hashKey,
      ECPAY_ECPG_TEST.hashIv,
    )
    expect(enc).toBe(
      'udqjXgM+7Q6lCrrculcvzUFnN5zv0ibax1glKFxrORoO0sl6pcoib/QDYPKCAP57ME4+3Yo84XmyabVFnxriMTuy9JK/RXS7DtEOvF+PUoU=',
    )
  })

  test('ECPG ReturnURL 業務資料往返：巢狀 OrderInfo + 整數 RtnCode 保真', () => {
    const biz = {
      RtnCode: 1, // ⚠️ ECPG 為整數（與 AIO 字串不同）
      RtnMsg: '交易成功',
      OrderInfo: { MerchantTradeNo: 'EC100ABCDEF', TradeNo: '2306010000001', TradeAmt: 1000 },
    }
    const decrypted = aesDecrypt(
      aesEncrypt(biz, ECPAY_ECPG_TEST.hashKey, ECPAY_ECPG_TEST.hashIv),
      ECPAY_ECPG_TEST.hashKey,
      ECPAY_ECPG_TEST.hashIv,
    )
    expect(decrypted).toEqual(biz)
    expect(typeof (decrypted as { RtnCode: unknown }).RtnCode).toBe('number')
    expect(
      ((decrypted as { OrderInfo: { MerchantTradeNo: string } }).OrderInfo).MerchantTradeNo,
    ).toBe('EC100ABCDEF')
  })
})
