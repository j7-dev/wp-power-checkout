# einvoice 第四階段-c：Amego provider 正規化錯誤模型 + 補齊缺漏測試 ✅ 完成

## 狀態
完成（2026-06-18）。照 `mem:einvoice-ezpay-error-model-template` 套到 Amego（4 家中失敗處理最弱者）。本階段除改造外另**補齊缺漏錯誤測試**（用戶 Q4 指定）。

## Gate 結果（只跑 scoped PHPStan，禁跑 wp-env PHPUnit 撞 DB）
- Scoped PHPStan `inc/classes/Domains/Invoice/Amego`：EXIT 1，但唯一 error 是 `?:?:Ignored error pattern #Using nullsafe method call on non-nullable type# was not matched`——**scoping 假象**（global ignore pattern 在 Invoice-only 子樹無 match，`reportUnmatchedIgnoredErrors` 預設 true），**0 個 Amego file:line 真錯**。等同 ezPay phase 的「baseline 1 error」。整個 Invoice 子樹 / Amego+Ecpg 子樹都同樣 1 假象。full-tree（paths: inc/）才會 match→clean。用 `--error-format=raw` 確認唯一錯在 `?:?:`。
- PHP lint：6 檔全 clean。PHPCS：4 source + 2 test 全 clean（test 2 個 alignment warning 已 PHPCBF）。

## 變更檔案（嚴格 scoped 到 Amego；shared 凍結未碰）
- 新 `Amego/Http/AmegoApiException.php`：typed exception，KIND_BUSINESS/NETWORK/DECODE（**無 KIND_SIGNATURE**——Amego 簽章失敗是 business code=16，由 map_error 映 SIGNATURE）。
- 改 `Amego/Shared/Helpers/Requester.php`（**Requester 是 Amego 的 Http client 層**，非 ApiClient；wp_remote_post+decode+業務檢查都在這、且原本 catch→null）：
  - post()/post_data()/query() 入口 reset $last_error_detail；catch 落地 to_error_detail()。
  - send() 內 wp_remote_post WP_Error→丟 KIND_NETWORK；新 build_response_dto() code≠0→丟 KIND_BUSINESS 帶 raw_code=(string)code。
  - query() WP_Error→KIND_NETWORK；新 decode_query_body() code≠0→KIND_BUSINESS。
  - 新 `public static ?array $mock_error_override`（測試注入；mock 路徑經 decode_mock_response/decode_query_body 走 build → 觸發 business）。
  - 新 get_last_error_detail()。既有 ?DTO/?array 回 null 契約不變。
  - ⚠️ mock 路徑用 `$this->decode_mock_response()` 不是 `self::`（它是 instance method 用 $this->build_response_dto）。
- 改 `Amego/Http/ApiClient.php`（薄 wrapper）：+get_last_error_detail() 委派給注入的 Requester。
- 改 `Amego/Services/AmegoProvider.php`：5 方法 `: array|\WP_Error`；`?? []`/`return []` → NormalizedError；補顯式 try/catch（**Amego 原本沒有**，這是重點）；新 map_error/error_from_client/unknown_error/user_message/build_dispatch_params/starts_with。
- 新 test `AmegoInvoiceErrorMapTest.php`（18 tests，class-level @group amego error）。
- 改 test `AmegoAllowanceTest.php`（4 處 assertSame([]) → WP_Error+code：金額0/超額→VALIDATION、未開立→NOT_FOUND、無折讓→NOT_FOUND；契約演進非退化）。

## map_error 涵蓋的 Amego 錯誤碼（依 amego-invoice skill error-codes.md）
數字字串碼，match(true)：
- 15/16(Time/簽章)→SIGNATURE；11/12/13/14/19/22(統編/認證/停權/未申請API/IP/未啟用)→AUTH；10/18/21(停機/DB/人數)→NETWORK。
- 開立 3040111/3040191(字軌不足/無法取下一張)→NUMBER_EXHAUSTED；3040171(OrderId重複)→CONFLICT。
- 作廢 3050121/22/23/31/3050141(開立中/已作廢/已註銷/等待/已開折讓)→CONFLICT；3050125(發票不存在)→NOT_FOUND。
- 折讓 4040161/62/4050131/32/41→CONFLICT；4040156/4050134→NOT_FOUND。
- 查詢短碼 71(查無)→NOT_FOUND。
- 17/20/23/51 + str_starts_with(30401/30501/40401/40501)→VALIDATION。
- 其餘（含查詢 99）→PROVIDER（fallthrough）。

## ⚠️ 踩雷 / 與 ezPay 模板差異
1. Amego **無獨立 SIGNATURE kind**（不像 ezPay CheckCode）。簽章失敗=業務 code=16，走 business→map_error→SIGNATURE。故測 SIGNATURE 用注入 code=16，**不是**設錯金鑰。
2. **DTO base validate() 讀 `$require_properties`（有 e）不是子類的 `$required_properties`（有 d）**——IssueInvoiceResponseDTO 的 required_properties=['code','msg'] 其實**從未被強制**。注入缺 code 時 DTO 建得起來，但 is_success() 存取未初始化 typed int $code → 拋 \Error（已用 probe 證實）→ Requester catch（kind=decode）→ PROVIDER。never-throw 測試靠此。
3. **issued_data 鍵名 = invoice_number/invoice_time/random_number**（非 ezPay 的 random_num/invoice_date）；allowance_data = allowance_number（非 allowance_no）。
4. B2B happy 用 companyId **04595257**（已用真實 validator 演算法驗 VALID；12345678/87654321 皆 INVALID）。
5. ApiClient::issue() 即使 response null 仍 update_provider_id（line 54 在 if 外）——pre-existing，未動；測試斷言 issued_data 空（update_issued_data 在 if 內）故 OK。
6. build_dispatch_params 在 provider try **之外**（照 ezPay 模板）；理論上 CheckoutInvoiceParams::create 的 EInvoiceType::from 會對偽造 invoiceType 拋 \ValueError 逃逸——但實務 issue_params 已過 checkout 表單驗證，realistic 輸入安全。ezPay 同結構。

## 後續（主窗口）
三家平行（Ecpay/Ezpay/Paynow/Amego）；主窗口跑合併 gate（full-tree PHPStan + PHPUnit）。第五階段才改 InvoiceApiService REST is_wp_error 映射 + ProviderRegister auto-issue wrapper（本階段未碰 REST/hook）。
