<?php
/**
 * M12 package G (admin, shop-owner UX, product features): issuing without an order (admin form and WP-CLI),
 * import of existing cards, admin search, settings sections, onboarding sample card, reports overview, bulk
 * actions, design gallery, physical cards, moving a card into store credit and the live preview data.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/shop-admin-integration.php
 */

use VCAP\Voucher;

function vcap_sa_assert( bool $condition, string $message, $context = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null !== $context ? ' — ' . wp_json_encode( $context ) : '' ) );
	}
	echo "PASS: {$message}\n";
}

/** Runs an admin-post handler that ends with a redirect and exit: the redirect is caught instead. */
function vcap_sa_post( callable $handler, array $post, string $nonce_action ): string {
	$_POST                = $post;
	$_REQUEST             = $post;
	$_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action );
	$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
	$stop                 = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	add_filter( 'wp_redirect', $stop, 1 );
	$out = '';
	try {
		$handler();
	} catch ( RuntimeException $e ) {
		$out = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $stop, 1 );
		$_POST    = array();
		$_REQUEST = array();
	}
	return $out;
}

function vcap_sa_user( string $role, array $caps = array() ): int {
	$login = 'vcap_sa_' . $role . '_' . strtolower( wp_generate_password( 6, false, false ) );
	$id    = (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.com', 'role' => $role ) );
	$user  = get_user_by( 'id', $id );
	foreach ( $caps as $cap => $grant ) {
		$user->add_cap( $cap, $grant );
	}
	return $id;
}

global $wpdb;
$events = VCAP\Install::tables()['events'];
$tag    = strtolower( wp_generate_password( 6, false, false ) );
$admin  = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
wp_set_current_user( $admin );
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	999,
	2
);

// ---- M12-19: New voucher (admin) and wp vcap issue. -----------------------------------------------------------
$issue_admin = new VCAP\Admin\IssueVoucher();
$out         = vcap_sa_post(
	array( $issue_admin, 'handle' ),
	array(
		'amount'          => '35,50',
		'type'            => 'giftcard',
		'recipient_name'  => 'Goodwill ' . $tag,
		'recipient_email' => 'gw-' . $tag . '@example.com',
		'message'         => 'Sorry!',
		'send'            => '1',
		'reason'          => 'Goodwill order 99',
		'expiry'          => '',
	),
	'vcap_issue_voucher'
);
$gw = vcap()->vouchers->query( array( 'search' => 'gw-' . $tag . '@example.com' ) )['items'][0] ?? null;
vcap_sa_assert( $gw instanceof Voucher && 35.5 === (float) $gw->initial_amount && 'giftcard' === $gw->type && 'balance' === $gw->usage_mode && 0 === $gw->order_id && str_contains( $out, 'voucher=' . $gw->id ), 'M12-19: the admin form "New voucher" issues a gift card without an order and opens it', array( $out, $gw ? $gw->id : null ) );
$gw_issued = $wpdb->get_row( $wpdb->prepare( "SELECT note, source FROM {$events} WHERE voucher_id = %d AND event = 'issued'", $gw->id ), ARRAY_A ); // phpcs:ignore
vcap_sa_assert( str_contains( VCAP\Note::render( (string) $gw_issued['note'] ), 'admin' ) && str_contains( VCAP\Note::render( (string) $gw_issued['note'] ), 'Goodwill order 99' ) && ( ! VCAP\Install::event_source_ready() || 'admin' === $gw_issued['source'] ), 'M12-19: the ledger entry names source "admin" and the reason', $gw_issued );
vcap_sa_assert( (bool) as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $gw->id ) ) || null !== vcap()->vouchers->get( $gw->id )->delivered_at, 'M12-19: "Send now" schedules the email delivery' );
$paid_seen = null;
$paid_spy  = static function ( $voucher, $args ) use ( &$paid_seen ) {
	$paid_seen = array_key_exists( 'paid', $args ) ? $args['paid'] : 'missing';
};
add_action( 'vcap_voucher_issued_standalone', $paid_spy, 10, 2 );
$plain = VCAP\Admin\IssueVoucher::issue( array( 'amount' => '20', 'type' => 'voucher', 'send' => false, 'reason' => 'Prize' ) );
remove_action( 'vcap_voucher_issued_standalone', $paid_spy, 10 );
vcap_sa_assert( $plain instanceof Voucher && 'single' === $plain->usage_mode && null !== $plain->delivered_at && false === $paid_seen, 'M12-19: issued vouchers are passed as not sold (paid => false); without "send" they count as handed over', $paid_seen );
$refused = array(
	VCAP\Admin\IssueVoucher::issue( array( 'amount' => '', 'type' => 'giftcard', 'send' => false ) ),
	VCAP\Admin\IssueVoucher::issue( array( 'amount' => '10', 'type' => 'credit', 'send' => false ) ),
	VCAP\Admin\IssueVoucher::issue( array( 'amount' => '10', 'recipient_email' => 'not-an-address', 'send' => true ) ),
	VCAP\Admin\IssueVoucher::issue( array( 'amount' => '10', 'expiry' => '2001-01-01', 'send' => false ) ),
);
vcap_sa_assert( array( 'vcap_invalid_amount', 'vcap_invalid_args', 'vcap_invalid_email', 'vcap_invalid_expiry' ) === array_map( static fn( $e ) => is_wp_error( $e ) ? $e->get_error_code() : 'ok', $refused ), 'M12-19: no value, store credit type, a wrong address and a past expiry are refused' );
$dated = VCAP\Admin\IssueVoucher::issue( array( 'amount' => '10', 'expiry' => ( (int) wp_date( 'Y' ) + 2 ) . '-06-30', 'send' => false ) );
vcap_sa_assert( $dated instanceof Voucher && get_date_from_gmt( (string) $dated->expires_at, 'Y-m-d' ) === ( (int) wp_date( 'Y' ) + 2 ) . '-06-30', 'M12-19: the expiry date entered is stored (end of that local day)' );
$clerk = vcap_sa_user( 'subscriber', array( 'vcap_manage_vouchers' => true ) );
wp_set_current_user( $clerk );
vcap_sa_assert( ! VCAP\Admin\IssueVoucher::can(), 'M12-19: staff who may manage but not raise values cannot issue vouchers' );
$denied  = false;
$die_fn  = static function () use ( &$denied ) {
	$denied = true;
	throw new RuntimeException( 'died' );
};
$die_hdl = static fn() => $die_fn;
add_filter( 'wp_die_handler', $die_hdl );
vcap_sa_post( array( $issue_admin, 'handle' ), array( 'amount' => '10', 'reason' => 'x', 'send' => '' ), 'vcap_issue_voucher' );
remove_filter( 'wp_die_handler', $die_hdl );
vcap_sa_assert( $denied, 'M12-19: their form post is refused' );
wp_set_current_user( $admin );
// The CLI command (WP_CLI is not loaded here: the shared issue() path it calls is checked with source "cli").
$cli = VCAP\Admin\IssueVoucher::issue( array( 'amount' => '15', 'recipient_email' => 'cli-' . $tag . '@example.com', 'send' => false, 'reason' => 'WP-CLI', 'source' => 'cli' ) );
$cli_src = $cli instanceof Voucher ? $wpdb->get_var( $wpdb->prepare( "SELECT source FROM {$events} WHERE voucher_id = %d AND event = 'issued'", $cli->id ) ) : null; // phpcs:ignore
vcap_sa_assert( $cli instanceof Voucher && ( ! VCAP\Install::event_source_ready() || 'cli' === $cli_src ), 'M12-19: wp vcap issue --amount --email issues through the same path (ledger source "cli")', $cli_src );
vcap_sa_assert( method_exists( VCAP\CLI\Command::class, 'issue' ) && str_contains( (string) ( new ReflectionMethod( VCAP\CLI\Command::class, 'issue' ) )->getDocComment(), '[--amount=<amount>]' ), 'M12-19: the CLI command documents --amount, --email, --product and --send' );

