# einvoice 第四階段-d：PayNow 發票 provider 正規化錯誤模型 ✅ 完成

## 狀態
完成（2026-06-18）。PayNow 發票（paynow_invoice，Bearer JWT、無對稱加密）照 ezPay 模板（見 `mem:einvoice-ezpay-error-model-template`）改造完畢。第 4 家 provider，4 家全到位。

## Gate 結果（只跑 scoped PHPStan，未跑 PHPUnit — 三家平行禁撞測試 DB）
- Scoped PHPStan PayNow 子樹 = **0 真實 error**。
  - 預設跑 EXIT 1，唯一輸出是 `?:?:Ignored error pattern #Using nullsafe...# was not matched`——**scoped 假象**（phpstan.neon 全域 ignore pattern 對應碼不在 PayNow 子樹）。對照組：完全未改的 Ezpay 子樹 scoped 跑也冒**完全相同**這條，證明與 PayNow 程式碼無關。
  - 加臨時 config `reportUnmatchedIgnoredErrors:false`（includes 用絕對路徑指 phpstan.neon）→ `[OK] No errors` EXIT 0。
- PHP lint 5 檔全綠。PHPCS **0 error** + 3 warning（ErrorMapTest 的 putenv，測試切 API_MODE 慣例，非 gating；既有 PaynowInvoiceApiClientMockTest 同樣用）。

## 變更檔案（3 source + 2 test）
- 新 `Paynow/Http/PaynowInvoiceApiException.php`：typed exception，raw_code/raw_message/kind（KIND_SIGNATURE/BUSINESS/NETWORK/DECODE）。⚠️ PayNow 發票無數字錯誤碼表，**raw_code = 外層 type 值**（validation_error/rejected/failed）。
- 改 `Paynow/Http/InvoiceApiClient.php`：
  - decode_result() type≠success 改丟 PaynowInvoiceApiException（raw_code=type、kind=business）；handle_response() WP_Error→kind=network。
  - 5 業務方法入口 reset `$last_error_detail=null`；post()/get() catch 落地 `to_error_detail()`。
  - 新 `public static ?array $mock_error_override`（mock_response() 優先回它，鍵 type/message/result）。
  - 新 `get_last_error_detail():?array{raw_code,raw_message,raw,kind}`。
  - 既有 ?IssueResponse/?AllowanceResponse/?QueryResponse 回 null 契約**不變**（既有 ApiClientMockTest 全綠）。decode_result type非success 仍 is-a RuntimeException（既有 expectException(\RuntimeException) 過）。
- 改 `Paynow/Services/PaynowInvoiceProvider.php`：5 方法回 `: array|\WP_Error`；新 map_error/message_matches/error_from_client/unknown_error/user_message/build_dispatch_params；issue() 第一步接 validate_for_dispatch。
- 新 test `PaynowInvoiceErrorMapTest.php`（24 tests，class-level `@group integration invoice paynow error`）。
- 改 test `PaynowInvoiceProviderTest.php`：6 處 assertSame([])→WP_Error+code（契約演進）；B2B happy companyId 87654321→04595257（UBN checksum）。

## ⚠️ PayNow 專屬踩雷（與 ezPay 模板的差異，給後續維護避雷）
1. **PayNow 發票無數字錯誤碼對照表**（官方 GAP，error-codes.md §10 明載），僅外層 `type`+`message`。故 map_error 形態異於 ezPay：
   - `$raw_code` = 外層 type（validation_error→VALIDATION）。
   - `$raw_message`(message) 做**關鍵字補強分類**（message_matches helper，大小寫不敏感、中英關鍵字）：JWT/token/認證/金鑰/簽章→AUTH；duplicate/already/重複/已開立/已作廢/已折讓→CONFLICT；exhaust/用罄/字軌→NUMBER_EXHAUSTED；not found/查無/不存在→NOT_FOUND；其餘 rejected/failed→PROVIDER。
   - match(true) 順序：validation_error→AUTH→CONFLICT→NUMBER_EXHAUSTED→NOT_FOUND→default PROVIDER。「已用罄」不含 CONFLICT 整詞（已開立/已作廢/已折讓），故正確落 NUMBER_EXHAUSTED。
   - PayNow 純 Bearer JWT、無對稱簽章 → **SIGNATURE 正常不出現**（保留 KIND_SIGNATURE 常數對齊模板）；認證類一律 AUTH。
