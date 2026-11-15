<?php
/**
 * Object cache and page analysis: WooCommerce's code → coupon id cache is dropped under both keys (M12-85), export
 * batches empty the runtime cache (M12-88) and the redeem form lookup of a page is remembered per request (M12-89).
 *   wp eval-file tests/caching-integration.php
 */

function vcap_cache_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

// M12-85: disabling, publishing and reissuing drop the code → id cache under the plain and the md5 key.
$m85_code   = 'vcap-m85-' . strtolower( wp_generate_password( 8, false, false ) );
$m85_coupon = new WC_Coupon();
$m85_coupon->set_code( $m85_code );
$m85_coupon->set_discount_type( 'fixed_cart' );
$m85_coupon->set_amount( 5 );
$m85_coupon->save();
$m85_prefix = WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_';
$m85_keys   = array( $m85_prefix . wc_format_coupon_code( $m85_code ), $m85_prefix . md5( wc_strtolower( $m85_code ) ) );
$m85_prime  = static function () use ( $m85_keys ): void {
	foreach ( $m85_keys as $key ) {
		wp_cache_set( $key, array( 999999 ), 'coupons' );
	}
};
$m85_gone   = static function () use ( $m85_keys ): bool {
	foreach ( $m85_keys as $key ) {
		if ( false !== wp_cache_get( $key, 'coupons' ) ) {
			return false;
		}
	}
	return true;
};
$m85_prime();
VCAP\Coupons\CouponSync::forget_code_cache( strtoupper( $m85_code ) );
vcap_cache_assert( $m85_gone(), 'M12-85: forget_code_cache() deletes the code → coupon id cache under the code key (WooCommerce 8.x) and its md5 key (newer versions)' );
$m85_prime();
VCAP\Coupons\CouponSync::set_coupon_post_status( $m85_coupon->get_id(), $m85_code, false );
$m85_disabled = $m85_gone();
$m85_prime();
VCAP\Coupons\CouponSync::set_coupon_post_status( $m85_coupon->get_id(), $m85_code, true );
vcap_cache_assert( $m85_disabled && $m85_gone() && $m85_coupon->get_id() === wc_get_coupon_id_by_code( $m85_code ), 'M12-85: disabling and publishing a voucher coupon leave no stale code → id entry (both keys)' );
$m85_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Discounts/Bonus.php' ) . (string) file_get_contents( dirname( __DIR__ ) . '/src/Reissue.php' );
vcap_cache_assert( 2 === substr_count( $m85_src, 'CouponSync::forget_code_cache(' ) && ! str_contains( $m85_src, "'coupon_id_from_code_'" ), 'M12-85: bonus activation and reissue use the shared forget_code_cache()' );
$m85_coupon->delete( true );

// M12-88: in WordPress the export flush empties the runtime cache of the default object cache.
wp_cache_set( 'vcap-m88', 'kept', 'vcap_m88' );
$m88_flushes = VCAP\Ledger::$flushes;
VCAP\Ledger::flush_runtime_cache();
vcap_cache_assert( false === wp_cache_get( 'vcap-m88', 'vcap_m88' ) && VCAP\Ledger::$flushes === $m88_flushes + 1 && ( wp_using_ext_object_cache() || 'runtime' === VCAP\Ledger::runtime_flush_mode( wp_cache_supports( 'flush_runtime' ), false ) ), 'M12-88: an export batch empties the runtime object cache (default cache: flush_runtime supported)' );

// M12-89: the content analysis of a page runs once per request and is forgotten when the page changes.
$m89_page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'M89', 'post_content' => 'No form here' ) );
$m89_reads = 0;
$m89_count = static function ( $value, $object_id, $meta_key ) use ( $m89_page, &$m89_reads ) {
	if ( (int) $object_id === $m89_page && '_elementor_data' === $meta_key ) {
		++$m89_reads;
	}
	return $value;
};
add_filter( 'get_post_metadata', $m89_count, 10, 3 );
$m89_first  = VCAP\Shortcodes::page_has_redeem_form( $m89_page );
$m89_second = VCAP\Shortcodes::page_has_redeem_form( $m89_page );
vcap_cache_assert( false === $m89_first && false === $m89_second && 1 === $m89_reads, 'M12-89: page_has_redeem_form() analyses a page once per request (memo per post id)' );
wp_update_post( array( 'ID' => $m89_page, 'post_content' => '<!-- wp:vcap/redeem /-->' ) );
vcap_cache_assert( VCAP\Shortcodes::page_has_redeem_form( $m89_page ), 'M12-89: saving the page forgets the remembered answer' );
wp_update_post( array( 'ID' => $m89_page, 'post_content' => 'Elementor page' ) );
update_post_meta( $m89_page, '_elementor_data', '[{"widgetType":"shortcode","settings":{"shortcode":"[vcap_redeem]"}}]' );
vcap_cache_assert( VCAP\Shortcodes::page_has_redeem_form( $m89_page ), 'M12-89: saving builder data (post meta) forgets the remembered answer' );
// After the headers went out (always the case in WP-CLI) a redeem link visit analyses nothing.
VCAP\Shortcodes::forget_page_memo();
$m89_reads = 0;
wp_update_post( array( 'ID' => $m89_page, 'post_content' => 'No form here' ) );
delete_post_meta( $m89_page, '_elementor_data' );
$m89_reads = 0;
$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $m89_page ) );
wc_load_cart();
$m89_sc = new VCAP\Shortcodes( vcap()->downloads, new VCAP\Referrals( vcap()->settings, vcap()->store_credit ) );
$m89_sc->redeem_link();
wp_reset_query();
remove_filter( 'get_post_metadata', $m89_count, 10 );
vcap_cache_assert( headers_sent() && 0 === $m89_reads, 'M12-89: once the headers are sent, redeem_link() returns before analysing the page content' );
wp_delete_post( $m89_page, true );

echo "Voucher Captain caching checks passed.\n";