// ---- M12-56: import of existing cards. -----------------------------------------------------------------------
$future = ( (int) wp_date( 'Y' ) + 1 ) . '-12-31';
$rows   = array(
	array( 'Code', 'Initial', 'Balance', 'Expiry', 'Recipient', 'Type' ),
	array( 'OLD-' . $tag . '-A1', '50,00', '20,50', '31.12.' . ( (int) wp_date( 'Y' ) + 1 ), 'old-' . $tag . '@example.com', 'giftcard' ),
	array( 'OLD-' . $tag . '-B2', '25', '', '', '', 'voucher' ),
	array( 'OLD-' . $tag . '-C3', '30', '0', $future, '', '' ),
	array( 'OLD-' . $tag . '-D4', '30', '40', $future, '', '' ),
	array( 'OLD-' . $tag . '-E5', '30', '10', $future, '', 'voucher' ),
	array( strtoupper( $plain->code ), '30', '10', $future, '', '' ),
	array( 'OLD-' . $tag . '-F6', '30', '10', '2001-01-01', '', '' ),
);
$dry = VCAP\Admin\CardImport::run( $rows, true );
vcap_sa_assert( 2 === $dry['imported'] && 45.5 === $dry['value'] && ! vcap()->vouchers->get_by_code( 'old-' . $tag . '-a1' ) && 3 === count( $dry['errors'] ) && 2 === count( $dry['skipped'] ), 'M12-56: the dry run checks every row and imports nothing', $dry );
$mails_before = count( $mails );
$real         = VCAP\Admin\CardImport::run( $rows, false, 'test file' );
$a1           = vcap()->vouchers->get_by_code( wc_format_coupon_code( 'OLD-' . $tag . '-A1' ) );
vcap_sa_assert( 2 === $real['imported'] && $a1 && 50.0 === (float) $a1->initial_amount && 20.5 === (float) $a1->balance && 'balance' === $a1->usage_mode && get_date_from_gmt( (string) $a1->expires_at, 'Y-m-d' ) === $future, 'M12-56: a card is imported with its code, value, remaining value and expiry', $real );
$a1_coupon = new WC_Coupon( $a1->coupon_id );
vcap_sa_assert( abs( (float) $a1_coupon->get_amount() - 20.5 ) < 0.001 && vcap()->vouchers->has_event( $a1->id, 'imported' ) && null !== $a1->delivered_at, 'M12-56: its coupon carries the remaining value, the history has "imported", no delivery is due', (float) $a1_coupon->get_amount() );
$a1_issued = $wpdb->get_row( $wpdb->prepare( "SELECT amount, balance_after FROM {$events} WHERE voucher_id = %d AND event = 'issued'", $a1->id ), ARRAY_A ); // phpcs:ignore
vcap_sa_assert( 50.0 === (float) $a1_issued['amount'] && 20.5 === (float) $a1_issued['balance_after'], 'M12-56: the ledger books the value and the remaining balance (outstanding value correct)', $a1_issued );
$again = VCAP\Admin\CardImport::run( $rows, false );
vcap_sa_assert( 0 === $again['imported'] && 'already imported' === ( $again['skipped'][2] ?? '' ) && 'already imported' === ( $again['skipped'][3] ?? '' ), 'M12-56: a second run skips cards imported before (idempotent)', $again );
vcap_sa_assert( 1 === count( array_filter( vcap()->vouchers->query( array( 'search' => 'OLD-' . $tag . '-A1' ) )['items'] ) ), 'M12-56: no duplicate card exists' );
$no_header = VCAP\Admin\CardImport::run( array( array( 'foo', 'bar' ), array( 'X', '1' ) ), true );
vcap_sa_assert( '' !== $no_header['error'], 'M12-56: a file without the column names "code" and "initial" is refused' );
vcap_sa_assert( 0.0 === (float) VCAP\Admin\CardImport::parse_amount( '0' ) && 1234.5 === VCAP\Admin\CardImport::parse_amount( '1.234,50 €' ) && 1234.5 === VCAP\Admin\CardImport::parse_amount( '1,234.50' ) && null === VCAP\Admin\CardImport::parse_date( '31.02.2027' ), 'M12-56: German and English amounts and dates are read' );
vcap_sa_assert( method_exists( VCAP\CLI\Command::class, 'import' ), 'M12-56: wp vcap import <file> exists' );

