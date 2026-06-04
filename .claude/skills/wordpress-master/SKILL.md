---
name: wordpress-master
description: >
  WordPress Plugin 開發的工作流程與專案架構指南。涵蓋測試撰寫與驗證、
  審查提交與退回處理、除錯技巧、DDD 領域驅動設計架構、新增檔案原則。
  供 wordpress-master agent 開發時載入。
---

# WordPress Master

WordPress Plugin 資深工程師的開發工作流程與架構參考。

## Reference Files

- `references/wp-dev-workflow.md` — 開發工作流程：測試撰寫與驗證、審查提交與退回處理迴圈、技術債處理策略、除錯技巧、工具使用
- `references/wp-project-architecture.md` — 專案架構指南：DDD 領域驅動設計目錄結構（Application / Domain / Infrastructure / Shared）、新增檔案原則、WordPress 區塊開發註冊

Read the relevant reference file(s) based on the task at hand.

## Quick Decision: Which Reference?

```
任務是...
├─ 寫測試 / 提交審查 / 處理退回 → references/wp-dev-workflow.md
├─ 除錯 / 查 DB / 設定 xdebug → references/wp-dev-workflow.md
├─ 理解專案結構 / DDD 架構 → references/wp-project-architecture.md
├─ 新增檔案 / 決定放哪個目錄 → references/wp-project-architecture.md
└─ 註冊 WordPress 區塊 → references/wp-project-architecture.md
```
