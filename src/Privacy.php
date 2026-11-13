<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress personal data export / erasure for buyer and recipient data stored on vouchers.
 */
final class Privacy {
	public function __construct( private VoucherRepository $repo ) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	public function exporters( array $exporters ): array {
		$exporters['voucher-captain'] = array(
			'exporter_friendly_name' => __( 'Vouchers', 'voucher-captain' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function erasers( array $erasers ): array {
		$erasers['voucher-captain'] = array(
			'eraser_friendly_name' => __( 'Vouchers', 'voucher-captain' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/** Records per export page and source (vouchers, uses of other vouchers, external redemptions). */
	public const EXPORT_PAGE = 500;

	public function export( string $email, int $page = 1 ): array {
		$items    = array();
		$offset   = max( 0, ( $page - 1 ) * self::EXPORT_PAGE );
		$vouchers = $this->repo->for_email( $email, self::EXPORT_PAGE, $offset );
		foreach ( $vouchers as $voucher ) {
			$data = array(
				array( 'name' => __( 'Code', 'voucher-captain' ), 'value' => $voucher->code ),
				array( 'name' => __( 'Status', 'voucher-captain' ), 'value' => $voucher->status_label() ),
				array( 'name' => __( 'Buyer name', 'voucher-captain' ), 'value' => $voucher->buyer_name ),
				array( 'name' => __( 'Buyer email', 'voucher-captain' ), 'value' => $voucher->buyer_email ),
				array( 'name' => __( 'Recipient name', 'voucher-captain' ), 'value' => $voucher->recipient_name ),
				array( 'name' => __( 'Recipient email', 'voucher-captain' ), 'value' => $voucher->recipient_email ),
				array( 'name' => __( 'Message', 'voucher-captain' ), 'value' => $voucher->message ),
			);
			foreach ( $voucher->fields as $field ) {
				$data[] = array( 'name' => $field['label'] ?? '', 'value' => $field['value'] ?? '' );
			}
			$items[] = array(
				'group_id'    => 'vcap-vouchers',
				'group_label' => __( 'Vouchers', 'voucher-captain' ),
				'item_id'     => 'vcap-voucher-' . $voucher->id,
				'data'        => $data,
			);
		}
		// All three sources are paged alike; the export is done only when every one of them is exhausted.
		$uses        = $this->repo->events_noting( $email, self::EXPORT_PAGE, $offset );
		$redemptions = $this->redemptions_of( $email, self::EXPORT_PAGE, $offset );
		// Uses of other people's vouchers: WooCommerce records the redeeming customer ("used by") in their history.
		foreach ( $uses as $row ) {
			$items[] = array(
				'group_id'    => 'vcap-voucher-uses',
				'group_label' => __( 'Voucher uses', 'voucher-captain' ),
				'item_id'     => 'vcap-event-' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'Event', 'voucher-captain' ), 'value' => $row['event'] ),
					array( 'name' => __( 'Amount', 'voucher-captain' ), 'value' => (string) $row['amount'] ),
					array( 'name' => __( 'Date', 'voucher-captain' ), 'value' => $row['created_at'] ),
				),
			);
		}
		foreach ( $redemptions as $row ) {
			$items[] = array(
				'group_id'    => 'vcap-redemptions',
				'group_label' => __( 'Voucher redemptions', 'voucher-captain' ),
				'item_id'     => 'vcap-redemption-' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'Redemption key', 'voucher-captain' ), 'value' => $row['redemption_key'] ),
					array( 'name' => __( 'Target', 'voucher-captain' ), 'value' => $row['target'] ),
					array( 'name' => __( 'Status', 'voucher-captain' ), 'value' => $row['state'] ),
					array( 'name' => __( 'Date', 'voucher-captain' ), 'value' => $row['created_at'] ),
					array( 'name' => __( 'Details', 'voucher-captain' ), 'value' => (string) $row['meta'] ),
				),
			);
		}
		$done = count( $vouchers ) < self::EXPORT_PAGE && count( $uses ) < self::EXPORT_PAGE && count( $redemptions ) < self::EXPORT_PAGE;
		return array( 'data' => $items, 'done' => $done );
	}

	/**
	 * External redemptions made by the WordPress user with this email address.
	 *
	 * @param string $email Email.
	 * @return array<int, array<string, mixed>>
	 */
	private function redemptions_of( string $email, int $limit, int $offset ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array();
		}
		$table = Install::tables()['redemptions'];
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d", $user->ID, max( 1, $limit ), max( 0, $offset ) ), ARRAY_A ) ?: array(); // phpcs:ignore
	}

	public function erase( string $email, int $page = 1 ): array {
		$removed  = false;
		$retained = false;
		$vouchers = $this->repo->for_email( $email, 500 );
		foreach ( $vouchers as $voucher ) {
			$changed = false;
			if ( 0 === strcasecmp( $voucher->recipient_email, $email ) ) {
				$changed = true;
				$voucher->recipient_email = '';
				$voucher->recipient_name  = '';
				$voucher->message         = '';
				$voucher->fields          = array();
			}
			if ( 0 === strcasecmp( $voucher->buyer_email, $email ) ) {
				$voucher->buyer_email = '';
				$voucher->buyer_name  = '';
				$changed              = true;
			}
			if ( ! $changed ) {
				continue;
			}
			// The WooCommerce coupon may be restricted to the erased address; drop exactly that address.
			if ( $voucher->coupon_id ) {
				$coupon = new \WC_Coupon( $voucher->coupon_id );
				$emails = $coupon->get_id() ? (array) $coupon->get_email_restrictions() : array();
				$kept   = array_values( array_filter( $emails, static fn( $e ) => 0 !== strcasecmp( (string) $e, $email ) ) );
				if ( $emails && ! $kept ) {
					// An empty restriction would make a personal voucher usable by anyone: keep it restricted to an
					// address nobody can have, until an administrator assigns a new holder.
					$kept = array( 'erased-' . $voucher->id . '@invalid.invalid' );
				}
				if ( count( $kept ) !== count( $emails ) || $kept !== array_values( $emails ) ) {
					$coupon->set_email_restrictions( $kept );
					$coupon->save();
				}
			}
			// Remove free-text notes before clearing the lookup email, so a database failure remains retryable.
			if ( ! $this->repo->anonymise_event_notes( $voucher->id ) || ! $this->repo->update( $voucher ) ) {
				$retained = true;
				continue;
			}
			$removed = true;
		}
		// "Used by" entries in the history of other people's vouchers.
		if ( $this->repo->events_noting( $email ) ) {
			if ( $this->repo->erase_event_notes( $email ) ) {
				$removed = true;
			} else {
				$retained = true;
			}
		}
		// Unlink external redemptions from the user and drop integration details (amounts stay for accounting).
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			global $wpdb;
			$table   = Install::tables()['redemptions'];
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = 0, meta = '[]' WHERE user_id = %d", $user->ID ) ); // phpcs:ignore
			if ( false === $changed ) {
				$retained = true;
			} elseif ( $changed > 0 ) {
				$removed = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Some voucher records could not be fully anonymised. Please retry the erasure request.', 'voucher-captain' ) ) : array(),
			'done'           => count( $vouchers ) < 500,
		);
	}

	public function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				__( 'Voucher Captain', 'voucher-captain' ),
				wp_kses_post( wpautop( __( 'When you buy a voucher, we store the recipient name, recipient email address, personal message and any personalisation you enter so that we can create and deliver the voucher. The recipient receives an email with the voucher if you entered their address. This data is kept as long as the voucher can be used and is then removed according to our retention settings.', 'voucher-captain' ) ) )
			);
		}
	}
}