// ---- Shared helpers for the product features. -------------------------------------------------------------------
$sa_product = static function ( array $meta, bool $virtual = true, string $price = '30' ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( 'SA voucher ' . wp_generate_password( 4, false, false ) );
	$p->set_regular_price( $price );
	$p->set_virtual( $virtual );
	$p->set_status( 'publish' );
	$p->update_meta_data( '_vcap_enabled', 'yes' );
	foreach ( $meta as $k => $v ) {
		$p->update_meta_data( $k, $v );
	}
	$p->save();
	return $p;
};
$sa_order = static function ( WC_Product $p, string $email, array $item_meta = array(), string $status = 'processing' ): WC_Order {
	$order = wc_create_order();
	$line  = $order->add_product( $p, 1 );
	if ( $item_meta ) {
		$item = $order->get_item( $line );
		foreach ( $item_meta as $k => $v ) {
			$item->add_meta_data( $k, $v, true );
		}
		$item->save();
	}
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Sa' );
	$order->calculate_totals( false );
	$order->save();
	$order->set_status( $status );
	$order->save();
	$order = wc_get_order( $order->get_id() );
	vcap()->issuer->issue_for_order( $order, true, false );
	return wc_get_order( $order->get_id() );
};

// ---- M12-54: admin search. --------------------------------------------------------------------------------------
$tail_code = 'SRCH-' . strtoupper( $tag ) . '-M9X' . wp_rand( 1, 9 );
$searched  = vcap()->issuer->issue_standalone( array( 'amount' => 12, 'send' => false, 'code' => $tail_code ) );
$find      = static fn( string $term ): array => array_map( static fn( $v ) => $v->id, vcap()->vouchers->query( array( 'search' => $term, 'per_page' => 50 ) )['items'] );
$tail      = substr( $tail_code, -4 );
vcap_sa_assert( in_array( $searched->id, $find( "\u{2026}" . $tail ), true ) && in_array( $searched->id, $find( '…' . strtolower( $tail ) ), true ), 'M12-54: a masked code end ("…M9X1") finds the voucher', array( $tail, $find( "\u{2026}" . $tail ) ) );
vcap_sa_assert( in_array( $searched->id, $find( str_replace( '-', '', $tail_code ) ), true ) && in_array( $searched->id, $find( str_replace( '-', ' ', strtolower( $tail_code ) ) ), true ), 'M12-54: the code without separators or with spaces finds the voucher' );
vcap_sa_assert( ! in_array( $searched->id, $find( 'zzzz' ), true ) && in_array( $searched->id, $find( 'srch-' . $tag ), true ), 'M12-54: the start of the code still finds it, an unrelated word does not' );
$search_product = $sa_product( array( '_vcap_type' => 'voucher' ) );
$search_order   = $sa_order( $search_product, 'search-' . $tag . '@example.com' );
$order_voucher  = vcap()->vouchers->for_order( $search_order->get_id() )[0] ?? null;
$seq_number     = 990000 + $search_order->get_id();
$seq_filter     = static fn( $number ) => (int) $number === $seq_number ? $search_order->get_id() : $number;
add_filter( 'woocommerce_shortcode_order_tracking_order_id', $seq_filter );
vcap_sa_assert( $order_voucher && in_array( $order_voucher->id, $find( '#' . $search_order->get_id() ), true ) && in_array( $order_voucher->id, $find( (string) $seq_number ), true ), 'M12-54: the order id ("#123") and a sequential order number find the order\'s vouchers', array( $seq_number, $find( (string) $seq_number ) ) );
remove_filter( 'woocommerce_shortcode_order_tracking_order_id', $seq_filter );
$profile_user = get_user_by( 'id', $admin );
ob_start();
( new VCAP\Admin\VoucherLinks() )->profile( $profile_user );
$profile_html = (string) ob_get_clean();
vcap_sa_assert( str_contains( $profile_html, 'page=vcap-vouchers' ) && str_contains( $profile_html, 's=' ) && str_contains( $profile_html, 'Show vouchers' ), 'M12-54: the user profile links to the customer\'s vouchers', $profile_html );
require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
global $product_object;
$product_object = wc_get_product( $search_product->get_id() );
ob_start();
( new VCAP\ProductSettings() )->panel();
$panel_html = (string) ob_get_clean();
vcap_sa_assert( str_contains( $panel_html, 'product=' . $search_product->get_id() ) && str_contains( $panel_html, '_vcap_allowed_templates' ) && str_contains( $panel_html, '_vcap_physical' ), 'M12-54/96/97: the product\'s Voucher tab links to its vouchers and offers designs and "Physical card"' );

