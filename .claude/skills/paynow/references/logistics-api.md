# PayNow 物流 API（體系 4）

對應官方文件 `docs.paynow.com.tw/developer/docs/apipdf/logistics/`（側欄「PayNowAPI文件 → 物流」，
共 13 頁：API 認證說明、711大宗冷凍B2C、711冷凍C2C、711大宗物流B2C、711海外配送、
黑貓到店、黑貓宅配、全家大宗、全家冷凍C2C、全家冷凍大宗B2C、四大超商C2C、附錄）。

> **物流是 PayNow 的第 4 套獨立體系**，與金流 REST（Bearer）、舊版 CashFlow（PassCode）、
> 發票（Bearer JWT）都不同：物流用 **TripleDES ECB + SHA-1 PassCode**，端點在
> `logistic.paynow.com.tw`，認證靠 `user_account` + `apicode`（非 Token）。

---

## 環境

```
正式：https://logistic.paynow.com.tw
測試：https://testlogistic.paynow.com.tw
傳遞方式：HTTP form POST（application/x-www-form-urlencoded）／GET／PUT／DELETE
```

### Header 認證：partner_token

部分介面需在 HTTP Request Header 帶 `partner_token`（合作夥伴識別碼），
**用於新增訂單時的回傭計算**。是否必填「依介面而定」。

| Header | 說明 | 必須 |
|---|---|---|
| `partner_token` | 合作夥伴識別碼 | 依介面而定 |

```php
// PHP（官方 C# / PHP / Go 範例之 PHP 版）
curl_setopt($ch, CURLOPT_URL, 'https://logistic.paynow.com.tw/api/Orderapi/Add_Order');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'partner_token: YOUR_PARTNER_TOKEN',
]);
```

---

## 加密與簽章（官方附錄原文）

### TripleDES（**ECB + Zeros padding**，不是 CBC）

官方 C# 原文：

```csharp
public string Encrypt(string content)
{
  TripleDes.IV      = Encoding.UTF8.GetBytes("12345678");
  TripleDes.Key     = Encoding.UTF8.GetBytes("123456789070828783123456");
  TripleDes.Mode    = CipherMode.ECB;          // ← ECB
  TripleDes.Padding = PaddingMode.Zeros;       // ← 補 \0，不是 PKCS#7
  var data   = Encoding.UTF8.GetBytes(content);
  var ict    = TripleDes.CreateEncryptor();
  var enc    = ict.TransformFinalBlock(data, 0, data.Length);
  var result = Convert.ToBase64String(enc).Replace(' ', '+');   // ← 空白換 +
  return result;
}
```

官方文件把兩個值稱為「公鑰 / 私鑰」，實際是 IV 與 Key：

| 官方稱呼 | 值 | 實際角色 |
|---|---|---|
| 公鑰 | `12345678` | IV（8 bytes；ECB 模式下不生效） |
| 私鑰 | `123456789070828783123456` | Key（24 bytes，DES-EDE3） |

> **這是官方文件公布的固定值，不是「待向 PayNow 索取的正式環境金鑰」**——正式與測試共用同一組。
> ECB 模式下 IV 不參與運算，設定它只是官方範例的慣例寫法。

PHP 對照：

```php
// JsonOrder：JSON → TripleDES ECB → base64 → urlencode
function paynow_logistics_encrypt(string $content): string {
    $key = '123456789070828783123456';
    $pad = 8 - (strlen($content) % 8);
    if ($pad !== 8) { $content .= str_repeat("\0", $pad); }   // Zeros padding
    $enc = openssl_encrypt($content, 'DES-EDE3', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    return str_replace(' ', '+', base64_encode($enc));
}
```

> OpenSSL 的 `DES-EDE3`（不帶 `-CBC` 後綴、不給 IV）即為 ECB。

### SHA-1 PassCode

```csharp
public string SHA1Encrypt(string data)
{
  var keyBytes = Encoding.Default.GetBytes(data);
  var hash     = new SHA1CryptoServiceProvider().ComputeHash(keyBytes);
  return BitConverter.ToString(hash).Replace("-", "");   // 十六進位大寫
}
```

