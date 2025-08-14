<?php

declare( strict_types = 1 );

namespace J7\PowerCheckoutTests\Shared;

use J7\PowerCheckoutTests\Attributes;
use J7\PowerCheckoutTests\Contracts\IResource;
use J7\PowerCheckoutTests\Utils\STDOUT;


/**
 * WC_UnitTestCase
 *
 * 設計思想：
 * 1. 抽象出公用屬性，方便設定
 * 2. 預設已經先執行必要的生命週期，也可以自己添加
 * 3. 測試結束後，清除所有數據
 *  */
abstract class WC_UnitTestCase extends \WP_UnitTestCase {
    
    /** @var Plugin[] 每個測試都要載入的外掛 */
    protected static array $required_plugins = [
        Plugin::WOOCOMMERCE
    ];
    /** @var \ReflectionClass 反射類別 */
    protected static \ReflectionClass $reflection;
    /** @var \ReflectionAttribute[] 建立測試時需要的屬性 */
    protected static array $create_attributes;
    /** @var Api API 模式 */
    protected Api $api = Api::MOCK;
    /** @var array<string, IResource> 測試需要的資源 Helper 實例類別 */
    protected array $containers = [];
    
    /** 此類所有測試方法執行前執行一次 */
    public static function set_up_before_class(): void {
        // 載入必要外掛，做完主要的幾個生命週期
        \add_action( 'plugins_loaded', [ __CLASS__, 'required_plugins' ], -1 );
        \do_action( 'plugins_loaded' );
        \do_action( 'after_setup_theme' );
        \do_action( 'init' );
        \do_action( 'wp_loaded' );
        \do_action( 'parse_request' );
        \do_action( 'send_headers' );
        
        self::$reflection = new \ReflectionClass( static::class );
        self::$create_attributes = self::$reflection->getAttributes( Attributes\Create::class );
    }
    
    /** 此類所有測試方法執行後執行一次 */
    public static function tear_down_after_class(): void {}
    
    /** 載入 WooCommerce 插件  */
    public static function required_plugins(): void {
        foreach ( self::$required_plugins as $plugin ) {
            require_once PLUGIN_DIR . $plugin->value;
        }
    }
    
    /** 每個測試方法執行前執行一次 */
    public function set_up(): void {
        foreach ( self::$create_attributes as $attribute ) {
            $attribute_name = $attribute->getName();
            if( Attributes\Create::class === $attribute_name ) {
                /** @var string[] $resource_classes 類別名稱 */
                $resource_classes = $attribute->getArguments();
                foreach ( $resource_classes as $resource_class ) {
                    if( !\is_callable( [ $resource_class, 'instance' ] ) ) {
                        continue;
                    }
                    $instance = call_user_func( [ $resource_class, 'instance' ] );
                    $this->containers[$resource_class] = $instance;
                    $instance->create();
                }
            }
            
        }
    }
    
    /** 每個測試方法執行後執行一次 */
    public function tear_down(): void {
        // 取得所有 Helper 資源
        foreach ( $this->containers as $container ) {
            if( method_exists( $container, 'tear_down' ) ) {
                $container->tear_down();
            }
        }
    }
    
    /**
     * 取得容器
     *
     * @param string $class_name 類別名稱
     *
     * @return IResource|null
     */
    public function get_container( string $class_name ): ?IResource {
        if( !isset( $this->containers[$class_name] ) ) {
            STDOUT::error( "容器 {$class_name} 不存在" );
            return null;
        }
        
        return $this->containers[$class_name];
        
    }
    
    /** 前置斷言：在每個測試開始前確認環境是否正確，例如確認某函式存在或某物件已初始化 */
    protected function assert_pre_conditions(): void {}
    
    /** 後置斷言：在每個測試結束前確認結果是否符合預期，例如確認資料未被污染或狀態未異常。 */
    protected function assert_post_conditions(): void {}
}
