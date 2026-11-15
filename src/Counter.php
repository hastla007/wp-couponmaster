<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Counter (scanner/till) bookings (R6): the till/branch and receipt number of a booking as ledger columns, and
 * daily limits for counter redemptions per staff member and per till/branch.
 *
 * Till and receipt: the scanner sends both with every redemption (Tickets::rest_redeem()). While a booking runs,
 * they are set with with(); VoucherRepository::log() stores them in the columns "till" and "receipt" of the
 * redemption ("redeemed") and of its cancellation ("redeem_reversed", which takes them from the redemption). The
 * note keeps its text ("… · Receipt 4711 · Till Shop 1") for the history; rows booked before schema 1.8.0 are read
 * from it once (parse_note(), Install::fill_event_tills()).
 *
 * Daily limits (default off, 0 = unlimited): amount and/or number of counter redemptions per staff member and per
 * till/branch and shop day (shop time zone). Counted are the redemptions without an order ("redeemed", order 0) of
 * the day; the amount counts value vouchers in the currency of the voucher being redeemed (gift cards: the amount
 * booked; single-use value vouchers: their value; percentage, ticket and reusable vouchers count only as a
 * redemption). Cancellations do not give quota back. Staff who manage vouchers are not limited (filter
 * "vcap_counter_limit_applies"). A device without till/branch counts as the till "" (one shared bucket). The check
 * and the booking run under named database locks per staff member and till, so parallel scans cannot both pass.
 */
final class Counter {
	/** Settings: daily limits per staff member (amount, count) and per till/branch (amount, count); 0 = unlimited. */
	public const USER_AMOUNT = 'vcap_counter_limit_amount';
	public const USER_COUNT  = 'vcap_counter_limit_count';
	public const TILL_AMOUNT = 'vcap_counter_till_limit_amount';
	public const TILL_COUNT  = 'vcap_counter_till_limit_count';
	/** User options (per site): own daily limits of a staff member ('' or missing = the setting). */
	public const META_AMOUNT = 'vcap_counter_limit_amount';
	public const META_COUNT  = 'vcap_counter_limit_count';
	/** Seconds a counter booking waits for the limit lock of another one (same staff member or till). */
	private const LOCK_WAIT = 5;