- 以 **ASCII** 做 SHA-1，輸出**十六進位大寫**（例：`F6ACC37A32D592A90414E1AB0F3DE0DE4474B98A`）。
- 建單 / 取消 / 重新取號 / 關轉：
  `PassCode = SHA1(user_account + OrderNo + TotalAmount + apicode)`
- 黑貓設定 API：
  `PassCode = SHA1(user_account + apicode + 黑貓契客代號)`
- **依序直接相接成一字串，不含 `+` 號**（`+` 只是文件的連接示意）。

---

## 物流服務代碼（Logistic_service / Logistic_serviceID）總表

| 代碼 | 服務 | 溫層 | 型態 |
|---|---|---|---|
| `01` | 7-11 交貨便 | 常溫 | C2C |
| `02` | 7-11 大宗物流 | 常溫 | B2C |
| `03` | 全家店到店 | 常溫 | C2C |
| `04` | 全家大宗物流 | 常溫 | B2C |
| `05` | 萊爾富店到店 | 常溫 | C2C |
| `06` | 黑貓宅急便 | 常溫／冷藏／冷凍 | 宅配 |
| `07` | 7-11 海外配送（店配） | 常溫 | 跨境 |
| `08` | 7-11 海外配送（宅配） | 常溫 | 跨境 |
| `10` | OK 店到店 | 常溫 | C2C |
| `12` | 7-11 大宗退貨便 | 常溫 | 逆物流 |
| `14` | 全家大宗退貨便 | 常溫 | 逆物流 |
| `21` | 7-11 交貨便（冷凍） | 冷凍 | C2C |
| `22` | 7-11 大宗物流（冷凍） | 冷凍 | B2C |
| `23` | 全家店到店（冷凍） | 冷凍 | C2C |
| `24` | 全家大宗物流（冷凍） | 冷凍 | B2C |
| `36` | 黑貓宅急便（PayNow 契客） | 常溫／冷藏／冷凍 | 宅配 |
| `46` | 黑貓到店（PayNow 契客） | 常溫 | 店配 |

> `06` 用商家自有黑貓契客代號；`36` / `46` 走 PayNow 契客代號（`2822995505`），
> 兩者在 `Setting_BlackCat` 的必填欄位不同（見下方黑貓段）。

---

## 通用 API（跨服務共用）

| # | 功能 | Method | 路徑 | 備註 |
|---|---|---|---|---|
| 1 | 選擇物流服務（電子地圖） | POST | `/Member/Order/Choselogistics` | `apicode` 需 TripleDES 加密；空 `Logistic_serviceID` 導向服務選單 |
| 2 | 建立物流訂單 | POST | `/api/Orderapi/Add_Order` | `JsonOrder` = urlencode(base64(TripleDES(JSON))) |
| 3 | 查詢物流單（物流單號） | GET | `/api/Orderapi/Get_Order_Info` | `LogisticNumber` + `sno`(固定 1) |
| 4 | 查詢物流單（商家訂單編號） | GET | `/api/Orderapi/Get_Order_Info_orderno` | `orderno` + `user_account` + `sno` |
| 5 | 取消物流單 | **DELETE** | `/api/Orderapi/CancelOrder` | 出貨中大宗訂單不可取消；回純字串 `S,訂單已取消` / `F,...` |
| 6 | 門市更新（關轉） | **PUT** | `/api/Orderapi/Put` | `UpdateOrder`；`ChangeType` 01 取件 / 02 退件 |
| 7 | 重新取號 | POST | `/api/Orderapi/ReNewOrder` | `JsonOrder`；萊爾富需訂單成立 30 分鐘後 |
| 8 | 物流貨態回傳（PayNow → 商家） | POST | 商家自訂 URL | 見「貨態回傳」段 |

### 1. 選擇物流服務 `/Member/Order/Choselogistics`

Request：

