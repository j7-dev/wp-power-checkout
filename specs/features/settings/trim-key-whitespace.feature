# language: zh-TW
功能: 金鑰類欄位自動修剪前後不可見字元
  作為 網站管理員
  我想要 在儲存金鑰／設定欄位時系統自動移除前後不可見字元
  以便 避免從 Shopline、Amego 後台或其他來源複製貼上時不小心帶到的空白、全形空白、零寬字元造成 API 認證失敗

  背景:
    假設 管理員已登入後台
    而且 已進入 WooCommerce > 設定 > Power Checkout 設定 分頁
    而且 系統中有以下 Provider 設定：
      | provider_id               | enabled | title                     |
      | shopline_payment_redirect | yes     | Shopline Payment 線上付款 |
      | amego                     | yes     | 光貿電子發票              |

  規則: 後端儲存時對所有設定欄位修剪前後不可見字元（最終防線）

    場景大綱: 儲存 Shopline Payment 金鑰類欄位時自動修剪前後空白
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key      | value             |
        | <field>  | <input_value>     |
      那麼 回應狀態碼為 200
      而且 wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "<field>" 為 "<expected_stored>"
      而且 回應 data 中的 "<field>" 為 "<expected_stored>"

      例子:
        | field      | input_value          | expected_stored   |
        | platformId | "  platform_001  "   | "platform_001"    |
        | merchantId | "merchant_xyz "      | "merchant_xyz"    |
        | apiKey     | " sk_live_abc123 "   | "sk_live_abc123"  |
        | clientKey  | "pk_live_xyz789  "   | "pk_live_xyz789"  |
        | signKey    | "  sign_secret_key"  | "sign_secret_key" |
        | apiUrl     | " https://a.com/ "   | "https://a.com/"  |

    場景大綱: 儲存 Amego 電子發票欄位時自動修剪前後空白
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/amego，參數為：
        | key      | value           |
        | <field>  | <input_value>   |
      那麼 回應狀態碼為 200
      而且 wp_options 中 "woocommerce_amego_settings" 的 "<field>" 為 "<expected_stored>"

      例子:
        | field   | input_value     | expected_stored |
        | invoice | " 12345678 "    | "12345678"      |
        | app_key | "  amego_key  " | "amego_key"     |

    場景大綱: 描述、標題類欄位也適用前後修剪（全部欄位皆 trim 前後不可見字元）
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key     | value           |
        | <field> | <input_value>   |
      那麼 wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "<field>" 為 "<expected_stored>"

      例子:
        | field             | input_value           | expected_stored   |
        | title             | "  我的金流標題  "    | "我的金流標題"    |
        | description       | " 我的金流描述 "      | "我的金流描述"    |
        | order_button_text | "  立即付款 "         | "立即付款"        |

    場景大綱: 多種不可見字元都會被修剪
      假設 管理員已登入並取得 Nonce
      當 管理員儲存 Shopline Payment 設定，apiKey 欄位包含特殊字元：
        | char_type     | input_hex_around                                      | expected_stored  |
        | 半形空白      | "0x20 sk_live_abc 0x20"                               | "sk_live_abc"    |
        | Tab           | "0x09 sk_live_abc 0x09"                               | "sk_live_abc"    |
        | 換行 LF       | "0x0A sk_live_abc 0x0A"                               | "sk_live_abc"    |
        | 換行 CRLF     | "0x0D 0x0A sk_live_abc 0x0D 0x0A"                     | "sk_live_abc"    |
        | 全形空白 U+3000 | "U+3000 sk_live_abc U+3000"                         | "sk_live_abc"    |
        | 不換行空白 U+00A0 | "U+00A0 sk_live_abc U+00A0"                       | "sk_live_abc"    |
        | 零寬空白 U+200B | "U+200B sk_live_abc U+200B"                         | "sk_live_abc"    |
        | 零寬非連接 U+200C | "U+200C sk_live_abc U+200C"                       | "sk_live_abc"    |
        | 零寬連接 U+200D | "U+200D sk_live_abc U+200D"                         | "sk_live_abc"    |
        | BOM U+FEFF      | "U+FEFF sk_live_abc U+FEFF"                         | "sk_live_abc"    |
        | 混合不可見字元  | "0x20 U+3000 U+200B sk_live_abc U+200C 0x09"        | "sk_live_abc"    |
      那麼 wp_options 中對應 "apiKey" 的儲存值與 expected_stored 一致

    場景: 中間的不可見字元【不會】被修剪（保留可能合法的內部字元）
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key    | value             |
        | apiKey | "sk_live abc 123" |
      那麼 wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "apiKey" 為 "sk_live abc 123"

    場景: 純不可見字元的欄位儲存後變成空字串
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key    | value     |
        | apiKey | "    "    |
      那麼 wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "apiKey" 為 ""

    場景: 修剪邏輯位於 ProviderUtils::update_option，未來新 Provider 自動受惠
      假設 系統中存在一個未來新增的 provider "future_provider"
      當 透過 ProviderUtils::update_option('future_provider', ['some_key' => '  value  '])
      那麼 wp_options 中 "woocommerce_future_provider_settings" 的 "some_key" 為 "value"

  規則: 讀取設定時對既有資料即時 trim（既有資料無感修復）

    場景: 既有資料庫中帶空白的金鑰，DTO 讀取時自動修剪
      假設 wp_options "woocommerce_shopline_payment_redirect_settings" 的 "apiKey" 已經是 "  sk_live_legacy  "（升級前殘留）
      當 系統呼叫 RedirectSettingsDTO::instance()
      那麼 DTO 物件的 apiKey 屬性為 "sk_live_legacy"

    場景: 既有資料的 Amego app_key 讀取時自動修剪
      假設 wp_options "woocommerce_amego_settings" 的 "app_key" 已經是 " amego_legacy_key "（升級前殘留）
      當 系統呼叫 AmegoSettingsDTO::instance()
      那麼 DTO 物件的 app_key 屬性為 "amego_legacy_key"

    場景: 讀取時的 trim 不會主動寫回資料庫
      假設 wp_options "woocommerce_shopline_payment_redirect_settings" 的 "apiKey" 已經是 "  sk_live_legacy  "
      當 系統呼叫 RedirectSettingsDTO::instance()
      那麼 wp_options 中的原始值仍為 "  sk_live_legacy  "（沒有副作用，僅 in-memory trim）
      但是 下次管理員手動點儲存時，原始值會被覆寫為已修剪的 "sk_live_legacy"

  規則: 前端 Vue 表單在欄位失焦時即時修剪（UX 加分）

    場景大綱: el-input 失去焦點時自動修剪欄位前後不可見字元
      假設 管理員開啟 Shopline Payment 設定頁
      當 管理員在 "<field_label>" 欄位貼上 "<input_value>"
      而且 點擊欄位以外的位置（input 失去焦點）
      那麼 欄位顯示值為 "<displayed_value>"
      而且 表單 v-model 綁定值為 "<displayed_value>"
      而且 不顯示任何提示訊息（靜默修剪）

      例子:
        | field_label  | input_value          | displayed_value  |
        | API Key      | "  sk_live_abc123  " | "sk_live_abc123" |
        | Merchant ID  | "merchant_001 "      | "merchant_001"   |
        | 統一編號     | "　12345678　"        | "12345678"       |

    場景: 中間空白在前端不被移除
      假設 管理員開啟 Shopline Payment 設定頁
      當 管理員在 API Key 欄位輸入 "sk_live abc 123"
      而且 欄位失去焦點
      那麼 欄位顯示值為 "sk_live abc 123"

    場景: 共用 TrimmedInput 元件包裝 el-input
      假設 前端有共用元件 "TrimmedInput"
      當 開發者使用 <TrimmedInput v-model="form.apiKey" />
      那麼 該元件對外行為與 el-input 一致（接收 v-model、clearable、disabled 等 prop）
      而且 該元件在 @blur 事件觸發時對 v-model 綁定值執行 trim 不可見字元邏輯
      而且 修剪邏輯涵蓋與後端相同的字元集（半形空白、Tab、換行、全形空白、零寬字元、BOM、不換行空白）

    場景: 管理員透過 REST API 直接打 API（繞過前端）仍受後端保護
      假設 第三方腳本直接 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect
      而且 payload 為 { "apiKey": "  sk_live_abc123  " }
      那麼 wp_options 儲存的 "apiKey" 為 "sk_live_abc123"
      因為 後端 ProviderUtils::update_option 是最終防線

  規則: 修剪邏輯不影響其他既有行為

    場景: 修剪不破壞既有 sanitize_text_field_deep 消毒邏輯
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key   | value                          |
        | title | " <script>alert(1)</script> "  |
      那麼 wp_options 中 "title" 不包含 "<script>"
      而且 wp_options 中 "title" 沒有前後空白

    場景: 修剪不影響數值類欄位（int / float）
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key        | value   |
        | min_amount | "  5  " |
      那麼 wp_options 中 "min_amount" 為 5（整數）

    場景: 修剪不影響陣列類欄位內元素
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key                    | value                                |
        | allowPaymentMethodList | ["  CreditCard  ", " LinePay "]      |
      那麼 wp_options 中 "allowPaymentMethodList" 為 ["CreditCard", "LinePay"]

    場景: 不會修剪 'enabled' 之類非字串型欄位的合法值
      假設 wp_options "woocommerce_amego_settings" 的 "enabled" 為 "yes"
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/amego/toggle
      那麼 wp_options 中 "enabled" 為 "no"
      而且 不受 trim 邏輯影響

  規則: 客服 SOP 連動

    場景: 客服可建議「請按一次儲存」即可清理舊資料
      假設 商店 A 在升級前 wp_options 中 apiKey 為 "  sk_live_old  "
      當 管理員依客服指示開啟設定頁
      而且 不修改任何欄位，直接點擊「儲存」按鈕
      那麼 wp_options 中 "apiKey" 為 "sk_live_old"（前後空白被清除）
      而且 下次呼叫 SLP API 時不會再因隱形字元失敗
