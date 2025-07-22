<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * Shopline Payment Mode
 */
enum Mode: string {
	/** @var string 測試模式 */
	case TEST = 'test';
	/** @var string 正式模式 */
	case PROD = 'prod';
}
