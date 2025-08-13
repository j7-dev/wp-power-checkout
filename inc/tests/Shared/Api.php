<?php

namespace J7\PowerCheckoutTests\Shared;

/**
 * 測試使用的 API 模式
 * 要不要真的打 API 出去
 *
 * @example composer test -- --API=live
 */
enum Api : string {
    case MOCK = 'mock'; // 模擬 API
    case LIVE = 'live'; // 正式 API
    case SANDBOX = 'sandbox'; // 沙盒 API
}