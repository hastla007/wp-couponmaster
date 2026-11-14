<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress personal data export / erasure (buyer and recipient data on vouchers, "used by" history, external
 * redemptions, refer a friend) and the suggested privacy policy text.
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
		if ( 1 === $page ) {
			$items = array_merge( $items, $this->export_referrals( $email ) );
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

	/**
	 * Refer a friend: the customer's own referral code, the number of friends who ordered and the rewards received
	 * on this site (Referrals: per-site user options, coupon meta "_vcap_referral_owner", order meta
	 * "_vcap_referral_rewarded"/"_reward"). Nothing about the friends is exported.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function export_referrals( string $email ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array();
		}
		$items     = array();
		$group     = __( 'Refer a friend', 'voucher-captain' );
		$coupon_id = Referrals::coupon_id( (int) $user->ID, false );
		$count     = Referrals::count( (int) $user->ID, false );
		$codes     = array();
		foreach ( array_merge( array( $coupon_id ), $this->referral_coupons( (int) $user->ID, false ) ) as $id ) {
			if ( $id ) {
				$codes[ $id ] = strtoupper( (string) get_post_field( 'post_title', $id ) );
			}
		}
		if ( $codes || null !== $count ) {
			$code    = implode( ', ', array_unique( array_filter( $codes ) ) );
			$items[] = array(
				'group_id'    => 'vcap-referrals',
				'group_label' => $group,
				'item_id'     => 'vcap-referral-code-' . $user->ID,
				'data'        => array(
					array( 'name' => __( 'Referral code', 'voucher-captain' ), 'value' => $code ),
					array( 'name' => __( 'Friends who ordered', 'voucher-captain' ), 'value' => (string) (int) $count ),
				),
			);
		}
		foreach ( $this->referral_reward_orders( $user->ID ) as $order ) {
			$reward = $order->get_meta( Referrals::REWARD );
			$reward = is_array( $reward ) ? $reward : array();
			$value  = 'credit' === ( $reward['type'] ?? '' )
				? (string) ( $reward['amount'] ?? '' )
				: ( ! empty( $reward['coupon'] ) ? strtoupper( (string) get_post_field( 'post_title', (int) $reward['coupon'] ) ) : '' );
			$date   = $order->get_date_completed() ?: $order->get_date_created();
			$items[] = array(
				'group_id'    => 'vcap-referrals',
				'group_label' => $group,
				'item_id'     => 'vcap-referral-reward-' . $order->get_id(),
				'data'        => array(
					array( 'name' => __( 'Referral reward', 'voucher-captain' ), 'value' => 'credit' === ( $reward['type'] ?? '' ) ? __( 'Store credit', 'voucher-captain' ) : __( 'Coupon', 'voucher-captain' ) ),
					array( 'name' => __( 'Amount or code', 'voucher-captain' ), 'value' => $value ),
					array( 'name' => __( 'Withdrawn', 'voucher-captain' ), 'value' => empty( $reward['revoked'] ) ? __( 'No', 'voucher-captain' ) : __( 'Yes', 'voucher-captain' ) ),
					array( 'name' => __( 'Date', 'voucher-captain' ), 'value' => $date ? $date->date( 'Y-m-d H:i:s' ) : '' ),
				),
			);
		}
		return $items;
	}

	/**
	 * Referral coupons of the customer on this site (coupon meta "_vcap_referral_owner" = user id), at most 500:
	 * also codes no longer referenced by the stored id (e.g. from the network-wide storage of earlier versions).
	 *
	 * @param bool $trashed Include coupons in the trash (they still name the customer).
	 * @return int[]
	 */
	private function referral_coupons( int $user_id, bool $trashed ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => $trashed ? array( 'any', 'trash' ) : 'any',
				'numberposts' => 500,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => Referrals::OWNER, 'value' => (string) $user_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => (int) get_post_meta( $id, Referrals::OWNER, true ) === $user_id ) );
	}

	/**
	 * Friends' orders that rewarded this customer (at most 500 – one erasure/export run).
	 *
	 * @return \WC_Order[]
	 */
	private function referral_reward_orders( int $user_id ): array {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 500,
				'type'       => 'shop_order',
				'status'     => 'any',
				'meta_query' => array( array( 'key' => Referrals::REWARDED, 'value' => (string) $user_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		// Checked again here: older WooCommerce versions ignore meta_query in wc_get_orders() for some storages.
		return array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn( $o ) => $o instanceof \WC_Order && (int) $o->get_meta( Referrals::REWARDED ) === $user_id ) );
	}

	/**
	 * Erase the referral link of a customer: the personal referral code is withdrawn (it names the customer and would
	 * otherwise become an unrestricted discount code), the counters go, friends' orders no longer point to the
	 * customer (the reward stays recorded for accounting, marked as erased) and unused reward coupons restricted to the
	 * address are restricted to an address nobody can have.
	 *
	 * @return array{0: bool, 1: bool} removed, retained
	 */
	private function erase_referrals( string $email ): array {
		$removed  = false;
		$retained = false;
		$user     = get_user_by( 'email', $email );
		if ( $user ) {
			// Every referral coupon of the customer on this site – only coupons whose owner is this customer.
			$owned = $this->referral_coupons( (int) $user->ID, true );
			$own   = Referrals::coupon_id( (int) $user->ID, false );
			if ( $own && ! in_array( $own, $owned, true ) ) {
				$owned[] = $own;
			}
			foreach ( $owned as $coupon_id ) {
				delete_post_meta( $coupon_id, Referrals::OWNER );
				wp_update_post( array( 'ID' => $coupon_id, 'post_excerpt' => '' ) );
				if ( 'trash' !== get_post_status( $coupon_id ) && ! wp_trash_post( $coupon_id ) ) {
					$retained = true;
				}
				$removed = true;
			}
			// The per-site values; the network-wide ones of earlier versions belong to the main site (single site).
			$keys = array( Referrals::site_key( Referrals::USER_META ), Referrals::site_key( Referrals::COUNT_META ) );
			if ( ! is_multisite() || is_main_site() ) {
				array_push( $keys, Referrals::USER_META, Referrals::COUNT_META );
			}
			foreach ( $keys as $key ) {
				if ( '' !== (string) get_user_meta( $user->ID, $key, true ) ) {
					delete_user_meta( $user->ID, $key );
					$removed = true;
				}
			}
			foreach ( $this->referral_reward_orders( $user->ID ) as $order ) {
				$reward = $order->get_meta( Referrals::REWARD );
				if ( is_array( $reward ) ) {
					$reward['user']   = 0;
					$reward['erased'] = time();
					$order->update_meta_data( Referrals::REWARD, $reward );
				}
				// Stays set (an order never rewards twice), but no longer names the customer.
				$order->update_meta_data( Referrals::REWARDED, 'erased' );
				$order->save_meta_data();
				$removed = true;
			}
		}
		$coupons = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => array( 'any', 'trash' ),
				'numberposts' => 500,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_vcap_referral_reward_for', 'compare' => 'EXISTS' ),
					array( 'key' => 'customer_email', 'value' => '"' . strtolower( $email ) . '"', 'compare' => 'LIKE' ),
				),
			)
		);
		foreach ( $coupons as $id ) {
			$coupon = new \WC_Coupon( (int) $id );
			$emails = (array) $coupon->get_email_restrictions();
			$kept   = array_values( array_filter( $emails, static fn( $e ) => 0 !== strcasecmp( (string) $e, $email ) ) );
			if ( count( $kept ) === count( $emails ) ) {
				continue;
			}
			$coupon->set_email_restrictions( $kept ?: array( 'erased-referral-' . $coupon->get_id() . '@invalid.invalid' ) );
			if ( $coupon->save() ) {
				$removed = true;
			} else {
				$retained = true;
			}
		}
		return array( $removed, $retained );
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
		// Failed-mail log of Site Health (masked address, kept up to 7 days).
		if ( 1 === $page && Health::erase_mail_failures( $email ) ) {
			$removed = true;
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
		list( $referrals_removed, $referrals_retained ) = $this->erase_referrals( $email );
		$removed  = $removed || $referrals_removed;
		$retained = $retained || $referrals_retained;
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Some voucher records could not be fully anonymised. Please retry the erasure request.', 'voucher-captain' ) ) : array(),
			'done'           => count( $vouchers ) < 500,
		);
	}

	public function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Voucher Captain', 'voucher-captain' ), wp_kses_post( wpautop( implode( "\n\n", self::policy_paragraphs() ) ) ) );
		}
	}

	/**
	 * Suggested privacy policy text, one paragraph per kind of data.
	 *
	 * @return string[]
	 */
	public static function policy_paragraphs(): array {
		$paragraphs = array(
			__( 'When you buy a voucher, we store the recipient name, recipient email address, personal message and any personalisation you enter so that we can create and deliver the voucher. The recipient receives an email with the voucher if you entered their address. This data is kept as long as the voucher can be used and is then removed according to our retention settings.', 'voucher-captain' ),
			__( 'Vouchers, gift cards and store credit keep a history of every change of their balance (issue, payment in an order, refund, top-up, manual correction) with the date, the amount, the order number and, when a voucher is used by another customer, that customer\'s email address. Store credit is linked to your customer account. We keep this history as long as the voucher or credit can be used and as long as we are required to keep accounting records.', 'voucher-captain' ),
			__( 'Refer a friend: if you take part, we store your personal referral code, how many friends have ordered with it and which rewards you received. A friend\'s order that used your code is linked to your customer account so that we can give you the reward and take it back if the order is cancelled or refunded.', 'voucher-captain' ),
			__( 'When a voucher is redeemed outside the shop checkout, for example by a booking or ticket system on this website that is connected to our vouchers, we record the redemption with the connected service, the amount, the date, your customer account and the details that service passes on (for example its order or booking number).', 'voucher-captain' ),
			__( 'Tickets: when you exchange a ticket voucher for tickets or pay for tickets with a gift card, the voucher code, the amount, your name and email address and the attendee names you enter are passed to the ticket system on this website (WP Pass Captain), which issues and sends the tickets. Its privacy information applies to the tickets.', 'voucher-captain' ),
			__( 'At the entrance or the counter, our staff may scan the code of your voucher or ticket. We record the time, the staff member and the result (redeemed, checked in or refused) in the voucher history.', 'voucher-captain' ),
			__( 'To protect voucher codes against guessing, we count failed code entries per visitor for a short time window (at most 15 minutes). For this we store the counter under a hashed (pseudonymised) form of your IP address, never the address itself; the counters are deleted automatically after the window.', 'voucher-captain' ),
		);
		if ( Licensing\Entitlements::required() ) {
			$paragraphs[] = __( 'License check (premium edition): to check the license and offer updates, this website sends its address (URL), the WordPress, PHP and plugin versions and a random installation ID to the license server of the plugin vendor when the license is activated or deactivated, about twice a day and when updates are checked. The license key is sent only once, on activation, and is not stored on this website. No data about customers or orders is sent.', 'voucher-captain' );
		}
		return $paragraphs;
	}
}
