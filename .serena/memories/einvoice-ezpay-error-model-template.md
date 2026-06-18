# einvoice 第四階段-a：ezPay provider 正規化錯誤模型（參考模板）✅ 完成

## 狀態
完成（2026-06-18）。ezPay 是 4 個 Invoice provider 的錯誤模型**參考實作**，Ecpay/Amego/PayNow 照抄。

## Gate 結果
- PHPStan EXIT 1：維持 baseline 1 error（pre-existing RedirectSettingsDTO::$mode isset），零新增。
- PHPUnit ezpay group EXIT 1：84 tests（70 baseline + 14 新），1 failure = pre-existing EzpayAesCryptoTest::test_edge_標準AES16blockPadding（ezpay-edge，不計退化）。其餘 83 全綠。
- PHPCS：3 source files + 改動 test files error-clean（warnings 為 alignment/commented-code 非 gating，已 PHPCBF）。

## 變更檔案
- 新 `Ezpay/Http/EzpayApiException.php`：typed exception，攜帶 raw_code/raw_message/kind（KIND_SIGNATURE/BUSINESS/NETWORK/DECODE）。
- 改 `Ezpay/Http/InvoiceApiClient.php`：
  - decode_result() 改丟 EzpayApiException（business 帶 Status 為 raw_code；CheckCode 不符 kind=signature）。
  - request() 入口 reset $last_error_detail；網路失敗丟 kind=network；catch 落地 to_error_detail()。
  - 新 `public static ?array $mock_error_override`（測試注入錯誤回應；mock_response() 優先回它）。
  - 新 `get_last_error_detail(): ?array{raw_code,raw_message,raw,kind}`。
  - 既有 ?IssueResponse/?array 回 null 契約**不變**（既有 client mock 測試全綠）。
- 改 `Ezpay/Services/EzpayInvoiceProvider.php`：5 方法回傳 `: array|\WP_Error`；15 處 return [] → NormalizedError；新 map_error/error_from_client/unknown_error/user_message/build_dispatch_params。
- 新 test `EzpayInvoiceErrorMapTest.php`（14 tests，class-level @group ezpay error）。
- 改 test：EzpayAllowanceTest（5 assertSame([]) → WP_Error+code）、EzpayQueryTest（2）、EzpayInvoiceProviderTest（B2B companyId 87654321→04595257 因新 UBN checksum；LIB10007 加 CONFLICT+raw_code 斷言）。

## ⚠️ 關鍵踩雷（給後續三家避雷）
1. **新 test 檔 @group 必須放 class docblock，不是 file docblock**。file docblock（<?php 後第一個）與 class 被 declare/namespace/use 隔開，PHPUnit 只讀 class 緊鄰 docblock。放錯 → `--group ezpay` 收集不到（但 `--filter` 仍可）→ 計數不變察覺。method-level @group 仍可被 xml 白名單收集，故 --filter 假綠。
2. **B2B happy 測試 companyId 須通過財政部 UBN checksum**。issue() 第一步跑 validate_for_dispatch，87654321 checksum 不過 → 會回 VALIDATION 而非開立。改用 04595257（sum=40 合法）。注意 EzpayIssueParamsTest 直接測 DTO 不走 issue()，87654321 不影響。
3. **既有 assertSame([], $result) 失敗斷言 = 舊契約，必須改**（contract evolution 非退化）：allowance 金額0/超額→VALIDATION、未開立→NOT_FOUND、CheckCode→SIGNATURE；invalid_allowance 無折讓→NOT_FOUND；query 未開立→NOT_FOUND、CheckCode→SIGNATURE。
4. **build_dispatch_params 原樣取 companyId/carrier/donateCode**（不依 invoiceType 篩）→ 互斥不變式才攔得到偽造「同時帶載具+捐贈」。

## map_error 模板（Ecpay/Amego/PayNow 照抄此形狀）
provider 內 `private static function map_error(string $raw_code, string $raw_message=''): ErrorCode`，match(true) + fallthrough→PROVIDER。
驗章(SIGNATURE)/網路(NETWORK) 由 client kind 在 error_from_client() 先分流，不進 map_error（map_error 只管 business 碼）。
ezPay 映射：KEY*→AUTH、LIB10003/05/07/08/09→CONFLICT、INV20006→NOT_FOUND、INV90006→NUMBER_EXHAUSTED、INV10003/04/06/12/13/14/15/16/17/19+INV70001→VALIDATION、NOR10001/KEY10007/10014→NETWORK、其餘→PROVIDER。

## issue() 接驗證層位置
冪等檢查後、try 前：`$err = InvoiceParamsValidator::validate_for_dispatch(self::build_dispatch_params($order)); if($err instanceof \WP_Error) return $err;`（不打 API）。

## WP_Error 回傳範式
`return NormalizedError::from(ErrorCode::X, $userMsg, ['provider'=>self::ID,'raw_code'=>$rc?:null,'raw_message'=>$rm?:null,'raw'=>$raw?:null]);`
catch \Throwable → unknown_error()→UNKNOWN（never-throw，logger 記 order note）。冪等成功路徑不變。

## 後續（主窗口）
平行複製到 Ecpay/Amego/PayNow（第四階段-b/c/d）；第五階段才改 InvoiceApiService REST is_wp_error 映射 + ProviderRegister wrapper。本階段未碰 REST/hook。
