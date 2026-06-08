# 藍新 NewebPay MPG 金流整合（gateway ID: newebpay_mpg）

導轉式金流第四個 provider，1:1 對應 EcpayAIO 骨架。完成於 feat/ecpay-gateway-integration 分支。
唯一 API reference：`.claude/skills/newebpay-mpg/`（NDNF-1.2.2，MPG 2.3）。

## 核心檔案
- domain 根：`inc/classes/Domains/Payment/NewebpayMpg/`（13 class 檔）
- 註冊：`Payment/ProviderRegister.php` $gateway_services 加一行
- 前端：`inc/assets/blocks/newebpay_mpg.tsx`、`js/src/pages/Payments/NewebpayMpg/`、router `ROUTER_MAPPER.newebpay_mpg`
- 測試：`inc/tests/Domains/Payment/NewebpayMpg/`（6 檔）

## 三種雜湊（最易混淆，絕不可混用）— 都在 TradeInfoCrypto
1. TradeSha（整包驗章）：SHA256("HashKey={K}&{hex}&HashIV={IV}") 大寫
2. CheckCode（callback 結果驗章）：順序 HashIV,Amt,MerchantID,MerchantOrderNo,TradeNo,HashKey；key 名 HashIV/HashKey
3. CheckValue（QueryTradeInfo）：順序 IV,Amt,MerchantID,MerchantOrderNo,Key；key 名 IV/Key（非 HashIV/HashKey）

## 加密
TradeInfoCrypto = AES-256-CBC（key padEnd(32)/IV padEnd(16)/PKCS#7/**hex**）。
⚠️ 不可複用 Ecpg\AesCrypto（那是 AES-128/base64）。

## Envelope 差異
- MPG 建單：{ MerchantID, TradeInfo, TradeSha, Version }（TradeInfo value 須先 rawurlencode）
- Close/Cancel（DoActionClient）：{ MerchantID_, PostData_ }（尾底線，PostData AES-256 hex），Close Version=1.1 CloseType=1請款/2退款，Cancel Version=1.0
- EWallet refund（EWalletRefundClient）：明文 form { MerchantID, TradeNo, Amt, PaymentType }，路徑 /API/EWallet/refund（小寫 r）

## 狀態判定
付款成功 = Status==='SUCCESS' && Result.RespondCode==='00'（StatusManager）。
offline 取號（VACC/CVS/BARCODE）= Status SUCCESS 但 RespondCode≠00 → 寫 _pc_newebpay_payment_info 不改狀態。
金額防竄改：Result.Amt ≠ ceil(order total) → 維持 pending + 告警。
NotifyURL 為真實來源；ReturnURL 僅 UX。所有 callback 失敗分支一律回 HTTP 200（避免重送風暴）。

## REST namespace
power-checkout/newebpay（與綠界 ecpay 區隔）：POST /mpg/notify、POST /mpg/return（皆 permission_callback __return_true，驗章於內）

## 退款分流（依存 meta 的 PaymentType，非前端）
CREDIT→Close CloseType=2；LINEPAY/TAIWANPAY/ESUNWALLET→EWallet；VACC/WEBATM/CVS/BARCODE/APPLEPAY/TWQR/AFTEE→WP_Error('refund_unsupported')

## Order meta keys
_pc_newebpay_order_no（MerchantOrderNo 冪等鍵，反查訂單主鍵）/_pc_newebpay_trade_no（藍新 TradeNo）/_pc_newebpay_payment_detail/_pc_newebpay_payment_info/_pc_newebpay_credit_variant/_pc_newebpay_installment/_pc_newebpay_capture_status

## 後台訂單操作（woocommerce_order_actions）
重新查詢付款狀態（pc_newebpay_mpg_query，所有 MPG 訂單）+ 請款 pc_newebpay_mpg_capture / 取消授權 pc_newebpay_mpg_cancel_auth（僅信用卡）

## 未實作（限制條件）
AES-256-GCM（僅預留 encryptType 欄位，固定送 CBC）、AFTEE BNPL、定期定額、Token 綁卡、CVSCOM 超商取貨

## 測試環境限制（重要）
本機 LocalWP MySQL 的帳密/socket CLI 無法存取，且無 wp-phpunit 測試 WP 安裝（C:\Windows\TEMP/wordpress/ 不存在）→ `composer test` 在 bootstrap 即 fatal（既有 SLP/ATM 測試同樣卡此）。
驗證改用：(1) PHPStan level 9（`php -d memory_limit=2G vendor/bin/phpstan analyse`，單跑會誤報 unmatched ignore pattern，須跑全專案）(2) PHPCS（須 2G 記憶體跑 phpstan）(3) 離線 PHP harness（stub WP/WC 驗純邏輯，crypto/StatusManager/退款分流/邊界）。
PHPStan baseline 既有 1 error：ShoplinePayment/DTOs/RedirectSettingsDTO.php:148（非本次工作，勿動）。
