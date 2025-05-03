


## 金流商回傳的資料統一紀錄在 `pp_gateway_response_params` order_meta

不再區分相同訂單不同金流付款的回傳，正常的訂單不會一直重新付款

除錯需求的話，order_note 還有 wc log 也都會有紀錄每次的回傳值

## 透過訂單取得 Payment Gateway Class 用 `wc_get_payment_gateway_by_order`