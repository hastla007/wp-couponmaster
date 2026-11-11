<?php
/**
 * Plugin Name: Voucher Captain – Example Connector
 * Description: Reference implementation for connector plugins: redeems a Voucher Captain voucher to unlock a
 *              "premium service" for the logged-in user, safely retryable after errors and timeouts.
 * Version:     1.0.0
 * Requires Plugins: woocommerce, voucher-captain
 * License:     GPLv2 or later
 *
 * This file is documentation that runs. Copy it, rename the prefix and replace grant()/revoke() with your
 * own logic (course access, membership, ticket …). See CONNECTORS.md for the rules it follows.
 */

defined( 'ABSPATH' ) || exit;

final class VCAP_Example_Connector {
	const CHANNEL   = 'example';            // Own redemption channel ("Redeemable via" on the voucher product).
	const TARGET    = 'example:premium';    // What is unlocked; checked by vcap_redemption_allowed.
	const PENDING   = 'vcap_example_pending'; // user meta: redemption key => attempts (survives timeouts).
	const ACCESS    = 'vcap_example_access';  // user meta: redemption key => voucher id (the "grant").
	const RETRY     = 'vcap_example_retry';   // Action Scheduler hook.
	const MAX_TRIES = 5;

	public static function boot(): void {
		if ( ! function_exists( 'vcap' ) ) {
			return;
		}
		$self = new self();
		add_filter( 'vcap_voucher_types', array( $self, 'types' ) );
		add_filter( 'vcap_redemption_channels', array( $self, 'channels' ) );
		add_filter( 'vcap_redemption_channel_for_target', array( $self, 'channel_for_target' ), 10, 2 );
		add_filter( 'vcap_redemption_allowed', array( $self, 'allowed' ), 10, 4 );
		add_action( self::RETRY, array( $self, 'retry' ), 10, 2 );
		add_action( 'vcap_voucher_event', array( $self, 'on_voucher_event' ), 10, 2 );
		add_shortcode( 'vcap_example_unlock', array( $self, 'shortcode' ) );
	}

	// ---------------------------------------------------------------- extension points.

	public function types( array $types ): array {
		return $types + array( 'premium' => __( 'Premium service voucher', 'vcap-example' ) );
	}

	public function channels( array $channels ): array {
		return $channels + array( self::CHANNEL => __( 'Example connector (premium service)', 'vcap-example' ) );
	}

	public function channel_for_target( string $channel, string $target ): string {
		return 0 === strpos( $target, 'example:' ) ? self::CHANNEL : $channel;
	}

	/** Only "premium" vouchers unlock the premium service. */
	public function allowed( $allowed, $voucher, string $target, int $user_id ) {
		if ( 0 === strpos( $target, 'example:' ) && 'premium' !== $voucher->type ) {
			return new WP_Error( 'vcap_not_allowed', __( 'This voucher cannot unlock the premium service.', 'vcap-example' ), array( 'status' => 403 ) );
		}
		return $allowed;
	}

	// ---------------------------------------------------------------- redemption flow.

	/**
	 * Stable key per user and code: a double click, a reload or a retry after a timeout reuses it, so the voucher
	 * is never reserved twice and the user is never granted twice.
	 */
	public static function key( int $user_id, string $code ): string {
		return 'example:' . $user_id . ':' . substr( hash( 'sha256', strtolower( trim( $code ) ) ), 0, 24 );
	}

	/**
	 * @return string 'granted' | 'retrying' | error message
	 */
	public function redeem( int $user_id, string $code ): string {
		$service = vcap()->redemptions;
		$key     = self::key( $user_id, $code );

		// 1. Remember the key BEFORE calling the API, so a crash or timeout can be resumed.
		$pending         = (array) get_user_meta( $user_id, self::PENDING, true );
		$pending[ $key ] = (int) ( $pending[ $key ] ?? 0 );
		update_user_meta( $user_id, self::PENDING, $pending );

		// 2. Reserve (idempotent for the same key).
		$reserved = $service->reserve( $code, array( 'key' => $key, 'target' => self::TARGET, 'user_id' => $user_id ) );
		if ( is_wp_error( $reserved ) ) {
			if ( 'vcap_temporary_failure' === $reserved->get_error_code() ) {
				$this->schedule( $user_id, $key );
				return 'retrying';
			}
			if ( 'vcap_already_redeemed' === $reserved->get_error_code() && $this->has_access( $user_id, $key ) ) {
				$this->forget( $user_id, $key );
				return 'granted'; // Completed earlier (e.g. by a retry job).
			}
			$this->forget( $user_id, $key );
			return $reserved->get_error_message();
		}
		return $this->complete( $user_id, $key, (int) $reserved['voucher_id'] );
	}

	/** 3. Deliver the service, 4. commit. Each step is safe to repeat. */
	private function complete( int $user_id, string $key, int $voucher_id ): string {
		try {
			$this->grant( $user_id, $key, $voucher_id );
		} catch ( Throwable $e ) {
			// The reservation stays; retry later with the same key. It expires (and is released) after the
			// configured lifetime if every retry fails.
			$this->schedule( $user_id, $key );
			return 'retrying';
		}
		$committed = vcap()->redemptions->commit( $key, array( 'user_id' => $user_id ) );
		if ( is_wp_error( $committed ) ) {
			switch ( $committed->get_error_code() ) {
				case 'vcap_temporary_failure':
					$this->schedule( $user_id, $key );
					return 'retrying';
				case 'vcap_cancelled':            // Voided while reserved (purchase refunded).
				case 'vcap_reservation_missing': // Reservation expired before we could commit.
					$this->revoke( $user_id, $key );
					vcap()->redemptions->release( $key, array( 'user_id' => $user_id ) );
					$this->forget( $user_id, $key );
					return $committed->get_error_message();
			}
		}
		$this->forget( $user_id, $key );
		return 'granted';
	}

