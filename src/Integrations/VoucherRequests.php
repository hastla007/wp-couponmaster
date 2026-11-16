<?php
/**
 * Captain Suite: voucher requests from forms (WP Form Captain, contract v1).
 *
 * @package VCAP\Integrations
 */

namespace VCAP\Integrations;

use VCAP\Admin\IssueVoucher;
use VCAP\ProductConfig;
use VCAP\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Form action contract v1 (API.md → "Captain Suite: voucher requests"). A form plugin hands over a company order
 * or a personalisation request with submit(); Voucher Captain stores it as a request (private post of type
 * `vcap_request`, state "open") and nothing else: no voucher, no coupon, no email, no payment. A manager opens it
 * under Vouchers → Voucher requests and issues the vouchers explicitly with confirm(), through the existing
 * Issuer::issue_standalone() (one idempotent reference per voucher), or rejects it (the request is deleted).
 * The form plugin's entries are never read: only what submit() receives is kept.
 *
 * Hand-over (owner decision 16.11.2026, API.md → "Hand-over to a company order (add-on)"): an open request with at
 * least two recipients can be handed over to an add-on that builds a company order from it (filters
 * `vcap_voucher_request_handover_available` and `vcap_voucher_request_handover`). Without such an add-on nothing
 * changes. Retention (D10): the daily maintenance deletes open and partly issued requests after 6 months and issued or
 * handed-over ones after 24 months (filter `vcap_voucher_requests_retention_months`).
 *
 * @since 1.2.0
 */
final class VoucherRequests {
	public const CONTRACT       = '1.0.0';
	public const POST_TYPE      = 'vcap_request';
	public const META           = '_vcap_request';
	public const STATE_META     = '_vcap_request_state';
	public const VOUCHERS_META  = '_vcap_request_vouchers';
	public const ERRORS_META    = '_vcap_request_errors';
	/** Claim of a form reference (option): the request id, so a repeated submit() creates nothing. */
	public const CLAIM_PREFIX   = 'vcap_request_ref_';
	/** R8: hash of the form reference on the request itself (found again when its claim was not completed). */
	public const REF_META = '_vcap_request_ref';
	/** R8: claim value of a rejected request ("rejected:" + UTC time), removed by the retention after a while. */
	public const REJECTED_CLAIM = 'rejected:';
	/** R8: seconds after which a claim without a stored request is taken over by a repeat. */
	private const CLAIM_STALE = 120;
	public const KINDS          = array( 'company_order', 'personalisation' );
	public const MAX_VOUCHERS   = 100;
	public const STATE_OPEN     = 'open';
	public const STATE_PARTIAL  = 'partial';
	public const STATE_ISSUED   = 'issued';
	/** Handed over to a company order of an add-on (D5): the order holds the recipients now. */
	public const STATE_HANDED_OVER = 'handed_over';
	/** Hand-over record: order_id, url, at (UTC), user_id. */
	public const HANDOVER_META = '_vcap_request_handover';
	/** Contract of the hand-over payload (API.md → "Hand-over to a company order (add-on)"). */
	public const HANDOVER_CONTRACT = '1.0.0';
	/** Fewest recipients a request needs to be offered as a company order. */
	public const HANDOVER_MIN_RECIPIENTS = 2;
	/** Last change by a manager (UTC; confirmation, hand-over) – the start of the retention period of closed requests. */
	public const UPDATED_META = '_vcap_request_updated';
	/** Default of `vcap_voucher_requests_retention_months` (D10): open/partial and issued/handed-over requests. */
	public const RETENTION_MONTHS = array(
		'open'   => 6,
		'closed' => 24,
	);
	/** Requests one retention batch deletes. */
	public const RETENTION_BATCH = 50;
	/** Lookup rows (one per lower-case address of contact and recipients) for the privacy exporter and eraser. */
	public const EMAIL_META     = '_vcap_request_email';
	/** Default of `vcap_voucher_requests_max_open`. */
	public const MAX_OPEN       = 500;
	/** Requests per privacy export/erasure page. */
	public const PRIVACY_PAGE   = 100;

	/** @var callable(array): mixed */
	private $issue;
	/** @var callable(string, int, int): int[] */
	private $find;
	/** @var callable(int): int */
	private $count_open;
	/** @var callable(): bool */
	private $can_manage;

