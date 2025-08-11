<?php

/* Path to the WordPress codebase you'd like to test. Add a forward slash in the end. */
// 如果有用軟連結，最好寫絕對路徑
define('ABSPATH', "C:\\Users\\User\\LocalSites\\turbo\\app\\public\\wp-content\\plugins\\power-checkout\\wordpress\\");

/*
 * Path to the theme to test with.
 *
 * The 'default' theme is symlinked from test/phpunit/data/themedir1/default into
 * the themes directory of the WordPress installation defined above.
 */
define('WP_DEFAULT_THEME', 'default');

// Test with multisite enabled.
// Alternatively, use the tests/phpunit/multisite.xml configuration file.
// define( 'WP_TESTS_MULTISITE', true );

// Force known bugs to be run.
// Tests with an associated Trac ticket that is still open are normally skipped.
// define( 'WP_TESTS_FORCE_KNOWN_BUGS', true );

// Test with WordPress debug mode (default).
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);

// ** MySQL settings ** //

// This configuration file will be used by the copy of WordPress being tested.
// wordpress/wp-config.php will be ignored.

// WARNING WARNING WARNING!
// These tests will DROP ALL TABLES in the database with the prefix named below.
// DO NOT use a production database or one that is shared with something else.
define('DB_NAME', getenv('WP_DB_NAME') ?: 'test');
define('DB_USER', getenv('WP_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_DB_PASS') ?: 'root');
define('DB_HOST', getenv('WP_DB_HOST') ?: 'localhost');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 */
define('AUTH_KEY',         '`8tJ}IGn(+U2~v7I$i3:@Q*e|ziz+d%}6q.)lqME>alHj[S}MP+T}.P4)/SAn/o|');
define('SECURE_AUTH_KEY',  'biAN *QM}^8i-}nSP<o^?9HAS,#P[OFVg<pOc1 o.[eh,2nI##|{R9V3uM@@nc18');
define('LOGGED_IN_KEY',    ')uku>7RC@{-u(~ud=+TVy4h=kuWSIN6PTu+ydkjQxKBfW/+`;[K|&lOs+ll:WZMg');
define('NONCE_KEY',        '*<lM}Y+TL&Q9{dZOJ6#8I)RLUUfd;H<r:qh_wQ4Qey|CSFC]u`7ygQrn{9H,C$(K');
define('AUTH_SALT',        '}l(;r;.22[rNd7a-S^c. /!j3qhgGbi2/MC`6,_U]AvErT+h7-iajsd!?L-W:R C');
define('SECURE_AUTH_SALT', 'jTwUn1-vd1|~&=!Q_w+tUopF{Sk-4do7-a7[.cPTuV#+*!W!VG1bB`Jwcmq!*VmT');
define('LOGGED_IN_SALT',   'e4;M+IXSnD-h6<lH%MkI]eYx<B-B?# ff$d&is<-Y;Fq(Ac+|6XcscL3 L+PfkZI');
define('NONCE_SALT',       '4<dU36_OSQW7qO|s1d!Ld=F1jxzD VDwtzXzw#=Kt_jZ7$Gb#ZhBbe+j|zleG>|_');

$table_prefix = 'wp_';   // Only numbers, letters, and underscores please!

define('WP_TESTS_DOMAIN', 'wordpress-pest.test');
define('WP_TESTS_EMAIL', 'test@test.com');
define('WP_TESTS_TITLE', 'Test Mode');

define('WP_PHP_BINARY', 'php');

define('WPLANG', '');
