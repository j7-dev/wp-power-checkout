# CVS (7-ELEVEN) APIs

## POST `/api/logistics/trade` (Ver 1.3) -- Create CVS Shipment

**EncryptInfo request params**:

| Param | Req | Type | Description | Notes |
|---|---|---|---|---|
| `MerID` | Y | String | Merchant ID |  |
| `Timestamp` | Y | Int | Unix timestamp |  |
| `MerTradeNo` | Y | String | Order number | Max 25, [A-Za-z0-9_-], no repeat in 10 min |
| `GoodsType` | Y | Int | Temp type | 1=normal, 2=frozen |
| `LgsType` | Y | String | Logistics type | B2C or C2C |
| `ShipType` | Y | Int | Channel | Fixed: 1 (7-ELEVEN) |
| `TradeAmt` | Y | Int | Amount | Max 20,000 TWD |
| `ServiceType` | Y | Int | Pickup mode | 1=with payment, 3=no payment |
| `StoreID` | Y | String | Store code | 6 chars |
| `Consignee` | Y | String | Recipient | 2-5 Chinese or 4+ English, max 10 |
| `ConsigneeMobile` | Y | String | Mobile | 09xxxxxxxx |
| `ConsigneeMail` | C | String | Email |  |
| `RefundStoreID` | C | String | Return store | C2C only |
| `SenderName` | C | String | Return recipient | C2C only |
| `SenderMobile` | C | String | Return mobile | C2C only |
| `NotifyURL` | C | String | Callback URL | Port 80/443 only |

**Response**: `ShipTradeNo`, `TradeNo`, `StoreID/Name/Addr`, `TradeStatus`(0), `PaymentType`(5=pay,0=nopay)

## POST `/api/logistics/ship_map` (Ver 1.1) -- CVS Store Map

Form POST redirect. Returns formUrl + params for hidden form.

| Param | Req | Type | Description |
|---|---|---|---|
| `MerKeyNo` | Y | String | Unique key (max 20) |
| `GoodsType` | Y | Int | 1=normal, 2=frozen |
| `LgsType` | Y | String | B2C or C2C |
| `ShipType` | Y | Int | Fixed: 1 |
| `MapType` | Y | Int | 1=all, 2=frozen-capable |
| `MapReturnURL` | Y | String | Callback URL |
| `Tag` | Y | Int | 2=Family, 3=7-11, 4=OK, 5=HiLife |
| `MobileTag` | C | String | Y=mobile, N=desktop |

Callback POST contains `MapJson` with StoreID, StoreName, Address.

## POST `/api/logistics/query` (Ver 1.1) -- Query Status

`LgsType`(Y), `ShipTradeNo`(C), `TradeType`(C,HOME:1=fwd/2=ret), `ReturnOdno`(C)

## POST `/api/logistics/update` (Ver 1.1) -- Modify Shipment

`LgsType`(Y), `ShipTradeNo`(Y), `Consignee`(C), `ConsigneeMail`(C,CVS), `ConsigneeMobile`(C), `ConsigneeAddress`(C,HOME)

## POST `/api/logistics/print_label` (Ver 1.0) -- Print CVS Label

`ShipTradeNo`(Y,comma-sep), `GoodsType`(Y), `LgsType`(Y), ShipType=1, `ShipDate`(Y), `LabelMode`(C,1=single/2=batch)

## POST `/api/logistics/refund` (Ver 1.0) -- CVS Return (C2B)

`ShipTradeNo`(C), `TradeNo`(C), GoodsType=1, LgsType=C2B, `TradeAmt`(Y), `ServiceType`(Y,4=refund/5=norefund), `ShipAmt`(Y), ProcessType=1

## POST `/api/logistics/c2c_to_home_delivery` (Ver 1.0)

`ShipTradeNo`(Y), `Consignee`(Y), `ConsigneeTel`(Y), `ConsigneeAddress`(Y)
