---
name: wp-dev-workflow
description: WordPress 開發工作流程：測試撰寫與驗證、Quality Gate、技術債處理策略、除錯技巧。供 @wordpress-master agent 開發完成後的交付流程使用。驗收與 reviewer 均為 opt-in。
---

# WordPress 開發工作流程

## 一、測試撰寫與驗證（交付前必做）

### 步驟 1：撰寫測試

完成功能開發後，**必須**為新增或修改的功能撰寫對應的測試：

- **單元測試**：針對 Service、DTO、Enum、Repository 等核心邏輯撰寫 PHPUnit 測試
- **整合測試**：若涉及 WordPress Hook、REST API、資料庫操作，撰寫整合測試
- **測試涵蓋範圍**：至少涵蓋主要流程（happy path）與關鍵的錯誤場景（error path）

```php
// 測試檔案路徑應對應原始碼路徑
// 例如：inc/src/Domain/Product/ProductService.php
//   →  tests/Domain/Product/ProductServiceTest.php
```

- 測試目錄結構對應原始碼目錄結構
- 使用 `@testdox` 標註繁體中文測試說明
- 測試指令請查閱專案的 `composer.json` 或 `CLAUDE.md`

```php
declare(strict_types=1);

namespace MyPluginTests\Domain\Product;

/**
 * 商品服務測試
 */
class ProductServiceTest extends \WP_UnitTestCase {

    /**
     * @testdox 取得商品列表成功
     */
    public function test_get_product_list_success(): void {
        $service = new ProductService();
        $result  = $service->get_product_list();

        $this->assertGreaterThan( 0, \count( $result ) );
    }

    /**
     * @testdox 當商品 ID 不存在時應拋出異常
     */
    public function test_get_product_with_invalid_id_throws_exception(): void {
        $this->expectException( \RuntimeException::class );

        $service = new ProductService();
        $service->get_product( 999999 );
    }
}
```

> 禁止跳過：沒有測試的代碼不得進入交接流程。若功能性質確實無法撰寫單元測試（如純 UI Hook 註冊），需在交付摘要中說明原因。

### 步驟 2：執行所有測試並確認通過

進入交付前，**必須**執行以下 Quality Gate 並確認全數通過：

```bash
# 1. 靜態分析（如果專案有配置）
composer phpstan
composer psalm

# 2. 代碼風格檢查
composer phpcs

# 3. 單元測試 / 整合測試
composer test
# 或
./vendor/bin/phpunit
```

> 只有當所有測試全數通過時，才可以進入下一步交付。若有測試失敗，必須先修復再重新執行測試，直到全部通過。

---

## 二、完成後的動作：交付給主窗口

Quality Gate 全部通過後，回報主窗口，附變更摘要與測試結果。

**Opt-in 深度審查**：若用戶要求進一步 code review，再由用戶顯式喚醒對應 reviewer：

```
@wordpress-reviewer   ← 程式品質深度審查（opt-in）
@zenbu-powers:security-reviewer    ← 安全審查（涉及 auth / payment / nonce 強烈建議，opt-in）
```

---

## 三、用戶顯式發起 review-fix 迴圈時的處理流程

僅當用戶顯式喚醒 `@wordpress-reviewer` 並要求進入「reviewer ↔ master」修復迴圈時：

1. **逐一檢視**：仔細閱讀 reviewer 列出的所有嚴重問題和重要問題
2. **逐一修復**：按照 reviewer 的建議修改代碼，不可忽略任何阻擋合併的問題
3. **補充測試**：若 reviewer 指出缺少測試覆蓋的場景，補寫對應測試
4. **重新執行測試**：修改完成後，重新執行所有測試確認通過
5. **再次提交審查**：測試通過後，由用戶決定是否再次喚醒 reviewer

> 此 opt-in 迴圈最多進行 **3 輪**，若超過 3 輪仍未通過，應停止並請求人類介入。

---

## 四、遇到違背原則的專案時的處置

### 步驟 1：評估當前任務性質

判斷當前的任務/Issue 是否屬於 **[優化]**、**[重構]**、**[改良]** 類型。

### 步驟 2A：是 [優化] / [重構] / [改良] 任務

- 使用 Serena MCP 查看代碼的引用關係
- 執行重新命名（rename）、移動檔案、修改專案目錄等重構操作
- 確保重構後所有引用都正確更新

### 步驟 2B：不是 [優化] / [重構] / [改良] 任務

- 維持**最小變更原則**
- 只做當前任務所需的修改
- 避免大規模重構導致更多問題
- 在 PR 中標註發現的技術債，建議後續 Issue 處理

---

## 五、除錯技巧

### 查看 DB 資料

如果有 MySQL MCP 或 LocalWP MCP，可以直接查看 DB 數值是否與預期相同。

### 中斷點

如果有 Xdebug MCP，可以在本地環境設置中斷點，查看程式執行流程和變數狀態。

### 查看 Log

- 使用 `WC_Logger`、`error_log` 等方法印出日誌
- 從 `/wp-content/debug.log`、`/wp-content/uploads/wc-logs/` 查看錯誤日誌
- 使用 `WP_DEBUG`、`WP_DEBUG_LOG` 等常量來控制日誌輸出

### 開啟除錯模式（wp-config.php）

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## 六、工具使用

- 優先使用 **serena MCP** 查看代碼引用關係，快速定位問題所在
- 使用 **local-wp MCP** 或 **MySQL MCP** 查看 DB 資料
- 使用 **Xdebug MCP** 設置中斷點除錯
- 使用 **web_search** 搜尋解決方案
- 遇到不確定的 WordPress/WooCommerce API 用法時，主動上網搜尋官方文件
