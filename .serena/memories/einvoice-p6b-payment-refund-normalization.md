# einvoice 第六階段-b：6 金流 process_refund 正規化 + callback always-200 護欄 ✅ 完成

## 狀態
完成（2026-06-18）。承接 P6a（Abstract 預設 UNSUPPORTED + PaymentApiService::error_response 豐富化）。

## Gate 結果
- PHPStan EXIT 1：維持 baseline 1 error（pre-existing RedirectSettingsDTO::$mode isset @148），**零新增**。
- PHPUnit Payment suite（mock）EXIT 0：**962 tests, 1874 assertions, OK**（P6a 基線 947 + 本輪新增 15 net；全綠，零退化）。
- PHPCS：6 gateway source files **0 errors**（RedirectGateway 2 warnings 為 pre-existing commented-code @128/@204，非我改動、非 gating）。

## 改動 A：6 金流 process_refund → NormalizedError（只換回傳值建構，判定邊界不變）
全部加 `use ...Shared\Errors\{ErrorCode, NormalizedError}`：
- **Payuni / PayuniUniEmbed / Paynow**：拆 `if($amount<=0 || >total) return false` → `if($amount<=0) return false`（保留 null/≤0 契約）+ `if($amount>total) return VALIDATION`（超額不打 API）；非信用卡 `refund_unsupported` → `UNSUPPORTED`。
- **Ecpg / EcpayAIO**：無超額分支（不新增，避免改判定）；非信用卡 `refund_unsupported` → `UNSUPPORTED`，保留 `!$amount→false`。
- **ShoplinePayment**：catch \Throwable 的 `refund_failed` → `UNKNOWN`，raw_message 帶原始例外訊息；保留 `!$amount→false` + `can_refund` 既有回傳。

退款 API 支援度對照（process_refund 內可達 code）：
| Gateway | 信用卡 | 非信用卡 | 超額 | null/0 |
|---|---|---|---|---|
| PayuniUpp/UniEmbed/Paynow | true(委派) | UNSUPPORTED | VALIDATION | false |
| Ecpg/EcpayAIO | true(委派) | UNSUPPORTED | (無分支) | false |
| Shopline | can_refund 回傳 | (can_refund WP_Error) | (can_refund) | false / catch→UNKNOWN |

⚠️ **AUTH/NETWORK/PROVIDER 在這 6 支 process_refund「不可達」**：信用卡實際退款 API 在 handle_payment_gateway_refund/process_gateway_refund（回 void + order note），process_refund 對信用卡僅回 true 後延遲委派。映射詞彙存在但此層不觸及——刻意不新增 API 呼叫到 process_refund（守「不改判定」）。

## 改動 B：callback always-200 護欄（**不改 callback 任何一行程式**）
- Payuni / PayuniUniEmbed / Paynow / Ecpg / EcpayAIO：**已有**驗簽失敗→200+狀態未變護欄（Payuni系 HashInfo竄改→200+pending；Paynow HMAC失敗→200+pending；Ecpg Data解密失敗+例外→1|OK/200；AIO CheckMacValue不符→pending+空明細 / 1|OK+200）→ 略過。
- **新增**：EcpayAioCallbackTest `test_FM06_偽造CheckMacValue透過REST_callback仍回200且訂單未變`（強化：同一 REST callback 路徑一次斷言 200+pending+空明細）。
- **Shopline gap 補上**：WebhookSignatureTest 新增 2 個 FM-06 護欄（原本只測 HMAC 邏輯、未 dispatch callback）。

## ⚠️ Shopline webhook 特例（重要踩雷）
SLP WebHook.php **不是 always-200**：成功回 200、mapping 失敗回 500、`is_valid()` 在 **try 區塊外** 呼叫（line 55），env=production + 驗簽失敗 → `verify_hmac_sha256_signature` throw → 由 WP 包成 HTTP 500（**不是 200**）。本輪硬約束「不改 WebHook.php」→ 護欄測試不主張「一律 200」，改鎖 FM-06 真正不變式：**偽造驗簽 → 訂單維持 pending + 不寫付款明細 + (env=production 下)throw**。測試須顯式 `Plugin::$env='production'`（預設 wp_get_environment_type，測試環境不確定）+ finally 還原。
踩雷：原本想加「正確簽章→200」對照組，但 mock 模式下 Body→StatusManager→payment_complete 全流程會丟例外→500（與 P6b 無關），故移除該脆弱對照，改成「偽造→不寫明細」確定性斷言。

## 既有測試契約演進（green→red 必修，已修）
`refund_unsupported` → `UNSUPPORTED`、`refund_failed` → `UNKNOWN` 改了 get_error_code() 字串。更新 8 處既有 assertSame：EcpayRefundTest@393、EcpgGetCodeTest@387、EcpgGatewayTest@416、PaynowRefundTest×4、PayuniRefundTest×3 → 全改 `ErrorCode::UNSUPPORTED->value` + `use ...ErrorCode`。超額/零金額既有測試本就 tolerant（is_wp_error||false），未受影響。前端 js/ 無依賴這些 code 字串。

## 變更檔案
source(6): Payuni/PayuniUniEmbed/Paynow/Ecpg/EcpayAIO Gateway + ShoplinePayment RedirectGateway。
test 新(1): PaymentRefundNormalizationTest（12 tests，class+method @group error/edge）。
test 改(8): WebhookSignatureTest(+2 FM-06)、EcpayAioCallbackTest(+1 FM-06)、EcpayRefundTest/EcpgGetCodeTest/EcpgGatewayTest/PaynowRefundTest/PayuniRefundTest（assertSame UNSUPPORTED + use ErrorCode）。

## 未動（硬約束遵守）
process_refund 簽名(bool|\WP_Error)不改；callback always-200 程式不改；ErrorCode/NormalizedError 凍結檔不改；NewebpayMpg **不在 6 金流內**（其 refund_unsupported 保留，inc/tests/ legacy 不計）。

## 後續（主窗口）
控制權回主窗口續派 P7（ECPay AES 抽取，計劃第七階段）。
