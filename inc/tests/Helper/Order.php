<?php

declare( strict_types = 1 );

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\STDOUT;
use J7\PowerCheckoutTests\Shared\Resource;
use J7\WpUtils\Classes\WP;

/**
 * Order class
 * 1. 實例化 Order 類別時，會自動創建訂單
 * 2. 有 create 跟 add 方法
 *
 * @see https://rudrastyh.com/woocommerce/create-orders-programmatically.html
 */
class Order extends Resource {
    use \J7\WpUtils\Traits\SingletonTrait;
    
    /** @var string 資源標籤 */
    protected string $label = '訂單';
    
    /** @var \WC_Order[] */
    protected array $items = [];
    
    /**
     * 創建訂單
     *
     * @param array<string, mixed> $args 訂單資料
     *                                   參考 https://woocommerce.github.io/code-reference/files/woocommerce-includes-abstracts-abstract-wc-data.html#source-view.462
     * @param int                  $qty  創建數量
     *
     * @return self
     */
    public function create( array $args = [], int $qty = 1 ): self {
        
        /** @var \WP_User $user */
        $user = User::instance()->create()->get_item();
        /** @var \WC_Product $product */
        $product = Product::instance()->create()->get_item();
        
        for ( $i = 0; $i < $qty; $i++ ) {
            $default_args = [
                'status'             => 'pending', // 等待付款中
                'created_via'        => 'admin', // default values are "admin", "checkout", "store-api"
                'customer_id'        => $user->ID, // 客戶ID
                
                // billing 資料
                'billing_address_1'  => '台北市信義區信義路五段7號',
                'billing_address_2'  => '101大樓35樓',
                'billing_city'       => '台北市',
                'billing_company'    => '台積電股份有限公司',
                'billing_country'    => 'TW',
                'billing_email'      => 'chen.ming.hui@example.com.tw',
                'billing_first_name' => '明輝',
                'billing_last_name'  => '陳',
                'billing_phone'      => '0912345678',
                'billing_postcode'   => '110',
                'billing_state'      => '台北市',
                
                // discount 小數點折扣
                // 'discount_total' => 10.87,
            ];
            
            /**
             * @var array{
             * status?: string,
             * customer_id?: int,
             * customer_note?: string,
             * parent?: int,
             * order_id?: int,
             * created_via: "admin", "checkout", "store-api",
             * card_hash?: string,
             * } $args
             */
            $args = \wp_parse_args( $args, $default_args );
            
            $order = \wc_create_order();
            
            [
                'data'      => $data,
                'meta_data' => $meta_data,
            ] = WP::separator( $args, 'order' );
            
            foreach ( $data as $key => $value ) {
                $order->{"set_{$key}"}( $value );
            }
            
            foreach ( $meta_data as $key => $value ) {
                $order->update_meta_data( $key, $value );
            }
            
            $order->add_product( $product, 1 );
            $order->calculate_totals();
            $order->save();
            $this->items[] = $order;
        }
        
        $ids = array_map( fn( $order ) => "#{$order->get_id()}", $this->items );
        STDOUT::ok( "創建 {$qty} 個訂單成功: " . implode( ', ', $ids ) );
        return $this;
    }
    
    
    /**
     * 測試結束後 刪除訂單
     *
     * @throws \Exception 如果刪除訂單失敗
     */
    public function tear_down(): void {
        parent::tear_down();
        Product::instance()->tear_down();
        User::instance()->tear_down();
    }
}
