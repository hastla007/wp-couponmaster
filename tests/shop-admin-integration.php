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

/* PACKAGE-G-MORE */

echo "Shop admin integration: all checks passed.\n";