	/** @var array<int, array{till:string, receipt:string}> Contexts set by with(), innermost last. */
	private static array $stack = array();

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
	}

	/* ---- Till and receipt of a booking ------------------------------------------------------------- */

	/**
	 * Runs $work with the till/branch and receipt number of a counter booking (stored with its ledger rows).
	 *
	 * @return mixed What $work returns.
	 */
	public static function with( string $till, string $receipt, callable $work ) {
		self::push( $till, $receipt );
		try {
			return $work();
		} finally {
			self::pop();
		}
	}

	public static function push( string $till, string $receipt ): void {
		self::$stack[] = array(
			'till'    => self::clean_till( $till ),
			'receipt' => self::clean_receipt( $receipt ),
		);
	}

	public static function pop(): void {
		array_pop( self::$stack );
	}

	/** @return array{till:string, receipt:string}|null The context of the running counter booking. */
	public static function context(): ?array {
		return self::$stack ? end( self::$stack ) : null;
	}

	public static function clean_till( string $till ): string {
		return mb_substr( trim( sanitize_text_field( $till ) ), 0, 100 );
	}

	public static function clean_receipt( string $receipt ): string {
		return mb_substr( trim( sanitize_text_field( $receipt ) ), 0, 64 );
	}

	/**
	 * Till and receipt of a counter note written before schema 1.8.0 (Tickets::counter_note(): the note, then
	 * " · Receipt X" and " · Till Y" – in the language of the request that booked it). The labels are matched in
	 * English, German and the current site language; a note cut at 250 characters may have lost them.
	 *
	 * @return array{till:string, receipt:string}
	 */
	public static function parse_note( string $note ): array {
		$out   = array( 'till' => '', 'receipt' => '' );
		$parts = explode( ' · ', $note );
		if ( count( $parts ) < 2 || Note::is_coded( $note ) ) {
			return $out;
		}
		/* translators: %s: receipt number of the till */
		$receipt = __( 'Receipt %s', 'voucher-captain' );
		/* translators: %s: till or branch name */
		$till   = __( 'Till %s', 'voucher-captain' );
		$labels = array(
			'receipt' => array_unique( array( 'Receipt ', 'Beleg ', self::label_prefix( $receipt ) ) ),
			'till'    => array_unique( array( 'Till ', 'Kasse ', self::label_prefix( $till ) ) ),
		);
		// The structured parts follow the free note: read from the end, receipt before till.
		foreach ( array_slice( $parts, 1 ) as $part ) {
			foreach ( $labels as $key => $prefixes ) {
				foreach ( $prefixes as $prefix ) {
					if ( '' !== trim( $prefix ) && str_starts_with( $part, $prefix ) && strlen( $part ) > strlen( $prefix ) ) {
						$out[ $key ] = 'till' === $key ? self::clean_till( substr( $part, strlen( $prefix ) ) ) : self::clean_receipt( substr( $part, strlen( $prefix ) ) );
					}
				}
			}
		}
		return $out;
	}

	/** Text before the placeholder of a label such as "Till %s" ("Till "). */
	private static function label_prefix( string $label ): string {
		$pos = strpos( $label, '%s' );
		return false === $pos ? '' : substr( $label, 0, $pos );
	}

	/**
	 * Till and receipt of the redemption a cancellation note refers to ("Cancels redemption #N: …").
	 *
	 * @return array{till:string, receipt:string}
	 */
	public static function reversed_context( string $note ): array {
		$out = array( 'till' => '', 'receipt' => '' );
		if ( ! preg_match( '/#(\d+)/', $note, $m ) || ! Install::event_till_ready() ) {
			return $out;
		}
		global $wpdb;
		$events = Install::tables()['events'];
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT till, receipt FROM {$events} WHERE id = %d AND event = 'redeemed'", (int) $m[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? array( 'till' => (string) $row['till'], 'receipt' => (string) $row['receipt'] ) : $out;
	}

	/* ---- Daily limits ------------------------------------------------------------------------------ */

	/**
	 * Daily limits in effect for a staff member at a till: own limits from the user profile, else the settings.
	 *
	 * @return array{user: array{amount:float, count:int}, till: array{amount:float, count:int}}
	 */
	public static function limits( int $user_id, string $till, ?Voucher $voucher = null ): array {
		$own_amount = $user_id ? (string) get_user_option( self::META_AMOUNT, $user_id ) : '';
		$own_count  = $user_id ? (string) get_user_option( self::META_COUNT, $user_id ) : '';
		$limits     = array(
			'user' => array(
				'amount' => max( 0.0, (float) ( is_numeric( $own_amount ) ? $own_amount : Settings::value( self::USER_AMOUNT ) ) ),
				'count'  => max( 0, (int) ( is_numeric( $own_count ) ? $own_count : Settings::value( self::USER_COUNT ) ) ),
			),
			'till' => array(
				'amount' => max( 0.0, (float) Settings::value( self::TILL_AMOUNT ) ),
				'count'  => max( 0, (int) Settings::value( self::TILL_COUNT ) ),
			),
		);
		/**
		 * Daily limits of counter redemptions for a staff member at a till (0 = unlimited), e.g. other limits per
		 * branch. Args: limits {user: {amount, count}, till: {amount, count}}, user id, till, Voucher|null.
		 */
		$filtered = (array) apply_filters( 'vcap_counter_limits', $limits, $user_id, $till, $voucher );
		foreach ( array( 'user', 'till' ) as $scope ) {
			$limits[ $scope ] = array(
				'amount' => max( 0.0, (float) ( $filtered[ $scope ]['amount'] ?? 0 ) ),
				'count'  => max( 0, (int) ( $filtered[ $scope ]['count'] ?? 0 ) ),
			);
		}
		return $limits;
	}

	/** Whether the daily limits apply to this staff member (not to staff who manage vouchers). */
	public static function applies( int $user_id, Voucher $voucher ): bool {
		$manager = $user_id > 0 && ( user_can( $user_id, 'vcap_manage_vouchers' ) || user_can( $user_id, 'manage_woocommerce' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install / WooCommerce.
		/** Whether the daily counter limits apply (default: not for staff who manage vouchers). Args: bool, user id, Voucher. */
		return (bool) apply_filters( 'vcap_counter_limit_applies', ! $manager, $user_id, $voucher );
	}

	/** Value a redemption of this voucher counts towards the amount limit (gift card: pass the amount booked). */
	public static function value_of( Voucher $voucher ): float {
		if ( 'percent' === $voucher->discount_type || $voucher->is_ticket() || ! in_array( $voucher->usage_mode, array( 'single', 'balance' ), true ) ) {
			return 0.0;
		}
		return $voucher->is_multi_use() ? (float) $voucher->balance : (float) $voucher->initial_amount;
	}

	/**
	 * Counter redemptions of today (shop day) by a staff member ('user', int id) or at a till ('till', name).
	 *
	 * @return array{count:int, amount:float} amount: value vouchers in $currency only
	 */
	public static function used_today( string $scope, $key, string $currency ): array {
		global $wpdb;
		if ( 'till' === $scope && ! Install::event_till_ready() ) {
			return array( 'count' => 0, 'amount' => 0.0 );
		}
		$t     = Install::tables();
		$today = wp_date( 'Y-m-d' );
		$since = Ledger::utc_range( $today, $today )[0];
		$value = Ledger::VALUE_VOUCHERS;
		if ( 'till' === $scope ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS cnt, COALESCE( SUM( CASE WHEN {$value} AND v.currency = %s THEN e.amount ELSE 0 END ), 0 ) AS amount FROM {$t['events']} e INNER JOIN {$t['vouchers']} v ON v.id = e.voucher_id WHERE e.created_at >= %s AND e.event = 'redeemed' AND e.order_id = 0 AND e.till = %s", $currency, $since, (string) $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $value is a constant.
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS cnt, COALESCE( SUM( CASE WHEN {$value} AND v.currency = %s THEN e.amount ELSE 0 END ), 0 ) AS amount FROM {$t['events']} e INNER JOIN {$t['vouchers']} v ON v.id = e.voucher_id WHERE e.created_at >= %s AND e.event = 'redeemed' AND e.order_id = 0 AND e.user_id = %d", $currency, $since, (int) $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $value is a constant.
		}
		return array( 'count' => (int) ( $row['cnt'] ?? 0 ), 'amount' => (float) ( $row['amount'] ?? 0 ) );
	}

	/**
	 * The first limit a redemption of $amount would exceed, null when it fits. Pure (unit-tested).
	 *
	 * @param array{amount:float, count:int} $limit
	 * @param array{count:int, amount:float} $used
	 * @return array{kind:string, limit:float, used:float, left:float}|null kind 'count' or 'amount'
	 */
	public static function exceeded( array $limit, array $used, float $amount, int $decimals = 2 ): ?array {
		if ( $limit['count'] > 0 && $used['count'] + 1 > $limit['count'] ) {
			return array( 'kind' => 'count', 'limit' => (float) $limit['count'], 'used' => (float) $used['count'], 'left' => (float) max( 0, $limit['count'] - $used['count'] ) );
		}
		if ( $limit['amount'] > 0 && $amount > 0 && CouponManager::minor_units( $used['amount'] + $amount, $decimals ) > CouponManager::minor_units( $limit['amount'], $decimals ) ) {
			return array( 'kind' => 'amount', 'limit' => $limit['amount'], 'used' => $used['amount'], 'left' => max( 0.0, round( $limit['amount'] - $used['amount'], $decimals ) ) );
		}
		return null;
	}

	/**
	 * Checks the daily limits for a counter redemption of $amount by the current user at $till and, when they are
	 * in effect, holds their locks until release(): the booking runs while no other counter booking of the same
	 * staff member or till can check. On a refusal nothing is held.
	 *
	 * @return array{message:string, reason:string, scope:string, exceeded:?array, locks:string[]} message '' = may book
	 */
	public static function claim( Voucher $voucher, float $amount, string $till ): array {
		$out     = array( 'message' => '', 'reason' => '', 'scope' => '', 'exceeded' => null, 'locks' => array() );
		$user_id = get_current_user_id();
		$till    = self::clean_till( $till );
		if ( ! self::applies( $user_id, $voucher ) ) {
			return $out;
		}
		$limits = self::limits( $user_id, $till, $voucher );
		$scopes = array();
		if ( $limits['user']['amount'] > 0 || $limits['user']['count'] > 0 ) {
			$scopes['user'] = $user_id;
		}
		if ( ( $limits['till']['amount'] > 0 || $limits['till']['count'] > 0 ) && Install::event_till_ready() ) {
			$scopes['till'] = $till;
		}
		if ( ! $scopes ) {
			return $out;
		}
		global $wpdb;
		foreach ( $scopes as $scope => $key ) {
			$name = Install::lock_name( self::lock_key( $scope, $key ) );
			if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::release( $out['locks'] );
				$out['locks']   = array();
				$out['reason']  = 'reserved';
				$out['message'] = __( 'Not redeemed: another redemption at this till or by you is being booked right now. Please try again in a moment.', 'voucher-captain' );
				return $out;
			}
			$out['locks'][] = $name;
		}
		$decimals = $voucher->decimals();
		foreach ( $scopes as $scope => $key ) {
			$over = self::exceeded( $limits[ $scope ], self::used_today( $scope, $key, (string) $voucher->currency ), $amount, $decimals );
			if ( $over ) {
				self::release( $out['locks'] );
				$out['locks']    = array();
				$out['reason']   = 'daily_limit';
				$out['scope']    = $scope;
				$out['exceeded'] = $over;
				$out['message']  = self::limit_message( $scope, $till, $over, (string) $voucher->currency );
				return $out;
			}
		}
		return $out;
	}

	/**
	 * Name of the limit lock of a staff member or till (before Install::lock_name()). AP-S3-5: a till is compared
	 * like used_today() compares it in SQL (case- and accent-insensitive collation, surrounding spaces ignored), so
	 * "Till 1" and "till 1 " share one lock as they share one daily count.
	 *
	 * @param int|string $key
	 */
	public static function lock_key( string $scope, $key ): string {
		$key = 'till' === $scope ? remove_accents( mb_strtolower( trim( (string) $key ) ) ) : (string) $key;
		return 'counter_' . $scope . '_' . md5( $key );
	}

	/** @param string[] $locks Lock names of claim(). */
	public static function release( array $locks ): void {
		global $wpdb;
		foreach ( array_reverse( $locks ) as $name ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Scanner message naming the limit and what is left today. */
	public static function limit_message( string $scope, string $till, array $over, string $currency ): string {
		$money = static fn( float $v ): string => Tickets::money_text( $v, $currency );
		if ( 'till' === $scope ) {
			$name = '' !== $till ? $till : __( '(no till set)', 'voucher-captain' );
			if ( 'count' === $over['kind'] ) {
				/* translators: 1: till or branch, 2: redemptions allowed per day, 3: redemptions today, 4: redemptions left */
				return sprintf( __( 'Not redeemed: the daily limit of till "%1$s" is %2$d counter redemptions. Redeemed there today: %3$d – left: %4$d.', 'voucher-captain' ), $name, (int) $over['limit'], (int) $over['used'], (int) $over['left'] );
			}
			/* translators: 1: till or branch, 2: amount allowed per day, 3: amount redeemed today, 4: amount left */
			return sprintf( __( 'Not redeemed: the daily limit of till "%1$s" is %2$s. Redeemed there today: %3$s – still available: %4$s.', 'voucher-captain' ), $name, $money( $over['limit'] ), $money( $over['used'] ), $money( $over['left'] ) );
		}
		if ( 'count' === $over['kind'] ) {
			/* translators: 1: redemptions allowed per day, 2: redemptions today, 3: redemptions left */
			return sprintf( __( 'Not redeemed: your daily limit is %1$d counter redemptions. Redeemed by you today: %2$d – left: %3$d.', 'voucher-captain' ), (int) $over['limit'], (int) $over['used'], (int) $over['left'] );
		}
		/* translators: 1: amount allowed per day, 2: amount redeemed today, 3: amount left */
		return sprintf( __( 'Not redeemed: your daily limit for counter redemptions is %1$s. Redeemed by you today: %2$s – still available: %3$s.', 'voucher-captain' ), $money( $over['limit'] ), $money( $over['used'] ), $money( $over['left'] ) );
	}

	/* ---- Own limits on the user profile ------------------------------------------------------------ */

	/** Whether the current user may set the limits of $user (managers, for staff with scanner rights). */
	private static function can_edit( \WP_User $user ): bool {
		return current_user_can( 'vcap_manage_vouchers' ) && current_user_can( 'edit_user', $user->ID ) && user_can( $user, 'vcap_scan_tickets' ) && ! user_can( $user, 'vcap_manage_vouchers' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
	}

	/** @param \WP_User $user Profile being edited. */
	public function profile_fields( $user ): void {
		if ( ! $user instanceof \WP_User || ! self::can_edit( $user ) ) {
			return;
		}
		$amount = (string) get_user_option( self::META_AMOUNT, $user->ID );
		$count  = (string) get_user_option( self::META_COUNT, $user->ID );
		?>
		<h2><?php esc_html_e( 'Voucher scanner: daily limits', 'voucher-captain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="vcap-counter-limit-amount"><?php esc_html_e( 'Amount per day', 'voucher-captain' ); ?></label></th>
				<td><input type="number" min="0" step="0.01" class="small-text" name="vcap_counter_limit_amount" id="vcap-counter-limit-amount" value="<?php echo esc_attr( $amount ); ?>" />
				<p class="description"><?php esc_html_e( 'Empty = the setting under WooCommerce → Settings → Vouchers → Privacy & advanced. 0 = unlimited.', 'voucher-captain' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="vcap-counter-limit-count"><?php esc_html_e( 'Redemptions per day', 'voucher-captain' ); ?></label></th>
				<td><input type="number" min="0" step="1" class="small-text" name="vcap_counter_limit_count" id="vcap-counter-limit-count" value="<?php echo esc_attr( $count ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/** @param int $user_id Profile saved (WordPress checked its nonce "update-user_{id}" before this hook). */
	public function save_profile( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! self::can_edit( $user ) || ! isset( $_POST['vcap_counter_limit_amount'], $_POST['vcap_counter_limit_count'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WordPress (user-edit.php).
			return;
		}
		$amount = trim( sanitize_text_field( wp_unslash( $_POST['vcap_counter_limit_amount'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$count  = trim( sanitize_text_field( wp_unslash( $_POST['vcap_counter_limit_count'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( array( self::META_AMOUNT => $amount, self::META_COUNT => $count ) as $key => $value ) {
			if ( '' === $value || ! is_numeric( str_replace( ',', '.', $value ) ) ) {
				delete_user_option( $user->ID, $key );
				continue;
			}
			$number = max( 0, (float) str_replace( ',', '.', $value ) );
			update_user_option( $user->ID, $key, self::META_COUNT === $key ? (string) (int) $number : (string) $number );
		}
	}
}