| 參數 | 型態 | 長度 | 必須 | 備註 |
|---|---|---|---|---|
| `user_account` | string | 10 | Y | 商家主帳號 |
| `orderno` | string | 27 | N | 有帶則原樣回傳 |
| `apicode` | string | 30 | Y | **需 TripleDES 加密後傳送** |
| `Logistic_serviceID` | string | 2 | Y | 見服務代碼表；空值 → 導向服務選單頁 |
| `returnUrl` | string | 200 | Y | 回傳店號網址 |

Response（POST 回 `returnUrl`）：`orderno`、`service`、`storeaddress`、`storename`、`storeid`。

### 2. 建立物流訂單 `/api/Orderapi/Add_Order`

`JsonOrder` 內容（**超商 C2C** 版；宅配版見黑貓段）：

| 參數 | 型態 | 長度 | 必須 | 備註 |
|---|---|---|---|---|
| `user_account` | string | 10 | Y | |
| `apicode` | string | 30 | Y | JSON 內的 apicode **不再另外加密**（整包 JSON 已加密） |
| `Logistic_service` | string | 2 | Y | 01 / 03 / 05 / 10 |
| `OrderNo` | string | 27 | Y | **限英文與數字** |
| `DeliverMode` | string | 2 | Y | `01` 取貨付款（COD）／`02` 取貨不付款 |
| `TotalAmount` | string | 5 | Y | 正整數，**不可大於 20000** |
| `Remark` | string | 200 | Y | 不填請帶空字串 |
| `Description` | string | 50 | Y | 不填請帶空字串 |
| `EC` | string | 50 | N | EC 平台 |
| `receiver_storeid` | string | 30 | Y | 取件店號 |
| `receiver_storename` | string | 100 | Y | 取件店名 |
| `return_storeid` | string | 6 | Y | 退件店號；不填帶空字串 |
| `Receiver_Name` | string | 10 | Y | **不可含標點**；長度上限依服務不同：7-11 10／全家 30／萊爾富 20／OK 10 |
| `Receiver_Phone` | string | 10 | Y | |
| `Receiver_Email` | string | 100 | Y | |
| `Receiver_address` | string | 150 | Y | 請填店址 |
| `Sender_Name` | string | 10 | Y | 不可含標點 |
| `Sender_Phone` | string | 10 | Y | |
| `Sender_Email` | string | 100 | Y | 不填帶空字串 |
| `Sender_address` | string | 150 | Y | 不填帶空字串 |
| `PassCode` | string | | Y | SHA1(user_account + OrderNo + TotalAmount + apicode) |

**7-11 訂單 Ibon 禁用字元**（任一欄位都不可出現）：