	/** Retry job (Action Scheduler): continue wherever the flow stopped. */
	public function retry( int $user_id, string $key ): void {
		$pending = (array) get_user_meta( $user_id, self::PENDING, true );
		if ( ! isset( $pending[ $key ] ) ) {
			return;
		}
		$redemption = vcap()->redemptions->get( $key );
		if ( is_wp_error( $redemption ) ) {
			if ( 'vcap_temporary_failure' === $redemption->get_error_code() ) {
				$this->schedule( $user_id, $key );
			} else {
				$this->forget( $user_id, $key ); // Never reserved: the user simply tries again.
			}
			return;
		}
		switch ( $redemption['state'] ) {
			case 'reserved':
				if ( $pending[ $key ] >= self::MAX_TRIES ) {
					$this->revoke( $user_id, $key );
					vcap()->redemptions->release( $key, array( 'user_id' => $user_id ) ); // Give the voucher back.
					$this->forget( $user_id, $key );
					return;
				}
				$this->complete( $user_id, $key, (int) $redemption['voucher_id'] );
				return;
			case 'committed':
				$this->grant( $user_id, $key, (int) $redemption['voucher_id'] ); // Idempotent: make sure access exists.
				$this->forget( $user_id, $key );
				return;
			default: // released / expired
				$this->revoke( $user_id, $key );
				$this->forget( $user_id, $key );
		}
	}

	private function schedule( int $user_id, string $key ): void {
		$pending         = (array) get_user_meta( $user_id, self::PENDING, true );
		$pending[ $key ] = (int) ( $pending[ $key ] ?? 0 ) + 1;
		update_user_meta( $user_id, self::PENDING, $pending );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 60 * $pending[ $key ], self::RETRY, array( $user_id, $key ), 'vcap-example' );
		}
	}

	private function forget( int $user_id, string $key ): void {
		$pending = (array) get_user_meta( $user_id, self::PENDING, true );
		unset( $pending[ $key ] );
		update_user_meta( $user_id, self::PENDING, $pending );
	}

	// ---------------------------------------------------------------- your service (replace these).

	/** Idempotent grant: the redemption key is stored with the access, so a repeat changes nothing. */
	private function grant( int $user_id, string $key, int $voucher_id ): void {
		if ( apply_filters( 'vcap_example_grant_fails', false, $user_id, $key ) ) {
			throw new RuntimeException( 'Simulated outage of the premium service.' );
		}
		$access = (array) get_user_meta( $user_id, self::ACCESS, true );
		if ( ! isset( $access[ $key ] ) ) {
			$access[ $key ] = $voucher_id;
			update_user_meta( $user_id, self::ACCESS, $access );
		}
	}

	private function revoke( int $user_id, string $key ): void {
		$access = (array) get_user_meta( $user_id, self::ACCESS, true );
		unset( $access[ $key ] );
		update_user_meta( $user_id, self::ACCESS, $access );
	}

	public function has_access( int $user_id, string $key = '' ): bool {
		$access = array_filter( (array) get_user_meta( $user_id, self::ACCESS, true ) );
		return '' === $key ? (bool) $access : isset( $access[ $key ] );
	}

	/**
	 * Policy after a completed redemption: when the voucher is voided later (its purchase order was refunded),
	 * this example takes the access back. Choose and document your own rule (see CONNECTORS.md).
	 */
	public function on_voucher_event( $voucher_id, $event ): void {
		if ( 'voided' !== $event ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", self::ACCESS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$access = maybe_unserialize( $row->meta_value );
			$left   = array_filter( (array) $access, static fn( $id ) => (int) $id !== (int) $voucher_id );
			if ( count( $left ) !== count( (array) $access ) ) {
				update_user_meta( (int) $row->user_id, self::ACCESS, $left );
			}
		}
	}

	// ---------------------------------------------------------------- UI.

	public function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to redeem your voucher.', 'vcap-example' ) . '</p>';
		}
		$message = '';
		if ( isset( $_POST['vcap_example_code'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'vcap_example' ) ) {
			$result  = $this->redeem( get_current_user_id(), sanitize_text_field( wp_unslash( $_POST['vcap_example_code'] ) ) );
			$message = 'granted' === $result
				? __( 'Premium service unlocked.', 'vcap-example' )
				: ( 'retrying' === $result ? __( 'We are unlocking your service. This can take a few minutes.', 'vcap-example' ) : $result );
		}
		ob_start();
		if ( $message ) {
			echo '<p role="status">' . esc_html( $message ) . '</p>';
		}
		?>
		<form method="post">
			<?php wp_nonce_field( 'vcap_example' ); ?>
			<label for="vcap-example-code"><?php esc_html_e( 'Voucher code', 'vcap-example' ); ?></label>
			<input id="vcap-example-code" name="vcap_example_code" required autocomplete="off" />
			<button type="submit"><?php esc_html_e( 'Unlock', 'vcap-example' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}

add_action( 'plugins_loaded', array( 'VCAP_Example_Connector', 'boot' ), 20 );
