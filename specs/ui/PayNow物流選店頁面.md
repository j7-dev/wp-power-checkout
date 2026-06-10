# PayNow 物流選店頁面

PayNow（立吉富）物流超商選店頁面。顧客在 classic checkout 結帳頁選擇 PayNow 超商取貨運送方式（7-11 / 全家 / HiLife）後，點按「選擇超商」按鈕，瀏覽器 form-POST 導轉至 PayNow 選店地圖頁；顧客選店後 PayNow 導回 returnUrl callback，門市資訊回填結帳頁。

> 知識來源：paynow skill 無物流 API，本頁依 woomp（../woomp/includes/paynow-shipping/templates/cart/choose-cvs.php + assets/js/paynow-shipping-public.js）反推。
> CiC(ASM): 依 woomp 反推；待 PayNow 官方文件核對選店地圖頁網域與參數。

## 描述

第三方系統（PayNow）託管的 RWD 超商門市地圖頁，非本站頁面。本站只負責：
1. 結帳頁顯示「選擇超商」按鈕（超商取貨運送方式才顯示；黑貓宅配 TCAT 不顯示）
2. 點按後組裝隱藏表單（帶 user_account / TripleDES 加密 apicode / Logistic_serviceID / returnUrl）form-POST 至 `{api_url}/Member/Order/Choselogistics`
3. 接收 PayNow 導回 returnUrl 的門市資訊（storeid / storename / storeaddress），回填結帳頁隱藏欄位並顯示已選門市

## 關鍵屬性

- **觸發按鈕**：`#choose-cvs-btn`（超商取貨運送方式才渲染；黑貓宅配跳過）
- **導轉端點**：`{api_url}/Member/Order/Choselogistics`（PayNow 託管地圖，非本站）
- **returnUrl**：本站選店 callback（帶 `?cid={cart_hash}` 關聯購物車），對應 `paynow-logistics-selection-callback.feature`
- **回填欄位**：`paynow_storeid` / `paynow_storename` / `paynow_storeaddress`（隱藏 input，回填後顯示「選擇超商:{門市名}」）
- **強制條件**：超商取貨須強制勾選「寄送至不同地址」（ship to different address），門市地址作為收件地址
- **冷凍變體**：全家冷凍 C2C（service 23/24）顯示額外冷凍欄位（`.paynow-shipping-family-frozen-field`）
- **block checkout**：本頁為 classic checkout；WC Blocks 選店 UI 第二期後補（對齊既有 ECPay 物流 classic-first 慣例）
- CiC(GAP): PayNow 選店地圖頁的實際 UI（門市清單呈現、地圖互動）由 PayNow 託管，本站不控制；具體網域與回傳欄位待 sandbox 實測。