// ---- M12-90: settings sections. -----------------------------------------------------------------------------------
$ids_of = static fn( string $section ): array => array_values( array_filter( array_map( static fn( $f ) => in_array( $f['type'] ?? '', array( 'title', 'sectionend' ), true ) ? '' : (string) ( $f['id'] ?? '' ), vcap()->settings->fields( $section ) ) ) );
$general_m = new ReflectionMethod( VCAP\Settings::class, 'general_fields' );
$general_m->setAccessible( true );
$all_general = array_values( array_filter( array_map( static fn( $f ) => in_array( $f['type'] ?? '', array( 'title', 'sectionend' ), true ) ? '' : (string) ( $f['id'] ?? '' ), $general_m->invoke( vcap()->settings ) ) ) );
$split       = array_merge( $ids_of( '' ), $ids_of( 'redemption' ), $ids_of( 'promotions' ), $ids_of( 'codes' ) );
sort( $split );
$sorted_all = $all_general;
sort( $sorted_all );
vcap_sa_assert( $split === $sorted_all && count( $split ) === count( array_unique( $split ) ), 'M12-90: the four sections show every former "General" option exactly once (option keys unchanged)' );
vcap_sa_assert( in_array( 'vcap_issue_statuses', $ids_of( '' ), true ) && in_array( 'vcap_code_prefix', $ids_of( 'codes' ), true ) && in_array( 'vcap_store_credit', $ids_of( 'redemption' ), true ) && in_array( 'vcap_referral_enabled', $ids_of( 'promotions' ), true ) && in_array( 'vcap_campaigns_enabled', $ids_of( 'promotions' ), true ) && ! in_array( 'vcap_code_prefix', $ids_of( '' ), true ), 'M12-90: issuing stays on "General", codes, redemption & balance and promotions & referrals have their own sections' );
$balanced = true;
foreach ( array( '', 'redemption', 'promotions', 'codes' ) as $sec ) {
	$open = 0;
	foreach ( vcap()->settings->fields( $sec ) as $f ) {
		$open += 'title' === ( $f['type'] ?? '' ) ? 1 : ( 'sectionend' === ( $f['type'] ?? '' ) ? -1 : 0 );
		$balanced = $balanced && $open >= 0 && $open <= 1;
	}
	$balanced = $balanced && 0 === $open;
}
vcap_sa_assert( $balanced, 'M12-90: every section opens and closes its blocks' );
$steps = VCAP\Admin\Onboarding::steps();
vcap_sa_assert( str_ends_with( $steps['issue_status']['url'], '#vcap_issue_statuses' ) && str_ends_with( $steps['terms']['url'], '#vcap_default_terms' ), 'M12-90: the checklist links straight to the option (anchor)' );

// ---- M12-91: onboarding sample gift card, blocks instead of shortcodes. -------------------------------------------
$sample_id = VCAP\Admin\Onboarding::create_sample_product();
$sample    = wc_get_product( $sample_id );
$sample_c  = $sample ? VCAP\ProductConfig::get( $sample ) : array();
vcap_sa_assert( $sample && 'draft' === $sample->get_status() && $sample->is_virtual() && VCAP\ProductConfig::is_voucher( $sample ) && 'giftcard' === $sample_c['type'] && 'balance' === $sample_c['usage'] && 'custom' === $sample_c['amount_source'] && array( 25.0, 50.0, 100.0 ) === array_map( 'floatval', VCAP\CustomAmount::presets( $sample_c ) ) && ! VCAP\ProductConfig::discount_errors( $sample_c ) && ! VCAP\CustomAmount::config_errors( $sample_c ), 'M12-91: "Create sample gift card" makes a draft virtual gift card with stored balance, own amount and 25/50/100', $sample_c ? array( $sample_c['type'], $sample_c['usage'], VCAP\CustomAmount::presets( $sample_c ) ) : null );
vcap_sa_assert( str_contains( $steps['product']['label'], 'sample gift card' ) && 'sample_product' === $steps['product']['action'], 'M12-91: the checklist step offers the sample card and describes the steps' );
$insert = new ReflectionMethod( VCAP\Admin\Onboarding::class, 'insert_page' );
$insert->setAccessible( true );
$keep_redeem = get_option( 'vcap_redeem_page_id' );
$block_page  = (int) $insert->invoke( null, 'balance_page' );
vcap_sa_assert( $block_page && '<!-- wp:vcap/balance /-->' === get_post_field( 'post_content', $block_page ) && str_contains( (string) apply_filters( 'the_content', get_post_field( 'post_content', $block_page ) ), 'vcap' ), 'M12-91: the created pages contain the plugin\'s block, not a shortcode block' );
wp_delete_post( $block_page, true );
wp_delete_post( $sample_id, true );
update_option( 'vcap_redeem_page_id', $keep_redeem );

