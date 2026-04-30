# ECPay AIO V5 -- 各付款方式專屬參數

> 本文件列出各付款方式在共用 AIO 參數之外的專屬參數。共用參數請參閱 SKILL.md。

## 目錄

- [信用卡一次付清](#信用卡一次付清-credit)
- [信用卡分期付款](#信用卡分期付款-credit-installment)
- [信用卡自費分期](#信用卡自費分期-flexible-installment)
- [信用卡定期定額](#信用卡定期定額-credit-period)
- [信用卡記憶卡號](#信用卡記憶卡號)
- [ATM 虛擬帳號](#atm-虛擬帳號)
- [CVS 超商代碼](#cvs-超商代碼)
- [BARCODE 超商條碼](#barcode-超商條碼)
- [WebATM 網路轉帳](#webatm-網路轉帳)
- [Apple Pay](#apple-pay)
- [TWQR 行動支付](#twqr-行動支付)
- [BNPL 無卡分期](#bnpl-無卡分期)

---

## 信用卡一次付清 (Credit)

`ChoosePayment = "Credit"`

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| Redeem | String | 否 | 1 | 設為 `Y` 時啟用紅利折抵交易流程 |
| UnionPay | Int | 否 | - | `0`=消費者可選擇是否使用銀聯卡, `1`=僅銀聯卡交易, `2`=隱藏銀聯卡選項。需另外申請，不支援分期/定期定額/紅利折抵/記憶卡號 |

## 信用卡分期付款 (Credit Installment)

`ChoosePayment = "Credit"`

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| CreditInstallment | String | 否 | 20 | 分期期數：`3`, `6`, `12`, `18`, `24`, `30N`(永豐)。閘道商另支援 `5`, `8`, `9`, `10` |

**限制**: 不可與紅利折抵或定期定額同時使用。

## 信用卡自費分期 (Flexible Installment)

`ChoosePayment = "Credit"`

消費者自付分期手續費。消費金額需達 1,000 元（含）以上。可選期數：6, 12, 24, 30 期。
偵測到簽帳金融卡時系統會封鎖交易。

## 信用卡定期定額 (Credit Period)

`ChoosePayment = "Credit"`

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| PeriodAmount | Int | 是 | - | 每次授權金額，必須與 TotalAmount 相同，整數 |
| PeriodType | String | 是 | 1 | 週期種類：`D`(天), `M`(月), `Y`(年) |
| Frequency | Int | 是 | - | 執行頻率。D: 1-365, M: 1-12, Y: 僅 1 |
| ExecTimes | Int | 是 | - | 執行次數。最少 2 次。D/M: 最多 999, Y: 最多 99 |
| PeriodReturnURL | String | 否 | 200 | 定期定額授權結果通知 URL，僅接受 DNS 域名 |

**限制**:
- 不可與紅利折抵或分期同時使用
- 首次授權失敗不會排入排程
- 連續 6 次授權失敗自動取消後續扣款
- 不支援銀聯卡
- 記憶卡號僅支援 Visa/MasterCard/JCB

## 信用卡記憶卡號

適用於信用卡一次付清、分期、定期定額。

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| BindingCard | Int | 否 | - | `1`=使用記憶卡號, `0`=不使用 |
| MerchantMemberID | String | 否 | 30 | 記憶卡號識別碼，格式：`MerchantID + 會員編號`。僅支援 Visa/MasterCard/JCB |

## ATM 虛擬帳號

`ChoosePayment = "ATM"`

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| ExpireDate | Int | 否 | - | 繳費有效天數，1-60 天，預設 3 天。到期日為建立日 + 天數的 23:59 |
| PaymentInfoURL | String | 否 | 200 | Server 端取號結果通知 URL（回傳銀行代碼、虛擬帳號、繳費期限） |
| ClientRedirectURL | String | 否 | 200 | Client 端取號結果導轉 URL |

**ChooseSubPayment 可用值**: `FIRST`(第一銀行), `CATHAY`(國泰世華), `PANHSIN`(板信), `KGI`(凱基)

**ATMAccNo**: 可回傳完整付款人銀行帳號

## CVS 超商代碼

`ChoosePayment = "CVS"`

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| StoreExpireDate | Int | 否 | - | 繳費截止時間（分鐘），預設 10080 (7天)，最大 43200 (30天) |
| PaymentInfoURL | String | 否 | 200 | Server 端取號結果通知 URL |
| ClientRedirectURL | String | 否 | 200 | Client 端取號結果導轉 URL |
| Desc_1 | String | 否 | 20 | 交易描述 1（顯示於全家/7-11 機台） |
| Desc_2 | String | 否 | 20 | 交易描述 2 |
| Desc_3 | String | 否 | 20 | 交易描述 3 |
| Desc_4 | String | 否 | 20 | 交易描述 4 |

## BARCODE 超商條碼

`ChoosePayment = "BARCODE"`

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| StoreExpireDate | Int | 否 | - | 繳費截止天數，預設 7 天，最短 1 天，最長 30 天 |
| PaymentInfoURL | String | 否 | 200 | Server 端取號結果通知 URL |
| ClientRedirectURL | String | 否 | 200 | Client 端取號結果導轉 URL |

**注意**: BARCODE 的 StoreExpireDate 單位是「天」，CVS 的單位是「分鐘」。

## WebATM 網路轉帳

`ChoosePayment = "WebATM"`

無額外專屬參數。使用共用 AIO 參數即可。

**ChooseSubPayment 可用值**: 參閱付款方式一覽表（BOT/CHINATRUST/FIRST/LAND 等）

## Apple Pay

`ChoosePayment = "ApplePay"`

無額外專屬參數。使用共用 AIO 參數即可。

## TWQR 行動支付

`ChoosePayment = "TWQR"`

無額外專屬參數，但有金額限制：

- **最低金額**: 6 元
- **最高金額**: 49,999 元

## BNPL 無卡分期

`ChoosePayment = "BNPL"`

| 參數 | 型別 | 必填 | 長度 | 說明 |
|------|------|------|------|------|
| ChooseSubPayment | String | 否 | 20 | `URICH`(裕富) 或 `ZINGALA`(中租) |
| PaymentInfoURL | String | 否 | 200 | Server 端訂單建立通知 URL（付款完成前） |

**金額限制**:
- URICH (裕富): 1,000 ~ 500,000 元
- ZINGALA (中租): 50 ~ 500,000 元