```
' " % | & ` ^ @ ! . # ( ) * _ + - ; : ,
```

Response：`Status`(S/F)、`LogisticNumber`（PayNow 物流單號）、`LogisticService`、
`LogisticServiceID`、`paymentno`（物流商貨運編號）、`validationno`（驗證碼，7-11 店到店回傳；
搭 `paymentno` 供 Ibon 列印）、`ReturnMsg`、`orderno`、`ErrorMsg`（成功為 null）。

### 5. 取消物流單 `/api/Orderapi/CancelOrder`（DELETE）

`LogisticNumber` + `sno`(1) + `PassCode`。回**純字串**：
成功 `S,訂單已取消`；失敗 `F,訂單取消失敗 失敗原因: {原因}`。

### 6. 門市更新／關轉 `/api/Orderapi/Put`（PUT）

收到貨態 `7101` / `7104` / `7201` / `7204` 後，須於 **D+7 前**呼叫此 API 換店，逾期無法關轉。
（例：10/07 收到 7101 → 10/14 前須換店。）

`UpdateOrder` 內容：`LogisticNumber`、`sno`(1)、`ChangeType`（`01` 取件門市更新 /
`02` 退件門市更新）、`NewStoreId`、`NewStoreName`、`PassCode`。
回純字串 `S,更新成功` / `F,更新失敗 錯誤原因: ...`。

### 7. 重新取號 `/api/Orderapi/ReNewOrder`

`JsonOrder`：`user_account`、`LogisticNumber`、`sno`、`OrderNo`（原始）、`TotalAmount`、
`apicode`、`PassCode`。

Response 多一個 **`paynoworderno`（新訂單編號）**：超過規定天數重取會產生新商家訂單編號，
**後續批次列印與查詢須改用此編號**。規定天數 = 物流單成立日 + （7-11 5 天／全家 當天／萊爾富 5 天）。

---

## 各服務專屬 API

### 四大超商 C2C（01 / 03 / 05 / 10）

| 功能 | Method | 路徑 |
|---|---|---|
| 列印 7-11 標籤 | GET | `/api/Order711` |
| 列印全家標籤 | GET | `/api/OrderFamiC2C` |
| 列印萊爾富標籤 | GET | `/api/OrderHiLife` |
| 列印 OK 標籤 | GET | `/api/OKC2C` |
| 全家店號轉換 | GET | `/api/OrderFamiC2C/GetFamiStoreID` |

列印 Request：`orderNumberStr`（多筆商家訂單編號以 `,` 分隔，**一次最多 100 筆**）+ `user_account`。
回純字串 `S,網址` / `F,錯誤訊息`。

全家店號轉換：`storeId`(6) + `IDtype`（`1` 轉現行店號 / `2` 轉原始店號）→ `{storeId, IDtype, Error}`。

**列印期限**：7-11 / 全家 7 天、萊爾富 5 天、OK 15 天，逾期未至門市繳費寄貨則失效。

### 黑貓宅配（06 / 36）

`Add_Order` 的 `JsonOrder` 與超商版**欄位不同**，宅配專屬必填：

| 參數 | 必須 | 備註 |
|---|---|---|
| `Logistic_service` | Y | `06` 黑貓宅急便 / `36` 黑貓宅急便(PayNow) |
| `TotalAmount` | Y | 正整數，**不可大於 100000**（超商是 20000） |
| `Deadline` | Y | 預定出貨天數；當日出貨帶 `0`（過當日 16:00 則順延），最多 6 天；週日與連假順延 |
| `Length` / `Wide` / `High` | Y | 公分；**長+寬+高 ≤ 150** |
| `Weight` | Y | kg；**≤ 20** |
| `DeliveryType` | Y | `0001` 常溫 / `0002` 冷藏 / `0003` 冷凍 |
| `Receiver_Name` | Y | 中文最多 10 字、英文 20 字（string 30） |
| `Receiver_address` | Y | 120；不可含特殊字元 |
| `Sender_Phone` | Y | 帶空字串會自動填 `0900000000` |
| `Description` | N | 商品類別代碼 `0001`~`0015`，未填預設 `0015` 其他 |
| `ExpectDeliverTime` | N | `1` 13:00 前 / `2` 14~18:00 / `4` 不固定（預設 4） |
| `ExpectDeliverDate` | N | `yyyy-MM-dd`；**最大為建單日 +6 天**；未帶自動為出貨日 D+1 |
| `IsInsurance` | N | 預設 false；保險單金額須 20001~100000 且 `DeliverMode` 須為 `02`；服務代號 36 無須帶（>20000 自動保險） |
| `Sender_Tel` / `Receiver_Tel` | N | 市話 |

`Description` 商品類別代碼：
`0001` 一般食品／`0002` 名特產甜點／`0003` 酒油醋醬／`0004` 穀物蔬果／`0005` 水產肉品／
`0006` 3C／`0007` 家電／`0008` 服飾配件／`0009` 生活用品／`0010` 美容彩妝／`0011` 保健食品／
`0012` 醫療用品／`0013` 寵物用品飼料／`0014` 印刷品／`0015` 其他。

黑貓專屬 API：

| 功能 | Method | 路徑 |
|---|---|---|
| 列印黑貓宅急便標籤 | POST | `/Member/Order/PrintBlackCatLabel` |
| 查詢黑貓材積 | POST | `/api/BlackCat/GetBlackCatVolume` |
| 查詢黑貓材積（api 密碼版） | POST | `/api/BlackCat/QueryBlackCatVolume` |
| 查詢黑貓設定 | GET | `/api/BlackCat/All_BlackCat_Setting` |
| 驗證是否有效地址 | GET | `/api/BlackCat/IsValidAddress` |
| 驗證特殊／偏遠地區 | GET | `/api/BlackCat/IsSpecialAddress` |
| 設定黑貓資料 | GET | `/api/BlackCat/Setting_BlackCat` |
| 更改黑貓資料 | PUT | `/api/BlackCat/Up_BlackCat_Setting` |

- 列印標籤 `LogisticNumbers` = `物流單號_子序號`，多筆以 `,` 分隔（例 `ABCD0017B21903001221_1,ABCD0017B21903001270_1`）；回傳列印畫面。
- 材積回純字串，例 `S60,S90,S120`。
- `IsSpecialAddress` → `{result, ErrorMsg, check_result}`；`check_result=true` 表偏遠地區（**運費 +60**）。
- `Setting_BlackCat` / `Up_BlackCat_Setting` 的 `SettingJson` 需 TripleDES 加密；
  `eshopID=2822995505`（PayNow 契客）時 `IsInsurance` 須帶 `1`、`Logistic_service` 須帶 `36`、
  `size` 預設 `S60,S90,S120,S150`。

### 黑貓到店（46）

`Choselogistics` 選店 → `Add_Order` → 列印 `/Member/Order/PrintBlackCat711Label`。
其餘查詢 / 重新取號 / 材積 / 設定與黑貓宅配共用。

### 7-11 大宗 B2C（02 / 12 常溫、22 冷凍）

| 功能 | Method | 路徑 |
|---|---|---|
| 出貨取號（常溫大宗） | POST | `/api/Bulk711Order/ShipBulk711paymentno` |
| 出貨取號（冷凍大宗） | POST | `/api/711FreezingB2C/Ship711B2Cpaymentno` |
| 修改訂單（常溫） | POST | `/api/Bulk711Order/UpdateB2C711Order` |
| 修改訂單（冷凍） | POST | `/api/711FreezingB2C/Update711B2COrder` |
| 列印標籤（常溫） | GET | `/Member/Order/Print711bulkLabel` |
| 列印標籤（冷凍） | GET | `/Member/Order/Print711FreezingB2CLabel` |
| 退貨便取號 | POST | `/api/Orderapi/ReturnPaymentno` |

### 7-11 冷凍 C2C（21）

`Add_Order` → 列印 `/Member/Order/Print711FreezingC2CLabel`；其餘走通用 API。

### 全家大宗 B2C（04 / 14 常溫、24 冷凍）

| 功能 | Method | 路徑 |
|---|---|---|
| 出貨取號 | POST | `/api/FamiB2COrder/ShipFamiB2Cpaymentno` |
| 修改訂單 | POST | `/api/FamiB2COrder/UpdateFamiB2COrder` |
| 列印標籤 | GET | `/Member/Order/PrintFamiB2CLabel` |
| 退貨便取號 | POST | `/api/Orderapi/ReturnPaymentno` |
| 冷凍：預約倉位確認 | POST | `/api/FamiFreezingB2C/UpSpaceConfirm` |
| 冷凍：修改訂單 | POST | `/api/FamiFreezingB2C/UpdateB2CFamiOrder` |
| 冷凍：列印標籤 | GET | `/Member/Order/PrintFamiFreezingB2CLabel` |
| 冷凍：查詢材積 | GET | `/api/FamiFreezingB2C/GetFamiFreezingVolume` |
| 冷凍：重設店號 | GET | `/Member/Order/ReStoreID` |

### 全家冷凍 C2C（23）

列印 `/Member/Order/PrintFamiFreezingC2CLabel`；
冷凍店號轉換 `/api/FamiFreezingC2C/GetFamiFreezingStoreID`。

### 7-11 海外配送（07 店配 / 08 宅配）

| 功能 | Method | 路徑 |
|---|---|---|
| 海外訂單建立 | POST | `/api/OverSeas711Order` |
| 查詢可配送國家 | GET | `/api/OverSeas711Order/SelCountryID` |
| 查詢重量級距運費 | GET | `/api/Orderapi/SelWeightChart` |
| 查詢帳單明細 | GET | `/api/OverSeas711Order/SelBillDetail` |
| 依日期查詢帳單明細 | GET | `/api/OverSeas711Order/SelBillDetailDate` |

---

## 貨態回傳（PayNow → 商家，HTTP POST）

| 參數 | 型態 | 長度 | 必須 | 備註 |
|---|---|---|---|---|
| `orderno` | string | 30 | Y | 商家自訂編號（重新取號後為新編號） |
| `OriginOrderno` | string | 27 | Y | 商家原始自訂單號 |
| `PayNowLogisticCode` | string | 4 | Y | 貨態代碼 |
| `Detail_Status_Description` | string | | Y | 貨態描述 |
| `paymentno` | string | | Y | 物流商託運單號 |
| `StoreDate` | string | | N | 代碼 `5000`/`5001` 時的實際到店日期 |
| `StoreTime` | string | | N | 代碼 `5000`/`5001` 時的實際到店時間 |

> 黑貓宅配版不含 `StoreDate` / `StoreTime`。

---

## 貨態代碼表

### C2C 共通

| 代碼 | 描述 |
|---|---|
| `0000` | 訂單已成立 等待出貨 |
| `0101` | 商品已到寄件門市 |
| `0102` | 門市已更新寄件中 |
| `0103` | 門市已更新退件中 |
| `4000` | 進驗成功 |
| `4031` | 商品破損退貨中 |
| `4032` | 商品超材退貨中 |
| `4040` | 條碼資料錯誤 |
| `5000` | **取件門市配達** |
| `5001` | 退件門市配達 |
| `7101` | 取件門市關轉店 |
| `7201` | 退件門市關轉店 |
| `8000` | **買家已取件** |
| `8010` | 買家已取件－代收金額錯誤 |
| `8020` | 買家已取件－商品有誤 |
| `8100` | 賣家已取件 |
| `8110` | 賣家已取件－代收金額錯誤 |
| `8120` | 賣家已取件－商品有誤 |
| `9411` | 貨態停滯 |

### 7-11 專屬

`4019` 物流中心未收到貨／`4033` 違禁品罰款退貨中／`4034` 同訂單兩包商品重複／
`4035` 已過門市進貨日／`4036` 門市關轉請更新門市／`4037` 條碼規格錯誤／`4038` 條碼無法判讀／
`4060` 物流中心理貨中／`4061` 商品遺失／`4062` 門市不配送／`4063` 包裹異常不配送／
`4064` 取消寄件再次寄送（直接轉 C 店）／`4065` 提早轉 C 店－廠商因素／`4066` 提早轉 C 店－超商因素／
`5011` 作業錯誤／`5012` 車輛故障／`5013` 天候不佳／`5014` 道路中斷／`5015` 門市停業／
`5016` 缺件／`5017` 門市報缺／`5018` 寄件貨態異常協尋中／`5019` 取件包裹異常協尋中／
`5102` 管制品取件門市配達／`5103` 管制品退件門市配達／`5201` EC 收退／`5202` 交貨便收件／
`5203` 退貨便收件／`5204` 異常收退／`5301` 取消寄件／`5302` 寄件遺失賠償程序／
`5303` 取件遺失賠償作業／`6002` 待退貨請盡速取件／`6003` 退至 7 總倉／`7001` 正常一退／
`7011` 商品瑕疵／`7012` 門市關店／`7013` 門市轉店／`7014` 廠商要求／`7015` 違禁品退貨與罰款／
`7021` 刷 A 給 B／`7022` 消費者要求／`7102` 取件門市舊店號更新／`7104` 取件門市臨時關轉店／
`7202` 退件門市舊店號更新／`7203` 退件門市無取件門市資料／`7204` 退件門市臨時關轉店／
`8077` 退至 7 總倉。

### 全家專屬

`4019` 物流中心未收到貨／`4067` 小物流遺失／`4068` 門市遺失／`4069` 包裝廠不良（滲漏）／
`4070` 門市反應商品包裝不良（滲漏）／`4071` 門市關店／`4072` 條碼資料重複／
`4073` 7 日內未寄件單號失效／`4074` 貨物進店後異常提早退貨／`5200` 商品運送中／
`6002` 待退貨請盡速取件／`6004` 商品退回物流中心／`8002` 退至全家總倉。

### 萊爾富專屬

`4067` 小物流遺失／`4068` 門市遺失／`4069` 包裝廠不良（滲漏）／`4070` 門市反應包裝不良／
`4071` 門市關店／`4073` 7 日內未寄件單號失效／`4074` 貨物進店後異常提早退貨／`8079` 退至萊爾富總倉。

### OK 專屬

`4030` 無進貨資料／`4031` 商品破損退貨中／`4032` 商品超材退貨中／`4040` 條碼資料錯誤／
`4069` 包裝廠不良（滲漏）／`4070` 門市反應商品包裝不良／`4074` 貨物進店後異常提早退貨／
`8076` 退至 OK 總倉。

### 貨態流程

| 情境 | 代碼序列 |
|---|---|
| 一般出貨（成功取件） | `0101 → 5202 → 4000 → 5000 → 8000` |
| 一般出貨（退貨成功） | `0101 → 5202 → 4000 → 5000 → 6002 → 5201 → 7001 → 5001 → 8100` |
| 二次退貨（貨到物流中心） | `0101 → 4000 → 5000 → 6002 → 4000 → 5001 → 8079/8077/8002` |
| 門市關轉（更新後取件成功） | `0101 → 7101 或 7104 → 0102 → 4000 → 5000 → 8000` |
| 門市關轉（更新退件門市後成功） | `0101 → 5202 → 4000 → 5000 → 7201/7203/7204 → 0103 → 4000 → 5001 → 8100` |

---

## 查詢物流單回應欄位

`LogisticNumber`、`sno`、`orderno`、`Logistic_Serviece`（**官方拼字如此，少一個 c**）、
`Status`（`0` 成立中訂單 / `1` 無效訂單）、`Delivery_Status`（流程狀態描述）、
`PayNowLogisticCode`、`Detail_Status_Description`、`paymentno`、`validationno`、
`ErrorMsg`（null 為查詢成功）。

---

## 陷阱

- ❌ **TripleDES 是 ECB 不是 CBC**——用 `DES-EDE3-CBC` 或給 IV 會產生完全不同的密文。
- ❌ **padding 是 Zeros 不是 PKCS#7**——PHP 須 `OPENSSL_ZERO_PADDING` 並自行補 `\0` 到 8 bytes 邊界。
- ❌ base64 後要 `str_replace(' ', '+', ...)`（官方 C# `.Replace(' ', '+')`）。
- ❌ PassCode **不含 `+` 號**，是各值直接相接；輸出十六進位**大寫**。
- ❌ **金額上限依服務不同**：超商 C2C ≤ 20000、黑貓宅配 ≤ 100000。
- ❌ **7-11 訂單全欄位不可含 Ibon 禁用字元**（含 `.` `-` `_` `,` 這些常見符號），
  收件人姓名 / 地址 / 備註都要先過濾。
- ❌ `OrderNo` **限英文與數字**。
- ❌ 取消用 **DELETE**、關轉用 **PUT**——不是全部 POST。
- ❌ 重新取號後若跨過規定天數會拿到 **新的 `paynoworderno`**，後續列印 / 查詢必須改用新編號。
- ❌ 關轉有 **D+7 期限**，逾期無法換店。
- ❌ 黑貓 `ExpectDeliverDate` 最大只能是建單日 +6 天，超過會建單失敗。
- ❌ 黑貓 `IsInsurance=true` 時 `DeliverMode` 必須為 `02`（不可貨到付款）。
- ❌ 服務代碼 `06` 與 `36`/`46` 是不同契約（自有契客 vs PayNow 契客），設定欄位不通用。
- ❌ 回應欄位 `Logistic_Serviece` 是官方拼錯的欄位名，程式須照抄，不可自行更正為 `Service`。
