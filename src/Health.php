<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health (Tools → Site Health) checks for what Voucher Captain depends on: scheduled deliveries (Action
 * Scheduler / WP-Cron), outgoing mail, the private upload folder and – when ticket vouchers are sold – the redeem
 * page. Failed mails are kept in a short, masked log (option "vcap_mail_failures") so a broken mailer is noticed.
 */
final class Health {
	public const MAIL_LOG = 'vcap_mail_failures';
	private const LOG_SIZE = 20;

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
		add_action( 'wp_mail_failed', array( $this, 'record_mail_failure' ) );
	}

	/** Checks that go through all voucher products: run asynchronously (REST), so Site Health opens at once. */
	private const ASYNC = array( 'validity', 'tax_status' );
	/** Voucher products checked at most per test (the result says when there are more). */
	private const MAX_PRODUCTS = 500;

	public function tests( $tests ) {
		$tests = (array) $tests;
		foreach ( array( 'schema', 'scheduler', 'mail', 'upload_dir', 'redeem_page', 'markets' ) as $id ) {
			$tests['direct'][ 'vcap_' . $id ] = array(
				'label' => __( 'Voucher Captain', 'voucher-captain' ),
				'test'  => array( $this, 'test_' . $id ),
			);
		}
		foreach ( self::ASYNC as $id ) {
			$tests['async'][ 'vcap_' . $id ] = array(
				'label'             => __( 'Voucher Captain', 'voucher-captain' ),
				'test'              => rest_url( 'vcap/v1/site-health/' . str_replace( '_', '-', $id ) ),
				'has_rest'          => true,
				'async_direct_test' => array( $this, 'test_' . $id ),
			);
		}
		return $tests;
	}

	/** REST endpoints of the asynchronous checks (same permission as Site Health's own checks). */
	public function rest_routes(): void {
		foreach ( self::ASYNC as $id ) {
			register_rest_route(
				'vcap/v1',
				'/site-health/' . str_replace( '_', '-', $id ),
				array(
					'methods'             => 'GET',
					'callback'            => fn() => rest_ensure_response( $this->{'test_' . $id}() ),
					'permission_callback' => static fn() => current_user_can( 'view_site_health_checks' ),
				)
			);
		}
	}

	/**
	 * Voucher products (and their variations) of the given post types, at most MAX_PRODUCTS.
	 *
	 * @param string[] $post_types Post types.
	 * @return array{0:int[],1:bool} ids, whether there are more
	 */
	private static function voucher_products( array $post_types ): array {
		$ids = get_posts( array( 'post_type' => $post_types, 'post_status' => array( 'publish', 'private', 'draft' ), 'fields' => 'ids', 'numberposts' => self::MAX_PRODUCTS + 1, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_vcap_enabled', 'meta_value' => 'yes' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$ids = array_map( 'intval', $ids );
		return array( array_slice( $ids, 0, self::MAX_PRODUCTS ), count( $ids ) > self::MAX_PRODUCTS );
	}

	/** Sentence added to a result when not every voucher product was checked. */
	private static function truncation_note( bool $more ): string {
		/* translators: %d: number of products checked */
		return $more ? ' ' . sprintf( __( 'Only the first %d voucher products were checked.', 'voucher-captain' ), self::MAX_PRODUCTS ) : '';
	}

	/** Records a failed mail (address masked, no content); the newest 20 of the last 7 days are kept. */
	public function record_mail_failure( $error ): void {
		if ( ! $error instanceof \WP_Error ) {
			return;
		}
		$data = (array) $error->get_error_data();
		$to   = array_map( 'strval', (array) ( $data['to'] ?? array() ) );
		$log  = self::mail_failures();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'to'      => implode( ', ', array_map( array( Compat\PassCaptainTickets::class, 'mask_email' ), $to ) ),
				'subject' => mb_substr( wp_strip_all_tags( (string) ( $data['subject'] ?? '' ) ), 0, 120 ),
				'error'   => mb_substr( wp_strip_all_tags( $error->get_error_message() ), 0, 300 ),
			)
		);
		update_option( self::MAIL_LOG, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/** @return array<int, array{time:int,to:string,subject:string,error:string}> recent failures, newest first. */
	public static function mail_failures(): array {
		$cutoff = time() - 7 * DAY_IN_SECONDS;
		return array_values( array_filter( (array) get_option( self::MAIL_LOG, array() ), static fn( $e ) => is_array( $e ) && (int) ( $e['time'] ?? 0 ) >= $cutoff ) );
	}

	public function test_schema(): array {
		$error = (string) get_option( Install::SCHEMA_ERROR_OPTION, '' );
		if ( '' === $error ) {
			return self::result( 'schema', 'good', __( 'The voucher database tables are complete', 'voucher-captain' ), __( 'All voucher tables, columns and unique keys exist and support transactions.', 'voucher-captain' ) );
		}
		/* translators: %s: technical details */
		return self::result( 'schema', 'critical', __( 'The voucher database update is incomplete', 'voucher-captain' ), sprintf( __( 'Missing: %s. The update is retried automatically; check the database permissions.', 'voucher-captain' ), $error ) );
	}

	public function test_scheduler(): array {
		$overdue = 0;
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$overdue = count(
				(array) as_get_scheduled_actions(
					array(
						'group'        => Delivery::GROUP,
						'status'       => \ActionScheduler_Store::STATUS_PENDING,
						'date'         => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						'date_compare' => '<=',
						'per_page'     => 100,
					),
					'ids'
				)
			);
		}
		$maintenance = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'vcap_daily_maintenance', array(), Delivery::GROUP );
		if ( ! $overdue && $maintenance ) {
			return self::result( 'scheduler', 'good', __( 'Voucher deliveries and daily maintenance are scheduled', 'voucher-captain' ), __( 'Scheduled vouchers, expiry reminders and the daily maintenance run through the Action Scheduler of WooCommerce.', 'voucher-captain' ) );
		}
		$text = $overdue
			/* translators: %d: number of overdue actions */
			? sprintf( _n( '%d scheduled voucher task has been waiting for more than an hour.', '%d scheduled voucher tasks have been waiting for more than an hour.', $overdue, 'voucher-captain' ), $overdue )
			: __( 'The daily voucher maintenance (expiry, reminders, delivery retries) is not scheduled.', 'voucher-captain' );
		return self::result( 'scheduler', 'recommended', __( 'Voucher tasks are not running on time', 'voucher-captain' ), $text . ' ' . __( 'Check that WP-Cron or a server cron job runs, and look at WooCommerce → Status → Scheduled Actions (group "vcap").', 'voucher-captain' ) );
	}

	public function test_mail(): array {
		$failures = self::mail_failures();
		if ( ! $failures ) {
			return self::result( 'mail', 'good', __( 'No failed emails recorded', 'voucher-captain' ), __( 'Voucher Captain records emails that WordPress could not send (last 7 days).', 'voucher-captain' ) );
		}
		$last = $failures[0];
		$text = sprintf(
			/* translators: 1: number of failed mails, 2: date of the last one, 3: error message */
			_n( '%1$d email could not be sent in the last 7 days (last on %2$s: %3$s).', '%1$d emails could not be sent in the last 7 days (last on %2$s: %3$s).', count( $failures ), 'voucher-captain' ),
			count( $failures ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ),
			(string) $last['error']
		);
		return self::result( 'mail', 'critical', __( 'Emails could not be sent', 'voucher-captain' ), $text . ' ' . __( 'Vouchers and tickets are delivered by email: configure an SMTP or mail service plugin and send a test mail.', 'voucher-captain' ) );
	}

	public function test_upload_dir(): array {
		$error = (string) get_option( 'vcap_upload_dir_error', '' );
		if ( '' === $error ) {
			return self::result( 'upload_dir', 'good', __( 'The private voucher folder is protected', 'voucher-captain' ), __( 'PDF vouchers and fonts are stored in a folder that cannot be opened from the web.', 'voucher-captain' ) );
		}
		/* translators: %s: folder path */
		return self::result( 'upload_dir', 'critical', __( 'The private voucher folder is missing or unprotected', 'voucher-captain' ), sprintf( __( 'Voucher Captain could not create or protect %s. Check the folder permissions.', 'voucher-captain' ), $error ) );
	}

	public function test_redeem_page(): array {
		$problem = Compat\PassCaptainTickets::sells_ticket_vouchers() ? Shortcodes::redeem_page_problem() : '';
		if ( '' === $problem ) {
			return self::result( 'redeem_page', 'good', __( 'The voucher redeem page is set up', 'voucher-captain' ), __( 'Voucher emails and ticket vouchers link to a page with the redeem form.', 'voucher-captain' ) );
		}
		return self::result( 'redeem_page', 'recommended', __( 'The voucher redeem page needs attention', 'voucher-captain' ), $problem );
	}

	/** EU countries the shop sells to: verified voucher validity, country conditions and a translation for each. */
	public function test_markets(): array {
		$gaps = array();
		foreach ( Markets::readiness() as $country => $state ) {
			$missing = array();
			if ( ! $state['validity'] ) {
				$missing[] = __( 'statutory validity not verified (3 years to the end of the year is used)', 'voucher-captain' );
			}
			if ( ! $state['terms'] ) {
				$missing[] = __( 'no redemption conditions', 'voucher-captain' );
			}
			if ( ! $state['translation'] ) {
				/* translators: %s: locale, e.g. fr_FR */
				$missing[] = sprintf( __( 'no translation (%s)', 'voucher-captain' ), $state['locale'] );
			}
			if ( $missing ) {
				$gaps[] = $country . ': ' . implode( ', ', $missing );
			}
		}
		if ( ! $gaps ) {
			return self::result( 'markets', 'good', __( 'Voucher rules are set for every EU country you sell to', 'voucher-captain' ), __( 'Each selling country has a verified voucher validity, redemption conditions and a translation.', 'voucher-captain' ) );
		}
		/* translators: %s: list of countries with what is missing */
		return self::result( 'markets', 'recommended', __( 'Some EU selling countries need voucher settings', 'voucher-captain' ), sprintf( __( 'Set the statutory validity and conditions per country under Vouchers → Settings (have them checked legally) and add translations: %s', 'voucher-captain' ), implode( '; ', $gaps ) ) );
	}

	/** Voucher products whose validity ends before the statutory limitation period in the shop or a selling country. */
	public function test_validity(): array {
		list( $ids, $more ) = self::voucher_products( array( 'product' ) );
		$selling = array_keys( Markets::readiness() ); // Once for all products.
		$names   = array();
		foreach ( $ids as $id ) {
			$short = ProductConfig::short_validity( $id, $selling );
			if ( $short ) {
				$names[] = get_the_title( $id ) . ' (' . implode( ', ', array_keys( $short ) ) . ')';
			}
		}
		if ( ! $names ) {
			return self::result( 'validity', 'good', __( 'Voucher validity is not shorter than the statutory period', 'voucher-captain' ), __( 'No voucher product ends before the statutory limitation period of the shop country or an EU selling country.', 'voucher-captain' ) . self::truncation_note( $more ) );
		}
		/* translators: %s: products with the countries concerned */
		return self::result( 'validity', 'recommended', __( 'Some vouchers are valid for less than the statutory period', 'voucher-captain' ), sprintf( __( 'A validity shorter than the statutory limitation period is often invalid in voucher conditions. Check legally or switch to the statutory validity: %s', 'voucher-captain' ), implode( '; ', $names ) ) . self::truncation_note( $more ) );
	}

	/** Voucher products whose tax status does not fit the voucher kind (multi-purpose: none, single-purpose: taxable). */
	public function test_tax_status(): array {
		list( $ids, $more ) = self::voucher_products( array( 'product', 'product_variation' ) );
		$names   = array();
		$checked = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$items   = $product ? array_merge( array( $product ), $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array() ) : array();
			foreach ( $items as $item ) {
				// A variation is found on its own (own voucher setting) and as a child of its product: check it once.
				if ( isset( $checked[ $item->get_id() ] ) ) {
					continue;
				}
				$checked[ $item->get_id() ] = true;
				$conflict                   = ProductConfig::tax_status_conflict( $item );
				if ( $conflict ) {
					/* translators: 1: product name, 2: expected tax status ("none" or "taxable") */
					$names[] = sprintf( __( '%1$s (tax status should be "%2$s")', 'voucher-captain' ), $item->get_name(), 'none' === $conflict['expected'] ? __( 'None', 'voucher-captain' ) : __( 'Taxable', 'voucher-captain' ) );
				}
			}
		}
		if ( ! $names ) {
			return self::result( 'tax_status', 'good', __( 'Voucher products have a tax status that fits their VAT treatment', 'voucher-captain' ), __( 'Multi-purpose vouchers are sold without VAT, single-purpose vouchers with VAT.', 'voucher-captain' ) . self::truncation_note( $more ) );
		}
		/* translators: %s: list of products */
		return self::result( 'tax_status', 'recommended', __( 'Some voucher products have a tax status that does not fit their VAT treatment', 'voucher-captain' ), sprintf( __( 'Multi-purpose vouchers are sold without VAT (tax status "None"), single-purpose vouchers with VAT ("Taxable"). Check with your tax adviser and correct: %s', 'voucher-captain' ), implode( '; ', $names ) ) . self::truncation_note( $more ) );
	}

	private static function result( string $id, string $status, string $label, string $text ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'Vouchers', 'voucher-captain' ), 'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ) ),
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'actions'     => 'mail' === $id || 'good' === $status ? '' : '<p><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=vcap' ) ) . '">' . esc_html__( 'Voucher settings', 'voucher-captain' ) . '</a></p>',
			'test'        => 'vcap_' . $id,
		);
	}
}
