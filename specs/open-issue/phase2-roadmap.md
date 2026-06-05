# Phase 2 Roadmap — Power Checkout 綠界整合第二期

> Clarifier (scope 收斂) 產出。本檔為「分類 + 排序 + 窄門清單」，**非定案 spec**。
> 標 `ready` 的項目可直接進 planner 做工程規劃；標 `blocked-on-user` 的項目須先由商家拍板業務決策，**不在此腦補規格**。
>
> 第一期已完成基準（可複用）：
> - **Payment**：SLP（redirect）、ECPay AIO（導轉，`allowed_payments` 可配置付款方式）、ECPay ECPG（站內付 2.0）
> - **Invoice**：Amego 電子發票（issue/cancel，B2C/B2B）
> - **Logistics**（第 4 domain，已上線）：綠界全方位物流 v2，統一抽象 `ILogisticsProvider`，超商三家（FAMI/UNIMART/HILIFE）+ 宅配 HOME，B2C/C2C 帳號切換，COD 代收，classic 選店

---

## TL;DR

| # | 候選功能 | 分類 | 規模 | 建議順序 |
|---|---------|------|------|---------|
| 1 | 物流退貨（ReturnCVS/Home） | ✅ ready | 中 | **1（第一個可直接建）** |
| 3 | Block checkout 選店 UI | ✅ ready | 小-中 | 2 |
| 2 | PAYUNi 物流 provider | ✅ ready（架構）/ ⚠️ 開通決策 | 大 | 3（待商家確認 PAYUNi 約） |
| 4 | 級距運費 | ⚠️ blocked-on-user（規則值） | 小-中 | 4（拿到級距規則即 ready） |
| 5 | 付款方式擴充（TWQR/BNPL/微信/銀聯） | ⚠️ blocked-on-user（開通+簽約+優先序） | 各小（共框架中） | 5 |
| 6 | 電子收據 | ⚠️ blocked-on-user（是否做+類型） | 中 | 待決 |
| 7 | 跨境物流 | ⚠️ blocked-on-user（是否跨境） | 大 | 待決 |

- **可直接進 planner（ready）**：#1 物流退貨、#3 Block 選店 UI。#2 PAYUNi 架構 ready（但「是否要做」取決於商家有無 PAYUNi 約）。
- **blocked-on-user（窄門）**：#4 級距規則值、#5 付款方式（哪家 BNPL / 是否開通 / 優先序）、#6 電子收據（是否做+類型）、#7 跨境（是否跨境）。
- **建議第一個動工**：**#1 物流退貨** — 抽象已預留 `create_return` 方法位、ECPay SDK 範例齊全、blast radius 最小（只擴充既有 Logistics domain，不碰 Payment/Invoice）、商家最可能需要。

---

## 可複用基礎盤點（決定分類的事實依據）

