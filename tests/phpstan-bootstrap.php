<?php
/**
 * Constants WordPress and voucher-captain.php define at runtime, for PHPStan (phpstan-wide.neon.dist).
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'WP_LANG_DIR' ) || define( 'WP_LANG_DIR', ABSPATH . 'wp-content/languages' );
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'voucher-captain/voucher-captain.php' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 2592000 );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 31536000 );
defined( 'VCAP_VERSION' ) || define( 'VCAP_VERSION', '0.0.0' );
defined( 'VCAP_DB_VERSION' ) || define( 'VCAP_DB_VERSION', '0.0.0' );
defined( 'VCAP_FILE' ) || define( 'VCAP_FILE', dirname( __DIR__ ) . '/voucher-captain.php' );
defined( 'VCAP_PATH' ) || define( 'VCAP_PATH', dirname( __DIR__ ) . '/' );
defined( 'VCAP_URL' ) || define( 'VCAP_URL', 'https://example.test/wp-content/plugins/voucher-captain/' );
defined( 'VCAP_LICENSE_REQUIRED' ) || define( 'VCAP_LICENSE_REQUIRED', false );