// ---- M12-92: reports overview. ----------------------------------------------------------------------------------
$today    = wp_date( 'Y-m-d' );
$before   = VCAP\Reports::overview( $today, $today );
$redeem_v = vcap()->issuer->issue_standalone( array( 'amount' => 20, 'send' => false ) );
vcap()->tickets->redeem_single( $redeem_v, 'overview test' );
$expire_v = vcap()->issuer->issue_standalone( array( 'amount' => 7, 'type' => 'giftcard', 'usage' => 'balance', 'send' => false ) );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $expire_v->id ) ); // phpcs:ignore
$after = VCAP\Reports::overview( $today, $today );
vcap_sa_assert( $after['free']['count'] === $before['free']['count'] + 2 && abs( $after['free']['value'] - $before['free']['value'] - 27 ) < 0.001, 'M12-92: the overview counts vouchers issued without an order', array( $before['free'], $after['free'] ) );
vcap_sa_assert( abs( $after['redeemed'] - $before['redeemed'] - 20 ) < 0.001 && $after['expired']['count'] === $before['expired']['count'] + 1 && abs( $after['expired']['value'] - $before['expired']['value'] - 7 ) < 0.001, 'M12-92: redeemed and expired value of the period', array( $before, $after ) );
// The top list holds the 5 products with the most vouchers of the period: the test product is in it, or (after other
// suites in the same database) 5 products with at least as many vouchers are.
$sa_top_ids  = array_column( $after['top'], 'product_id' );
$sa_top_last = $after['top'] ? (int) end( $after['top'] )['count'] : 0;
$sa_own      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VCAP\Install::tables()['vouchers'] . ' WHERE product_id = %d AND order_id > 0', $search_product->get_id() ) ); // phpcs:ignore
$sa_top_ok   = in_array( $search_product->get_id(), $sa_top_ids, true ) || ( 5 === count( $sa_top_ids ) && $sa_top_last >= $sa_own );
vcap_sa_assert( $after['sold']['count'] >= 1 && $sa_own >= 1 && $sa_top_ok && $after['open']['value'] > 0, 'M12-92: sold vouchers, top products and the open value are reported', array( $after['top'], $sa_own ) );
$_GET = array( 'page' => 'vcap-reports' );
ob_start();
( new VCAP\Admin\ReportsAdmin() )->page();
$report_html = (string) ob_get_clean();
$_GET        = array();
vcap_sa_assert( str_contains( $report_html, 'vcap-report-cards' ) && (bool) preg_match( '/nav-tab nav-tab-active[^>]*>Overview/', $report_html ), 'M12-92: "Overview" is the default reports tab' );
// COMPATIBILITY.md (WooCommerce Analytics section) is checked in tests/run.php: the release ZIP has no docs.

