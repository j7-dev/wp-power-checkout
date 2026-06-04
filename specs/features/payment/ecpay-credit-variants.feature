# language: zh-TW
@ignore @command
功能: 綠界 ECPay 信用卡分期與定期定額
  作為 網站訪客
  我想要 以信用卡分期或定期定額付款
  以便 分攤費用或訂閱扣款

  背景:
    假設 "ecpay_aio" 已啟用
    而且 ECPay AIO mode 為 "test"
    而且 系統中有以下訂單：
      | orderId | total |
      | 100     | 12000 |

  規則: 前置（參數）- 分期付款 CreditInstallment 必須為後台允許期數

    場景: 後台啟用 3/6/12 期時送出 6 期分期
      假設 ECPay AIO installment_periods 為 [3,6,12]
      而且 訂單 #100 顧客選擇 6 期分期
      當 WooCommerce 組裝建單參數
      那麼 ChoosePayment 為 "Credit"
      而且 CreditInstallment 為 "6"

    場景: 顧客選擇未啟用的期數時建單失敗
      假設 ECPay AIO installment_periods 為 [3,6,12]
      而且 訂單 #100 顧客選擇 24 期分期
      當 WooCommerce 組裝建單參數
      那麼 操作失敗，錯誤為 "分期期數不在允許範圍"

  規則: 前置（參數）- 定期定額必須提供 PeriodAmount / PeriodType / Frequency / ExecTimes

    場景: 建立每月扣款定期定額訂單
      假設 ECPay AIO period_config 為 PeriodType "M"、Frequency 1、ExecTimes 12
      而且 訂單 #100 顧客選擇定期定額
      當 WooCommerce 組裝建單參數
      那麼 ChoosePayment 為 "Credit"
      而且 PeriodAmount 為 12000
      而且 PeriodType 為 "M"
      而且 Frequency 為 1
      而且 ExecTimes 為 12

    場景: 定期定額缺 ExecTimes 時建單失敗
      假設 ECPay AIO period_config 未設定 ExecTimes
      而且 訂單 #100 顧客選擇定期定額
      當 WooCommerce 組裝建單參數
      那麼 操作失敗，錯誤為 "定期定額參數不完整"
