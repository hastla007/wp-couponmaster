<?php
/**
 * REST routes for external voucher redemptions.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/vcap/v1/redemptions.
 *
 * Authentication uses WordPress itself: logged-in browsers send the REST nonce (X-WP-Nonce), servers use
 * Application Passwords. The acting user is always the authenticated user; a user id in the request is ignored.
 *
 * @since 1.1.0
 */
final class RestController {
	public const NAMESPACE = 'vcap/v1';

	/**
	 * Construct the controller.
	 *
	 * @param RedemptionService $service Redemption service.
	 */
	public function __construct( private RedemptionService $service ) {}

	/**
	 * Hook route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		$key = '(?P<key>[A-Za-z0-9][A-Za-z0-9._:\-]{7,63})';
		register_rest_route(
			self::NAMESPACE,
			'/redemptions/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'can_redeem' ),
				'args'                => array(
					'code'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'pin'      => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'PIN of a card protected by one (M12-99); also accepted as code#PIN.',
					),
					'target'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'amount'   => array( 'type' => 'number' ),
					'currency' => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z]{3}$',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/redemptions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reserve' ),
				'permission_callback' => array( $this, 'can_redeem' ),
				'args'                => array(
					'code'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'pin'      => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'PIN of a card protected by one (M12-99); also accepted as code#PIN.',
					),
					'key'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'target'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'amount'   => array( 'type' => 'number' ),
					'currency' => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z]{3}$',
					),
					'meta'     => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/redemptions/' . $key,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
		foreach ( array( 'commit', 'release' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/redemptions/' . $key . '/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'can_access' ),
				)
			);
		}
		// M12-46: give back (part of) a completed redemption – same access rules as commit.
		register_rest_route(
			self::NAMESPACE,
			'/redemptions/' . $key . '/refund',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refund' ),
				'permission_callback' => array( $this, 'can_access' ),
				'args'                => array(
					'amount'     => array(
						'required' => true,
						'type'     => 'number',
					),
					'refund_key' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/** M12-46: optional capability for point-of-sale accounts ("Voucher POS" role): any non-internal target, no admin rights. */
	public const POS_CAP = 'vcap_redeem_api';

	/**
	 * Whether the current user manages vouchers (may act on every redemption).
	 *
	 * @return bool
	 */
	private function is_manager(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install / WooCommerce.
	}

