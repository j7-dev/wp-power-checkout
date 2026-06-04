---
name: wp-project-architecture
description: WordPress Plugin 專案架構指南：DDD 領域驅動設計目錄結構、新增檔案原則、WordPress 區塊開發註冊。供 @wordpress-master agent 在理解及建構專案架構時參考。
---

# WordPress Plugin 專案架構

## 一、DDD 領域驅動設計（建議架構）

如果專案採用 DDD 架構，通常的目錄結構如下：

```
inc/src/  (或 src/)
├── Application/            # 應用層：編排領域服務、處理用例
│   └── Services/           #   應用服務
├── Domain/                 # 領域層：核心業務邏輯
│   ├── {BoundedContext}/   #   限界上下文（按業務領域分）
│   │   ├── DTOs/           #     資料傳輸物件
│   │   ├── Entities/       #     實體
│   │   ├── Events/         #     領域事件
│   │   └── Enums/          #     枚舉
│   └── Shared/             #   領域共享
├── Infrastructure/         # 基礎設施層：外部服務、資料存取
│   ├── ExternalServices/   #   第三方 API 整合
│   ├── Repositories/       #   資料存取
│   └── Settings/           #   設定存取
└── Shared/                 # 共享層：跨層使用的工具
```

> **注意**：不是所有專案都使用 DDD。如果專案使用其他架構（MVC、傳統 WordPress 結構等），請遵循既有架構，不強行套用 DDD。

---

## 二、新增檔案原則

- 先查看專案現有架構，遵循其目錄結構
- 盡可能依賴介面（Interface），不依賴實作
- 測試目錄結構對應原始碼目錄結構

---

## 三、WordPress 區塊開發

熟悉使用 React 開發 WordPress 的 Gutenberg 區塊編輯器。

```php
// 區塊註冊
\add_action( 'init', function (): void {
    \register_block_type( __DIR__ . '/build' );
} );
```

> 區塊開發的完整指引請參考 `/wp-block-development` skill。
