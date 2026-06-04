# language: zh-TW
@ignore @command
功能: 綠界 ECPay 退款
  作為 網站管理員
  我想要 對綠界 ECPay 訂單發起退款
  以便 退還顧客款項

  背景:
    假設 系統中有以下訂單：
      | orderId | total | status     | payment_method | payment_type      |
      | 100     | 1000  | processing | ecpay_aio      | Credit_CreditCard |
      | 110     | 1000  | processing | ecpay_aio      | ATM_TAISHIN       |
      | 200     | 1000  | processing | ecpay_ecpg     | Credit_CreditCard |

  規則: 前置（狀態）- 訂單必須使用綠界 ECPay 付款

    場景: 非綠界訂單不由本 gateway 處理退款
      假設 系統中有訂單 #999，payment_method 為 "shopline_payment_redirect"
      當 管理員對訂單 #999 發起退款
      那麼 綠界 ECPay gateway 不處理該退款

  規則: 信用卡（AIO）訂單支援 API 退款（DoAction Action=R）

    場景: AIO 信用卡訂單全額退款成功
      假設 管理員已登入
      而且 訂單 #100 的 payment_type 為 "Credit_CreditCard"
      當 管理員對訂單 #100 發起退款 1000
      那麼 系統呼叫綠界 DoAction，Action 為 "R"
      而且 退款成功
      而且 訂單 #100 有 order note 記錄退款金額 1000

    場景: AIO 信用卡訂單部分退款成功
      假設 管理員已登入
      而且 訂單 #100 的 payment_type 為 "Credit_CreditCard"
      當 管理員對訂單 #100 發起退款 300
      那麼 系統呼叫綠界 DoAction，Action 為 "R"
      而且 退款成功
      而且 訂單 #100 有 order note 記錄退款金額 300

  規則: 信用卡（ECPG）訂單退款走 ecpayment domain

    場景: ECPG 信用卡訂單退款成功
      假設 管理員已登入
      而且 訂單 #200 的 payment_type 為 "Credit_CreditCard"
      當 管理員對訂單 #200 發起退款 1000
      那麼 系統呼叫綠界退款 API（ecpayment domain）
      而且 退款成功
      而且 訂單 #200 有 order note 記錄退款金額 1000

  規則: 非信用卡訂單不支援 API 退款，須綠界後台人工

    場景: ATM 訂單發起退款時提示人工處理
      假設 管理員已登入
      而且 訂單 #110 的 payment_type 為 "ATM_TAISHIN"
      當 管理員對訂單 #110 發起退款 1000
      那麼 操作失敗，錯誤為 "此付款方式不支援 API 退款，請至綠界商家後台人工處理"
      而且 不呼叫綠界 DoAction
