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
					'code'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'target' => array(
						'type'    => 'string',
						'default' => '',
					),
					'amount' => array( 'type' => 'number' ),
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
					'code'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'key'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'target' => array(
						'required' => true,
						'type'     => 'string',
					),
					'amount' => array( 'type' => 'number' ),
					'meta'   => array(
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
	}

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
	public function can_redeem( \WP_REST_Request $request ) {
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
		$prefixes = (array) apply_filters( 'vcap_internal_redemption_key_prefixes', array( 'wpcap-', 'passcaptain:' ) );
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
	private function target_allowed( string $target, \WP_REST_Request $request ) {
		$target = RedemptionService::clean_target( $target ); // As stored: "pass captain:" must not slip through.
		if ( self::is_internal_target( $target ) ) {
			return new \WP_Error( 'vcap_not_allowed', __( 'This redemption target is managed by an integration.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		if ( $this->is_manager() ) {
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
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_access( \WP_REST_Request $request ) {
		$allowed = $this->can_redeem( $request );
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( $this->is_manager() ) {
			return true;
		}
		$data = $this->service->get( (string) $request['key'] );
		if ( is_wp_error( $data ) ) {
			return true; // Let the callback answer 404 consistently.
		}
		if ( self::is_internal_key( (string) $request['key'] ) || self::is_internal_target( (string) $data['target'] ) ) {
			// Redemptions of built-in integrations are driven by those integrations only (otherwise a customer
			// could release the reservation behind their own Pass Captain order and keep the voucher value).
			return new \WP_Error( 'vcap_not_allowed', __( 'This redemption is managed by an integration.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		if ( 'GET' !== $request->get_method() ) {
			// Commit/release only for targets the customer may use over REST: a connector that reserved on the
			// customer's behalf completes its own redemptions after delivering.
			$target = $this->target_allowed( (string) $data['target'], $request );
			if ( true !== $target ) {
				return $target;
			}
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
			return new \WP_Error( 'vcap_not_allowed', __( 'This redemption belongs to another user.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * POST /redemptions/check
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check( \WP_REST_Request $request ) {
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
		return rest_ensure_response( $this->service->check( (string) $request['code'], $args ) );
	}

	/**
	 * POST /redemptions
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reserve( \WP_REST_Request $request ) {
		if ( self::is_internal_key( (string) $request['key'] ) ) {
			// Keys of built-in integrations (Pass Captain checkout and ticket vouchers) are never created over REST,
			// so nobody can claim them in advance and block or hijack those redemptions.
			return new \WP_Error( 'vcap_invalid_request', __( 'This redemption key is reserved.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$target = $this->target_allowed( (string) $request['target'], $request );
		if ( true !== $target ) {
			return $target;
		}
		$result = $this->service->reserve(
			(string) $request['code'],
			array(
				'key'     => (string) $request['key'],
				'target'  => (string) $request['target'],
				'amount'  => null !== $request['amount'] ? (float) $request['amount'] : 0.0,
				'meta'    => is_array( $request['meta'] ) ? $request['meta'] : array(),
				'user_id' => get_current_user_id(), // Never taken from the request body.
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * GET /redemptions/{key}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->service->get( (string) $request['key'] ) );
	}

	/**
	 * POST /redemptions/{key}/commit
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function commit( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->service->commit( (string) $request['key'] ) );
	}

	/**
	 * POST /redemptions/{key}/release
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function release( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->service->release( (string) $request['key'] ) );
	}
}
