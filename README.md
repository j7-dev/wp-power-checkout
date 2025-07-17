
讓結帳頁，為你的轉換率加上渦輪
從金流、物流到畫面設計，全面優化 WordPress 的結帳體驗

# 代辦事項

1. 需要支援 HPOS
2. 需要支援新版本結帳頁

##  每次付款請求，不論是哪種付款方式，都將請求參數、回應參數 raw data 儲存在 order meta 中

/** @var string 請求參數 meta_key */
const REQUEST_KEY = 'pc_payment_req_params';

/** @var string 回應參數 meta_key */
const RESPONSE_KEY = 'pc_payment_res_params';



不再區分相同訂單不同金流付款的回傳，正常的訂單不會一直重新付款

除錯需求的話，order_note 還有 wc log 也都會有紀錄每次的回傳值

## 透過訂單取得 Payment Gateway Class 用 `wc_get_payment_gateway_by_order`