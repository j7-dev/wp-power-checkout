# 綠界ECPay

綠界科技（ECPay）第三方金流 + 電子發票 + 物流服務商。Power Checkout 透過兩種金流整合模式（AIO 導轉式、ECPG 站內付 2.0）整合線上付款，可選用綠界電子發票（與 Amego 並存），並透過綠界全方位物流 v2（AllInOne）整合超商取貨 / 宅配出貨（Logistics domain 新 provider `ecpay_logistics`，鏡像金流/發票 provider 抽象）。

## 描述

第三方系統。負責：

### 金流 — AIO 導轉式（gateway `ecpay_aio`）
- 託管付款頁面（綠界 Cashier V5）：消費者在綠界頁面選擇付款方式並完成付款或取號
- 接收建單請求（AioCheckOut/V5），以 CheckMacValue（SHA256）驗證請求完整性
- 付款結果通知：
  - ReturnURL（幕後 Server-to-Server，Form POST，商家須回 `1|OK`）
  - OrderResultURL（前景，導回商家結果頁）
  - PaymentInfoURL（ATM/CVS/BARCODE 取號通知，Form POST，商家須回 `1|OK`）

### 金流 — ECPG 站內付 2.0（gateway `ecpay_ecpg`）
- GetTokenbyTrade / CreatePayment（ecpg domain，AES-128-CBC 加密 Data）
- ThreeDURL 3D Secure 驗證（2025/8 起強制，幾乎必出現）
- ReturnURL（幕後 JSON POST + AES 解密，商家須回 `1|OK`）
- 查詢 / 退款走 ecpayment domain（與 ecpg domain 不可混用）

### 退款
- 信用卡退款 API（DoAction，Action=R 退款 / Action=N 取消授權）

### 電子發票（Invoice provider `ecpay`，與 Amego 並存）
- B2C / B2B 開立、作廢（AES-JSON）

### 物流 — 全方位物流 v2 / AllInOne（Logistics provider `ecpay_logistics`）
- 端點前綴 `/Express/v2/`（test `logistics-stage.ecpay.com.tw` / prod `logistics.ecpay.com.tw`），AES-128-CBC + JSON POST（與 ECPG/發票同套加密，可複用 AesCrypto）
- RqHeader 必填 `Revision:"1.0.0"` + Timestamp（**5 分鐘**視窗，須即時 time()，比 ECPG/跨境物流的 10 分鐘短）
- 暫存單三段流程：
  - RedirectToLogisticsSelection（建暫存單 + 回 RWD 選店 HTML，用 PostWithAesStrResponseService）
  - 消費者選店 → 綠界 Form POST 至 ClientReplyURL（回 ResultData 含 TempLogisticsID）
  - CreateByTempTrade（憑 TempLogisticsID 成立正式物流單，回 LogisticsID）
- ServerReplyURL 貨態 callback：JSON body POST，回應**必須是 AES 加密 JSON 三層結構**（非 1|OK），否則綠界隔 60 分重送，當天最多 3 次
- 雙層錯誤檢查：外層 `TransCode===1`（傳輸層，整數）→ 解密 Data → 內層 `RtnCode===1`（業務層，整數）
- 帳號 B2C(2000132) / C2C(2000933) 不同，HashKey/HashIV 也不同
- 其他操作：QueryLogisticsTradeInfo（查詢）、PrintTradeDocument（列印託運單 HTML）、ReturnCVS/ReturnUniMartCVS/ReturnHilifeCVS/ReturnHome（退貨）、UpdateShipmentInfo（B2C 更新出貨）、CancelC2COrder/UpdateStoreInfo（C2C）
- 物流類型：超商（FAMI 全家 / UNIMART 統一 / HILIFE 萊爾富）、宅配（Home，含溫層 Temperature）
- callback 安全必做：驗 MerchantID、比對 LogisticsID 與訂單、防重複（記錄已處理 LogisticsID）、遮蔽 HashKey/HashIV

## 關鍵屬性

- 僅支援新台幣（TWD）
- AIO callback 的 RtnCode 為字串型別（成功付款 `'1'`；ATM 取號成功 `'2'`；CVS/BARCODE 取號成功 `'10100073'`）
- ECPG callback 解密後 RtnCode 為整數型別，須雙層錯誤檢查（TransCode 傳輸層 → RtnCode 業務層）
- callback 最多重送 4 次，須冪等處理（以 MerchantTradeNo 為 key）
- 非信用卡付款（ATM/WebATM/CVS/BARCODE/ApplePay）不支援 API 退款，須綠界後台人工
- 綠界付款頁不可用 iframe 嵌入
- 憑證（MerchantID / HashKey / HashIV）由管理員於後台 Vue 設定頁填入，存 `woocommerce_{gateway_id}_settings`；測試環境用綠界公開測試帳號（3002607…）；**不寫死於程式碼**
