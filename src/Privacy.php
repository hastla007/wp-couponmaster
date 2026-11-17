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

	/** @param mixed $exporters What the previous filter returned (AP-Q2-6: a non-array is normalised). */
	public function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['voucher-captain'] = array(
			'exporter_friendly_name' => __( 'Vouchers', 'voucher-captain' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/** @param mixed $erasers What the previous filter returned (AP-Q2-6: a non-array is normalised). */
	public function erasers( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();
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
		// From the order store in use (wc_get_orders() ignores meta_query for the classic store: it returned the 500
		// newest orders of anyone, and rewards in older orders were never exported or erased).
		$ids    = OrderLookup::ids_with_meta( Referrals::REWARDED, (string) $user_id, array( 'limit' => 500, 'order' => 'DESC' ) );
		$orders = array_map( 'wc_get_order', $ids );
		// Checked again here (the meta value of the order object is authoritative).
		return array_values( array_filter( $orders, static fn( $o ) => $o instanceof \WC_Order && (int) $o->get_meta( Referrals::REWARDED ) === $user_id ) );
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
				$code   = is_array( $reward ) && ! empty( $reward['coupon'] ) ? (string) get_post_field( 'post_title', (int) $reward['coupon'] ) : '';
				if ( ! self::redact_order_notes( $order->get_id(), array( $email, (string) $user->user_email ), $code ) ) {
					$retained = true;
				}
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

	/**
	 * Removes an address (and a reward code) from the notes of an order: reward notes of earlier versions named
	 * the referrer's email address and the full code in the friend's order.
	 *
	 * @param string[] $emails Addresses to remove.
	 * @param string   $code   Code to mask ('' = none).
	 * @return bool false when a note could not be changed
	 */
	public static function redact_order_notes( int $order_id, array $emails, string $code = '' ): bool {
		$emails = array_values( array_unique( array_filter( array_map( 'strval', $emails ) ) ) );
		if ( ! $emails && '' === $code ) {
			return true;
		}
		$ok    = true;
		$notes = function_exists( 'wc_get_order_notes' ) ? wc_get_order_notes( array( 'order_id' => $order_id ) ) : array();
		foreach ( (array) $notes as $note ) {
			if ( ! is_object( $note ) || empty( $note->id ) ) {
				continue;
			}
			$text = (string) $note->content;
			$new  = $text;
			foreach ( $emails as $address ) {
				$new = (string) str_ireplace( $address, __( '[erased]', 'voucher-captain' ), $new );
			}
			if ( '' !== $code ) {
				$new = (string) str_ireplace( $code, Mask::code( $code ), $new );
			}
			if ( $new === $text ) {
				continue;
			}
			$updated = wp_update_comment( array( 'comment_ID' => (int) $note->id, 'comment_content' => $new ), true );
			if ( false === $updated || is_wp_error( $updated ) ) {
				$ok = false;
			}
		}
		return $ok;
	}

	/** Voucher columns the eraser writes (M12-06): the personal data, never balance or status. */
	public const ERASED_COLUMNS = array( 'recipient_email', 'recipient_name', 'message', 'fields', 'buyer_email', 'buyer_name' );

	public function erase( string $email, int $page = 1 ): array {
		$removed  = false;
		$retained = false;
		$vouchers = $this->repo->for_email( $email, 500 );
		$items    = array(); // AP-S3-1: order lines whose personalisation copies go too (item id => scope).
		foreach ( $vouchers as $voucher ) {
			$changed = false;
			if ( 0 === strcasecmp( $voucher->recipient_email, $email ) ) {
				$changed = true;
				$voucher->recipient_email = '';
				$voucher->recipient_name  = '';
				$voucher->message         = '';
				$voucher->fields          = array();
				if ( $voucher->order_item_id ) {
					$items[ $voucher->order_item_id ] = 'recipient';
				}
			}
			if ( 0 === strcasecmp( $voucher->buyer_email, $email ) ) {
				$voucher->buyer_email = '';
				$voucher->buyer_name  = '';
				// The merchant's own fields were typed by the buyer (the recipient's go with the recipient).
				$voucher->fields = array_filter( $voucher->fields, static fn( $key ) => in_array( (string) $key, array_merge( Fields::RECIPIENT_FIELDS, array( 'delivery_date' ) ), true ), ARRAY_FILTER_USE_KEY );
				$changed         = true;
				if ( $voucher->order_item_id && ! isset( $items[ $voucher->order_item_id ] ) ) {
					$items[ $voucher->order_item_id ] = 'buyer';
				}
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
					$kept = array( VoucherRepository::erased_address( $voucher->id ) );
				}
				if ( count( $kept ) !== count( $emails ) || $kept !== array_values( $emails ) ) {
					$coupon->set_email_restrictions( $kept );
					$coupon->save();
				}
			}
			// Remove free-text notes before clearing the lookup email, so a database failure remains retryable.
			// M12-06: only the personal data columns – balance and status of $voucher were read before this loop and
			// may be stale (a redemption landing meanwhile must not be undone).
			if ( ! $this->repo->anonymise_event_notes( $voucher->id ) || ! $this->repo->update_columns( $voucher, self::ERASED_COLUMNS ) ) {
				$retained = true;
				continue;
			}
			$removed = true;
		}
		// AP-S3-1: the copies in the order lines (admin order screen, order emails, REST): of the vouchers above, and
		// of lines naming the address as recipient without a voucher (unpaid or cancelled orders).
		foreach ( Fields::items_for_recipient( $email ) as $item_id ) {
			$items[ $item_id ] = 'recipient';
		}
		foreach ( $items as $item_id => $scope ) {
			try {
				if ( Fields::erase_item_data( Fields::voucher_item( (int) $item_id ), $scope ) ) {
					$removed = true;
				}
			} catch ( \Throwable $e ) {
				$retained = true;
			}
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
		// Promotion limits per customer: plain-text email keys of earlier versions become keyed hashes (the use
		// keeps counting against the limit, the address is gone), also the key remembered on the orders.
		if ( is_email( $email ) && $this->erase_promotion_keys( $email ) ) {
			$removed = true;
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

	/** Converts the plain-text promotion usage keys of an address (see Discounts\Usage::hash_legacy_keys()). */
	private function erase_promotion_keys( string $email ): bool {
		global $wpdb;
		$legacy  = 'e:' . strtolower( trim( $email ) );
		$changed = false;
		if ( Install::storage_ready() ) {
			$table   = Install::tables()['usage'];
			$changed = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE customer_key = %s LIMIT 1', $table, $legacy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		Discounts\Usage::hash_legacy_keys( $email, 5000 );
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = array_map( 'wc_get_order', OrderLookup::ids_with_meta( Discounts\Usage::ORDER_KEY, $legacy, array( 'limit' => 500 ) ) );
			foreach ( $orders as $order ) {
				if ( $order instanceof \WC_Order && $legacy === (string) $order->get_meta( Discounts\Usage::ORDER_KEY ) ) {
					$order->update_meta_data( Discounts\Usage::ORDER_KEY, Discounts\Usage::email_key( $email ) );
					$order->save_meta_data();
					$changed = true;
				}
			}
		}
		return $changed;
	}

	public function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( Brand::name( 'privacy' ), wp_kses_post( wpautop( implode( "\n\n", self::policy_paragraphs() ) ) ) );
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
			__( 'Voucher requests: when you ask for vouchers through a form on this website (for example a company order or a personalised voucher), we store the contact details you enter (company, name, email address, phone number), your message and note, and the names, email addresses and messages of the recipients you list, so that we can check the request and issue the vouchers. No voucher is issued and no payment is taken by the form itself. When the vouchers are issued, the recipient list is removed from the request and kept only with the vouchers; when the request is taken over as a company order, the recipient list moves to that order. A rejected request is deleted at once. Requests that were never completed are deleted after 6 months, issued requests and requests taken over as a company order after 24 months.', 'voucher-captain' ),
			__( 'Review invitations: when a voucher for a service (for example a course or an experience) is redeemed and a review plugin on this website is connected, we note that this redemption may lead to a review invitation – without your name or email address. Only when the review plugin sends the invitation does it ask us for the email address and name of the recipient (or the buyer) and the language. Only the first redemption of a voucher counts. These notes are deleted 90 days after a cancellation or refund, and at the latest after 24 months.', 'voucher-captain' ),
			__( 'At the entrance or the counter, our staff may scan the code of your voucher or ticket. We record the time, the staff member and the result (redeemed, checked in or refused) in the voucher history.', 'voucher-captain' ),
			__( 'Promotion limits per customer: when a promotion may be used only a limited number of times per customer, we record each use with your customer account or, for guest orders, with a keyed hash of your email address (not the address itself), so that the limit also applies to later orders. These records are deleted together with the promotion.', 'voucher-captain' ),
			__( 'To protect voucher codes against guessing and our server against overload, we count code entries, PDF previews and downloads per visitor for a short time window (at most one hour). For this we store the counter under a keyed hash of your IP address (made with a secret key of this website, so it cannot be turned back into the address), never the address itself. The counters are deleted by the daily cleanup, at the latest about one day after the window has ended.', 'voucher-captain' ),
		);
		return $paragraphs;
	}
}
