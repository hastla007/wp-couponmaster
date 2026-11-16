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
	public const KINDS          = array( 'company_order', 'personalisation' );
	public const MAX_VOUCHERS   = 100;
	public const STATE_OPEN     = 'open';
	public const STATE_PARTIAL  = 'partial';
	public const STATE_ISSUED   = 'issued';
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

	/**
	 * @param callable|null $issue      Issues one voucher (default: vcap()->issuer->issue_standalone()).
	 * @param callable|null $find       Request ids naming an address ($email, $limit, $offset; default: EMAIL_META).
	 * @param callable|null $count_open Number of open requests, counted up to $cap (default: a post query).
	 */
	public function __construct( ?callable $issue = null, ?callable $find = null, ?callable $count_open = null ) {
		$this->issue      = $issue ?? static fn( array $args ) => vcap()->issuer->issue_standalone( $args );
		$this->find       = $find ?? array( self::class, 'ids_for_email' );
		$this->count_open = $count_open ?? array( self::class, 'open_count' );
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
				array( 'name' => __( 'Status', 'voucher-captain' ), 'value' => (string) $request['state'] ),
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
		}
		if ( ! add_option( $claim, 0, '', false ) ) {
			$id = (int) get_option( $claim, 0 );
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
	public function confirm( int $id, array $opts ) {
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
		if ( self::STATE_ISSUED === $result['state'] ) {
			// The recipients' data now lives in the vouchers (and their retention rules): keep only the count here.
			$request = (array) get_post_meta( $id, self::META, true );
			$contact = strtolower( (string) ( $request['contact']['email'] ?? '' ) );
			foreach ( array_diff( self::emails( $request ), array( $contact ) ) as $address ) {
				delete_post_meta( $id, self::EMAIL_META, $address );
			}
			$request['recipients'] = array();
			update_post_meta( $id, self::META, $request );
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
				$errors[] = sprintf( __( 'Voucher %1$d: %2$s', 'voucher-captain' ), $i + 1, is_wp_error( $voucher ) ? $voucher->get_error_message() : 'unknown error' );
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
	public function reject( int $id ) {
		if ( ! IssueVoucher::can() ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'You are not allowed to do this.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		$request = self::get( $id );
		if ( ! $request || self::STATE_OPEN !== $request['state'] ) {
			return new \WP_Error( 'vcap_invalid_args', __( 'Only open voucher requests can be rejected.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		wp_delete_post( $id, true );
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