| 基礎設施 | 位置 | 對第二期的意義 |
|---------|------|--------------|
| `ILogisticsProvider::create_return()` 方法位 | `inc/classes/Domains/Logistics/Shared/Interfaces/ILogisticsProvider.php:92-102` | 退貨 interface 已預留（目前 throw「尚未實作」），第二期填實作 |
| `ILogisticsProvider` 抽象（兩階段選店、callback 差異收進 provider） | 同上 | 設計時即聲明「可容 PAYUNi」，PAYUNi provider 鏡像 EcpayLogisticsProvider |
| `WC_EcpayLogisticsShipping::calculate_shipping()` + 固定運費 `cost` | `inc/classes/Domains/Logistics/Ecpay/Services/WC_EcpayLogisticsShipping.php:118` | 級距運費 = 擴充此處（T4 明確標「本期不做級距」） |
| classic 選店流程（7 features + Vue 設定頁 + 選店 callback） | `specs/features/logistics/`、`js/src/pages/Logistics/` | Block 選店 UI 複用同一套 REST `/logistics/*` + 選店 callback |
| `inc/assets/blocks/*.tsx`（SLP/AIO/ECPG 三個 block 入口） | `inc/assets/blocks/` + `react-blocks.rule.md` | Block 選店 UI 沿用 WC Blocks 註冊模式 |
| ECPay AIO `allowed_payments` + `ALL + IgnorePayment` 機制 | `specs/features/payment/ecpay-aio-checkout.feature:63-70` | TWQR/微信 = 擴充 `EcpayPaymentMethod` enum + allowed_payments 多選 |
| `EcpayPaymentMethod` enum（現 Credit/分期/定期/WebATM/ATM/CVS/BARCODE/ApplePay） | `inc/classes/Domains/Payment/EcpayAIO/Shared/Enums/EcpayPaymentMethod.php` | 缺 TWQR/BNPL/微信；擴充模式清楚（加 case） |
| Invoice domain（Amego，issue/cancel，IInvoiceService） | `inc/classes/Domains/Invoice/` | 電子收據可鏡像 Invoice domain 結構或併入 |
| ECPay-API-Skill guides + SDK 範例 | `.claude/skills/ECPay-API-Skill/` | 退貨(06/07)、跨境(08)、收據(25)、TWQR/BNPL/微信/銀聯 SDK 範例全備 |
| `payuni-logistics-v3` skill | plugin skill | PAYUNi 物流 API ref（ship_map 單段 + AES-256-GCM） |

---

## 分類詳述

### ✅ READY — 可直接進 planner（無需新業務資訊）

#### #1 物流退貨 — **建議第一個動工**

- **分類**：ready。API 明確、抽象已預留方法位。
- **複用**：`ILogisticsProvider::create_return()` 預留位；ECPay SDK `Logistics/AllInOne/B2C/{ReturnFamiCVS,ReturnUnimartCvs,ReturnHilifeCvs}.php`、`AllInOne/Home/ReturnHome.php`、`Domestic/Return*.php`；guide 06/07。
- **scope**：實作 `EcpayLogisticsProvider::create_return()`（ReturnCVS/ReturnUniMartCVS/ReturnHilifeCVS/ReturnHome 對應 sub_type）；新增 REST endpoint `POST /logistics/{order_id}/return`；後台訂單頁「建立退貨單」操作；對應 order meta（退貨單號 / 退貨門市 / 退貨狀態）；退貨貨態 callback。先 ECPay；PAYUNi 退貨待 #2。
- **依賴**：Logistics domain（已存在）。**零**跨 domain 侵入。
- **預估規模**：中（1 個方法填實 + 1 REST endpoint + meta + Vue 操作按鈕 + 退貨貨態處理；95% 鏡像既有 create_shipment 流程）。
- **blast radius**：小（只動 Logistics domain）。
- **下一步**：可立即交 planner 做輕量 discovery（補 `logistics-create-return.feature`，第一期已刪此檔，需重建）→ 工程規劃。

#### #3 Block checkout 選店 UI

- **分類**：ready。classic 已做，block 是已知的第二實作管道。
- **複用**：classic 選店 REST `/logistics/{id}/store-selection` + selection-callback（已存在，前後端共用）；`inc/assets/blocks/*.tsx` 三個既有 block 入口 + `react-blocks.rule.md` 註冊模式。
- **scope**：新增 WC Block checkout 的物流選店元件（React `.tsx`），呼叫既有 REST 選店流程；在 block checkout 顯示已選門市；與既有 classic 選店共用後端，不重複後端邏輯。
- **依賴**：Logistics domain 選店後端（已存在）、WC Blocks 基礎建設（已存在）。
- **預估規模**：小-中（純前端 block 整合，後端複用；風險在 WC Blocks shipping/checkout slot API 的整合細節）。
- **blast radius**：小（新增 block 入口檔，不改既有 classic 流程）。

#### #2 PAYUNi 物流 provider（架構 ready，「是否做」需商家確認開通）

