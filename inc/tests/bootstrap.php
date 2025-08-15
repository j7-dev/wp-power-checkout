<?php

/**
 * PHPUnit bootstrap file
 */

// 設定自訂錯誤處理器，忽略警告
set_error_handler( static function( $severity, $message, $file, $line ) {
    if( $severity === E_WARNING ) {
        return true; // 忽略警告，不讓它往上傳遞
    }
    return false; // 其他錯誤照常處理
} );

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available
require_once dirname( __FILE__, 3 ) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function() {
    // test set up, plugin activation, etc.
} );


// Start up the WP testing environment.
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';



