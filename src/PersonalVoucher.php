<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * V6: identity check of personal vouchers (WooCommerce coupons restricted to email addresses: "buyer", "recipient",
 * "buyer or recipient", referral rewards). WooCommerce itself compares the restriction with the billing email typed
 * at checkout, which anyone who knows the address can enter. A personal voucher is therefore redeemed only by a
 * customer who is logged in with an account whose verified email matches the restriction.
 *
 * AP-S1-1 – proof of mailbox: WordPress has no verification flag, and WooCommerce changes the account email in
 * My Account without confirmation and logs new accounts (registration, checkout) in at once. The account email
 * therefore counts as verified only with a proof stored in the user meta META_VERIFIED (the lower-cased address
 * it was proven for):
 * - a password reset through the emailed link (core and WooCommerce lost-password / set-password flows: the
 *   "password_reset" action, fired by both once the reset key was checked; WooCommerce 8.9 fires only this one,
 *   core and newer WooCommerce versions also "after_password_reset", which is hooked as well);
 * - a click on the confirmation link this class sends (HMAC with the site secret over user id, address and
 *   expiry; offered next to the refusal in the cart/checkout and in the account details, rate limited);
 * - grandfathering: accounts registered before this check existed on the site (option OPTION_SINCE, stored once
 *   on the first request of this version) whose email was never changed since (META_CHANGED) are verified on
 *   first use – they redeemed with their account email before, and locking every existing customer out at once
 *   is no option. Email changes before the update cannot be seen; that residual risk is accepted.
 * A change of the email (profile_update with the old user data, WooCommerce's account details) drops the proof
 * and ends grandfathering; a proof also only counts for the address it was stored for.
 * Filter "vcap_personal_voucher_email_verified" for shops with their own verification (double opt-in plugin).
 * Staff at the counter (scanner, admin) verify the holder's identity themselves and are not restricted by this.
 */
final class PersonalVoucher {
	/** User meta: the lower-cased address whose mailbox the customer proved. */
	public const META_VERIFIED = '_vcap_verified_email';
	/** User meta: time of the last change of the account email seen by this plugin (ends grandfathering). */
	public const META_CHANGED = '_vcap_email_changed';
	/** Option: when the proof of mailbox was introduced on this site (accounts registered before are grandfathered). */
	public const OPTION_SINCE = 'vcap_email_proof_since';
	/** Default lifetime of a confirmation link in seconds (48 hours; setting "vcap_email_confirm_link_hours", SET-VC-31). */
	public const LINK_LIFETIME = 172800;
	/** Default confirmation emails per account and hour (rate limit bucket "email_confirm"; setting "vcap_email_confirm_per_hour", at least 1). */
	public const SEND_PER_HOUR = 3;

	/** SET-VC-31: lifetime of a confirmation link in seconds – the setting in hours, within 1 and 168. */
	public static function link_lifetime(): int {
		return SettingsRegistry::bounded_int( 'vcap_email_confirm_link_hours' ) * HOUR_IN_SECONDS;
	}

	/** SET-VC-31: confirmation emails per account and hour – the setting, never below 1 (filter "vcap_rate_limit_email_confirm" wins). */
	public static function send_per_hour(): int {
		return SettingsRegistry::bounded_int( 'vcap_email_confirm_per_hour' );
	}

	public static function register(): void {
		self::since();
		add_action( 'password_reset', array( self::class, 'password_reset' ), 10, 1 );
		add_action( 'after_password_reset', array( self::class, 'password_reset' ), 10, 1 );
		add_action( 'profile_update', array( self::class, 'profile_update' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( self::class, 'account_saved' ), 10, 1 );
		add_action( 'template_redirect', array( self::class, 'handle_request' ), 4 );
		add_action( 'woocommerce_edit_account_form', array( self::class, 'account_form_hint' ) );
	}

	/**
	 * When the proof of mailbox was introduced here: stored once (add_option() never overwrites) on the first
	 * request of this version, i.e. on the update or the first activation (Settings::insert_once()).
	 */
	public static function since(): int {
		static $runtime = 0;
		$since = (int) get_option( self::OPTION_SINCE, 0 );
		if ( $since <= 0 ) {
			// M12-17: never overwritten (add_option() would, with a stale "notoptions" cache).
			$since = (int) Settings::insert_once( self::OPTION_SINCE, (string) time(), true );
		}
		if ( $since <= 0 ) {
			$runtime = $runtime > 0 ? $runtime : time();
			$since   = $runtime;
		}
		return $since;
	}

	/**
	 * Stores the proof for the account's current address.
	 *
	 * @param \WP_User|int $user Account.
	 */
	public static function mark_verified( $user ): void {
		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user instanceof \WP_User || ! $user->exists() || ! is_email( (string) $user->user_email ) ) {
			return;
		}
		update_user_meta( $user->ID, self::META_VERIFIED, strtolower( trim( (string) $user->user_email ) ) );
	}

	/**
	 * Password reset through the emailed link (wp-login.php, WooCommerce's lost-password and set-password forms).
	 *
	 * @param mixed $user WP_User.
	 */
	public static function password_reset( $user ): void {
		self::mark_verified( $user );
	}

	/**
	 * A changed account email drops the proof (any way it is changed: My Account, wp-admin, REST, wp_update_user()).
	 *
	 * @param int   $user_id       User id.
	 * @param mixed $old_user_data WP_User before the change.
	 */
	public static function profile_update( $user_id, $old_user_data = null ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User || ! $old_user_data instanceof \WP_User ) {
			return;
		}
		if ( strtolower( trim( (string) $old_user_data->user_email ) ) !== strtolower( trim( (string) $user->user_email ) ) ) {
			self::email_changed( (int) $user->ID );
		}
	}

	/**
	 * WooCommerce's account details form: checked again after the save (which also ends in profile_update).
	 *
	 * @param int $user_id User id.
	 */
	public static function account_saved( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$proof = (string) get_user_meta( $user->ID, self::META_VERIFIED, true );
		if ( '' !== $proof && strtolower( trim( (string) $user->user_email ) ) !== $proof ) {
			self::email_changed( (int) $user->ID );
		}
	}

	private static function email_changed( int $user_id ): void {
		delete_user_meta( $user_id, self::META_VERIFIED );
		update_user_meta( $user_id, self::META_CHANGED, time() );
	}

	/** Whether the customer proved the mailbox of the current account email (the filter not applied). */
	public static function has_proof( \WP_User $user ): bool {
		$email = strtolower( trim( (string) $user->user_email ) );
		if ( '' === $email ) {
			return false;
		}
		$proof = (string) get_user_meta( $user->ID, self::META_VERIFIED, true );
		if ( '' !== $proof ) {
			return $proof === $email;
		}
		if ( self::grandfathered( $user ) ) {
			self::mark_verified( $user ); // On first use: later checks read the proof.
			return true;
		}
		return false;
	}

	/** Registered before the proof of mailbox existed and the email never changed since (see the class comment). */
	public static function grandfathered( \WP_User $user ): bool {
		$registered = strtotime( (string) $user->user_registered . ' UTC' );
		return $registered && $registered < self::since() && '' === (string) get_user_meta( $user->ID, self::META_CHANGED, true );
	}

	/**
	 * Lower-cased email restrictions of a coupon (empty: transferable).
	 *
	 * @return string[]
	 */
	public static function restrictions( \WC_Coupon $coupon ): array {
		return array_values( array_filter( array_map( static fn( $e ) => strtolower( trim( (string) $e ) ), (array) $coupon->get_email_restrictions() ) ) );
	}

	/**
	 * Whether an address matches one of the restrictions (WooCommerce's "*" wildcard included).
	 *
	 * @param string[] $restrictions Lower-cased restrictions.
	 */
	public static function matches( string $email, array $restrictions ): bool {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		foreach ( $restrictions as $restriction ) {
			$pattern = '/^' . str_replace( '\*', '.*?', preg_quote( (string) $restriction, '/' ) ) . '$/';
			if ( preg_match( $pattern, $email ) ) {
				return true;
			}
		}
		return false;
	}

	/** The verified email of an account ('' for none): its user_email with a proof of mailbox, see the class comment. */
	public static function verified_email( ?\WP_User $user ): string {
		if ( ! $user || ! $user->exists() || ! is_email( (string) $user->user_email ) ) {
			return '';
		}
		/**
		 * Whether the account email of a customer counts as verified for personal vouchers (V6). Default: the
		 * customer proved the mailbox (password reset through the emailed link, or the confirmation link) or the
		 * account is grandfathered (registered before the proof of mailbox was introduced, email unchanged since; AP-S1-1).
		 *
		 * @since 1.2.0
		 * @param bool     $verified Whether the address is verified.
		 * @param \WP_User $user     Customer account.
		 */
		return apply_filters( 'vcap_personal_voucher_email_verified', self::has_proof( $user ), $user ) ? (string) $user->user_email : '';
	}

	/**
	 * Whether the account email matches a restriction but is not verified yet (where a confirmation link helps).
	 *
	 * @param string[] $restrictions Lower-cased restrictions.
	 */
	public static function needs_confirmation( array $restrictions, ?\WP_User $user ): bool {
		return $user && $user->exists() && $restrictions && '' === self::verified_email( $user ) && self::matches( (string) $user->user_email, $restrictions );
	}

	/**
	 * Whether the account may redeem a voucher with these restrictions.
	 *
	 * @param string[] $restrictions Lower-cased restrictions (empty: anyone).
	 */
	public static function allowed_for_user( array $restrictions, ?\WP_User $user ): bool {
		return ! $restrictions || self::matches( self::verified_email( $user ), $restrictions );
	}

	// ----------------------------------------------------------------- Confirmation link.

	private static function sign( int $user_id, string $email, int $exp ): string {
		return hash_hmac( 'sha256', 'confirm-email|' . $user_id . '|' . strtolower( trim( $email ) ) . '|' . $exp, vcap()->settings->secret() );
	}

	/** Signed link that proves the mailbox of the account's current address (bound to user id, address, expiry). */
	public static function confirmation_url( \WP_User $user ): string {
		$exp  = time() + self::link_lifetime();
		$base = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		return add_query_arg(
			array(
				'vcap_confirm_email' => $user->ID,
				'exp'                => $exp,
				'sig'                => self::sign( (int) $user->ID, (string) $user->user_email, $exp ),
			),
			'' !== $base ? $base : home_url( '/' )
		);
	}

	/**
	 * Checks a confirmation link and stores the proof. The address signed is the account's current one: a link sent
	 * before the email changed no longer matches.
	 */
	public static function confirm( int $user_id, int $exp, string $signature ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User || $exp < time() || '' === $signature || ! is_email( (string) $user->user_email ) ) {
			return false;
		}
		if ( ! hash_equals( self::sign( $user_id, (string) $user->user_email, $exp ), $signature ) ) {
			return false;
		}
		self::mark_verified( $user );
		return true;
	}

	/** SET-VC-31: last sentence of the confirmation email with the link lifetime of the setting. */
	public static function validity_sentence(): string {
		$hours = (int) ( self::link_lifetime() / HOUR_IN_SECONDS );
		if ( 48 === $hours ) {
			return __( 'The link is valid for 48 hours. If you did not ask for it, you can ignore this email.', 'voucher-captain' );
		}
		/* translators: %d: hours */
		return sprintf( _n( 'The link is valid for %d hour. If you did not ask for it, you can ignore this email.', 'The link is valid for %d hours. If you did not ask for it, you can ignore this email.', $hours, 'voucher-captain' ), $hours );
	}

	/**
	 * Sends the confirmation link to the account's address (at most send_per_hour() per account and hour).
	 *
	 * @return string sent|limited|verified|failed
	 */
	public static function send_confirmation( \WP_User $user ): string {
		if ( ! is_email( (string) $user->user_email ) ) {
			return 'failed';
		}
		if ( self::has_proof( $user ) ) {
			return 'verified';
		}
		if ( Plugin::rate_limited( 'email_confirm', self::send_per_hour(), 500, HOUR_IN_SECONDS, true, 'u' . $user->ID ) ) {
			return 'limited';
		}
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Confirm your email address at %s', 'voucher-captain' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$url        = self::confirmation_url( $user );
		$registered = strtotime( (string) $user->user_registered . ' UTC' );
		$body       = '<p>' . esc_html__( 'Please confirm your email address to redeem personal vouchers sent to it in our shop:', 'voucher-captain' ) . '</p>'
			// M12-25: name the account, so a recipient whose address someone else registered does not confirm it.
			. '<p>' . esc_html(
				sprintf(
					/* translators: 1: account (user name), 2: registration date */
					__( 'The link confirms the address for the customer account "%1$s", registered on %2$s. You need to log in to this account to confirm.', 'voucher-captain' ),
					(string) $user->user_login,
					$registered ? wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $registered ) : '–'
				)
			) . '</p>'
			. '<p><strong>' . esc_html__( 'Do not click the link if you did not create this account yourself.', 'voucher-captain' ) . '</strong></p>'
			. '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm email address', 'voucher-captain' ) . '</a></p>'
			. '<p>' . esc_html( self::validity_sentence() ) . '</p>';
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		$sent   = $mailer
			? $mailer->send( (string) $user->user_email, $subject, $mailer->wrap_message( __( 'Confirm your email address', 'voucher-captain' ), $body ), array( 'Content-Type: text/html; charset=UTF-8' ) )
			: wp_mail( (string) $user->user_email, $subject, wp_strip_all_tags( $body ) . "\n\n" . $url );
		return $sent ? 'sent' : 'failed';
	}

	/** URL that asks for a confirmation email (logged-in customer, nonce), returning to the current page. */
	public static function send_url(): string {
		return wp_nonce_url( add_query_arg( 'vcap_send_email_confirmation', '1' ), 'vcap_send_email_confirmation', 'vcap_nonce' );
	}

	/** Front end: confirmation links, their result notice, and requests for a confirmation email. */
	public static function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the confirmation link carries an HMAC instead of a nonce; the result flag only selects a notice.
		if ( isset( $_GET['vcap_confirm_email'] ) ) {
			self::private_headers(); // M12-87: the signed link is neither cached nor passed on as referrer.
			$target = absint( $_GET['vcap_confirm_email'] );
			$exp    = absint( $_GET['exp'] ?? 0 );
			$sig    = sanitize_key( wp_unslash( $_GET['sig'] ?? '' ) );
			if ( get_current_user_id() <= 0 || get_current_user_id() !== $target ) {
				self::confirm_after_login( $target, $exp, $sig );
				return;
			}
			$ok = ! Plugin::rate_limited( 'email_confirm_link', 30, 0, 10 * MINUTE_IN_SECONDS ) && self::confirm( $target, $exp, $sig );
			wp_safe_redirect( add_query_arg( 'vcap_email_confirmed', $ok ? '1' : '0', remove_query_arg( array( 'vcap_confirm_email', 'exp', 'sig' ) ) ) );
			exit;
		}
		if ( isset( $_GET['vcap_email_confirmed'] ) && function_exists( 'wc_add_notice' ) ) {
			if ( '1' === $_GET['vcap_email_confirmed'] ) {
				wc_add_notice( __( 'Thank you – your email address is confirmed. Personal vouchers sent to it can now be redeemed with your account.', 'voucher-captain' ), 'success' );
			} else {
				wc_add_notice( __( 'This confirmation link is invalid or has expired, or the email address of the account has changed. Please log in and request a new link.', 'voucher-captain' ), 'error' );
			}
		}
		if ( ! isset( $_GET['vcap_send_email_confirmation'] ) ) {
			return;
		}
		// phpcs:enable
		$back = remove_query_arg( array( 'vcap_send_email_confirmation', 'vcap_nonce' ) );
		if ( ! is_user_logged_in() || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['vcap_nonce'] ?? '' ) ), 'vcap_send_email_confirmation' ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		$user   = wp_get_current_user();
		$result = self::send_confirmation( $user );
		if ( function_exists( 'wc_add_notice' ) ) {
			switch ( $result ) {
				case 'sent':
					/* translators: %s: masked email address, e.g. j***@example.com */
					wc_add_notice( sprintf( __( 'We have sent a confirmation link to %s. Please open it, then apply the voucher again.', 'voucher-captain' ), Mask::email( (string) $user->user_email ) ), 'success' );
					break;
				case 'verified':
					wc_add_notice( __( 'Your email address is already confirmed.', 'voucher-captain' ), 'notice' );
					break;
				case 'limited':
					wc_add_notice( __( 'Too many confirmation emails. Please check your inbox or try again in an hour.', 'voucher-captain' ), 'error' );
					break;
				default:
					wc_add_notice( __( 'The confirmation email could not be sent. Please try again later or contact us.', 'voucher-captain' ), 'error' );
			}
		}
		wp_safe_redirect( $back );
		exit;
	}

	/** M12-87: no caching and no referrer for pages that carry a signed confirmation link. */
	private static function private_headers(): void {
		$add = static fn( $headers ) => array_merge( (array) $headers, array( 'Referrer-Policy' => 'no-referrer' ) );
		add_filter( 'nocache_headers', $add, 99 );
		$headers = wp_get_nocache_headers(); // As nocache_headers(), plus the referrer policy.
		remove_filter( 'nocache_headers', $add, 99 );
		if ( headers_sent() ) {
			return;
		}
		unset( $headers['Last-Modified'] );
		header_remove( 'Last-Modified' );
		foreach ( $headers as $name => $value ) {
			header( "{$name}: {$value}" );
		}
	}

	/**
	 * M12-25: a confirmation link only confirms for the account it was sent for, logged in. Otherwise someone who
	 * registered the recipient's address (pre-hijacking) would have the account verified by the recipient's click.
	 * A visitor who is not logged in gets the login form of My Account, which returns to the link after the login;
	 * a customer logged in with another account is asked to log out first.
	 */
	private static function confirm_after_login( int $target, int $exp, string $sig ): void {
		$account = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'myaccount' ) > 0 ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		$link    = add_query_arg(
			array(
				'vcap_confirm_email' => $target,
				'exp'                => $exp,
				'sig'                => $sig,
			),
			'' !== $account ? $account : home_url( '/' )
		);
		$notice = function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && WC()->session;
		if ( is_user_logged_in() ) {
			if ( $notice ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: link "log out" */
						__( 'This confirmation link was sent for another customer account. Please %s and log in with the account it was sent for.', 'voucher-captain' ),
						'<a href="' . esc_url( wp_logout_url( $link ) ) . '">' . esc_html__( 'log out', 'voucher-captain' ) . '</a>'
					),
					'error'
				);
			}
			wp_safe_redirect( remove_query_arg( array( 'vcap_confirm_email', 'exp', 'sig' ) ) );
			exit;
		}
		if ( '' !== $account && function_exists( 'is_account_page' ) && is_account_page() ) {
			if ( $notice ) {
				wc_add_notice( __( 'Please log in to the customer account this confirmation link was sent for. The email address is confirmed right after the login.', 'voucher-captain' ), 'notice' );
			}
			return; // The login form posts this URL back as referrer: WooCommerce returns here after the login.
		}
		wp_safe_redirect( '' !== $account ? $link : wp_login_url( $link ) );
		exit;
	}

	/** Account details form: offers the confirmation link while the account email is not verified. */
	public static function account_form_hint(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() || '' !== self::verified_email( $user ) ) {
			return;
		}
		echo '<p class="vcap-email-confirmation">' . esc_html__( 'Personal vouchers sent to your email address can be redeemed once the address is confirmed.', 'voucher-captain' )
			. ' <a href="' . esc_url( self::send_url() ) . '">' . esc_html__( 'Send confirmation link', 'voucher-captain' ) . '</a></p>';
	}

	/**
	 * Classic cart and checkout: next to the refusal, a notice with the link that sends the confirmation email
	 * (once per request; not in REST requests such as the Store API, which shows no notices).
	 */
	private static function offer_confirmation( \WP_User $user ): void {
		static $offered = false;
		if ( $offered || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! function_exists( 'wc_add_notice' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$offered = true;
		wc_add_notice(
			sprintf(
				/* translators: 1: masked email address, e.g. j***@example.com, 2: link "Send confirmation link" */
				__( 'Confirm %1$s to use personal vouchers sent to it: %2$s', 'voucher-captain' ),
				esc_html( Mask::email( (string) $user->user_email ) ),
				'<a class="button" href="' . esc_url( self::send_url() ) . '">' . esc_html__( 'Send confirmation link', 'voucher-captain' ) . '</a>'
			),
			'notice'
		);
	}

	/** Customer-facing hint added to the "Personal – …" texts (voucher, email, PDF, account, product page). */
	public static function login_hint(): string {
		return __( 'Online redemption requires logging in to the customer account with that email address.', 'voucher-captain' );
	}

	/** Staff working on an order in wp-admin (they verify the holder themselves, like at the counter). */
	public static function staff_context(): bool {
		return is_admin() && ( current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
	}

	/**
	 * Message for a refused personal voucher: whom to log in as (masked addresses only). A logged-in customer whose
	 * account email matches but is not verified yet is asked to confirm it, and the classic cart/checkout gets a
	 * notice with the link that sends the confirmation email (AP-S1-1).
	 *
	 * @param string[] $restrictions Lower-cased restrictions.
	 * @param bool     $logged_in    Whether a (non-matching) customer is logged in.
	 */
	public static function refusal( array $restrictions, bool $logged_in ): string {
		$user = $logged_in && is_user_logged_in() ? wp_get_current_user() : null;
		if ( $user && self::needs_confirmation( $restrictions, $user ) ) {
			self::offer_confirmation( $user );
			return sprintf(
				/* translators: %s: masked email address, e.g. j***@example.com */
				__( 'Please confirm your email address %s to use this personal voucher. You can request the confirmation link in your account details.', 'voucher-captain' ),
				Mask::email( (string) $user->user_email )
			);
		}
		$masked = array_values( array_unique( array_map( array( Mask::class, 'email' ), $restrictions ) ) );
		if ( 1 === count( $masked ) ) {
			return $logged_in
				/* translators: %s: masked email address, e.g. j***@example.com */
				? sprintf( __( 'This personal voucher belongs to another customer account. Please log in with %s to use it.', 'voucher-captain' ), $masked[0] )
				/* translators: %s: masked email address, e.g. j***@example.com */
				: sprintf( __( 'Please log in with %s to use this personal voucher.', 'voucher-captain' ), $masked[0] );
		}
		return $logged_in
			/* translators: %s: list of masked email addresses */
			? sprintf( __( 'This personal voucher belongs to another customer account. Please log in with one of these addresses to use it: %s', 'voucher-captain' ), implode( ', ', $masked ) )
			/* translators: %s: list of masked email addresses */
			: sprintf( __( 'Please log in with one of these addresses to use this personal voucher: %s', 'voucher-captain' ), implode( ', ', $masked ) );
	}
}