- **分類**：架構 ready（抽象設計時即為可容 PAYUNi 而做）；但**「值不值得做」取決於商家是否已與 PAYUNi 簽約開通物流**（見窄門 Q1）。
- **複用**：`ILogisticsProvider` 全套抽象（兩階段選店、callback 差異收進 provider、統一 `_pc_logistics_ref` 主鍵）；鏡像 `EcpayLogisticsProvider` 結構；`payuni-logistics-v3` skill 提供 API ref。
- **scope**：新增 `Domains/Logistics/Payuni/` provider（implements ILogisticsProvider，AES-256-GCM + SHA256 自帶，ship_map 單段選店 + trade 建單）；註冊進 `$logistics_providers`；Vue 設定頁；7-ELEVEN + 黑貓，B2C/C2C/C2B。
- **依賴**：`ILogisticsProvider`（已存在，設計時已驗證可容）。
- **預估規模**：大（新 provider 全套，但抽象已穩定，風險低於第一期 ECPay）。
- **新 library 評估**：`payuni-logistics` / `payuni-crypto`（AES-256-GCM）為專案目前未使用的加密/SDK 路徑 → **觸發新 library 評估流程**（見下方「技術依賴」）。
- **blast radius**：小（新增 provider，零侵入既有 ECPay provider；抽象已驗證）。

---

### ⚠️ BLOCKED-ON-USER — 需商家業務決策（窄門，不腦補）

#### #4 級距運費（重量/金額級距）

- **分類**：blocked-on-user。架構擴充點明確，但**級距規則的門檻值是商家業務參數，未知**。
- **缺口**：依重量還是依金額？各級距的門檻與費率？是否分超商/宅配/不同地區？滿額免運門檻？
- **複用（一旦拿到規則）**：擴充 `WC_EcpayLogisticsShipping::calculate_shipping()`（現為固定 `cost`）。
- **預估規模**：小-中（拿到明確級距表後，純運費計算邏輯 + 後台設定欄位）。
- **窄門 → Q4**。

#### #5 付款方式擴充（TWQR / BNPL / 微信支付 / 銀聯）

- **分類**：技術框架 ready（AIO `allowed_payments` enum 擴充 + ECPay SDK 範例齊全：`CreateTwqrOrder/CreateBnplOrder/CreateWeiXinOrder.php`、Ecpg `CreateUnionPayOrder`），但**每項都需商家業務決策**：
  - **BNPL**：綠界 BNPL 須指定簽約對象（裕富 / 中租），商家簽哪家 → 決定參數與顯示（窄門 Q2）。
  - **TWQR / 微信 / 銀聯**：商家是否已向綠界**申請開通**該收款方式？未開通則建了也不能用 → 須確認開通狀態 + **優先序**（窄門 Q3）。
- **複用**：擴充 `EcpayPaymentMethod` enum + AIO `allowed_payments` 多選 + `IgnorePayment` 邏輯；銀聯在 Ecpg（站內付）路徑。
- **預估規模**：各項小（共用 AIO 框架，每項 = 1 enum case + 設定選項 + 可能的額外參數）；但 BNPL 因額外參數較多，屬小-中。
- **窄門 → Q2、Q3**。

#### #6 電子收據（一般 / 公益 / 政治獻金）

- **分類**：blocked-on-user。技術上 guide 25-receipt + Invoice domain 模式可循，但**「商家是否有開立收據（非發票）的業務」+「需要哪種類型」未知**——電子收據與電子發票是不同業務情境，不能假設商家需要。
- **缺口**：商家是否開收據？一般收據 / 公益勸募收據 / 政治獻金收據 哪些類型？與既有 Amego 發票的關係（互斥？並存？）？
- **複用（一旦確認）**：鏡像 Invoice domain（IInvoiceService 模式）或新增收據 provider。
- **預估規模**：中。
- **窄門 → Q5**。

#### #7 跨境物流

- **分類**：blocked-on-user。guide 08-logistics-crossborder 有 API，但**「商家是否有跨境出貨需求」是根本前提，未知**。
- **缺口**：商家是否跨境銷售？目的地區域？跨境物流商（綠界跨境 / 其他）？
- **複用（一旦確認）**：Logistics domain + `ILogisticsProvider` 抽象（可能需評估抽象是否容跨境的報關/關稅欄位）。
- **預估規模**：大（跨境牽涉報關、關稅、目的地規則，可能超出現有抽象）。
- **窄門 → Q6**。

