# language: zh-TW
@ignore @command
功能: PAYUNi 統一金流 UNi Embed 買方信用卡 Token 管理（記憶卡號 / 約定卡）
  作為 網站訪客
  我想要 綁定我的信用卡並在後續用 Token 付款
  以便 下次結帳免重新輸入卡號

  # 範本：對齊 payuni-uni-embed-v3 skill 的信用卡 Token 段 + payuni-upp-v2 skill 買方 Token / /api/credit_bind 段。
  # 綁卡：首次交易帶 UseTokenType（1=約定可取消 / 2=記憶卡號 / 3=強制約定不可取消）+ CreditToken（付款人識別，≤150，建議會員編號/Email/手機）+ CreditTokenType（1=會員 / 2=商店）。
  # 授權成功回傳 CreditHash（Token Hash）+ CreditLife（有效日期 MMYY），寫入 _pc_payuni_uni_credit_hash / _pc_payuni_uni_credit_life。
  # ⚠️ 安全硬約束：只保存 PAYUNi 回傳的 Token Hash 與有效日期，絕不保存卡號 / CVC。
  # 後續續期扣款走 UPP /api/credit 體系（共用，UNi Embed 僅負責首次綁卡）。
  # 具體 Example 資料（CreditToken、CreditHash、CreditLife）待 sandbox 驗證階段補充（Phase 03）。

  # --- 綁卡建立（Command） ---
  規則: 前置（參數）- 綁卡須提供 UseTokenType（1/2/3）與 CreditToken（≤150，格式 [A-Za-z0-9@.#$%_-]）

  規則: 後置（狀態）- 綁卡且授權成功時寫入 _pc_payuni_uni_credit_hash 與 _pc_payuni_uni_credit_life

  規則: 後置（狀態）- 系統僅保存 PAYUNi 回傳的 Token Hash 與有效日期，絕不保存卡號 / CVC

  # --- Token 扣款（Command） ---
  規則: 前置（狀態）- 以 Token 扣款須訂單已有有效的 _pc_payuni_uni_credit_hash

  規則: 前置（狀態）- Token 已過期（超過 CreditLife）時不可用於扣款，須引導重新綁卡

  # --- Token 查詢（Query） ---
  規則: 後置（回應）- Token 查詢應回傳 Token 狀態（有效 / 失效 / 過期）與有效日期

  # --- Token 取消（Command） ---
  規則: 約定信用卡（UseTokenType=1）與記憶卡號（UseTokenType=2）可由顧客取消，強制約定（UseTokenType=3）不可取消

  規則: 後置（狀態）- Token 取消成功後清除 _pc_payuni_uni_credit_hash 與 _pc_payuni_uni_credit_life

  # --- 具體範例（payuni-uni-embed-v3 skill + PAYUNi sandbox 測試卡 4147631000000001）---
  # 首次交易帶 UseTokenType（1/2/3）+ CreditToken（≤150）+ CreditTokenType（1=會員 / 2=商店）。
  # 授權成功回傳 CreditHash（Token Hash）+ CreditLife（MMYY）。安全硬約束：絕不存卡號 / CVC。

  場景大綱: 綁卡且授權成功寫入 Token Hash 與有效日期
    假設 訂單 #<order> 付款方式為 payuni_uni_embed
    並且 顧客以測試卡 "4147631000000001" 綁卡，UseTokenType="<token_type>"、CreditToken="member_8821"、CreditTokenType="1"
    當 merchant_trade 授權成功
    那麼 回傳含 CreditHash 與 CreditLife（MMYY）
    並且 寫入 _pc_payuni_uni_credit_hash 與 _pc_payuni_uni_credit_life
    並且 系統不保存卡號 / CVC

    例子:
      | order | token_type | 說明           |
      | 116   | 1          | 約定信用卡     |
      | 117   | 2          | 記憶卡號       |
      | 118   | 3          | 強制約定       |

  場景: CreditToken 格式不合法時拒絕綁卡
    假設 訂單 #119 付款方式為 payuni_uni_embed
    當 顧客以 UseTokenType="1" 但 CreditToken 含非法字元 "member 88!" 綁卡
    那麼 後端拒絕（CreditToken 須符合格式 [A-Za-z0-9@.#$%_-]，長度 ≤150）

  場景: 以有效 Token 扣款
    假設 訂單 #120 付款方式為 payuni_uni_embed 且已有有效的 _pc_payuni_uni_credit_hash（CreditLife 未過期）
    當 以該 Token 進行扣款
    那麼 後端使用既有 CreditHash 進行幕後扣款（續期收款走 UPP /api/credit 體系）

  場景: Token 已過期不可扣款須引導重新綁卡
    假設 訂單 #121 付款方式為 payuni_uni_embed 且 _pc_payuni_uni_credit_life 已超過效期
    當 嘗試以該 Token 扣款
    那麼 後端拒絕扣款並引導顧客重新綁卡

  場景: Token 查詢回傳狀態與有效日期
    假設 顧客有一筆已綁定的買方信用卡 Token
    當 查詢該 Token
    那麼 回傳 Token 狀態（有效 / 失效 / 過期）與有效日期

  場景大綱: 約定 / 記憶卡號可取消，強制約定不可取消
    假設 顧客以 UseTokenType="<token_type>" 綁定了買方信用卡 Token
    當 顧客嘗試取消該 Token
    那麼 取消結果為 "<result>"

    例子:
      | token_type | result                     |
      | 1          | 可取消（約定信用卡）       |
      | 2          | 可取消（記憶卡號）         |
      | 3          | 不可取消（強制約定）       |

  場景: Token 取消成功清除本地 Token 欄位
    假設 訂單 #122 付款方式為 payuni_uni_embed 且已有 _pc_payuni_uni_credit_hash 與 _pc_payuni_uni_credit_life
    當 顧客成功取消約定信用卡 Token
    那麼 清除 _pc_payuni_uni_credit_hash 與 _pc_payuni_uni_credit_life
