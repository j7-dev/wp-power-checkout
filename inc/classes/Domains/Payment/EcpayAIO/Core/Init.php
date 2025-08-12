<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Core;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Shared\BlocksIntegration;
use J7\WpUtils\Classes\General;

/**
 * Init 初始化付款方式 單例
 */
final class Init {
    use \J7\WpUtils\Traits\SingletonTrait;
    
    /** @var string 付款方式 callback 的 action 前綴 */
    public const PREFIX = 'pc_ecpayaio_';
    
    /** Constructor */
    public function __construct() {
        \add_filter( 'woocommerce_payment_gateways', [ $this, 'add_method' ] );
        
        // 整合區塊結帳
        \add_action( 'woocommerce_blocks_payment_method_type_registration', [ $this, 'register_checkout_blocks' ] );
    }
    
    /** 添加付款方式 @param array<string> $methods 付款方式 @return array<string> */
    public function add_method( array $methods ): array {
        $methods[] = Atm::class;
        $methods[] = Barcode::class;
        $methods[] = Credit::class;
        $methods[] = CreditInstallment::class;
        $methods[] = CVS::class;
        $methods[] = WebAtm::class;
        return $methods;
    }
    
    /** 註冊區塊結帳支援 */
    public function register_checkout_blocks( PaymentMethodRegistry $payment_method_registry ): void {
        /** @noinspection ClassConstantCanBeUsedInspection */
        if( !class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }
        
        $gateway_ids = [
            Atm::ID,
            Barcode::ID,
            Credit::ID,
            CreditInstallment::ID,
            CVS::ID,
            WebAtm::ID,
        ];
        
        $gateways = \WC()->payment_gateways()->payment_gateways;
        
        foreach ( $gateway_ids as $gateway_id ) {
            $gateway = General::array_find( $gateways, static fn( $gateway ) => $gateway->id === $gateway_id );
            if( !$gateway ) {
                continue;
            }
            $payment_method_registry->register( new BlocksIntegration( $gateway ) );
        }
    }
}