// ---- M12-94: bulk actions. ----------------------------------------------------------------------------------------
$voucher_admin = new VCAP\Admin\VoucherAdmin( vcap()->vouchers );
$ext           = vcap()->issuer->issue_standalone( array( 'amount' => 30, 'send' => false, 'recipient_email' => 'ext-' . $tag . '@example.com', 'expires_at' => gmdate( 'Y-m-d 22:59:59', time() + 10 * DAY_IN_SECONDS ) ) );
$never         = vcap()->issuer->issue_standalone( array( 'amount' => 30, 'send' => false, 'expires_at' => null ) );
$old_exp       = $ext->expires_at;
$res           = $voucher_admin->extend_validity( array( $ext->id, $never->id ), 30, '' );
$ext_fresh     = vcap()->vouchers->get( $ext->id );
$ext_coupon    = new WC_Coupon( $ext->coupon_id );
vcap_sa_assert( 1 === $res['done'] && 1 === $res['skipped'] && strtotime( $ext_fresh->expires_at . ' UTC' ) - strtotime( $old_exp . ' UTC' ) === 30 * DAY_IN_SECONDS && null === vcap()->vouchers->get( $never->id )->expires_at, 'M12-94: "Extend validity" adds the days to the expiry; vouchers without expiry are skipped', $res );
vcap_sa_assert( $ext_coupon->get_date_expires() && abs( $ext_coupon->get_date_expires()->getTimestamp() - strtotime( $ext_fresh->expires_at . ' UTC' ) ) < DAY_IN_SECONDS && str_contains( VCAP\Note::render( (string) $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$events} WHERE voucher_id = %d AND event = 'edited' ORDER BY id DESC LIMIT 1", $ext->id ) ) ), 'Validity extended' ), 'M12-94: the coupon gets the new expiry and the history the change' ); // phpcs:ignore
$target = ( (int) wp_date( 'Y' ) + 3 ) . '-01-31';
$voucher_admin->extend_validity( array( $expire_v->id ), 0, $target );
$revived = vcap()->vouchers->get( $expire_v->id );
vcap_sa_assert( get_date_from_gmt( (string) $revived->expires_at, 'Y-m-d' ) === $target && ! $revived->is_expired(), 'M12-94: "until" a date also makes an expired card usable again (with the right to raise values)' );
wp_set_current_user( $clerk );
$expire_v2 = vcap()->issuer->issue_standalone( array( 'amount' => 7, 'type' => 'giftcard', 'usage' => 'balance', 'send' => false ) );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $expire_v2->id ) ); // phpcs:ignore
VCAP\VoucherRepository::forget();
$clerk_res = $voucher_admin->extend_validity( array( $expire_v2->id ), 30, '' );
wp_set_current_user( $admin );
vcap_sa_assert( 0 === $clerk_res['done'] && 1 === $clerk_res['skipped'], 'M12-94: without the right to raise values an expired voucher is not revived' );
$mails_before = count( $mails );
$remind       = $voucher_admin->send_reminders( array( $ext->id, $never->id ) );
vcap_sa_assert( 1 === $remind['sent'] && 1 === $remind['skipped'] && count( $mails ) === $mails_before + 1 && vcap()->vouchers->has_event( $ext->id, 'expiry_reminder' ), 'M12-94: "Send expiry reminder" mails the expiring voucher and records it', $remind );
ob_start();
@$voucher_admin->stream_csv( ( static function () use ( $ext, $never ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- headers in CLI.
	yield vcap()->vouchers->get( $ext->id );
	yield vcap()->vouchers->get( $never->id );
} )() );
$csv = (string) ob_get_clean();
vcap_sa_assert( str_contains( $csv, $ext->code ) && str_contains( $csv, $never->code ) && str_contains( $csv, 'End of export: 2' ), 'M12-94: "Export selection" writes the selected vouchers' );
$bulk_actions = ( new ReflectionMethod( VCAP\Admin\VouchersListTable::class, 'get_bulk_actions' ) );
$bulk_actions->setAccessible( true );
vcap_sa_assert( array() === array_diff( array( 'extend', 'remind', 'export' ), array_keys( $bulk_actions->invoke( new VCAP\Admin\VouchersListTable( vcap()->vouchers ) ) ) ), 'M12-94: the list offers the three bulk actions' );

// ---- M12-96: design gallery. --------------------------------------------------------------------------------------
$tpl_a = (int) wp_insert_post( array( 'post_type' => VCAP\TemplatePostType::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'SA design A ' . $tag ) );
update_post_meta( $tpl_a, VCAP\TemplatePostType::META, wp_slash( wp_json_encode( VCAP\TemplatePostType::default_designs()['giftcard'] ) ) );
$tpl_b = (int) wp_insert_post( array( 'post_type' => VCAP\TemplatePostType::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'SA design B ' . $tag ) );
update_post_meta( $tpl_b, VCAP\TemplatePostType::META, wp_slash( wp_json_encode( VCAP\TemplatePostType::default_designs()['ticket'] ) ) );
$tpl_draft = (int) wp_insert_post( array( 'post_type' => VCAP\TemplatePostType::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'SA draft ' . $tag ) );
$gallery_p = $sa_product( array( '_vcap_type' => 'giftcard', '_vcap_allowed_templates' => array( $tpl_a, $tpl_b, $tpl_draft ), '_vcap_template' => $tpl_a ) );
$gallery_c = VCAP\ProductConfig::get( $gallery_p );
vcap_sa_assert( array( $tpl_a, $tpl_b ) === VCAP\DesignGallery::allowed( $gallery_c ), 'M12-96: the allowed designs are the published templates of the list', array( $tpl_a, $tpl_b, $gallery_c['allowed_templates'], VCAP\DesignGallery::allowed( $gallery_c ) ) );
$GLOBALS['product'] = $gallery_p;
ob_start();
( new VCAP\DesignGallery() )->render( $gallery_p );
$gallery_html = (string) ob_get_clean();
vcap_sa_assert( 2 === substr_count( $gallery_html, 'type="radio"' ) && str_contains( $gallery_html, 'vcap-design-thumb' ) && str_contains( $gallery_html, 'value="' . $tpl_a . '" checked' ), 'M12-96: the product page shows a radio group with pictures, the product template preselected' );
$gallery = new VCAP\DesignGallery();
$_POST   = array( 'vcap_template' => (string) $tpl_draft );
$bad     = $gallery->validate( true, $gallery_p->get_id(), 1 );
$_POST   = array( 'vcap_template' => (string) $tpl_b );
$good    = $gallery->validate( true, $gallery_p->get_id(), 1 );
$data    = $gallery->cart_item_data( array(), $gallery_p->get_id(), 0 );
$_POST   = array();
if ( function_exists( 'wc_clear_notices' ) && WC()->session ) {
	wc_clear_notices();
}
vcap_sa_assert( false === $bad && true === $good && $tpl_b === ( $data['vcap_template'] ?? 0 ), 'M12-96: a design outside the list is refused; a valid one is stored as cart item data "vcap_template"' );
$gallery_order = $sa_order( $gallery_p, 'gallery-' . $tag . '@example.com', array( VCAP\DesignGallery::ITEM_META => $tpl_b ) );
$gallery_v     = vcap()->vouchers->for_order( $gallery_order->get_id() )[0] ?? null;
$forged_order  = $sa_order( $gallery_p, 'gallery2-' . $tag . '@example.com', array( VCAP\DesignGallery::ITEM_META => $tpl_draft ) );
$forged_v      = vcap()->vouchers->for_order( $forged_order->get_id() )[0] ?? null;
vcap_sa_assert( $gallery_v && $tpl_b === $gallery_v->template_id && $forged_v && $tpl_a === $forged_v->template_id, 'M12-96: the voucher is issued with the chosen design; a design not (or no longer) allowed falls back to the product template', array( $gallery_v ? $gallery_v->template_id : null, $forged_v ? $forged_v->template_id : null ) );

