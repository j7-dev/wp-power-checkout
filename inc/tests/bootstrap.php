<?php

/**
 * PHPUnit bootstrap file
 */

// 因為不同的測試情境會 require 不同的 plugin，所以需要定義 PLUGIN_DIR，如果有用軟連結，最好寫絕對路徑
define('PLUGIN_DIR', "C:\\Users\\User\\LocalSites\\turbo\\app\\public\\wp-content\\plugins\\");

// 關閉警告
error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 0);

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available
require_once dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv('WP_PHPUNIT__DIR') . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    // test set up, plugin activation, etc.
});

// Start up the WP testing environment.
require getenv('WP_PHPUNIT__DIR') . '/includes/bootstrap.php';

