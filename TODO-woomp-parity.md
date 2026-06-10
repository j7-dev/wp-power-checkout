# Power Checkout — woomp 功能對齊代辦清單

> 來源：2026-06-09 比對 `woomp`（MorePower Addon v3.5.8）vs `power-checkout`。
> 僅列「woomp 有、power-checkout 沒有」的缺口。已標註優先級（P0 整服務商缺 / P1 同服務商能力缺 / P2 設計取捨）。

---

## 一、金流 Payment

### 整服務商缺（power-checkout 完全沒有）

- [ ] **速買配 SmilePay**（P0）— 信用卡 / ATM / WebATM / BARCODE / CVS 7-11(ibon) / CVS 全家；CVS 走 `get_code_mode` API 後端取碼
- [ ] **立吉富 PayNow**（P0）— 信用卡 / WebATM / ATM / ibon 代碼 / BARCODE
- [ ] **PChomePay 支付連**（P0）— 信用卡 / 分期(3/6/12) / ATM / 銀行支付 EACH / 餘額 ACCT / 7-11 取貨付款 IPL7 / **PI 拍錢包**；含退款（部分退款）+ 審單
- [ ] **LINE Pay 獨立直連**（P0）— 獨立 gateway（reserve→confirm）、admin/customer 分身分退款、部分退款、金額不符自動退款
      （PC 現況：LINE Pay 僅為 SLP / 藍新 MPG / PAYUNi 託管頁內的「一個付款選項」，無獨立直連串接）

### 同服務商能力缺

- [ ] **PAYUNi 信用卡定期定額 subscription**（P1）— woomp V2 + V3 皆整合 WC Subscriptions 完整訂閱續扣（CreditHash 續扣 / UseTokenType=2）
      （PC 現況：UPP / UNi Embed 僅一次付清 + 分期 + 綁卡 token，無完整 subscription 自動扣款流程）
- [ ] **PAYUNi 重複訂單編號自動重試**（P2，設計取捨）— woomp 用 `woomp_copy_order` 重試；PC 用固定 idempotency key (PCU/PCE) 防重，方向不同，評估是否需要

---

## 二、物流 Logistics

### 整服務商缺

- [ ] **速買配 SmilePay 物流**（P0）— 7-11 / 全家 C2C 超商取貨、COD(Pay_zg 51/52)、地圖選店、列印
- [ ] **立吉富 PayNow 物流**（P0，基礎版 + 冷凍擴充）— 7-11/全家/萊爾富 C2C 店到店、黑貓常溫/冷藏/冷凍宅配、**7-11/全家冷凍交貨便**、COD、建單/取號/查詢/取消/列印
- [ ] **藍新 NewebPay 物流**（P0）— CVS 超商取貨（藍新端合併選店）、COD、地圖選店
      （PC 現況：有藍新金流 newebpay_mpg，但無藍新物流）

### 綠界 ECPay 物流 同服務商能力缺

- [ ] **OKmart 超商取貨**（P1）— woomp 有 `OKMART`；PC ecpay_logistics 僅 FAMI/UNIMART/HILIFE + HOME
- [ ] **7-11 冷凍超商 UNIMARTFREEZE**（P1）— woomp 有獨立冷凍超商通路；PC 超商通路無冷凍（僅宅配 HOME 有溫層）
- [ ] **中華郵局宅配 Post**（P1）— woomp 有 `SubType=POST`；PC 宅配僅黑貓 HOME

---

## 三、電子發票 Invoice

### 整服務商缺

- [ ] **立吉富 PayNow 電子發票**（P0）— 開立 B2C/B2B、作廢、自動/手動/**批次**開立、SOAP 串接、稅別 1/2/3
- [ ] **EasyCard 悠遊卡載具（1K0001）**（P1，隨 PayNow 發票附帶）— woomp PayNow 發票獨有；PC 的 ECPay/ezPay/Amego 三家皆無

---

## 參考：power-checkout 反而領先（非缺口，勿誤判）

- 綠界 AIO 金流：定期定額 Period / TWQR / 銀聯 / 微信 / BNPL / 綁卡幕後代扣 / 信用卡 API 退款（woomp ECPay 連退款都沒有）
- 藍新 MPG：e-wallet 退款 + 信用卡分期 3/6/12/18/24/30（與 woomp 同）
- PAYUNi 物流：PC 已完整實作（7-11 + 黑貓 + 退貨便 C2B）；woomp 為 0 byte 空檔
- ECPay / ezPay 發票：PC 有折讓開立/作廢 + 查詢；woomp 皆無
- 光貿 Amego 發票：PC 獨有；woomp 完全沒有

> 註：CLAUDE.md 落後於 code——NewebPay MPG 金流 (`newebpay_mpg`)、PAYUNi 物流 (`payuni_logistics`) 實際已完整實作並在 `ProviderRegister` 註冊。