2. **never-throw 修正 ezPay 模板缺陷**：ezPay 模板把 `$order=OrderUtils::get_order()` + 冪等放 try **外**——但 `OrderUtils::get_order()` 對不存在訂單 **throw \Exception**（OrderUtils.php:108）。ezPay 沒測「訂單不存在」所以沒踩。**PayNow 既有測試有 2 條訂單不存在案例**（issue/query_invoice 傳 9999999），故 PayNow 5 方法一律把 order 解析 + 冪等移進 try，catch 傳 `$order ?? null`，`unknown_error` 簽名改 `?\WC_Order`（null 時只記 log 不記 order note）→ 訂單不存在回 UNKNOWN 不拋。**後續 Amego/Ecpay 若也有訂單不存在測試須同樣處理。**
3. **既有 ProviderTest 契約演進改點**（assertSame([])→WP_Error）：issue 訂單不存在→UNKNOWN；載具捐贈互斥→VALIDATION（dispatch 攔）；零稅率缺reason→PROVIDER（IssueParams::create 在 client from_order throw→kind=decode→PROVIDER，**非** VALIDATION，因零稅率原因不在 dispatch 三項內）；issue_allowance/query 未開立→NOT_FOUND；invalid_allowance 無折讓→NOT_FOUND；query 訂單不存在→UNKNOWN。
4. **group 名 = `paynow`（非 paynow_invoice）**：既有 PaynowInvoiceProviderTest/ApiClientMockTest class-level group 都是 `integration invoice paynow`（金流發票共用）。新 ErrorMapTest 跟隨用 `paynow`+`error`。@group 放 class docblock（踩雷1 of ezPay）。
5. **錯誤注入走 issue 端點最乾淨**（同 ezPay）。cancel CONFLICT/issue_allowance NOT_FOUND 等前置攔截測試不需 client 注入（provider 層直接攔）。business 注入測試 create_order 不帶 issue_params→dispatch 通過→進 client。NETWORK 測試用 putenv(API_MODE=sandbox_test_only)+pre_http_request WP_Error。
6. **PROVIDER fallback 路徑**：type=success 但 result 缺 invoice_number → IssueResponse is_success=false → client 回 null **但 last_error_detail 仍 null**（無例外）→ error_from_client `?? [kind=decode]` → PROVIDER。此即 never-throw + PROVIDER fallback 雙覆蓋測試。

## map_error 涵蓋的 PayNow type/碼清單
type=validation_error→VALIDATION；message 關鍵字：認證(jwt/token/unauthorized/forbidden/認證/授權/權限/金鑰/簽章)→AUTH、衝突(duplicate/already/exist/重複/已開立/已作廢/已折讓/已存在)→CONFLICT、字軌(exhaust/用罄/用完/字軌/號碼不足)→NUMBER_EXHAUSTED、查無(not found/notfound/查無/不存在/找不到)→NOT_FOUND；其餘(rejected/failed 無關鍵字)→PROVIDER。client kind 分流：network→NETWORK、decode/無明細→PROVIDER、signature→SIGNATURE（保留未用）。

## 後續（主窗口）
4 家 provider（ezpay/ecpay/amego/paynow）error-map 全到位。第五階段才改 InvoiceApiService REST is_wp_error 映射 + ProviderRegister auto-issue/cancel wrapper + maybe_issue_allowance_on_refund is_wp_error 分支。本階段未碰 REST/hook（ProviderRegister 第188/196行仍直掛 [$provider,'issue'/'cancel']，WC action hook 不消費回傳值故 array|WP_Error 不破壞）。主窗口跑合併 gate（含 PHPUnit）。
