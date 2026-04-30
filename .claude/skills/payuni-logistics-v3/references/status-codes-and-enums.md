# Status Codes, Enums, and Notify Callback

## Notify Callback

PAYUNi POSTs to `NotifyURL`. Payload: form-encoded MerID, EncryptInfo, HashInfo. Verify hash, decrypt, return "OK".

**CVS Notify**: `TradeStatus=1`, `PaymentType=5`, `Odno` (8 chars), `PayTime`.

**Tcat Notify**: `ShipTradeNo`, `ShipStatus`, `OBTNumber`.

## Logistics Status Codes (ShipStatus)

| Code | Meaning | Mapping |
|---|---|---|
| `11` | Picked up / Signed | `delivered` |
| `21` | Created / Pending | `preparing` |
| `22` | Processing | `preparing` |
| `31` | In transit | `shipped` |
| `32` | Arrived at store | `shipped` |
| `51` | Return initiated | `returned` |
| `52` | Return in transit | `returned` |
| `53` | Returned to sender | `returned` |
| `55` | Return completed | `returned` |
| `56` | Return exception | `returned` |
| `91` | Exception processing | `preparing` |
| `92` | Reprocessing | `preparing` |
| `98` | System processing | `preparing` |

## Enum Reference

| Enum | Values |
|---|---|
| **LgsType** | B2C(bulk), C2C(store-to-store), HOME(Tcat), C2B(return) |
| **GoodsType** | 1=normal, 2=frozen, 3=refrigerated(Tcat only) |
| **ServiceType** | 1=pay/COD, 3=nopay, 4=return+refund(C2B), 5=return(C2B) |
| **ShipType** | 1=7-ELEVEN, 2=Tcat |
| **Tag** | 2=FamilyMart, 3=7-ELEVEN, 4=OK, 5=Hi-Life |
| **DeliveryTimeTag** | 01=<13:00, 02=14-18, 04=any |
| **Spec** | 1=60cm, 2=90cm, 3=120cm, 4=150cm |
