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
		add_action( 'wp_mail_failed', array( $this, 'record_mail_failure' ) );
	}

	public function tests( $tests ) {
		$tests = (array) $tests;
		foreach ( array( 'schema', 'scheduler', 'mail', 'upload_dir', 'redeem_page' ) as $id ) {
			$tests['direct'][ 'vcap_' . $id ] = array(
				'label' => __( 'Voucher Captain', 'voucher-captain' ),
				'test'  => array( $this, 'test_' . $id ),
			);
		}
		return $tests;
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
