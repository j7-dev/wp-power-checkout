<?php

namespace J7\PowerCheckoutTests\Shared;

/**
 * Group 列舉
 * Pest 的 group
 *
 * @example composer test -- --group=payment
 */
enum Group : string {
    
    // ----- 金流相關 ----- //
    
    /** 付款流程初始化（建立訂單、選擇付款方式) */
    case PAYMENT_INIT = 'payment:initiation';
    /** 付款成功後的處理（狀態更新、通知、庫存扣除） */
    case PAYMENT_SUCCESS = 'payment:success';
    /** 付款失敗或取消（錯誤訊息、重試機制） */
    case PAYMENT_FAILURE = 'payment:failure';
    /** 退款流程（部分退款、全額退款、API 回傳驗證）*/
    case PAYMENT_REFUND = 'payment:refund';
    /** 第三方金流的 callback 處理（驗證簽章、狀態同步） */
    case PAYMENT_CALLBACK = 'payment:callback';
    
    
    // ----- 物流相關 ----- //
    
    /** 運送方式選擇（宅配、超商取貨、黑貓、郵局） */
    case SHIPPING_METHOD = 'shipping:method';
    /** 運費計算（依地區、重量、優惠） */
    case SHIPPING_RATE = 'shipping:rate';
    /** 物流單產生（API 整合、格式驗證） */
    case SHIPPING_LABEL = 'shipping:label';
    /** 物流追蹤（查詢 API、狀態更新） */
    case SHIPPING_TRACKING = 'shipping:tracking';
    /** 超商取貨流程（門市選擇、取貨通知） */
    case SHIPPING_PICKUP = 'shipping:pickup';
    /** 物流異常處理（退貨、遺失、延遲） */
    case SHIPPING_EXCEPTION = 'shipping:exception';
    
    
}