---

## 建議優先序（heuristic：複用既有抽象 ↑、blast radius ↓、商家最可能需要 ↑）

1. **#1 物流退貨** — 方法位已預留、零跨 domain、商家高需求。**第一個動工**。
2. **#3 Block 選店 UI** — 後端全複用、純前端、blast radius 小。
3. **#2 PAYUNi 物流** — 抽象為它而生、零侵入；待商家確認 PAYUNi 開通（Q1）。
4. **#4 級距運費** — 擴充點明確；拿到級距規則（Q4）即降為 ready。
5. **#5 付款方式擴充** — 共用 AIO 框架；待開通/簽約/優先序（Q2/Q3）逐項解鎖。
6. **#6 電子收據 / #7 跨境物流** — 須先確認商家是否有此業務（Q5/Q6），確認後再評估。

---

## 技術依賴（新 library 待評估）

| Library | 觸發項 | 狀態 | 行動 |
|---------|-------|------|------|
| `payuni-logistics` / `payuni-crypto`（AES-256-GCM） | #2 PAYUNi 物流 | 專案目前未使用 | 待 #2 動工前，依「新 Library 評估流程」以 `@zenbu-powers:lib-skill-creator` 評估是否需製作 SKILL（`payuni-logistics-v3` skill 已存在，可能已足夠，需確認） |

> 註：TWQR/BNPL/微信/銀聯/退貨/跨境/收據皆走既有綠界整合路徑（AES-128-CBC / CheckMacValue / ECPay-API-Skill），**不引入新 library**。

---

## Hand-off

> ⚠️ 本 agent 為 sub-agent，無法 spawn。以下交回 orchestrator 決策與接續。

**回報 orchestrator**：

1. **ready 可直接進 planner**：#1 物流退貨（建議第一個）、#3 Block 選店 UI。
2. **架構 ready 但待商家拍板**：#2 PAYUNi 物流（取決於 Q1）。
3. **blocked-on-user 窄門清單**：見下方 6 題，請 orchestrator 轉達商家拍板。
4. **建議**：orchestrator 拍板後，先就 **#1 物流退貨** 接 planner（需先重建 `logistics-create-return.feature`，第一期已刪）。

### 需商家拍板的業務窄門清單（精簡，給 orchestrator 轉達）

| Q | 窄門問題 | 阻擋項 | 為何不能腦補 |
|---|---------|-------|------------|
| Q1 | 商家是否已與 **PAYUNi 簽約並開通物流**？（若否，PAYUNi provider 建了也無法用） | #2 | 開通狀態是外部事實，無法推測 |
| Q2 | BNPL 先買後付要接**哪一家**？（裕富 / 中租 / 都要） | #5-BNPL | 簽約對象決定參數與顯示，須商家簽約資訊 |
| Q3 | TWQR 行動支付 / 微信支付 / 銀聯，**哪些已向綠界申請開通**？三者**優先序**為何？ | #5-其他 | 開通狀態 + 商業優先序皆為商家決策 |
| Q4 | 級距運費依**重量**還是**金額**？各級距的**門檻與費率**？是否含**滿額免運**門檻？ | #4 | 級距表是商家定價策略，無預設值可推 |
| Q5 | 商家是否有開立**電子收據**（非發票）的業務？若有，需哪些類型（一般 / 公益勸募 / 政治獻金）？與既有 Amego 發票是互斥還是並存？ | #6 | 收據業務是否存在是商家經營型態，不可假設 |
| Q6 | 商家是否有**跨境出貨**需求？若有，目的地區域與跨境物流商為何？ | #7 | 跨境與否是商家經營範圍，不可假設 |

> orchestrator 取得以上答案後：Q1=是 → #2 解鎖；Q2/Q3 → #5 逐項解鎖；Q4 → #4 解鎖；Q5/Q6 → #6/#7 評估。
> 不論窄門結果，**#1、#3 已可立即動工**。
</content>
</invoke>
