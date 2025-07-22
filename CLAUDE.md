# CLAUDE.md

此檔案為 Claude Code (claude.ai/code) 在此專案中工作時的指導文件。

## 專案概覽

Power Checkout 是一個專為 WooCommerce 設計的結帳體驗優化外掛，整合金流、物流與電子發票功能。專案採用現代化的 PHP + React 架構，遵循 WordPress 外掛開發標準。

## 核心技術架構

### 後端架構
- **WordPress Plugin Framework**: 基於 WordPress 外掛架構
- **PHP 8.1+** 配合 **Composer** 套件管理
- **PSR-4 Autoloading**: 命名空間為 `J7\PowerCheckout`
- **WP Utils Framework**: 使用 `j7-dev/wp-utils` 作為基礎工具庫
- **依賴管理**: 依賴 WooCommerce (>=8.3.0) 與 Powerhouse 外掛

### 前端架構
- **React 18** + **TypeScript** + **Vite**
- **TanStack Query** 處理 API 狀態管理
- **Ant Design** UI 組件庫
- **Multiple App 模式**: 支援在同一頁面渲染多個 React 應用
- **Vite for WordPress**: 使用 `@kucrut/vite-for-wp` 整合

### 專案結構
```
├── plugin.php           # 主要外掛檔案
├── inc/classes/         # PHP 類別檔案 (PSR-4)
│   ├── Bootstrap.php    # 核心啟動類別
│   ├── Admin/          # 管理介面相關
│   ├── FrontEnd/       # 前端相關
│   ├── Domains/        # 業務邏輯領域
│   └── Utils/          # 工具類別
├── js/src/             # React 前端原始碼
│   ├── main.tsx        # 主要入口點
│   ├── App1.tsx        # 應用程式 1
│   ├── App2.tsx        # 應用程式 2
│   ├── api/            # API 相關
│   ├── components/     # 共用組件
│   ├── hooks/          # 自定義 Hooks
│   ├── pages/          # 頁面組件
│   ├── types/          # TypeScript 型別定義
│   └── utils/          # 工具函數
├── inc/templates/      # PHP 模板檔案
├── inc/tests/          # PHP 測試檔案 (Pest)
└── js/dist/           # 前端建置輸出
```

## 常用開發指令

### 環境設置
```bash
pnpm bootstrap          # 安裝依賴 (pnpm + composer)
```

### 開發建置
```bash
pnpm dev               # 啟動 Vite 開發伺服器 (port 5181)
pnpm build             # 建置前端資源與移動 manifest
pnpm preview           # 預覽建置結果
```

### 代碼品質檢查
```bash
pnpm lint              # ESLint (前端) + PHPCS (後端)
pnpm lint:fix          # 自動修正代碼風格問題
pnpm format            # Prettier 格式化前端代碼

# PHP 相關
composer lint          # 執行 PHPCS 檢查
./vendor/bin/pest      # 執行 Pest 測試
./vendor/bin/pest --coverage  # 執行測試並產生覆蓋率報告
```

### 版本發佈
```bash
pnpm release           # 發佈 patch 版本
pnpm release:patch     # 發佈 patch 版本
pnpm release:minor     # 發佈 minor 版本
pnpm release:major     # 發佈 major 版本
pnpm release:build-only # 僅建置不發佈
pnpm zip               # 建立外掛壓縮檔
```

### 國際化與版本同步
```bash
pnpm i18n              # 產生 .pot 翻譯模板
pnpm i18n:commit       # 產生翻譯檔案並提交
pnpm sync:version      # 同步 package.json 版本到 plugin.php
```

## 開發注意事項

### PHP 開發規範
- 嚴格使用 PHP 8.1+ 語法，包含 `declare(strict_types=1)`
- 遵循 WordPress Coding Standards (PHPCS)
- 使用 PHPStan level 9 進行靜態分析
- 所有類別應為 final class 或 abstract
- 使用 PSR-4 autoloading 標準

### 前端開發模式(尚未開發)
- 採用 Multiple App 架構，通過 CSS selector 掛載不同 React 應用
- 使用 `app1Selector` 和 `app2Selector` 識別掛載點
- TanStack Query 負責 API 狀態管理，關閉自動重新整理
- 支援 React Query DevTools (開發模式)

### WordPress 整合要點
- 依賴 WooCommerce 8.3.0+ 版本
- 需要 Powerhouse 外掛作為基礎依賴
- 使用 WordPress REST API 與前端通信
- 透過 `wp_localize_script` 傳遞環境變數到前端

### 測試與代碼檢查
- PHP: 使用 Pest 測試框架，支援覆蓋率報告
- 前端: ESLint + Prettier 自動格式化
- 提交前必須通過 lint 檢查
- PHPStan 設定為最高等級 (level 9)

### 建置與部署
- Vite 建置前端資源到 `js/dist/`
- `mv-manifest.cjs` 腳本處理 Vite manifest 檔案
- Release-it 自動化版本管理與 GitHub 發佈
- 支援建立 WordPress 外掛標準 zip 檔案

### 待辦事項
根據 README.md，目前需要：
1. 支援 HPOS (High-Performance Order Storage)
2. 支援 WooCommerce 新版本結帳頁
3. 每次付款請求都將參數儲存在 order meta 中

## 開發工作流程

1. 使用 `pnpm bootstrap` 初始化環境
2. 前端開發使用 `pnpm dev` 啟動開發伺服器
3. 前端開發完成後執行 `pnpm lint` 確保代碼品質
4. 後端開發執行 `./vendor/bin/pest` 確保測試通過
5. 前端使用 `pnpm build` 建置生產版本
6. 專案使用 `pnpm release` 發佈新版本