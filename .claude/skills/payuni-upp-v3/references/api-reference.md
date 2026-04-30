# PAYUNi API Reference

> 完整 API 端點參考。所有 API 共用相同的加解密機制（見 SKILL.md）。

## TOC

- [API 端點總覽](#api-端點總覽)
- [共用請求格式](#共用請求格式)
- [交易查詢 API](#交易查詢-api)
- [交易請退款 API (CREDIT)](#交易請退款-api-credit)
- [交易取消授權 API (CREDIT)](#交易取消授權-api-credit)
- [信用卡 Token 查詢 API](#信用卡-token-查詢-api)
- [信用卡 Token 取消 API](#信用卡-token-取消-api)
- [超商代碼取消 API](#超商代碼取消-api)
- [非信用卡退款 API (icash/Aftee/LinePay)](#非信用卡退款-api)
- [AFTEE 交易確認 API](#aftee-交易確認-api)
- [續期收款 API](#續期收款-api)
- [物流相關回傳欄位](#物流相關回傳欄位)
- [錯誤代碼](#錯誤代碼)

---

## API 端點總覽

| 功能 | SDK mode | API 路徑 | 方式 |
|------|----------|----------|------|
| 整合支付頁 (UPP) | `upp` | `/api/upp` | Form POST (HTML) |
| 虛擬帳號幕後 | `atm` | `/api/atm` | CURL POST |
| 超商代碼幕後 | `cvs` | `/api/cvs` | CURL POST |
| 信用卡幕後 | `credit` | `/api/credit` | CURL POST |
| LINE Pay 幕後 | `linepay` | `/api/linepay` | CURL POST (Version 1.1) |
| AFTEE 幕後 | `aftee_direct` | `/api/aftee_direct` | CURL POST |
| 交易查詢 | `trade_query` | `/api/trade/query` | CURL POST |
| 交易請退款 | `trade_close` | `/api/trade/close` | CURL POST |
| 交易取消授權 | `trade_cancel` | `/api/trade/cancel` | CURL POST |
| 超商代碼取消 | `cancel_cvs` | `/api/cancel_cvs` | CURL POST |
| Token 查詢 | `credit_bind_query` | `/api/credit_bind/query` | CURL POST |
| Token 取消 | `credit_bind_cancel` | `/api/credit_bind/cancel` | CURL POST |
| icash 退款 | `trade_refund_icash` | `/api/trade/common/refund/icash` | CURL POST |
| AFTEE 退款 | `trade_refund_aftee` | `/api/trade/common/refund/aftee` | CURL POST |
| AFTEE 確認 | `trade_confirm_aftee` | `/api/trade/common/confirm/aftee` | CURL POST |
| LINE Pay 退款 | `trade_refund_linepay` | `/api/trade/common/refund/linepay` | CURL POST |

基底 URL:
- 正式: `https://api.payuni.com.tw`
- 測試: `https://sandbox-api.payuni.com.tw`

---

## 共用請求格式

所有 API (除 UPP 外) 使用 CURL POST，body 為:

```
MerID={商店代號}&Version={版本}&EncryptInfo={加密字串}&HashInfo={雜湊}
```

回傳 JSON:
```json
{
  "Status": "SUCCESS",
  "MerID": "...",
  "Version": "1.0",
  "EncryptInfo": "...(hex encoded)...",
  "HashInfo": "...(SHA256)..."
}
```

若 Status 為 `ERROR`，直接回傳錯誤，無 EncryptInfo。

---

## 交易查詢 API

**端點**: `/api/trade/query`
**Mode**: `trade_query`
**Version**: `1.0`

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `MerTradeNo` | C | 商店訂單編號 (與 TradeNo 擇一) |
| `TradeNo` | C | PAYUNi 序號 (與 MerTradeNo 擇一) |

### 回傳 (EncryptInfo 解密後)

通用欄位 + 對應 PaymentType 的專有欄位 (同 UPP 回傳)。
額外欄位: `CloseStatus` (請款狀態), `CloseAmt` (請款金額)。

---

## 交易請退款 API (CREDIT)

**端點**: `/api/trade/close`
**Mode**: `trade_close`
**Version**: `1.0`

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `TradeNo` | Y | PAYUNi 序號 |
| `CloseType` | Y | `1`=請款, `2`=退款 |
| `TradeAmt` | C | 退款金額 (部分退款時帶入) |

**注意**:
- 一次付清: 支援部分退款
- 分期: 僅支援全額退款
- Apple Pay: 支援部分退款
- 退款僅在 CloseStatus=2 (請款完成) 時可執行

---

## 交易取消授權 API (CREDIT)

**端點**: `/api/trade/cancel`
**Mode**: `trade_cancel`
**Version**: `1.0`

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `TradeNo` | Y | PAYUNi 序號 |

取消尚未請款的授權交易。

---

## 信用卡 Token 查詢 API

**端點**: `/api/credit_bind/query`
**Mode**: `credit_bind_query`
**Version**: `1.0`

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `UseTokenType` | C | Token 類型 |
| `BindVal` | C | CreditToken 或 CreditHash |

---

## 信用卡 Token 取消 API

**端點**: `/api/credit_bind/cancel`
**Mode**: `credit_bind_cancel`
**Version**: `1.0`

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `UseTokenType` | Y | Token 類型 |
| `BindVal` | Y | 綁定回傳值 / 信用卡Token |

---

## 超商代碼取消 API

**端點**: `/api/cancel_cvs`
**Mode**: `cancel_cvs`
**Version**: `1.0`

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `PayNo` | Y | 超商繳費代碼 |

---

## 非信用卡退款 API

### icash 退款

**端點**: `/api/trade/common/refund/icash`
**Mode**: `trade_refund_icash`

### AFTEE 退款

**端點**: `/api/trade/common/refund/aftee`
**Mode**: `trade_refund_aftee`

### LINE Pay 退款

**端點**: `/api/trade/common/refund/linepay`
**Mode**: `trade_refund_linepay`

### 共用請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `TradeNo` | Y | PAYUNi 序號 |
| `TradeAmt` | Y | 退款金額 |

---

## AFTEE 交易確認 API

**端點**: `/api/trade/common/confirm/aftee`
**Mode**: `trade_confirm_aftee`

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `Timestamp` | Y | Unix 時間戳 |
| `TradeNo` | Y | PAYUNi 序號 |

---

## 續期收款 API

用於信用卡 Token 約定扣款的後續授權交易。需先透過 UPP 完成首次 Token 綁定取得 CreditHash。

使用 `credit` mode 搭配 CreditHash 進行幕後授權:

### EncryptInfo 請求參數

| 參數 | 必要 | 說明 |
|------|------|------|
| `MerID` | Y | 商店代號 |
| `MerTradeNo` | Y | 新訂單編號 |
| `TradeAmt` | Y | 金額 |
| `Timestamp` | Y | Unix 時間戳 |
| `CreditHash` | Y | 首次交易取得的 Token Hash |
| `UseTokenType` | Y | Token 類型 |

---

## 物流相關回傳欄位

### 純取貨 / 貨到付款 (PaymentType=5, ShipTag=1)

| 參數 | 說明 |
|------|------|
| `PartnerId` | 母代碼 (B2C, 長度3) |
| `ShipTradeNo` | UNi 物流序號 |
| `GoodsType` | `1`=常溫, `2`=冷凍 |
| `LgsType` | `B2C`=大宗寄倉, `C2C`=店到店 |
| `ShipType` | `1`=7-ELEVEN |
| `ServiceType` | `1`=取貨付款, `3`=取貨不付款 |
| `ShipAmt` | 取貨付款金額 |
| `StoreID` | 取件門市代碼 |
| `StoreName` | 取件門市名稱 |
| `StoreAddr` | 取件門市地址 |
| `Consignee` | 收件人名稱 |
| `ConsigneeMobile` | 收件人手機 |
| `ConsigneeMail` | 收件人信箱 |

### 宅配到付 (PaymentType=10)

| 參數 | 說明 |
|------|------|
| `TradeType` | 固定 `1`=正物流 |
| `ShipTradeNo` | 物流單號 |
| `GoodsType` | `1`=常溫, `2`=冷凍, `3`=冷藏 |
| `LgsType` | `HOME`=黑貓宅配 |
| `ShipType` | `2`=黑貓宅配 |
| `ServiceType` | `1`=取貨付款, `3`=取貨不付款 |

---

## 錯誤代碼

PAYUNi API 錯誤以 Status 欄位回傳錯誤代碼字串。常見格式:

| Status | 說明 |
|--------|------|
| `SUCCESS` | 成功 |
| `ERROR` | 系統錯誤 (無 EncryptInfo) |
| `UNKNOWN` | 等待授權結果逾期 (60秒無回應) |
| `UNAPPROVED` | 訂單待確認 (買家會員資格審查中) |
| `API00003` | 無 API 版本號 |

錯誤代碼完整清單見官方文件: https://docs.payuni.com.tw/web/#/7/44

**回傳處理流程**:
1. 檢查外層 Status 是否為 `ERROR` (此時無 EncryptInfo)
2. 若有 EncryptInfo，先驗證 HashInfo
3. 解密 EncryptInfo
4. 檢查內層 Status 是否為 `SUCCESS`
5. 非 SUCCESS 時，Message 欄位包含錯誤說明

---

## 平台/代理商模式

若為平台商代理子商店收款，外層需額外帶入:

| 參數 | 說明 |
|------|------|
| `IsPlatForm` | `1`=啟用平台模式 |

此參數在 EncryptInfo 加密前會被抽出放到外層。

---

## 信用卡支援卡別

| 卡別 | 一次付清 | 分期 | Apple Pay | Google Pay | Samsung Pay |
|------|---------|------|-----------|------------|-------------|
| Visa | O | O | O | O | O |
| MasterCard | O | O | O | O | O |
| JCB | O | O | O | O | X |
| 銀聯 | O | X | X | X | X |

分期期數: 3, 6, 9, 12, 18, 24, 30 期 (依各銀行支援)。