// ---- M12-53: live preview data and the print preview button. ------------------------------------------------------
$lp = VCAP\LivePreview::data( $gallery_p );
$texts = array();
foreach ( $lp['designs'][ $tpl_a ]['elements'] ?? array() as $el ) {
	if ( isset( $el['text'] ) ) {
		$texts[] = $el['text'];
	}
}
vcap_sa_assert( isset( $lp['designs'][ $tpl_a ], $lp['designs'][ $tpl_b ] ) && $tpl_a === $lp['template'] && in_array( '{coupon_value}', $texts, true ) && in_array( '{message}', $texts, true ) && ! preg_grep( '/\{site_name\}/', $texts ) && $lp['designs'][ $tpl_a ]['w'] > $lp['designs'][ $tpl_a ]['h'], 'M12-53: the live preview gets the designs (product and gallery) with name, message and amount left to fill in and the rest filled', $texts );
ob_start();
( new VCAP\LivePreview() )->render( $gallery_p );
$lp_html = (string) ob_get_clean();
vcap_sa_assert( str_contains( $lp_html, 'class="vcap-live-preview"' ) && str_contains( $lp_html, 'data-vcap-preview=' ), 'M12-53: the preview box with its data is printed next to the fields' );
ob_start();
vcap()->fields->render( 'before_button' );
$fields_html = (string) ob_get_clean();
vcap_sa_assert( (bool) preg_match( '/vcap-preview-button[^>]*aria-describedby="(vcap-preview-hint-\d+)"/', $fields_html, $hint_m ) && str_contains( $fields_html, 'id="' . $hint_m[1] . '">opens in a new window' ) && str_contains( $fields_html, 'Print preview (PDF)' ), 'M12-53: the PDF button is the "Print preview" with the hint "opens in a new window"' );
$variable = new WC_Product_Variable();
$variable->set_name( 'SA variable ' . $tag );
$variable->update_meta_data( '_vcap_enabled', 'yes' );
$variable->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable->get_id() );
$variation->set_regular_price( '40' );
$variation->update_meta_data( '_vcap_template', $tpl_b );
$variation->save();
$lp_var = VCAP\LivePreview::data( wc_get_product( $variable->get_id() ) );
vcap_sa_assert( $tpl_b === ( $lp_var['variations'][ $variation->get_id() ]['template'] ?? 0 ) && 40.0 === ( $lp_var['variations'][ $variation->get_id() ]['amount'] ?? null ) && isset( $lp_var['designs'][ $tpl_b ] ), 'M12-53: the preview data carries template and amount of each variation' );
$GLOBALS['product'] = null;

// ---- M12-97: physical gift cards. ---------------------------------------------------------------------------------
$batch = 'sa-cards-' . $tag;
$cards = array();
for ( $i = 0; $i < 2; $i++ ) {
	$cards[] = 'PHY' . strtoupper( wp_generate_password( 12, false, false ) );
	vcap()->code_pool->add( end( $cards ), $batch );
}
$physical_p = $sa_product( array( '_vcap_type' => 'giftcard', '_vcap_physical' => 'yes', '_vcap_code_batch' => $batch, '_vcap_send_to' => 'recipient' ), false, '25' );
vcap_sa_assert( ! array_filter( VCAP\ProductChecks::problems( $physical_p ), static fn( $p ) => str_contains( (string) $p['message'], 'Virtual' ) ), 'M12-97: a physical card product is not asked to be virtual' );
$mails_before = count( $mails );
$phys_order   = $sa_order( $physical_p, 'phys-' . $tag . '@example.com', array( '_vcap_fields' => array( 'recipient_email' => array( 'label' => 'Recipient email', 'value' => 'phys-rcp-' . $tag . '@example.com' ) ) ) );
$card         = vcap()->vouchers->for_order( $phys_order->get_id() )[0] ?? null;
vcap_sa_assert( $card && in_array( strtoupper( $card->code ), $cards, true ) && VCAP\Voucher::STATUS_SCHEDULED === $card->status && null !== $card->delivered_at && null === $card->delivery_at, 'M12-97: a paid order issues the card with a code from the batch, inactive and handed over (no email)', $card );
$mails_before = count( $mails ); // Order emails of WooCommerce went out above.
vcap()->delivery->deliver( $card->id );
vcap_sa_assert( count( $mails ) === $mails_before && 'publish' !== get_post_status( $card->coupon_id ), 'M12-97: no email is sent and the coupon stays disabled until shipping', array( count( $mails ) - $mails_before, get_post_status( $card->coupon_id ) ) );
$mails_before = count( $mails );
$phys_order->update_status( 'completed' );
$card_after = vcap()->vouchers->get( $card->id );
$voucher_mails = array_filter( array_slice( $mails, $mails_before ), static fn( $m ) => str_contains( (string) ( $m['message'] ?? '' ), $card->code ) );
vcap_sa_assert( VCAP\Voucher::STATUS_ACTIVE === $card_after->status && 'publish' === get_post_status( $card->coupon_id ) && vcap()->vouchers->has_event( $card->id, 'activated' ) && ! $voucher_mails, 'M12-97: completing (shipping) the order activates the card, still without a voucher email', array( $card_after->status, get_post_status( $card->coupon_id ) ) );