	/**
	 * @param callable|null $issue      Issues one voucher (default: vcap()->issuer->issue_standalone()).
	 * @param callable|null $find       Request ids naming an address ($email, $limit, $offset; default: EMAIL_META).
	 * @param callable|null $count_open Number of open requests, counted up to $cap (default: a post query).
	 * @param callable|null $can_manage Whether the current user may hand requests over (default: vcap_manage_vouchers).
	 */
	public function __construct( ?callable $issue = null, ?callable $find = null, ?callable $count_open = null, ?callable $can_manage = null ) {
		$this->issue      = $issue ?? static fn( array $args ) => vcap()->issuer->issue_standalone( $args );
		$this->find       = $find ?? array( self::class, 'ids_for_email' );
		$this->count_open = $count_open ?? array( self::class, 'open_count' );
		$this->can_manage = $can_manage ?? static fn(): bool => current_user_can( 'vcap_manage_vouchers' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'vcap_voucher_request_service_v1', array( $this, 'service' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/** @param mixed $exporters What the previous filter returned. */
	public function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['voucher-captain-requests'] = array(
			'exporter_friendly_name' => __( 'Voucher requests', 'voucher-captain' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/** @param mixed $erasers What the previous filter returned. */
	public function erasers( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();
		$erasers['voucher-captain-requests'] = array(
			'eraser_friendly_name' => __( 'Voucher requests', 'voucher-captain' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/** Lower-case addresses a request names (contact and recipients). @return string[] */
	public static function emails( array $request ): array {
		$emails = array( (string) ( $request['contact']['email'] ?? '' ) );
		foreach ( (array) ( $request['recipients'] ?? array() ) as $r ) {
			$emails[] = (string) ( $r['email'] ?? '' );
		}
		return array_values( array_unique( array_filter( array_map( static fn( $e ) => strtolower( trim( $e ) ), $emails ) ) ) );
	}

	/**
	 * Ids of requests naming the address. Read from the posts tables directly (own post type): WP_Query would show
	 * private posts only to their author or to users with read_private_posts.
	 *
	 * @return int[]
	 */
	public static function ids_for_email( string $email, int $limit, int $offset ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = %s GROUP BY p.ID ORDER BY p.ID LIMIT %d OFFSET %d", self::EMAIL_META, strtolower( trim( $email ) ), self::POST_TYPE, max( 1, $limit ), max( 0, $offset ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Open requests, counted up to $cap (also for visitors, see ids_for_email()). */
	public static function open_count( int $cap ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = %s LIMIT %d ) open_requests", self::STATE_META, self::STATE_OPEN, self::POST_TYPE, max( 1, $cap ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Privacy exporter: the parts of requests that belong to the address – as contact (contact data, message,
	 * note) or as recipient (name, email, message). Other recipients of the same request are not exported.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$ids   = ( $this->find )( $email, self::PRIVACY_PAGE, max( 0, ( $page - 1 ) * self::PRIVACY_PAGE ) );
		$items = array();
		foreach ( $ids as $id ) {
			$request = self::get( (int) $id );
			if ( ! $request ) {
				continue;
			}
			$data = array(
				array( 'name' => __( 'Reference', 'voucher-captain' ), 'value' => (string) $request['reference'] ),
				array( 'name' => __( 'Received', 'voucher-captain' ), 'value' => (string) $request['received_at'] ),
				array( 'name' => __( 'Status', 'voucher-captain' ), 'value' => self::state_label( (string) $request['state'] ) ),
			);
			$contact = (array) $request['contact'];
			if ( 0 === strcasecmp( (string) $contact['email'], $email ) ) {
				$data[] = array( 'name' => __( 'Company', 'voucher-captain' ), 'value' => (string) $contact['company'] );
				$data[] = array( 'name' => __( 'Contact name', 'voucher-captain' ), 'value' => (string) $contact['name'] );
				$data[] = array( 'name' => __( 'Contact email', 'voucher-captain' ), 'value' => (string) $contact['email'] );
				$data[] = array( 'name' => __( 'Phone', 'voucher-captain' ), 'value' => (string) $contact['phone'] );
				$data[] = array( 'name' => __( 'Message', 'voucher-captain' ), 'value' => (string) $request['message'] );
				$data[] = array( 'name' => __( 'Note', 'voucher-captain' ), 'value' => (string) $request['note'] );
			}
			foreach ( (array) $request['recipients'] as $r ) {
				if ( 0 === strcasecmp( (string) $r['email'], $email ) ) {
					$data[] = array( 'name' => __( 'Recipient name', 'voucher-captain' ), 'value' => (string) $r['name'] );
					$data[] = array( 'name' => __( 'Recipient email', 'voucher-captain' ), 'value' => (string) $r['email'] );
					$data[] = array( 'name' => __( 'Message', 'voucher-captain' ), 'value' => (string) $r['message'] );
				}
			}
			$items[] = array(
				'group_id'    => 'vcap-voucher-requests',
				'group_label' => __( 'Voucher requests', 'voucher-captain' ),
				'item_id'     => 'vcap-request-' . (int) $id,
				'data'        => $data,
			);
		}
		return array( 'data' => $items, 'done' => count( $ids ) < self::PRIVACY_PAGE );
	}

	/**
	 * Privacy eraser: removes the address with the name, phone and texts that belong to it (contact: name, email,
	 * phone, message, note; recipient: name, email, message). The company name, amounts and the count stay. Erased
	 * requests no longer match the address, so every page starts at the first match.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page; erased requests no longer match, so every page starts at the first match.
		$ids      = ( $this->find )( $email, self::PRIVACY_PAGE, 0 );
		$removed  = false;
		$retained = false;
		$lower    = strtolower( trim( $email ) );
		foreach ( $ids as $id ) {
			$request = get_post_meta( (int) $id, self::META, true );
			if ( is_array( $request ) ) {
				$hit = false;
				if ( 0 === strcasecmp( (string) ( $request['contact']['email'] ?? '' ), $email ) ) {
					$request['contact'] = array( 'name' => '', 'email' => '', 'phone' => '' ) + (array) $request['contact'];
					$request['message'] = '';
					$request['note']    = '';
					$hit                = true;
				}
				foreach ( (array) ( $request['recipients'] ?? array() ) as $i => $r ) {
					if ( 0 === strcasecmp( (string) ( $r['email'] ?? '' ), $email ) ) {
						$request['recipients'][ $i ] = array( 'name' => '', 'email' => '', 'message' => '' );
						$hit                         = true;
					}
				}
				if ( $hit && ! update_post_meta( (int) $id, self::META, $request ) ) {
					$retained = true;
					continue;
				}
				$removed = $removed || $hit;
			}
			delete_post_meta( (int) $id, self::EMAIL_META, $lower );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Some voucher requests could not be anonymised. Please retry the erasure request.', 'voucher-captain' ) ) : array(),
			'done'           => count( $ids ) < self::PRIVACY_PAGE,
		);
	}

	/**
	 * `vcap_voucher_request_service_v1`: the service for a form plugin.
	 *
	 * @param mixed $current Earlier answer.
	 * @return mixed
	 */
	public function service( $current ) {
		return null === $current ? $this : $current;
	}

	public function contract(): string {
		return self::CONTRACT;
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Voucher requests', 'voucher-captain' ),
					'singular_name' => __( 'Voucher request', 'voucher-captain' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * Checks and cleans a request (contract v1). Unknown keys – also "paid", "code", "send" or a status – are
	 * dropped: a form decides nothing about payment or issuing.
	 *
	 * @param array<string, mixed> $in reference (required, 8–100 characters A–Z a–z 0–9 . _ : / -, e.g.
	 *                                 "formcaptain:entry:123"), kind (company_order|personalisation), product_id,
	 *                                 amount (per voucher), quantity (without recipients), recipients (list of
	 *                                 name, email, message), contact (company, name, email, phone), message, note,
	 *                                 contract (optional, major version 1).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function normalise( array $in ) {
		$contract = (string) ( $in['contract'] ?? self::CONTRACT );
		if ( strtok( $contract, '.' ) !== strtok( self::CONTRACT, '.' ) ) {
			return new \WP_Error( 'vcap_request_contract', 'Unsupported contract version (voucher requests v1).', array( 'status' => 409 ) );
		}
		$reference = trim( (string) ( $in['reference'] ?? '' ) );
		if ( ! preg_match( '#^[A-Za-z0-9._:/\-]{8,100}$#', $reference ) ) {
			return new \WP_Error( 'vcap_invalid_request', 'A reference of 8–100 characters (A–Z a–z 0–9 . _ : / -) is required.', array( 'status' => 400 ) );
		}
		$kind       = sanitize_key( (string) ( $in['kind'] ?? 'company_order' ) );
		$product_id = absint( $in['product_id'] ?? 0 );
		if ( $product_id && ! ProductConfig::is_voucher( $product_id ) ) {
			return new \WP_Error( 'vcap_invalid_product', __( 'This product is no voucher product.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$raw_amount = trim( (string) ( $in['amount'] ?? '' ) );
		$amount     = '' === $raw_amount ? '' : round( (float) str_replace( ',', '.', $raw_amount ), 2 );
		if ( '' !== $amount && $amount <= 0 ) {
			return new \WP_Error( 'vcap_invalid_amount', __( 'Please give a voucher value greater than zero.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$contact = is_array( $in['contact'] ?? null ) ? $in['contact'] : array();
		$contact = array(
			'company' => mb_substr( sanitize_text_field( (string) ( $contact['company'] ?? '' ) ), 0, 100 ),
			'name'    => mb_substr( sanitize_text_field( (string) ( $contact['name'] ?? '' ) ), 0, 100 ),
			'email'   => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
			'phone'   => mb_substr( sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ), 0, 40 ),
		);
		if ( ! is_email( $contact['email'] ) ) {
			return new \WP_Error( 'vcap_invalid_email', __( 'Please give a valid contact email address.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$recipients = array();
		foreach ( is_array( $in['recipients'] ?? null ) ? $in['recipients'] : array() as $r ) {
			$r     = is_array( $r ) ? $r : array();
			$email = sanitize_email( (string) ( $r['email'] ?? '' ) );
			if ( '' !== trim( (string) ( $r['email'] ?? '' ) ) && ! is_email( $email ) ) {
				return new \WP_Error( 'vcap_invalid_email', __( 'A recipient email address is not valid.', 'voucher-captain' ), array( 'status' => 400 ) );
			}
			$recipients[] = array(
				'name'    => mb_substr( sanitize_text_field( (string) ( $r['name'] ?? '' ) ), 0, 100 ),
				'email'   => $email,
				'message' => mb_substr( sanitize_textarea_field( (string) ( $r['message'] ?? '' ) ), 0, 1000 ),
			);
		}
		$quantity = $recipients ? count( $recipients ) : max( 1, absint( $in['quantity'] ?? 1 ) );
		if ( $quantity > self::MAX_VOUCHERS ) {
			/* translators: %d: maximum number of vouchers */
			return new \WP_Error( 'vcap_invalid_request', sprintf( __( 'A request may ask for at most %d vouchers.', 'voucher-captain' ), self::MAX_VOUCHERS ), array( 'status' => 400 ) );
		}
		return array(
			'contract'    => self::CONTRACT,
			'reference'   => $reference,
			'kind'        => in_array( $kind, self::KINDS, true ) ? $kind : 'company_order',
			'product_id'  => $product_id,
			'amount'      => $amount,
			'quantity'    => $quantity,
			'recipients'  => $recipients,
			'contact'     => $contact,
			'message'     => mb_substr( sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ), 0, 1000 ),
			'note'        => mb_substr( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ), 0, 2000 ),
			'received_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Stores a request as "open". Idempotent per reference: a repeated call answers the same request with
	 * `replayed` true. Checks no capability (form plugins call it for visitors after their own checks: nonce,
	 * spam protection) – a request is a draft only and issues nothing.
	 *
	 * @param array<string, mixed> $request See normalise().
	 * @return array{request_id:int, reference:string, state:string, replayed:bool}|\WP_Error
	 */
	public function submit( array $request ) {
		$clean = self::normalise( $request );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$claim = self::CLAIM_PREFIX . md5( $clean['reference'] );
		if ( false === get_option( $claim, false ) ) {
			/**
			 * Most open requests at a time (protection against floods of form entries); above it submit() answers
			 * `vcap_request_limit` (429). 0 accepts no new request.
			 *
			 * @since 1.2.0
			 * @param int $max Default 500.
			 */
			$max = max( 0, (int) apply_filters( 'vcap_voucher_requests_max_open', self::MAX_OPEN ) );
			if ( 0 === $max || ( $this->count_open )( $max ) >= $max ) {
				return new \WP_Error( 'vcap_request_limit', __( 'Too many voucher requests are waiting to be handled. Please try again later.', 'voucher-captain' ), array( 'status' => 429 ) );
			}
			if ( self::flooded( $clean ) ) {
				return new \WP_Error( 'vcap_request_limit', __( 'You have sent several voucher requests in a short time. Please try again later.', 'voucher-captain' ), array( 'status' => 429 ) );
			}
		}
		if ( ! add_option( $claim, '0:' . time(), '', false ) ) {
			$stored = (string) get_option( $claim, '0' );
			if ( str_starts_with( $stored, self::REJECTED_CLAIM ) ) {
				return array( 'request_id' => 0, 'reference' => $clean['reference'], 'state' => 'rejected', 'replayed' => true );
			}
			$id = (int) $stored;
			if ( 0 === $id ) {
				// R8: the first call stopped between storing the request and completing its claim: find it again.
				$id = self::id_by_reference( $clean['reference'] );
				if ( $id ) {
					update_option( $claim, $id, false );
				} elseif ( (int) substr( (string) strstr( $stored, ':' ), 1 ) < time() - self::CLAIM_STALE && delete_option( $claim ) ) {
					return $this->submit( $request ); // Nothing was stored: this repeat takes the reference over.
				}
			}
			return array(
				'request_id' => $id,
				'reference'  => $clean['reference'],
				'state'      => $id ? (string) get_post_meta( $id, self::STATE_META, true ) : self::STATE_OPEN,
				'replayed'   => true,
			);
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				/* translators: %s: reference of the form entry */
				'post_title'  => sprintf( __( 'Voucher request %s', 'voucher-captain' ), $clean['reference'] ),
				'meta_input'  => array( self::REF_META => md5( $clean['reference'] ) ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			delete_option( $claim );
			return new \WP_Error( 'vcap_temporary_failure', 'The request could not be saved – retry with the same reference.', array( 'status' => 503 ) );
		}
		update_post_meta( (int) $id, self::META, $clean );
		update_post_meta( (int) $id, self::STATE_META, self::STATE_OPEN );
		foreach ( self::emails( $clean ) as $address ) {
			add_post_meta( (int) $id, self::EMAIL_META, $address );
		}
		update_option( $claim, (int) $id, false );
		/**
		 * A voucher request was stored (nothing was issued). No personal data in the arguments.
		 *
		 * @since 1.2.0
		 * @param int    $request_id Request (post id).
		 * @param string $kind       company_order or personalisation.
		 * @param int    $vouchers   Number of vouchers asked for.
		 */
		do_action( 'vcap_voucher_request_received', (int) $id, $clean['kind'], (int) $clean['quantity'] );
		return array(
			'request_id' => (int) $id,
			'reference'  => $clean['reference'],
			'state'      => self::STATE_OPEN,
			'replayed'   => false,
		);
	}

	/**
	 * A stored request with its state, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		if ( $id <= 0 || self::POST_TYPE !== get_post_type( $id ) ) {
			return null;
		}
		$data = get_post_meta( $id, self::META, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return $data + array(
			'id'       => $id,
			'state'    => (string) get_post_meta( $id, self::STATE_META, true ),
			'vouchers' => array_map( 'intval', (array) get_post_meta( $id, self::VOUCHERS_META, true ) ),
			'errors'   => array_map( 'strval', (array) get_post_meta( $id, self::ERRORS_META, true ) ),
		);
	}

	/**
	 * Arguments for Issuer::issue_standalone() of the voucher number $index (0-based) of a request.
	 *
	 * @param array<string, mixed> $request Request (get()).
	 * @param array<string, mixed> $opts    Decisions of the confirming manager: sale ("sold" or "free", required),
	 *                                      send (bool), product_id, amount, type (without product), reason.
	 * @return array<string, mixed>
	 */
	public static function issue_args( array $request, int $id, int $index, array $opts ): array {
		$recipient = $request['recipients'][ $index ] ?? array();
		$contact   = (array) ( $request['contact'] ?? array() );
		$product   = absint( $opts['product_id'] ?? $request['product_id'] ?? 0 );
		$amount    = (float) ( '' !== (string) ( $opts['amount'] ?? '' ) ? $opts['amount'] : ( $request['amount'] ?? 0 ) );
		$args      = array(
			'recipient_name'  => (string) ( $recipient['name'] ?? '' ),
			'recipient_email' => (string) ( $recipient['email'] ?? '' ),
			'message'         => '' !== (string) ( $recipient['message'] ?? '' ) ? (string) $recipient['message'] : (string) ( $request['message'] ?? '' ),
			'buyer_name'      => '' !== (string) ( $contact['company'] ?? '' ) ? (string) $contact['company'] : (string) ( $contact['name'] ?? '' ),
			'buyer_email'     => (string) ( $contact['email'] ?? '' ),
			'send'            => ! empty( $opts['send'] ),
			// Only the manager decides whether it is a sale (company order on invoice) or free of charge.
			'paid'            => 'sold' === ( $opts['sale'] ?? '' ),
			'source'          => 'request',
			'reference'       => 'vcap-request:' . $id . '/' . ( $index + 1 ),
			'reason'          => '' !== trim( (string) ( $opts['reason'] ?? '' ) )
				? sanitize_text_field( (string) $opts['reason'] )
				/* translators: %s: reference of the form entry */
				: sprintf( __( 'Voucher request %s', 'voucher-captain' ), (string) ( $request['reference'] ?? '' ) ),
		);
		if ( $product ) {
			$args['product_id'] = $product;
		} else {
			$type          = 'voucher' === ( $opts['type'] ?? '' ) ? 'voucher' : 'giftcard';
			$args['type']  = $type;
			$args['usage'] = ProductConfig::default_usage( $type );
		}
		if ( $amount > 0 ) {
			$args['amount'] = $amount;
		}
		return $args;
	}

	/**
	 * Explicit confirmation by a manager (Vouchers → Voucher requests): issues the request's vouchers through
	 * Issuer::issue_standalone(). Needs the right to issue vouchers without an order (IssueVoucher::can()) and an
	 * explicit sale decision. Repeating it issues only what is still missing (references "vcap-request:{id}/{n}").
	 *
	 * @param array<string, mixed> $opts See issue_args().
	 * @return array{state:string, vouchers:int[], errors:string[]}|\WP_Error
	 */
	private function confirm_locked( int $id, array $opts ) {
		if ( ! IssueVoucher::can() ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'You are not allowed to do this.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $opts['sale'] ?? '', array( 'sold', 'free' ), true ) ) {
			return new \WP_Error( 'vcap_invalid_args', __( 'Please choose whether the vouchers were sold or are free of charge.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$request = self::get( $id );
		if ( ! $request ) {
			return new \WP_Error( 'vcap_not_found', __( 'This voucher request does not exist.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		if ( self::STATE_HANDED_OVER === $request['state'] ) {
			return new \WP_Error( 'vcap_handed_over', __( 'This voucher request was handed over to a company order. Its vouchers are issued there.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		if ( self::STATE_ISSUED === $request['state'] ) {
			return array(
				'state'    => self::STATE_ISSUED,
				'vouchers' => $request['vouchers'],
				'errors'   => array(),
			);
		}
		$result = Source::with( Source::ADMIN, fn() => $this->issue_all( $request, $id, $opts ) );
		update_post_meta( $id, self::STATE_META, $result['state'] );
		update_post_meta( $id, self::VOUCHERS_META, $result['vouchers'] );
		update_post_meta( $id, self::ERRORS_META, $result['errors'] );
		update_post_meta( $id, self::UPDATED_META, gmdate( 'Y-m-d H:i:s' ) );
		if ( self::STATE_ISSUED === $result['state'] ) {
			// The recipients' data now lives in the vouchers (and their retention rules): keep only the count here.
			self::drop_recipients( $id );
		}
		/**
		 * A manager confirmed a voucher request (vouchers were issued through the voucher service).
		 *
		 * @since 1.2.0
		 * @param int    $request_id Request.
		 * @param string $state      issued or partial.
		 * @param int[]  $vouchers   Ids of the vouchers of this request.
		 * @param int    $user_id    Confirming user.
		 */
		do_action( 'vcap_voucher_request_confirmed', $id, $result['state'], $result['vouchers'], get_current_user_id() );
		return $result;
	}

	/**
	 * Removes the recipients of a request (name, address, message and their lookup rows) and keeps only their
	 * number: after issuing or a hand-over their data lives in the vouchers or the company order.
	 */
	private static function drop_recipients( int $id ): void {
		$request = (array) get_post_meta( $id, self::META, true );
		$contact = strtolower( (string) ( $request['contact']['email'] ?? '' ) );
		foreach ( array_diff( self::emails( $request ), array( $contact ) ) as $address ) {
			delete_post_meta( $id, self::EMAIL_META, $address );
		}
		$request['recipients'] = array();
		update_post_meta( $id, self::META, $request );
	}

	// -----------------------------------------------------------------------------------------------------------
	// D5: hand-over to a company order of an add-on (contract 1.0.0).
	// -----------------------------------------------------------------------------------------------------------

	/** Whether the current user may hand requests over (vcap_manage_vouchers). */
	public function can_hand_over(): bool {
		return (bool) ( $this->can_manage )();
	}

	/**
	 * Whether the request view shows "Take over as company order": the request is open (nothing issued), has at least
	 * two recipients, the user has vcap_manage_vouchers and an add-on offers the hand-over for it.
	 *
	 * @param array<string, mixed> $request Request (get()).
	 */
	public function handover_available( array $request ): bool {
		if ( ! self::handover_possible( $request ) || ! $this->can_hand_over() ) {
			return false;
		}
		/**
		 * Whether an add-on takes this voucher request over as a company order (D5, contract 1.0.0). Default false:
		 * without an add-on the request view stays as it is.
		 *
		 * @since 1.2.0
		 * @param bool $available  Default false.
		 * @param int  $request_id Request.
		 */
		return true === (bool) apply_filters( 'vcap_voucher_request_handover_available', false, (int) ( $request['id'] ?? 0 ) );
	}

	/**
	 * Whether the request could be handed over at all: open (nothing issued) with at least two recipients.
	 *
	 * @param array<string, mixed> $request Request (get()).
	 */
	public static function handover_possible( array $request ): bool {
		return self::STATE_OPEN === ( $request['state'] ?? '' ) && count( (array) ( $request['recipients'] ?? array() ) ) >= self::HANDOVER_MIN_RECIPIENTS;
	}

	/**
	 * The data an add-on receives (exactly these keys): contract, request_id, reference ("vcap-request:{id}", stable –
	 * the add-on de-duplicates by it), company, currency, recipients, note.
	 *
	 * @param array<string, mixed> $request Request (get()).
	 * @return array<string, mixed>
	 */
	public static function handover_payload( array $request ): array {
		$id         = (int) ( $request['id'] ?? 0 );
		$contact    = (array) ( $request['contact'] ?? array() );
		$amount     = '' !== (string) ( $request['amount'] ?? '' ) ? (float) $request['amount'] : 0.0;
		$recipients = array();
		foreach ( (array) ( $request['recipients'] ?? array() ) as $r ) {
			$recipients[] = array(
				'name'    => (string) ( $r['name'] ?? '' ),
				'email'   => (string) ( $r['email'] ?? '' ),
				'amount'  => $amount,
				'message' => (string) ( $r['message'] ?? '' ),
			);
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : (string) get_option( 'woocommerce_currency', 'EUR' );
		return array(
			'contract'   => self::HANDOVER_CONTRACT,
			'request_id' => $id,
			'reference'  => 'vcap-request:' . $id,
			'company'    => array(
				'name'          => (string) ( $contact['company'] ?? '' ),
				'contact_name'  => (string) ( $contact['name'] ?? '' ),
				'contact_email' => (string) ( $contact['email'] ?? '' ),
				'contact_phone' => (string) ( $contact['phone'] ?? '' ),
			),
			'currency'   => strtoupper( '' !== $currency ? $currency : 'EUR' ),
			'recipients' => $recipients,
			'note'       => implode( "\n\n", array_filter( array( trim( (string) ( $request['message'] ?? '' ) ), trim( (string) ( $request['note'] ?? '' ) ) ), 'strlen' ) ),
		);
	}

	/**
	 * Hands an open request over to a company order of an add-on (`vcap_voucher_request_handover`). On success the
	 * request becomes "handed_over", keeps order id, URL, time and user, and loses its recipients (they belong to the
	 * company order now). A WP_Error of the add-on leaves the request open with all its data. A request handed over
	 * already answers its stored order (double click) without asking the add-on again.
	 *
	 * @return array{order_id:int, url:string, replayed:bool}|\WP_Error
	 */
	private function hand_over_locked( int $id ) {
		if ( ! $this->can_hand_over() ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'You are not allowed to do this.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		$request = self::get( $id );
		if ( ! $request ) {
			return new \WP_Error( 'vcap_not_found', __( 'This voucher request does not exist.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		if ( self::STATE_HANDED_OVER === $request['state'] ) {
			$done = (array) get_post_meta( $id, self::HANDOVER_META, true );
			return array(
				'order_id' => (int) ( $done['order_id'] ?? 0 ),
				'url'      => (string) ( $done['url'] ?? '' ),
				'replayed' => true,
			);
		}
		if ( ! self::handover_possible( $request ) ) {
			/* translators: %d: fewest recipients */
			return new \WP_Error( 'vcap_handover_not_possible', sprintf( __( 'Only open voucher requests with at least %d recipients can be taken over as a company order.', 'voucher-captain' ), self::HANDOVER_MIN_RECIPIENTS ), array( 'status' => 409 ) );
		}
		$unavailable = new \WP_Error( 'vcap_handover_unavailable', __( 'No extension takes over this request.', 'voucher-captain' ), array( 'status' => 409 ) );
		if ( ! $this->handover_available( $request ) ) {
			return $unavailable;
		}
		/**
		 * Hands a voucher request over to a company order (D5, contract 1.0.0). Return `array{url: string, order_id:
		 * int}` (admin URL of the order's preview) or a WP_Error; null means no extension took it. The same reference
		 * again must answer the same order.
		 *
		 * @since 1.2.0
		 * @param mixed $result   Default null.
		 * @param array $handover contract, request_id, reference, company, currency, recipients, note.
		 */
		$result = apply_filters( 'vcap_voucher_request_handover', null, self::handover_payload( $request ) );
		if ( null === $result ) {
			return $unavailable;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$order_id = is_array( $result ) ? (int) ( $result['order_id'] ?? 0 ) : 0;
		$url      = is_array( $result ) ? esc_url_raw( (string) ( $result['url'] ?? '' ) ) : '';
		if ( $order_id <= 0 || '' === $url ) {
			return new \WP_Error( 'vcap_handover_invalid', __( 'The extension did not answer with a company order. The request stays open.', 'voucher-captain' ), array( 'status' => 502 ) );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		update_post_meta(
			$id,
			self::HANDOVER_META,
			array(
				'order_id' => $order_id,
				'url'      => $url,
				'at'       => $now,
				'user_id'  => get_current_user_id(),
			)
		);
		update_post_meta( $id, self::STATE_META, self::STATE_HANDED_OVER );
		update_post_meta( $id, self::UPDATED_META, $now );
		self::drop_recipients( $id );
		/**
		 * A voucher request was handed over to a company order of an add-on (no personal data).
		 *
		 * @since 1.2.0
		 * @param int $request_id Request.
		 * @param int $order_id   Company order of the add-on.
		 * @param int $user_id    Acting user.
		 */
		do_action( 'vcap_voucher_request_handed_over', $id, $order_id, get_current_user_id() );
		return array(
			'order_id' => $order_id,
			'url'      => $url,
			'replayed' => false,
		);
	}

	/**
	 * The hand-over record of a request (order_id, url, at, user_id), null when it was not handed over.
	 *
	 * @return array{order_id:int, url:string, at:string, user_id:int}|null
	 */
	public static function handover( int $id ): ?array {
		$done = get_post_meta( $id, self::HANDOVER_META, true );
		if ( ! is_array( $done ) || (int) ( $done['order_id'] ?? 0 ) <= 0 ) {
			return null;
		}
		return array(
			'order_id' => (int) $done['order_id'],
			'url'      => (string) ( $done['url'] ?? '' ),
			'at'       => (string) ( $done['at'] ?? '' ),
			'user_id'  => (int) ( $done['user_id'] ?? 0 ),
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// D10: retention (daily maintenance step "voucher_requests").
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * Months after which requests are deleted: "open" for open and partly issued requests (counted from the receipt,
	 * a partly issued one from the last confirmation), "closed" for issued and handed-over ones (from the issuing or
	 * the hand-over). 0 = never.
	 *
	 * @return array{open:int, closed:int}
	 */
	public static function retention_months(): array {
		/**
		 * Retention of voucher requests in months (D10): `open` – open and partly issued requests (default 6), `closed`
		 * – issued and handed-over requests (default 24). 0 keeps them. Rejected requests are deleted at once.
		 *
		 * @since 1.2.0
		 * @param array{open:int, closed:int} $months Months.
		 */
		$months = apply_filters( 'vcap_voucher_requests_retention_months', self::RETENTION_MONTHS );
		$months = is_array( $months ) ? $months : array();
		return array(
			'open'   => max( 0, (int) ( $months['open'] ?? self::RETENTION_MONTHS['open'] ) ),
			'closed' => max( 0, (int) ( $months['closed'] ?? self::RETENTION_MONTHS['closed'] ) ),
		);
	}

	/**
	 * Ids of requests in one of $states whose last change (UTC) lies before $before, oldest first.
	 *
	 * @param string[] $states States.
	 * @return int[]
	 */
	public static function expired_ids( array $states, string $before, int $limit ): array {
		global $wpdb;
		$states = array_values( array_map( 'strval', $states ) );
		if ( ! $states ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above, values passed as one array.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s WHERE p.post_type = %s AND s.meta_value IN ({$in}) AND COALESCE( u.meta_value, p.post_date_gmt ) < %s ORDER BY p.ID LIMIT %d", array_merge( array( self::STATE_META, self::UPDATED_META, self::POST_TYPE ), $states, array( $before, max( 1, $limit ) ) ) ) ) );
	}

	/**
	 * Daily maintenance: deletes expired requests (retention_months()) in batches of RETENTION_BATCH until $budget
	 * seconds are used. A deleted request takes its form-reference claim with it.
	 *
	 * @param callable|null $expired Finder ($states, $before, $limit) → ids (default: expired_ids()).
	 * @return bool false when the time budget ran out before everything was deleted.
	 */
	public function purge_expired( float $budget = 20.0, ?callable $expired = null ): bool {
		$expired = $expired ?? array( self::class, 'expired_ids' );
		$start   = microtime( true );
		$months  = self::retention_months();
		$groups  = array(
			'open'   => array( self::STATE_OPEN, self::STATE_PARTIAL ),
			'closed' => array( self::STATE_ISSUED, self::STATE_HANDED_OVER ),
		);
		$seen    = array();
		foreach ( $groups as $group => $states ) {
			if ( $months[ $group ] <= 0 ) {
				continue;
			}
			$before = gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . $months[ $group ] . ' months', time() ) );
			do {
				$ids   = array_values( array_diff( array_map( 'intval', (array) $expired( $states, $before, self::RETENTION_BATCH ) ), $seen ) );
				$found = count( $ids );
				foreach ( $ids as $id ) {
					$seen[] = $id;
					$this->delete_request( $id );
				}
				if ( $found && microtime( true ) - $start >= $budget ) {
					return false;
				}
			} while ( self::RETENTION_BATCH === $found );
		}
		if ( $months['closed'] > 0 ) {
			// R8: rejected form entries are answered as rejected for as long as closed requests are kept.
			$before = gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . $months['closed'] . ' months', time() ) );
			do {
				$removed = self::purge_rejected_claims( $before, self::RETENTION_BATCH );
				if ( $removed && microtime( true ) - $start >= $budget ) {
					return false;
				}
			} while ( self::RETENTION_BATCH === $removed );
		}
		return true;
	}

	/**
	 * Confirms a request and issues its vouchers (see confirm_locked()). R9: state changes of one request run under
	 * a lock, so a parallel confirm, hand-over or rejection by another manager waits and then sees the new state.
	 *
	 * @param array<string, mixed> $opts Options (sale, …).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function confirm( int $id, array $opts ) {
		return self::locked( $id, fn() => $this->confirm_locked( $id, $opts ) );
	}

	/**
	 * Hands a request over to a company order of an add-on (see hand_over_locked()), under the request's lock (R9).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function hand_over( int $id ) {
		return self::locked( $id, fn() => $this->hand_over_locked( $id ) );
	}

	/**
	 * Rejects (deletes) an open request (see reject_locked()), under the request's lock (R9).
	 *
	 * @return true|\WP_Error
	 */
	public function reject( int $id ) {
		return self::locked( $id, fn() => $this->reject_locked( $id ) );
	}

	/**
	 * R9: runs $action while holding the lock of request $id (MySQL GET_LOCK, 10 s); the request is read fresh inside.
	 *
	 * @return mixed|\WP_Error
	 */
	private static function locked( int $id, callable $action ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $action(); // No database (unit tests).
		}
		$name = \VCAP\Install::lock_name( 'voucher_request_' . $id );
		/**
		 * Seconds a state change of a voucher request waits for another one on the same request (R9).
		 *
		 * @since 1.2.0
		 * @param int $seconds Default 10.
		 */
		$wait = max( 0, (int) apply_filters( 'vcap_voucher_request_lock_wait', 10 ) );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		try {
			clean_post_cache( $id ); // Another request may have changed it while this one waited.
			return $action();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Readable name of a request state (admin list, detail, privacy export). */
	public static function state_label( string $state ): string {
		$labels = array(
			self::STATE_OPEN        => __( 'Open', 'voucher-captain' ),
			self::STATE_PARTIAL     => __( 'Partly issued', 'voucher-captain' ),
			self::STATE_ISSUED      => __( 'Issued', 'voucher-captain' ),
			self::STATE_HANDED_OVER => __( 'Handed over to a company order', 'voucher-captain' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/** SEC3: new requests per visitor (client address) and per contact address in RATE_WINDOW seconds. */
	public const RATE_PER_VISITOR = 5;
	public const RATE_WINDOW      = HOUR_IN_SECONDS;

	/**
	 * SEC3: whether this new request comes too often from the same visitor or contact address (form spam), so a
	 * flood cannot fill the open-request limit for everyone. Limits: filter `vcap_rate_limit_request`. Background
	 * calls without a visitor (WP-Cron, WP-CLI) are counted by contact address only.
	 *
	 * @param array<string, mixed> $request Normalised request.
	 */
	private static function flooded( array $request ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! function_exists( 'wp_salt' ) ) {
			return false; // No database (unit tests).
		}
		$email = strtolower( trim( (string) ( $request['contact']['email'] ?? '' ) ) );
		if ( '' !== $email && \VCAP\Plugin::rate_limited( 'request', self::RATE_PER_VISITOR, 0, self::RATE_WINDOW, true, 'contact:' . hash( 'sha256', $email ) ) ) {
			return true;
		}
		$background = ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI );
		return ! $background && \VCAP\Plugin::rate_limited( 'request', self::RATE_PER_VISITOR, 0, self::RATE_WINDOW );
	}

	/** R8: request stored for a form reference (by its hash on the request), 0 when there is none. */
	private static function id_by_reference( string $reference ): int {
		$ids = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => self::REF_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only after an interrupted submit.
				'meta_value'  => md5( $reference ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * R8: claims of rejected requests older than $before (UTC) are deleted, at most $limit per call.
	 *
	 * @return int Claims removed.
	 */
	public static function purge_rejected_claims( string $before, int $limit = 200 ): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return 0; // No database (unit tests).
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- option rows by prefix and value.
		$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s AND option_value < %s LIMIT %d", $wpdb->esc_like( self::CLAIM_PREFIX ) . '%', $wpdb->esc_like( self::REJECTED_CLAIM ) . '%', self::REJECTED_CLAIM . $before, max( 1, $limit ) ) );
		foreach ( $names as $name ) {
			delete_option( (string) $name );
		}
		return count( $names );
	}

	/** Deletes a request with its data and its form-reference claim (retention). */
	private function delete_request( int $id ): void {
		$request = get_post_meta( $id, self::META, true );
		wp_delete_post( $id, true );
		if ( is_array( $request ) && '' !== (string) ( $request['reference'] ?? '' ) ) {
			delete_option( self::CLAIM_PREFIX . md5( (string) $request['reference'] ) );
		}
		/**
		 * A voucher request was deleted by the retention rule (D10). No personal data in the arguments.
		 *
		 * @since 1.2.0
		 * @param int $request_id Request.
		 */
		do_action( 'vcap_voucher_request_expired', $id );
	}

	/**
	 * Issues every voucher of the request that is still missing. Call only after the confirmation (confirm()).
	 *
	 * @param array<string, mixed> $request Request.
	 * @param array<string, mixed> $opts    Options.
	 * @return array{state:string, vouchers:int[], errors:string[]}
	 */
	private function issue_all( array $request, int $id, array $opts ): array {
		$vouchers = array();
		$errors   = array();
		$count    = max( 1, min( self::MAX_VOUCHERS, (int) ( $request['quantity'] ?? 1 ) ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$voucher = ( $this->issue )( self::issue_args( $request, $id, $i, $opts ) );
			if ( $voucher instanceof \VCAP\Voucher ) {
				$vouchers[] = (int) $voucher->id;
			} elseif ( is_wp_error( $voucher ) && 'vcap_reference_used' === $voucher->get_error_code() && (int) ( ( (array) $voucher->get_error_data() )['voucher_id'] ?? 0 ) > 0 ) {
				$vouchers[] = (int) ( (array) $voucher->get_error_data() )['voucher_id']; // Issued by an earlier confirmation.
			} else {
				/* translators: 1: number of the voucher in the request, 2: reason */
				$errors[] = sprintf( __( 'Voucher %1$d: %2$s', 'voucher-captain' ), $i + 1, is_wp_error( $voucher ) ? $voucher->get_error_message() : __( 'Unknown error', 'voucher-captain' ) );
			}
		}
		return array(
			'state'    => $errors ? self::STATE_PARTIAL : self::STATE_ISSUED,
			'vouchers' => $vouchers,
			'errors'   => $errors,
		);
	}

	/**
	 * Rejects a request: it is deleted with its data (the form reference stays claimed, so the same entry is not
	 * stored again). Needs IssueVoucher::can().
	 *
	 * @return true|\WP_Error
	 */
	private function reject_locked( int $id ) {
		if ( ! IssueVoucher::can() ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'You are not allowed to do this.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		$request = self::get( $id );
		if ( ! $request || self::STATE_OPEN !== $request['state'] ) {
			return new \WP_Error( 'vcap_invalid_args', __( 'Only open voucher requests can be rejected.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		wp_delete_post( $id, true );
		if ( '' !== (string) $request['reference'] ) {
			// R8: the form entry stays answered as rejected (no new request from a repeat) until the retention removes it.
			update_option( self::CLAIM_PREFIX . md5( (string) $request['reference'] ), self::REJECTED_CLAIM . gmdate( 'Y-m-d H:i:s' ), false );
		}
		/**
		 * A manager rejected (deleted) a voucher request.
		 *
		 * @since 1.2.0
		 * @param int $request_id Request.
		 * @param int $user_id    Acting user.
		 */
		do_action( 'vcap_voucher_request_rejected', $id, get_current_user_id() );
		return true;
	}

	/**
	 * R5: one page of requests, newest first, optionally only the given states.
	 *
	 * @param string[] $states States to list (empty: all).
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public static function page( array $states = array(), int $page = 1, int $per_page = 50 ): array {
		$per_page = max( 1, min( 200, $per_page ) );
		$args     = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $page ),
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		$states = array_values( array_filter( array_map( 'sanitize_key', $states ) ) );
		if ( $states ) {
			$args['meta_query'] = array( array( 'key' => self::STATE_META, 'value' => $states, 'compare' => 'IN' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small admin list of a private post type.
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( (array) $query->posts as $id ) {
			$request = self::get( (int) $id );
			if ( $request ) {
				$items[] = $request;
			}
		}
		return array( 'items' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
	}

	/**
	 * Stored requests, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 100 ): array {
		$out = array();
		$ids = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'numberposts' => max( 1, min( 200, $limit ) ),
				'orderby'     => 'ID',
				'order'       => 'DESC',
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$request = self::get( (int) $id );
			if ( $request ) {
				$out[] = $request;
			}
		}
		return $out;
	}
}
