# Home Delivery (Tcat) APIs

## POST `/api/home_delivery/trade` (Ver 1.2) -- Create Shipment

| Param | Req | Type | Description |
|---|---|---|---|
| `MerTradeNo` | Y | String | Order number (max 25) |
| `GoodsType` | Y | Int | 1=normal, 2=refrigerated, 3=frozen |
| `LgsType` | Y | String | Fixed: HOME |
| `ShipType` | Y | Int | Fixed: 2 (Tcat) |
| `TradeAmt` | Y | Int | Order amount |
| `ServiceType` | Y | Int | 1=COD, 3=no payment |
| `DeliveryTimeTag` | Y | String | 01=<13:00, 02=14-18, 04=any |
| `Consignee` | Y | String | Recipient name |
| `ConsigneeMobile` | Y | String | Mobile |
| `ConsigneeAddress` | Y | String | Delivery address |
| `ProdDesc` | Y | String | Product (max 20) |
| `NotifyURL` | C | String | Callback URL |

## POST `/api/home_delivery/get_obt_number_pdf` (Ver 1.0) -- PDF

PostType=1, PrintType=1, `ShipTradeNo`(Y), `GoodsType`(Y), LgsType=HOME, ShipType=2, `ShipDate`(Y), `DeliveryDate`(Y), `Spec`(Y,1=60/2=90/3=120/4=150cm)

## POST `/api/home_delivery/download_pdf` (Ver 1.0)

`FileNo`(Y) + optional `ShipTradeNo`

## POST `/api/home_delivery/call_cat` (Ver 1.0) -- Driver Pickup

`ContactName`(Y), `ContactMobile`(C), `ContactAddress`(Y), `NormalQuantity`(Y), `ColdQuantity`(Y), `FreezeQuantity`(Y), `IsContact`(Y), `IsTrolley`(Y). Exactly one temp > 0.

## POST `/api/home_delivery/refund` (Ver 1.0) -- Tcat Return

`ShipTradeNo`(C), `GoodsType`(Y), LgsType=HOME, ServiceType=3, `DeliveryTimeTag`(Y), `Consignee`(Y), `ConsigneeAddress`(Y), `Consignor`(Y), `ConsignorAddress`(Y), `ProdDesc`(Y), `Spec`(Y,1-4), `ShipDate`(Y)