// ---- M12-98: received card into store credit. ---------------------------------------------------------------------
$keep_credit = get_option( 'vcap_store_credit', false );
update_option( 'vcap_store_credit', 'yes' );
$customer  = vcap_sa_user( 'customer' );
$c2c       = new VCAP\CardToCredit( vcap()->store_credit, vcap()->vouchers );
$gift      = vcap()->issuer->issue_standalone( array( 'amount' => 40, 'type' => 'giftcard', 'usage' => 'balance', 'send' => false ) );
$liability = static function () use ( $today ): float {
	$sum = 0.0;
	foreach ( ( new VCAP\Ledger( vcap()->vouchers ) )->liability( $today )['totals'][ get_option( 'woocommerce_currency' ) ] ?? array() as $b ) {
		$sum += $b['amount'];
	}
	return round( $sum, 2 );
};
$liab_before = $liability();
vcap_sa_assert( '' !== $c2c->refusal( $gift, $customer, false ), 'M12-98: without the code, a card the customer did not buy is refused' );
$moved = $c2c->move( $gift, $customer, true );
$gift_after = vcap()->vouchers->get( $gift->id );
vcap_sa_assert( 40.0 === $moved && VCAP\Voucher::STATUS_VOID === $gift_after->status && 0.0 === (float) $gift_after->balance && 40.0 === vcap()->store_credit->balance( $customer ) && 'publish' !== get_post_status( $gift->coupon_id ), 'M12-98: the code holder moves the card into store credit: credit +40, card voided with balance 0', is_wp_error( $moved ) ? $moved->get_error_message() : $moved );
vcap_sa_assert( vcap()->vouchers->has_event( $gift->id, 'moved_to_credit' ) && abs( $liability() - $liab_before ) < 0.001, 'M12-98: both histories show the booking and the outstanding value stays the same', array( $liab_before, $liability() ) );
$again2 = $c2c->move( vcap()->vouchers->get( $gift->id ), $customer, true );
vcap_sa_assert( is_wp_error( $again2 ) && 40.0 === vcap()->store_credit->balance( $customer ), 'M12-98: a voided card cannot be added twice' );
$personal = vcap()->issuer->issue_standalone( array( 'amount' => 10, 'type' => 'giftcard', 'usage' => 'balance', 'send' => false, 'recipient_email' => 'someone-' . $tag . '@example.com' ) );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'transferable' => 0 ), array( 'id' => $personal->id ) ); // phpcs:ignore
VCAP\VoucherRepository::forget();
$single_v = vcap()->issuer->issue_standalone( array( 'amount' => 10, 'send' => false ) );
vcap_sa_assert( is_wp_error( $c2c->move( vcap()->vouchers->get( $personal->id ), $customer, true ) ) && is_wp_error( $c2c->move( $single_v, $customer, true ) ), 'M12-98: personal cards of other people and single-use vouchers are refused' );
$own_card_p  = $sa_product( array( '_vcap_type' => 'giftcard', '_vcap_usage' => 'balance' ) );
$own_order   = wc_create_order( array( 'customer_id' => $customer ) );
$own_order->add_product( $own_card_p, 1 );
$own_order->set_billing_email( get_userdata( $customer )->user_email );
$own_order->calculate_totals( false );
$own_order->set_status( 'processing' );
$own_order->save();
vcap()->issuer->issue_for_order( wc_get_order( $own_order->get_id() ), true, false );
$own_card = vcap()->vouchers->for_order( $own_order->get_id() )[0] ?? null;
wp_set_current_user( $customer );
ob_start();
$c2c->button( $own_card );
$button_html = (string) ob_get_clean();
wp_set_current_user( $admin );
vcap_sa_assert( $own_card && '' === $c2c->refusal( $own_card, $customer, false ) && str_contains( $button_html, 'Add to my store credit' ), 'M12-98: a card bought in the customer\'s own order gets the one-click button in My Account', $own_card ? $c2c->refusal( $own_card, $customer, false ) : null );
update_option( 'vcap_store_credit', 'no' );
vcap_sa_assert( '' !== $c2c->refusal( $own_card, $customer, false ), 'M12-98: without store credit switched on nothing can be moved' );
if ( false === $keep_credit ) {
	delete_option( 'vcap_store_credit' );
} else {
	update_option( 'vcap_store_credit', $keep_credit );
}

echo "Shop admin integration: all checks passed.\n";