	/**
	 * Any authenticated user may check and reserve; integrations can narrow this.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_redeem( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'Please log in to redeem a voucher.', 'voucher-captain' ), array( 'status' => 401 ) );
		}
		/**
		 * Filters whether the current user may redeem vouchers through the REST API.
		 *
		 * @since 1.1.0
		 * @param bool             $allowed Default true for logged-in users.
		 * @param \WP_REST_Request $request Request.
		 */
		return (bool) apply_filters( 'vcap_rest_can_redeem', true, $request );
	}

	/**
	 * Keys used by built-in integrations (Pass Captain checkout "wpcap-…", ticket vouchers "passcaptain:…").
	 * Connectors add their own prefixes with the filter vcap_internal_redemption_key_prefixes.
	 * "learnboating:grant:" stays in the default list for the project connector 1.0.0, which does not
	 * register it; from 1.1.0 on the connector registers its prefixes itself (CONNECTORS.md §8).
	 *
	 * @param string $key Redemption key.
	 * @return bool
	 */
	public static function is_internal_key( string $key ): bool {
		/**
		 * Key prefixes reserved for built-in integrations (never created or driven over REST).
		 *
		 * @since 1.2.0
		 * @param string[] $prefixes Prefixes.
		 */
		$prefixes = (array) apply_filters( 'vcap_internal_redemption_key_prefixes', array( 'wpcap-', 'passcaptain:', 'learnboating:grant:' ) );
		return self::has_prefix( $key, $prefixes );
	}

	/**
	 * Targets of built-in integrations (Pass Captain checkout and ticket vouchers "passcaptain:…"): created and
	 * completed by those integrations only, never over REST – otherwise a voucher could be booked for a ticket or
	 * a ticket order without anything being delivered.
	 *
	 * @param string $target Redemption target.
	 * @return bool
	 */
	public static function is_internal_target( string $target ): bool {
		/**
		 * Target prefixes reserved for built-in integrations (never used over REST).
		 *
		 * @since 1.2.0
		 * @param string[] $prefixes Prefixes.
		 */
		$prefixes = (array) apply_filters( 'vcap_internal_redemption_target_prefixes', array( 'passcaptain:' ) );
		return self::has_prefix( $target, $prefixes );
	}

	/**
	 * Whether the current user may use a target over REST: shop managers any target except internal ones,
	 * customers only targets of connectors that registered their prefix for customers
	 * (filter vcap_rest_redemption_target_prefixes, e.g. "learnboating:"). Without a registered prefix,
	 * customers can check codes but not redeem them over REST.
	 *
	 * @param string           $target  Redemption target.
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private function target_allowed( string $target, \WP_REST_Request $request ): bool|\WP_Error {
		$target = RedemptionService::clean_target( $target ); // As stored: "pass captain:" must not slip through.
		if ( self::is_internal_target( $target ) ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'This redemption target is managed by an integration.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		if ( $this->is_manager() || current_user_can( self::POS_CAP ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Optional, granted by the shop (API.md).
			return true;
		}
		/**
		 * Target prefixes customers may redeem for over REST (connectors that let customers redeem in the browser
		 * add theirs, e.g. "learnboating:"). Default none.
		 *
		 * @since 1.2.0
		 * @param string[]         $prefixes Prefixes.
		 * @param \WP_REST_Request $request  Request.
		 */
		$prefixes = (array) apply_filters( 'vcap_rest_redemption_target_prefixes', array(), $request );
		if ( ! self::has_prefix( $target, $prefixes ) ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'Vouchers cannot be redeemed for this target here.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Whether the value starts with one of the prefixes.
	 *
	 * @param string   $value    Key or target.
	 * @param string[] $prefixes Prefixes.
	 * @return bool
	 */
	private static function has_prefix( string $value, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( '' !== (string) $prefix && 0 === stripos( $value, (string) $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Only the user who reserved (or a voucher manager) may read, commit or release a redemption.
	 *
	 * S9: for customers a missing redemption, an internal one and one of another user all get the same answer
	 * (404, as for an unknown key), so the API never tells whether such a key exists. commit, release and refund
	 * check the same rules again in the service under the row lock (customer_args()).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_access( \WP_REST_Request $request ): bool|\WP_Error {
		$allowed = $this->can_redeem( $request );
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( $this->is_manager() ) {
			return true;
		}
		$key = (string) $request['key'];
		if ( self::is_internal_key( $key ) ) {
			return self::not_found(); // Never looked up for customers.
		}
		$data = $this->service->get( $key );
		return $this->customer_may_access( is_wp_error( $data ) ? null : $data, $request );
	}

	/**
	 * S9: the access rules for customers, on a redemption as read now (null: none exists).
	 *
	 * @param array<string, mixed>|null $data    Redemption data.
	 * @param \WP_REST_Request          $request Request.
	 * @return true|\WP_Error
	 */
	private function customer_may_access( ?array $data, \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! $data || self::is_internal_key( (string) ( $data['redemption_key'] ?? '' ) ) || self::is_internal_target( (string) ( $data['target'] ?? '' ) ) ) {
			// Redemptions of built-in integrations are driven by those integrations only (otherwise a customer
			// could release the reservation behind their own Pass Captain order and keep the voucher value).
			return self::not_found();
		}
		$own = get_current_user_id() === (int) $data['user_id'];
		/**
		 * Filters whether the current user may read/commit/release a redemption.
		 *
		 * @since 1.1.0
		 * @param bool             $own     Whether the redemption belongs to the current user.
		 * @param array            $data    Redemption data.
		 * @param \WP_REST_Request $request Request.
		 */
		if ( ! apply_filters( 'vcap_rest_can_access_redemption', $own, $data, $request ) ) {
			return self::not_found(); // The same answer as for an unknown key.
		}
		if ( 'GET' !== $request->get_method() ) {
			// Commit/release only for targets the customer may use over REST: a connector that reserved on the
			// customer's behalf completes its own redemptions after delivering.
			$target = $this->target_allowed( (string) $data['target'], $request );
			if ( true !== $target ) {
				return $target;
			}
		}
		return true;
	}

	/**
	 * S9: arguments for commit(), release() and refund() of a customer: the service checks the access rules (and
	 * the owner) again on the locked row. Empty for voucher managers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function customer_args( \WP_REST_Request $request ): array {
		if ( $this->is_manager() ) {
			return array();
		}
		$args = array( 'access' => fn( array $data ) => $this->customer_may_access( $data, $request ) );
		$data = $this->service->get( (string) $request['key'] );
		if ( ! is_wp_error( $data ) && get_current_user_id() === (int) $data['user_id'] ) {
			// The owner, as stored (a filter may let others in; the access rule above covers them).
			$args['user_id'] = get_current_user_id();
		}
		return $args;
	}

	/** The answer for a redemption a customer may not see: the same as for an unknown key. */
	private static function not_found(): \WP_Error {
		return RedemptionService::error( 'vcap_reservation_missing', __( 'No redemption exists for this key.', 'voucher-captain' ), 404 );
	}

	/**
	 * The code of a request, with the "pin" parameter appended in the code#PIN convention (M12-99).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function code_with_pin( \WP_REST_Request $request ): string {
		$pin = is_string( $request['pin'] ) ? trim( $request['pin'] ) : '';
		return (string) $request['code'] . ( '' !== $pin ? \VCAP\CodeLookup::PIN_SEPARATOR . $pin : '' );
	}

	/**
	 * POST /redemptions/check
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( '' !== (string) $request['target'] ) {
			$target = $this->target_allowed( (string) $request['target'], $request );
			if ( true !== $target ) {
				return $target;
			}
		}
		$args = array( 'target' => (string) $request['target'] );
		if ( null !== $request['amount'] ) {
			$args['amount'] = (float) $request['amount'];
		}
		if ( is_string( $request['currency'] ) && '' !== $request['currency'] ) {
			$args['currency'] = $request['currency']; // M12-33: the caller's currency.
		}
		return rest_ensure_response( $this->service->check( self::code_with_pin( $request ), $args ) );
	}

	/**
	 * POST /redemptions
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reserve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( self::is_internal_key( (string) $request['key'] ) ) {
			// Keys of built-in integrations (Pass Captain checkout and ticket vouchers) are never created over REST,
			// so nobody can claim them in advance and block or hijack those redemptions.
			return new \WP_Error( 'vcap_invalid_request', __( 'This redemption key is reserved.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$target = $this->target_allowed( (string) $request['target'], $request );
		if ( true !== $target ) {
			return $target;
		}
		if ( ! self::key_matches_target( (string) $request['key'], (string) $request['target'] ) ) {
			// Keys are global: "learnboating:…" with a "test:…" target would occupy a connector's key in advance.
			return new \WP_Error( 'vcap_invalid_request', __( 'A redemption key with a prefix ("system:…") must use the prefix of its target.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$result = $this->service->reserve(
			self::code_with_pin( $request ),
			array(
				'key'      => (string) $request['key'],
				'target'   => (string) $request['target'],
				'amount'   => null !== $request['amount'] ? (float) $request['amount'] : 0.0,
				'meta'     => is_array( $request['meta'] ) ? $request['meta'] : array(),
				'currency' => is_string( $request['currency'] ) ? $request['currency'] : '',
				'user_id'  => get_current_user_id(), // Never taken from the request body.
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		// 201 only for a new reservation; a repeated request answers 200 with the stored state ("replayed").
		$response->set_status( empty( $result['replayed'] ) ? 201 : 200 );
		return $response;
	}

	/**
	 * A key with a namespace ("system:…", the part before the first colon) must belong to the target's namespace,
	 * so a REST caller cannot claim another connector's keys; keys without a colon (UUIDs) are free.
	 *
	 * @param string $key    Redemption key.
	 * @param string $target Redemption target.
	 * @return bool
	 */
	public static function key_matches_target( string $key, string $target ): bool {
		$colon = strpos( $key, ':' );
		if ( false === $colon ) {
			return true;
		}
		$target = RedemptionService::clean_target( $target );
		return 0 === strncasecmp( $target, substr( $key, 0, $colon + 1 ), $colon + 1 );
	}

	/**
	 * GET /redemptions/{key}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = $this->service->get( (string) $request['key'] );
		if ( ! is_wp_error( $data ) && ! $this->is_manager() ) {
			$allowed = $this->customer_may_access( $data, $request ); // S9: on the row as read here.
			if ( true !== $allowed ) {
				return $allowed;
			}
		}
		return rest_ensure_response( $data );
	}

	/**
	 * POST /redemptions/{key}/commit
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function commit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->is_manager() && self::is_internal_key( (string) $request['key'] ) ) {
			return self::not_found();
		}
		$args = $this->customer_args( $request );
		return rest_ensure_response( $this->with_counter( $request, fn() => $this->service->commit( (string) $request['key'], $args ) ) );
	}

	/**
	 * POST /redemptions/{key}/refund (M12-46): amount and refund_key (idempotency key of this refund).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refund( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$key = (string) $request['key'];
		if ( ! $this->is_manager() && self::is_internal_key( $key ) ) {
			return self::not_found();
		}
		if ( ! $this->is_manager() && ! current_user_can( self::POS_CAP ) && ! $this->customer_may_refund( $key ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Optional, granted by the shop (API.md).
			// SEC4c: the same answer for every key, so it tells nothing about the redemption.
			return RedemptionService::error( 'vcap_refund_not_allowed', __( 'Only the shop can give back a completed redemption. Please contact the shop.', 'voucher-captain' ), 403 );
		}
		$args = $this->customer_args( $request );
		return rest_ensure_response( $this->with_counter( $request, fn() => $this->service->refund( $key, (float) $request['amount'], (string) $request['refund_key'], $args ) ) );
	}

	/**
	 * SEC4c (owner decision 16.11.2026): a customer account gives back a completed redemption only where a connector
	 * allows it. By default only voucher managers, "Voucher POS" accounts (vcap_redeem_api) and server code
	 * (`vcap()->redemptions->refund()`) refund – the service may already have been provided; a customer cancels before
	 * the commit (release) instead.
	 *
	 * @param string $key Redemption key.
	 * @return bool
	 */
	private function customer_may_refund( string $key ): bool {
		$data = $this->service->get( $key );
		/**
		 * Filters whether a customer account may refund a completed redemption through the REST API (default false).
		 * The access rules of commit (own redemption only) still apply afterwards.
		 *
		 * @since 1.2.0
		 * @param bool       $allowed    Default false.
		 * @param array|null $redemption Redemption data (target, channel, amount …), null when unknown.
		 * @param int        $user_id    Current user.
		 */
		return true === apply_filters( 'vcap_customer_may_refund_redemption', false, is_wp_error( $data ) ? null : $data, get_current_user_id() );
	}

	/**
	 * M12-46: runs a booking with the till/branch and receipt number of the reservation's metadata
	 * (meta.till, meta.receipt), so a POS booking carries them like a scanner booking (Counter::with()).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param callable         $work    Booking.
	 * @return mixed What $work returns.
	 */
	private function with_counter( \WP_REST_Request $request, callable $work ): mixed {
		$data = $this->service->get( (string) $request['key'] );
		$meta = ! is_wp_error( $data ) && is_array( $data['meta'] ?? null ) ? $data['meta'] : array();
		$till = is_scalar( $meta['till'] ?? null ) ? (string) $meta['till'] : '';
		$rcpt = is_scalar( $meta['receipt'] ?? null ) ? (string) $meta['receipt'] : '';
		if ( '' === $till && '' === $rcpt ) {
			return $work();
		}
		return \VCAP\Counter::with( $till, $rcpt, $work );
	}

	/**
	 * POST /redemptions/{key}/release
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function release( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->is_manager() && self::is_internal_key( (string) $request['key'] ) ) {
			return self::not_found();
		}
		return rest_ensure_response( $this->service->release( (string) $request['key'], $this->customer_args( $request ) ) );
	}
}
