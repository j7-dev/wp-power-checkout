<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Abstracts;

use J7\PowerCheckout\Domains\Payment\AbstractPaymentService;

/**
 * EcPay 用付款服務抽象類別
 * 1. 請求結束時檢查是否有錯誤，有就印出，提供統一錯誤處理日誌
 */
abstract class PaymentService extends AbstractPaymentService {


}
