# language: zh-TW
功能: 綠界 AES-128-CBC 加解密單一化共用
  作為 開發者
  我想要 把重複的綠界 AES-128-CBC 加解密邏輯抽到單一領域中立的 Shared helper
  以便 消除跨領域抄寫的技術債，並保證抽取後的密文與既有三處使用完全一致

  # einvoice 導入重點 #4：抽 ECPay AES 到單一 Shared。
  # 訪談核實的精確邊界：
  #   - 可合併：Invoice/Ecpay/Shared/Helpers/AesCrypto（AES-128-CBC + base64）
  #            與 Payment/Ecpg/Shared/Helpers/AesCrypto（AES-128-CBC + base64）演算法相同。
  #   - Logistics 已複用 Payment/Ecpg 該套（見 erm.dbml line 337「複用既有 Payment\Ecpg AES-128-CBC AesCrypto」）。
  #   - 抽取目標：ECPay AES-128-CBC 單一化，三處使用（Invoice/Ecpay + Payment/Ecpg + Logistics）共用同一 Shared helper。
  # ⚠️ 不在抽取範圍（硬約束）：Ezpay/Shared/Helpers/AesCrypto 是 AES-256-CBC + hex + 自補 PKCS#7 blocksize=32，
  #   其 docblock 明確警告 padding 行為與 ECPay 不同，混用會被平台回 KEY10002。「絕不」併入 ECPay 共用 helper。

  規則: 加密等價 - 抽取後密文與原 ECPay 實作位元組一致

    場景: 同一明文以共用 helper 加密結果與原 Invoice/Ecpay 實作相同
      假設 一組綠界測試 HashKey 與 HashIV
      而且 一段待加密明文陣列
      當 以共用 ECPay AES helper 加密
      那麼 密文與原 Invoice/Ecpay AesCrypto 加密結果位元組一致

    場景: 同一明文以共用 helper 加密結果與原 Payment/Ecpg 實作相同
      假設 一組綠界測試 HashKey 與 HashIV
      而且 一段待加密明文陣列
      當 以共用 ECPay AES helper 加密
      那麼 密文與原 Payment/Ecpg AesCrypto 加密結果位元組一致

  規則: 解密等價 - 抽取後可解回原文

    場景: 共用 helper 解密原 ECPay 密文得回原文
      假設 一段由原 ECPay 實作產生的密文
      當 以共用 ECPay AES helper 解密
      那麼 解密結果與原始明文一致

  規則: 範圍邊界 - ezPay AES-256-CBC 不併入共用 helper

    場景: ezPay 加解密維持其獨立實作
      假設 ezPay 的 AES-256-CBC hex blocksize=32 實作
      當 檢視抽取範圍
      那麼 ezPay AesCrypto 不被併入 ECPay 共用 helper
      而且 ezPay 維持自補 PKCS#7 blocksize=32 與 hex 小寫輸出

  規則: 後置（行為）- 三處使用改用共用 helper 後行為不變

    場景: 綠界發票開立在改用共用 helper 後仍正常加密 PostData
      假設 "ecpay" 已啟用
      而且 綠界發票開立流程改用共用 ECPay AES helper
      當 開立綠界發票
      那麼 送出的加密資料格式與改動前一致

    場景: 綠界物流建單在改用共用 helper 後仍正常加密
      假設 "ecpay_logistics" 已啟用
      而且 綠界物流建單流程改用共用 ECPay AES helper
      當 建立綠界物流單
      那麼 送出的加密資料格式與改動前一致
