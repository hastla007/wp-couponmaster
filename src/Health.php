<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health (Tools → Site Health) checks for what Voucher Captain depends on: scheduled deliveries (Action
 * Scheduler / WP-Cron), outgoing mail, the private upload folder and – when ticket vouchers are sold – the redeem
 * page. Failed mails are kept in a short, masked log (option "vcap_mail_failures") so a broken mailer is noticed.
 */
final class Health {
	public const MAIL_LOG = 'vcap_mail_failures';
	private const LOG_SIZE = 20;

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
		add_action( 'wp_mail_failed', array( $this, 'record_mail_failure' ) );
		add_action( 'admin_post_vcap_reset_rate_limits', array( $this, 'reset_rate_limits' ) );
	}

	/** Site Health action: removes all rate limit counters (e.g. after fixing the proxy setup). */
	public function reset_rate_limits(): void {
		check_admin_referer( 'vcap_reset_rate_limits' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		Plugin::reset_rate_limits();
		wp_safe_redirect( admin_url( 'site-health.php?vcap_rate_limits_reset=1' ) );
		exit;
	}

	/**
	 * Whether the request looks like it came through a reverse proxy or CDN while the rate limits still use
	 * REMOTE_ADDR (setting "Trusted proxy header" = none or its header missing, no "vcap_client_ip" filter): then all
	 * customers share the proxy's counter. Signs: a proxy header is present, or REMOTE_ADDR is a private or reserved
	 * address (a proxy or load balancer in the same network).
	 *
	 * @param array<string, mixed> $server $_SERVER.
	 */
	public static function proxy_without_client_ip( array $server ): bool {
		$remote = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '';
		$client = Plugin::client_ip_from( $server, (string) Settings::value( Plugin::PROXY_HEADER_OPTION ) );
		if ( (string) apply_filters( 'vcap_client_ip', $client ) !== $remote ) { // Same setting and filter as Plugin::client_ip().
			return false; // The setting or the filter "vcap_client_ip" gives the visitor's address.
		}
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FASTLY_CLIENT_IP' ) as $header ) {
			if ( ! empty( $server[ $header ] ) ) {
				return true;
			}
		}
		return '' !== $remote && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/** Limit hits looked at by single_source() (the most recent ones, at most 30 days old). */
	private const SOURCE_HITS = 5;

	/**
	 * SET-VC-26: whether the last SOURCE_HITS rate-limit hits (Plugin::RATE_HITS_OPTION, all buckets, last 30 days)
	 * all came from one address – the sign of a proxy whose address every visitor shares (or of one persistent
	 * visitor). Fewer hits say nothing.
	 *
	 * @param mixed $hits Stored RATE_HITS_OPTION.
	 */
	public static function single_source( $hits ): bool {
		$recent = array();
		foreach ( is_array( $hits ) ? $hits : array() as $hit ) {
			foreach ( is_array( $hit ) && is_array( $hit['from'] ?? null ) ? $hit['from'] : array() as $from ) {
				if ( is_array( $from ) && is_string( $from[0] ?? null ) && '' !== $from[0] && (int) ( $from[1] ?? 0 ) > time() - 30 * DAY_IN_SECONDS ) {
					$recent[] = array( $from[0], (int) $from[1] );
				}
			}
		}
		if ( count( $recent ) < self::SOURCE_HITS ) {
			return false;
		}
		usort( $recent, static fn( array $a, array $b ) => $b[1] <=> $a[1] );
		return 1 === count( array_unique( array_column( array_slice( $recent, 0, self::SOURCE_HITS ), 0 ) ) );
	}

	/**
	 * Field "Trusted proxy header" of the section "Privacy & advanced" (SET-VC-26).
	 *
	 * @return array<string, mixed>
	 */
	public static function proxy_header_field(): array {
		return SettingsRegistry::field(
			Plugin::PROXY_HEADER_OPTION,
			array(
				'title'   => __( 'Trusted proxy header', 'voucher-captain' ),
				'type'    => 'select',
				'options' => array(
					'none'       => __( 'None – use the connection address (REMOTE_ADDR)', 'voucher-captain' ),
					'cloudflare' => __( 'Cloudflare (CF-Connecting-IP)', 'voucher-captain' ),
					'xff_last'   => __( 'X-Forwarded-For, last entry', 'voucher-captain' ),
				),
				'desc'    => __( 'Rate limits for code entries count per visitor address. Behind a reverse proxy or CDN every visitor seems to come from the proxy\'s address; choose the header your proxy sets so each visitor is counted on their own. Only choose a header when every request passes through that proxy – otherwise visitors can fake the header and get around the limits. The filter "vcap_client_ip" wins over this setting.', 'voucher-captain' ),
			)
		);
	}

	/** Rate limits: visitor addresses behind a proxy, and limits reached in the last day. */
	public function test_rate_limits(): array {
		$hits = get_option( Plugin::RATE_HITS_OPTION, array() );
		$hits = is_array( $hits ) ? $hits : array();
		$recent = array();
		foreach ( $hits as $bucket => $hit ) {
			if ( is_array( $hit ) && (int) ( $hit['last'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
				$recent[] = (string) $bucket;
			}
		}
		$proxy  = self::proxy_without_client_ip( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only checks which headers exist.
		$single = self::single_source( $hits );
		$reset  = '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vcap_reset_rate_limits' ), 'vcap_reset_rate_limits' ) ) . '">' . esc_html__( 'Reset rate limits', 'voucher-captain' ) . '</a></p>';
		if ( ! $proxy && ! $recent && ! $single ) {
			return self::result( 'rate_limits', 'good', __( 'Code entry rate limits see each visitor', 'voucher-captain' ), __( 'Failed code entries and PDF previews are limited per visitor address; no limit was reached in the last 24 hours.', 'voucher-captain' ) );
		}
		$text = array();
		if ( $proxy ) {
			$text[] = __( 'This site seems to run behind a reverse proxy or CDN, but the rate limits count by the address of the proxy. All customers then share one counter and may see "Too many attempts". Choose the header your proxy sets under WooCommerce → Settings → Vouchers → Privacy & advanced → "Trusted proxy header" (or return the real visitor address with the filter "vcap_client_ip").', 'voucher-captain' );
		}
		if ( $single ) {
			/* translators: %d: number of rate limit hits */
			$text[] = sprintf( __( 'The last %d times a rate limit was reached, the requests all came from the same address. If that is the address of your proxy or CDN, choose its header under "Trusted proxy header"; if it is a single visitor trying many codes, the limit worked as intended.', 'voucher-captain' ), self::SOURCE_HITS );
		}
		if ( $recent ) {
			/* translators: %s: list of rate limit names, e.g. "code, preview" */
			$text[] = sprintf( __( 'Rate limits reached in the last 24 hours: %s. Details are in the WooCommerce log (source "voucher-captain"). If customers were affected, reset the counters.', 'voucher-captain' ), implode( ', ', $recent ) );
		}
		$result            = self::result( 'rate_limits', 'recommended', __( 'Code entry rate limits need attention', 'voucher-captain' ), implode( ' ', $text ) );
		$result['actions'] = current_user_can( 'manage_woocommerce' ) ? $reset : '';
		return $result;
	}

	/** Checks that go through all voucher products: run asynchronously (REST), so Site Health opens at once. */
	private const ASYNC = array( 'validity', 'tax_status', 'ledger', 'upload_dir' ); // upload_dir: an HTTP request to the own site (M12-43).
	/** Voucher products checked at most per test (the result says when there are more). */
	private const MAX_PRODUCTS = 500;

	public function tests( $tests ) {
		$tests = (array) $tests;
		foreach ( array( 'extensions', 'database', 'schema', 'scheduler', 'mail', 'sender_domain', 'purchase_limits', 'redeem_page', 'markets', 'offline_payment', 'currency', 'rate_limits', 'template_images', 'template_overrides' ) as $id ) {
			$tests['direct'][ 'vcap_' . $id ] = array(
				'label' => Brand::name( 'site_health' ),
				'test'  => array( $this, 'test_' . $id ),
			);
		}
		foreach ( self::ASYNC as $id ) {
			$tests['async'][ 'vcap_' . $id ] = array(
				'label'             => Brand::name( 'site_health' ),
				'test'              => rest_url( 'vcap/v1/site-health/' . str_replace( '_', '-', $id ) ),
				'has_rest'          => true,
				'async_direct_test' => array( $this, 'test_' . $id ),
			);
		}
		return $tests;
	}

	/** REST endpoints of the asynchronous checks (same permission as Site Health's own checks). */
	public function rest_routes(): void {
		foreach ( self::ASYNC as $id ) {
			register_rest_route(
				'vcap/v1',
				'/site-health/' . str_replace( '_', '-', $id ),
				array(
					'methods'             => 'GET',
					'callback'            => fn() => rest_ensure_response( $this->{'test_' . $id}() ),
					'permission_callback' => static fn() => current_user_can( 'view_site_health_checks' ),
				)
			);
		}
	}

	/**
	 * Voucher products (and their variations) of the given post types, at most MAX_PRODUCTS.
	 *
	 * @param string[] $post_types Post types.
	 * @return array{0:int[],1:bool} ids, whether there are more
	 */
	private static function voucher_products( array $post_types ): array {
		$ids = get_posts( array( 'post_type' => $post_types, 'post_status' => array( 'publish', 'private', 'draft' ), 'fields' => 'ids', 'numberposts' => self::MAX_PRODUCTS + 1, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_vcap_enabled', 'meta_value' => 'yes' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$ids = array_map( 'intval', $ids );
		return array( array_slice( $ids, 0, self::MAX_PRODUCTS ), count( $ids ) > self::MAX_PRODUCTS );
	}

	/** Sentence added to a result when not every voucher product was checked. */
	private static function truncation_note( bool $more ): string {
		/* translators: %d: number of products checked */
		return $more ? ' ' . sprintf( __( 'Only the first %d voucher products were checked.', 'voucher-captain' ), self::MAX_PRODUCTS ) : '';
	}


	/** Depth of Voucher Captain mails being sent right now (only their failures are recorded). */
	private static int $sending = 0;
	/** @var string[] Kind of the mail being sent (e.g. "Voucher to recipient"), innermost last. */
	private static array $kinds = array();
	/** @var int[] Voucher of the mail being sent (0: none), innermost last. */
	private static array $vouchers = array();

	/** Marks the start of a Voucher Captain mail (called by the VC email classes around WC_Email::send()). */
	public static function mail_begin( string $kind = '', int $voucher_id = 0 ): void {
		++self::$sending;
		self::$kinds[]    = $kind;
		self::$vouchers[] = $voucher_id;
	}

	public static function mail_end(): void {
		self::$sending = max( 0, self::$sending - 1 );
		array_pop( self::$kinds );
		array_pop( self::$vouchers );
	}

	/**
	 * Records a failed Voucher Captain mail – other mails of the site are not ours to report. No content and no
	 * personal data: recipients and any address in the error text are masked, instead of the subject (which can
	 * contain names) only the kind of mail is kept. The newest 20 of the last 7 days are kept.
	 */
	public function record_mail_failure( $error ): void {
		if ( ! $error instanceof \WP_Error || self::$sending <= 0 ) {
			return;
		}
		$data = (array) $error->get_error_data();
		$to   = array_map( 'strval', (array) ( $data['to'] ?? array() ) );
		$log  = self::mail_failures();
		$entry = array(
			'time'       => time(),
			'to'         => implode( ', ', array_map( array( Mask::class, 'email' ), $to ) ),
			'email'      => mb_substr( wp_strip_all_tags( (string) end( self::$kinds ) ), 0, 80 ),
			'error'      => Mask::addresses( mb_substr( wp_strip_all_tags( $error->get_error_message() ), 0, 300 ) ),
			// Which voucher the failed mail was for (D4), so support finds its history.
			'voucher_id' => (int) end( self::$vouchers ),
		);
		array_unshift( $log, $entry );
		update_option( self::MAIL_LOG, array_slice( $log, 0, self::LOG_SIZE ), false );
		Logger::error( 'Voucher email could not be sent: ' . $entry['error'], array( 'voucher_id' => $entry['voucher_id'], 'email' => $entry['email'] ) );
	}

	/** "Could not send to jane@example.com" → "Could not send to j***@example.com". Alias of Mask::addresses(). */
	public static function mask_addresses( string $text ): string {
		return Mask::addresses( $text );
	}

	/** @return array<int, array{time:int,to:string,email?:string,error:string}> recent failures, newest first. */
	public static function mail_failures(): array {
		$cutoff = time() - 7 * DAY_IN_SECONDS;
		return array_values( array_filter( (array) get_option( self::MAIL_LOG, array() ), static fn( $e ) => is_array( $e ) && (int) ( $e['time'] ?? 0 ) >= $cutoff ) );
	}

	/** Drops entries older than 7 days (daily maintenance), also when no mail failed since. */
	public static function prune_mail_failures(): void {
		$stored = (array) get_option( self::MAIL_LOG, array() );
		$kept   = array_slice( self::mail_failures(), 0, self::LOG_SIZE );
		if ( ! $kept ) {
			delete_option( self::MAIL_LOG );
		} elseif ( count( $kept ) !== count( $stored ) ) {
			update_option( self::MAIL_LOG, $kept, false );
		}
	}

	/** Privacy erasure: removes the entries that name this address (masked). @return bool whether entries were removed */
	public static function erase_mail_failures( string $email ): bool {
		$masked = strtolower( Mask::email( $email ) );
		$log    = self::mail_failures();
		$kept   = array_values( array_filter( $log, static fn( $e ) => ! str_contains( strtolower( (string) ( $e['to'] ?? '' ) . ' ' . (string) ( $e['error'] ?? '' ) ), $masked ) ) );
		if ( count( $kept ) === count( $log ) ) {
			return false;
		}
		update_option( self::MAIL_LOG, $kept, false );
		return true;
	}

	/** PHP extensions: mbstring and dom are needed for PDFs at all, gd for QR codes and barcodes, zip for imports. */
	/** Oldest supported MySQL and MariaDB (owner decision 16.11.2026, the same for all Captain plugins; LTS releases). */
	public const MIN_MYSQL   = '9.7';
	public const MIN_MARIADB = '12.3';

	/**
	 * Minimum database versions, changeable with the filter "vcap_minimum_db_versions" (e.g. on a host whose older
	 * server the operator has tested).
	 *
	 * @return array{mysql: string, mariadb: string}
	 */
	public static function minimum_db_versions(): array {
		$defaults = array(
			'mysql'   => self::MIN_MYSQL,
			'mariadb' => self::MIN_MARIADB,
		);
		/**
		 * Filters the oldest database server versions Voucher Captain reports as supported in Site Health.
		 *
		 * @since 1.2.0
		 * @param array{mysql: string, mariadb: string} $versions Minimum versions.
		 */
		$filtered = apply_filters( 'vcap_minimum_db_versions', $defaults );
		$out      = $defaults;
		foreach ( array_keys( $defaults ) as $key ) {
			if ( is_array( $filtered ) && isset( $filtered[ $key ] ) && is_scalar( $filtered[ $key ] ) && preg_match( '/^\d+(\.\d+){0,2}$/', (string) $filtered[ $key ] ) ) {
				$out[ $key ] = (string) $filtered[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Server type and version from a version string ("9.7.0", "12.3.1-MariaDB-ubu2404", "5.5.5-10.11.6-MariaDB-…"):
	 * MariaDB is detected by its name, the replication prefix "5.5.5-" of older MariaDB servers is skipped.
	 *
	 * @return array{type: string, version: string}|null Null when no version can be read.
	 */
	public static function parse_db_server( string $info ): ?array {
		$info    = trim( $info );
		$mariadb = false !== stripos( $info, 'mariadb' );
		if ( $mariadb ) {
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );
		}
		if ( ! preg_match( '/(\d+)\.(\d+)(?:\.(\d+))?/', $info, $m ) ) {
			return null;
		}
		return array(
			'type'    => $mariadb ? 'mariadb' : 'mysql',
			'version' => $m[1] . '.' . $m[2] . '.' . ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : '0' ),
		);
	}

	/**
	 * Database server: an older one than the supported minimum is only reported ("recommended") – nothing is blocked,
	 * bookings keep working.
	 *
	 * @param string|null $info Version string; null = the connected server.
	 */
	public function test_database( ?string $info = null ): array {
		if ( null === $info ) {
			global $wpdb;
			$info = is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
			if ( '' === $info && is_object( $wpdb ) ) {
				$info = (string) $wpdb->get_var( 'SELECT VERSION()' );
			}
		}
		$server  = self::parse_db_server( $info );
		$minimum = self::minimum_db_versions();
		if ( ! $server || ! version_compare( $server['version'], $minimum[ $server['type'] ], '<' ) ) {
			return self::result(
				'database',
				'good',
				__( 'The database server is supported', 'voucher-captain' ),
				/* translators: 1: minimum MySQL version, 2: minimum MariaDB version */
				sprintf( __( 'Voucher Captain supports MySQL %1$s or newer and MariaDB %2$s or newer.', 'voucher-captain' ), $minimum['mysql'], $minimum['mariadb'] )
			);
		}
		return self::result(
			'database',
			'recommended',
			__( 'The database server is older than the supported minimum', 'voucher-captain' ),
			/* translators: 1: minimum MySQL version, 2: minimum MariaDB version, 3: MySQL or MariaDB, 4: version of this server */
			sprintf( __( 'Voucher Captain supports MySQL %1$s or newer and MariaDB %2$s or newer; this site uses %3$s %4$s. The plugin keeps working and nothing is blocked, but older database servers are no longer tested. Ask your host for an update.', 'voucher-captain' ), $minimum['mysql'], $minimum['mariadb'], 'mariadb' === $server['type'] ? 'MariaDB' : 'MySQL', $server['version'] )
		);
	}

	public function test_extensions(): array {
		$required    = Install::missing_extensions( Install::REQUIRED_EXTENSIONS );
		$recommended = Install::missing_extensions( Install::RECOMMENDED_EXTENSIONS );
		if ( ! $required && ! $recommended ) {
			return self::result( 'extensions', 'good', __( 'The PHP extensions for vouchers are available', 'voucher-captain' ), __( 'mbstring, dom, gd, zip and iconv are loaded: PDFs with QR codes and barcodes and spreadsheet imports work.', 'voucher-captain' ) );
		}
		if ( $required ) {
			$label = __( 'PHP extensions for vouchers are missing', 'voucher-captain' );
			/* translators: %s: comma separated PHP extension names */
			return self::result( 'extensions', 'critical', $label, sprintf( __( 'Without the PHP extensions %s, voucher PDFs cannot be created and voucher emails fail. Ask your host to enable them.', 'voucher-captain' ), implode( ', ', array_merge( $required, $recommended ) ) ) );
		}
		$label = __( 'Some PHP extensions for vouchers are missing', 'voucher-captain' );
		/* translators: %s: comma separated PHP extension names */
		return self::result( 'extensions', 'recommended', $label, sprintf( __( 'Missing PHP extensions: %s. Without gd, voucher PDFs have no QR codes, barcodes or images (scanners cannot read them); without zip, Excel files cannot be imported. Ask your host to enable them.', 'voucher-captain' ), implode( ', ', $recommended ) ) );
	}

	public function test_schema(): array {
		$error = (string) get_option( Install::SCHEMA_ERROR_OPTION, '' );
		if ( '' === $error ) {
			return self::result( 'schema', 'good', __( 'The voucher database tables are complete', 'voucher-captain' ), __( 'All voucher tables, columns and indexes exist and support transactions.', 'voucher-captain' ) );
		}
		return self::result(
			'schema',
			'critical',
			__( 'The voucher database update is incomplete', 'voucher-captain' ),
			/* translators: %s: technical details */
			sprintf( __( 'Missing: %s. The update is retried automatically; check the database permissions.', 'voucher-captain' ), $error )
		);
	}

	public function test_scheduler(): array {
		$overdue = 0;
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$overdue = count(
				(array) as_get_scheduled_actions(
					array(
						'group'        => Delivery::GROUP,
						'status'       => \ActionScheduler_Store::STATUS_PENDING,
						'date'         => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						'date_compare' => '<=',
						'per_page'     => 100,
					),
					'ids'
				)
			);
		}
		$maintenance = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'vcap_daily_maintenance', array(), Delivery::GROUP );
		if ( ! $overdue && $maintenance ) {
			return self::result( 'scheduler', 'good', __( 'Voucher deliveries and daily maintenance are scheduled', 'voucher-captain' ), __( 'Scheduled vouchers, expiry reminders and the daily maintenance run through the Action Scheduler of WooCommerce.', 'voucher-captain' ) );
		}
		$text = $overdue
			/* translators: %d: number of overdue actions */
			? sprintf( _n( '%d scheduled voucher task has been waiting for more than an hour.', '%d scheduled voucher tasks have been waiting for more than an hour.', $overdue, 'voucher-captain' ), $overdue )
			: __( 'The daily voucher maintenance (expiry, reminders, delivery retries) is not scheduled.', 'voucher-captain' );
		return self::result( 'scheduler', 'recommended', __( 'Voucher tasks are not running on time', 'voucher-captain' ), $text . ' ' . __( 'Check that WP-Cron or a server cron job runs, and look at WooCommerce → Status → Scheduled Actions (group "vcap").', 'voucher-captain' ) );
	}

	public function test_mail(): array {
		$failures = self::mail_failures();
		if ( ! $failures ) {
			return self::result( 'mail', 'good', __( 'No failed emails recorded', 'voucher-captain' ), __( 'Voucher Captain records emails that WordPress could not send (last 7 days).', 'voucher-captain' ) );
		}
		$last = $failures[0];
		$text = sprintf(
			/* translators: 1: number of failed mails, 2: date of the last one, 3: error message */
			_n( '%1$d email could not be sent in the last 7 days (last on %2$s: %3$s).', '%1$d emails could not be sent in the last 7 days (last on %2$s: %3$s).', count( $failures ), 'voucher-captain' ),
			count( $failures ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ),
			(string) $last['error']
		);
		return self::result( 'mail', 'critical', __( 'Emails could not be sent', 'voucher-captain' ), $text . ' ' . __( 'Vouchers and tickets are delivered by email: configure an SMTP or mail service plugin and send a test mail.', 'voucher-captain' ) );
	}

	/**
	 * M12-43: whether a file of the private folder can be fetched from the web. A probe file is written to the
	 * temp folder and requested over HTTP: status 200 with its content means the folder is open (nginx ignores
	 * .htaccess), which is critical. Returns 'open', 'closed' or 'unknown' (no request possible).
	 */
	public static function probe_private_dir(): string {
		if ( ! Install::ensure_upload_dir() ) {
			return 'unknown';
		}
		$name  = 'probe-' . wp_generate_password( 20, false ) . '.txt';
		$token = wp_generate_password( 32, false );
		$file  = Install::upload_dir() . '/tmp/' . $name;
		if ( false === @file_put_contents( $file, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors -- reported as unknown.
			return 'unknown';
		}
		$uploads = wp_upload_dir( null, false );
		$url     = trailingslashit( $uploads['baseurl'] ) . Install::private_dir_name() . '/tmp/' . $name;
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => false, // Loopback to the own site; only the reachability matters.
				)
			);
		} finally {
			wp_delete_file( $file );
		}
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return 200 === $code && str_contains( (string) wp_remote_retrieve_body( $response ), $token ) ? 'open' : ( $code > 0 ? 'closed' : 'unknown' );
	}

	/**
	 * M12-84: voucher emails sent "from" another domain than the shop's without an SMTP / mail service plugin are
	 * often filtered as spam (SPF/DKIM do not match).
	 */
	public function test_sender_domain(): array {
		$from   = strtolower( trim( (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) ) );
		$domain = (string) substr( (string) strrchr( $from, '@' ), 1 );
		$site   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$site   = (string) preg_replace( '/^www\./', '', $site );
		$same   = '' === $domain || '' === $site || $domain === $site || str_ends_with( $site, '.' . $domain ) || str_ends_with( $domain, '.' . $site );
		if ( $same || self::smtp_plugin() ) {
			return self::result( 'sender_domain', 'good', __( 'The sender address of the emails fits the shop', 'voucher-captain' ), __( 'Voucher emails are sent from an address of the shop\'s domain or through a mail service plugin.', 'voucher-captain' ) );
		}
		return self::result(
			'sender_domain',
			'recommended',
			__( 'Voucher emails are sent from another domain', 'voucher-captain' ),
			sprintf(
				/* translators: 1: sender domain, 2: shop domain */
				__( 'The emails are sent from an address at %1$s, but the shop runs on %2$s, and no SMTP or mail service plugin was found. Such emails often land in spam: use a sender address of the shop\'s domain (WooCommerce → Settings → Emails) or set up an SMTP plugin.', 'voucher-captain' ),
				$domain,
				$site
			)
		);
	}

	/** M12-84: whether a known SMTP / mail service plugin (or any phpmailer_init / pre_wp_mail handler) sends the mails. */
	public static function smtp_plugin(): bool {
		$known = defined( 'WPMS_PLUGIN_VER' ) || defined( 'POST_SMTP_VER' ) || defined( 'FLUENTMAIL' ) || defined( 'EASY_WP_SMTP_PLUGIN_VERSION' ) || class_exists( '\WPMailSMTP\Core' ) || class_exists( '\FluentMail\App\Hooks\Handlers\ActionHandler' );
		/** Whether the site sends its mails through an SMTP or mail service plugin (Site Health sender check). */
		return (bool) apply_filters( 'vcap_smtp_plugin_active', $known || has_action( 'phpmailer_init' ) );
	}

	/** M12-52: checkouts the daily voucher limit refused in the last 30 days (VoucherPurchaseRules::record_refusal()). */
	public function test_purchase_limits(): array {
		$refused = VoucherPurchaseRules::refusals( 30 );
		if ( ! $refused ) {
			return self::result( 'purchase_limits', 'good', __( 'No purchases refused by the daily voucher limit', 'voucher-captain' ), __( 'Voucher Captain counts the checkouts that the maximum voucher value per customer and 24 hours refused (last 30 days).', 'voucher-captain' ) );
		}
		return self::result(
			'purchase_limits',
			'recommended',
			__( 'Purchases were refused by the daily voucher limit', 'voucher-captain' ),
			sprintf(
				/* translators: 1: number of refused checkouts, 2: limit */
				_n( '%1$d checkout was refused in the last 30 days because the vouchers exceeded the limit of %2$s per customer and 24 hours. If regular customers are affected, raise the limit in the voucher settings.', '%1$d checkouts were refused in the last 30 days because the vouchers exceeded the limit of %2$s per customer and 24 hours. If regular customers are affected, raise the limit in the voucher settings.', $refused, 'voucher-captain' ),
				$refused,
				wp_strip_all_tags( wc_price( (float) Settings::value( 'vcap_customer_daily_limit' ) ) )
			)
		);
	}

	public function test_upload_dir(): array {
		$error = (string) get_option( 'vcap_upload_dir_error', '' );
		if ( '' === $error ) {
			$probe = self::probe_private_dir();
			if ( 'open' === $probe ) {
				return self::result(
					'upload_dir',
					'critical',
					__( 'The private voucher folder can be opened from the web', 'voucher-captain' ),
					/* translators: %s: folder path */
					sprintf( __( 'A test file in %s could be downloaded. The web server ignores the .htaccess protection (e.g. nginx): voucher PDFs and temp files could be fetched by anyone who knows their address. Block the folder in the server configuration (see the nginx snippet in COMPATIBILITY.md).', 'voucher-captain' ), Install::upload_dir() )
				);
			}
			if ( 'unknown' === $probe ) {
				return self::result( 'upload_dir', 'recommended', __( 'The protection of the private voucher folder could not be tested', 'voucher-captain' ), __( 'The site could not request a test file from itself. Make sure the web server blocks the private folder (see the nginx snippet in COMPATIBILITY.md).', 'voucher-captain' ) );
			}
			return self::result( 'upload_dir', 'good', __( 'The private voucher folder is protected', 'voucher-captain' ), __( 'PDF vouchers and fonts are stored in a folder that cannot be opened from the web (a test file could not be downloaded).', 'voucher-captain' ) );
		}
		return self::result(
			'upload_dir',
			'critical',
			__( 'The private voucher folder is missing or unprotected', 'voucher-captain' ),
			/* translators: %s: folder path */
			sprintf( __( 'Voucher Captain could not create or protect %s. Check the folder permissions.', 'voucher-captain' ), $error )
		);
	}

	/** Template images the PDFs cannot read (offloaded media, CDN, missing files) or draw (AVIF, HEIC, …). */
	public function test_template_images(): array {
		$problems = array();
		$ids      = get_posts( array( 'post_type' => TemplatePostType::POST_TYPE, 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 100, 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			foreach ( TemplatePostType::design_warnings( TemplatePostType::get_design( $id ), true ) as $warning ) {
				$problems[] = get_the_title( $id ) . ': ' . $warning;
			}
		}
		if ( ! $problems ) {
			return self::result( 'template_images', 'good', __( 'The PDF template images can be read', 'voucher-captain' ), __( 'Every image of the published PDF templates is a readable JPEG, PNG or GIF file on this server.', 'voucher-captain' ) );
		}
		return self::result( 'template_images', 'recommended', __( 'PDF template images are missing from voucher PDFs', 'voucher-captain' ), implode( ' ', array_slice( $problems, 0, 10 ) ) );
	}

	/** AP-W1-2: theme copies of the plugin templates without @version or older than the plugin's template. */
	public function test_template_overrides(): array {
		$outdated = array_values( array_filter( Plugin::template_overrides(), static fn( array $o ): bool => $o['outdated'] ) );
		if ( ! $outdated ) {
			return self::result( 'template_overrides', 'good', __( 'The theme copies of the voucher templates are up to date', 'voucher-captain' ), __( 'The theme overrides no voucher template, or only with copies of the current version.', 'voucher-captain' ) );
		}
		$list = implode(
			', ',
			array_map(
				/* translators: 1: template file, 2: version of the theme copy, 3: current version */
				static fn( array $o ): string => sprintf( __( '%1$s (version %2$s, current %3$s)', 'voucher-captain' ), $o['file'], '' !== $o['version'] ? $o['version'] : '–', $o['core_version'] ),
				$outdated
			)
		);
		return self::result(
			'template_overrides',
			'recommended',
			__( 'Your theme contains outdated copies of voucher templates', 'voucher-captain' ),
			/* translators: %s: list of template files with versions */
			sprintf( __( 'Compare these files in yourtheme/woocommerce/voucher-captain/ with the plugin\'s templates and update them, otherwise later fixes (such as the one-time key of the check-in form) are missing: %s', 'voucher-captain' ), $list )
		);
	}

	public function test_redeem_page(): array {
		/**
		 * Whether the shop needs the voucher redeem page (an integration that exchanges vouchers there, e.g. ticket
		 * vouchers, says so). Default false: then the check passes.
		 */
		$needed  = (bool) apply_filters( 'vcap_redeem_page_needed', false );
		$problem = $needed ? Shortcodes::redeem_page_problem() : '';
		if ( '' === $problem ) {
			return self::result( 'redeem_page', 'good', __( 'The voucher redeem page is set up', 'voucher-captain' ), __( 'Voucher emails and ticket vouchers link to a page with the redeem form.', 'voucher-captain' ) );
		}
		return self::result( 'redeem_page', 'recommended', __( 'The voucher redeem page needs attention', 'voucher-captain' ), $problem );
	}

	/** EU countries the shop sells to: verified voucher validity, country conditions and a translation for each. */
	public function test_markets(): array {
		$gaps = array();
		foreach ( Markets::readiness() as $country => $state ) {
			$missing = array();
			if ( ! $state['validity'] ) {
				$missing[] = __( 'statutory validity not verified (3 years to the end of the year is used)', 'voucher-captain' );
			}
			if ( ! $state['terms'] ) {
				$missing[] = __( 'no redemption conditions', 'voucher-captain' );
			}
			if ( ! $state['translation'] ) {
				/* translators: %s: locale, e.g. fr_FR */
				$missing[] = sprintf( __( 'no translation (%s)', 'voucher-captain' ), $state['locale'] );
			}
			if ( $missing ) {
				$gaps[] = $country . ': ' . implode( ', ', $missing );
			}
		}
		if ( ! $gaps ) {
			return self::result( 'markets', 'good', __( 'Voucher rules are set for every EU country you sell to', 'voucher-captain' ), __( 'Each selling country has a verified voucher validity, redemption conditions and a translation.', 'voucher-captain' ) );
		}
		return self::result(
			'markets',
			'recommended',
			__( 'Some EU selling countries need voucher settings', 'voucher-captain' ),
			/* translators: %s: list of countries with what is missing */
			sprintf( __( 'Set the statutory validity and conditions per country under Vouchers → Settings (have them checked legally) and add translations: %s', 'voucher-captain' ), implode( '; ', $gaps ) )
		);
	}

	/**
	 * Cash on delivery for virtual products: such voucher orders are "Processing" without payment, so their
	 * vouchers wait until the order is completed (Issuer::payment_ok()). Worth knowing, or switching off.
	 */
	public function test_offline_payment(): array {
		if ( ! Issuer::cod_for_virtual() ) {
			return self::result( 'offline_payment', 'good', __( 'Vouchers are not sold with cash on delivery', 'voucher-captain' ), __( 'Cash on delivery is not offered for virtual products. Orders paid by bank transfer or cheque issue vouchers once the payment is confirmed.', 'voucher-captain' ) );
		}
		return self::result( 'offline_payment', 'recommended', __( 'Cash on delivery is offered for virtual products', 'voucher-captain' ), __( 'Voucher orders paid by cash on delivery are "Processing" before any money arrived. Their vouchers are issued only when the order is completed (or the payment is recorded), so the customer waits. Switch off "Accept for virtual orders" under WooCommerce → Settings → Payments → Cash on delivery if vouchers should only be sold with immediate payment.', 'voucher-captain' ) );
	}

	/**
	 * Foreign currency without converter: a money operation was refused for want of an exchange rate (critical),
	 * or a currency switcher is active that no adapter (and no `vcap_amount_to_base_currency` callback) converts.
	 */
	public function test_currency(): array {
		/**
		 * Currency conversion state for Site Health, reported by the currency integration: "unknown" = currencies
		 * without exchange rate in the last 30 days (code => time), "switcher" = name of an active currency switcher
		 * nothing converts ('' = none).
		 */
		$state   = (array) apply_filters( 'vcap_currency_health', array( 'unknown' => array(), 'switcher' => '' ) );
		$unknown = (array) ( $state['unknown'] ?? array() );
		$advice  = __( 'Vouchers and gift card balances are kept in the shop base currency. Use a supported currency switcher (WooCommerce Multilingual, Aelia, WOOCS, CURCY, WooPayments Multi-Currency) or convert amounts with the filter "vcap_amount_to_base_currency".', 'voucher-captain' );
		$foreign = self::foreign_currency_vouchers();
		if ( $foreign ) {
			$list = array();
			foreach ( $foreign as $currency => $count ) {
				$list[] = $currency . ' (' . (int) $count . ')';
			}
			return self::result(
				'currency',
				'critical',
				__( 'Vouchers kept in another currency than the shop base currency', 'voucher-captain' ),
				sprintf(
					/* translators: 1: shop base currency, 2: currencies with the number of vouchers */
					__( 'The shop base currency is %1$s, but usable fixed-value vouchers are kept in %2$s (the base currency was changed after they were issued). They are refused in the cart and over the API until their value is converted (edit the balance or reissue them).', 'voucher-captain' ),
					(string) get_option( 'woocommerce_currency' ),
					implode( ', ', $list )
				)
			);
		}
		if ( $unknown ) {
			return self::result(
				'currency',
				'critical',
				__( 'Foreign-currency amounts could not be converted', 'voucher-captain' ),
				/* translators: %s: currency codes */
				sprintf( __( 'No exchange rate to the shop base currency was known for %s in the last 30 days: voucher sales in that currency were held, store-credit refunds and fixed-value vouchers were refused.', 'voucher-captain' ), implode( ', ', array_keys( $unknown ) ) ) . ' ' . $advice
			);
		}
		$switcher = (string) ( $state['switcher'] ?? '' );
		if ( '' !== $switcher ) {
			return self::result(
				'currency',
				'recommended',
				__( 'Foreign currency without converter', 'voucher-captain' ),
				/* translators: %s: plugin name */
				sprintf( __( 'Voucher Captain cannot read the exchange rates of %s. Orders in other currencies cannot issue vouchers or be charged to gift cards until a rate is known.', 'voucher-captain' ), $switcher ) . ' ' . $advice
			);
		}
		return self::result( 'currency', 'good', __( 'Voucher amounts are converted to the shop base currency', 'voucher-captain' ), __( 'Either all orders are in the base currency or a supported currency switcher provides the exchange rates.', 'voucher-captain' ) );
	}

	/**
	 * M12-33: usable fixed-value vouchers whose currency is not the shop base currency.
	 *
	 * @return array<string, int> currency => number of vouchers
	 */
	public static function foreign_currency_vouchers(): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}
		$table = Install::tables()['vouchers'];
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT currency, COUNT(*) AS n FROM %i WHERE status IN ('active','scheduled','reserved') AND discount_type <> 'percent' AND type <> 'ticket' AND currency <> '' AND currency <> %s GROUP BY currency ORDER BY n DESC LIMIT 10",
				$table,
				(string) get_option( 'woocommerce_currency' )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['currency'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** Voucher products whose validity ends before the statutory limitation period in the shop or a selling country. */
	public function test_validity(): array {
		list( $ids, $more ) = self::voucher_products( array( 'product' ) );
		$selling = array_keys( Markets::readiness() ); // Once for all products.
		$names   = array();
		foreach ( $ids as $id ) {
			$short = ProductConfig::short_validity( $id, $selling );
			if ( $short ) {
				$names[] = get_the_title( $id ) . ' (' . implode( ', ', array_keys( $short ) ) . ')';
			}
		}
		if ( ! $names ) {
			return self::result( 'validity', 'good', __( 'Voucher validity is not shorter than the statutory period', 'voucher-captain' ), __( 'No voucher product ends before the statutory limitation period of the shop country or an EU selling country.', 'voucher-captain' ) . self::truncation_note( $more ) );
		}
		return self::result(
			'validity',
			'recommended',
			__( 'Some vouchers are valid for less than the statutory period', 'voucher-captain' ),
			/* translators: %s: products with the countries concerned */
			sprintf( __( 'A validity shorter than the statutory limitation period is often invalid in voucher conditions. Check legally or switch to the statutory validity: %s', 'voucher-captain' ), implode( '; ', $names ) ) . self::truncation_note( $more )
		);
	}

	/** Seconds the ledger check of Site Health may read (the rest is left to "wp vcap ledger verify"). */
	private const LEDGER_BUDGET = 10.0;

	/**
	 * M12-63: stored balances that differ from their ledger (Ledger::reconcile(); each one is also listed in the
	 * Attention notice until it matches again).
	 */
	public function test_ledger(): array {
		$result = Ledger::reconcile( self::LEDGER_BUDGET );
		$count  = count( $result['rows'] );
		$more   = $result['complete'] ? '' : ' ' . __( 'Not every voucher could be checked in time; run "wp vcap ledger verify" for the complete check.', 'voucher-captain' );
		if ( ! $count ) {
			return self::result( 'ledger', 'good', __( 'Voucher balances match their history', 'voucher-captain' ), __( 'The balance of every gift card and store credit account equals the balance after its last booking in the history.', 'voucher-captain' ) . $more );
		}
		$codes = array_map( static fn( $r ) => $r['code'], array_slice( $result['rows'], 0, 20 ) );
		return self::result(
			'ledger',
			'critical',
			__( 'Some voucher balances differ from their history', 'voucher-captain' ),
			sprintf(
				/* translators: 1: number of vouchers, 2: voucher codes */
				_n( '%1$d voucher has a stored balance that differs from the balance after its last booking (written without a booking). The liability report reads the history, so it no longer matches the card. Check and correct: %2$s', '%1$d vouchers have a stored balance that differs from the balance after their last booking (written without a booking). The liability report reads the history, so it no longer matches the cards. Check and correct: %2$s', $count, 'voucher-captain' ),
				$count,
				implode( ', ', $codes )
			) . $more
		);
	}

	/** Voucher products whose tax status does not fit the voucher kind (multi-purpose: none, single-purpose: taxable). */
	public function test_tax_status(): array {
		list( $ids, $more ) = self::voucher_products( array( 'product', 'product_variation' ) );
		$names   = array();
		$untaxed = array();
		$single  = array();
		$checked = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$items   = $product ? array_merge( array( $product ), $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array() ) : array();
			foreach ( $items as $item ) {
				// A variation is found on its own (own voucher setting) and as a child of its product: check it once.
				if ( isset( $checked[ $item->get_id() ] ) ) {
					continue;
				}
				$checked[ $item->get_id() ] = true;
				$conflict                   = ProductConfig::tax_status_conflict( $item );
				if ( $conflict && ! empty( $conflict['untaxed'] ) ) {
					$untaxed[] = $item->get_name();
				} elseif ( $conflict ) {
					/* translators: 1: product name, 2: expected tax status ("none" or "taxable") */
					$names[] = sprintf( __( '%1$s (tax status should be "%2$s")', 'voucher-captain' ), $item->get_name(), 'none' === $conflict['expected'] ? __( 'None', 'voucher-captain' ) : __( 'Taxable', 'voucher-captain' ) );
				}
				if ( '' !== ProductConfig::single_purpose_warning( $item ) ) {
					$single[] = $item->get_name();
				}
			}
		}
		if ( $untaxed ) {
			// M12-03: multi-purpose vouchers sold without VAT while they are redeemed as a price reduction.
			return self::result(
				'tax_status',
				'critical',
				__( 'Multi-purpose vouchers are never taxed', 'voucher-captain' ),
				/* translators: %s: list of products */
				sprintf( __( 'These voucher products sell multi-purpose vouchers without VAT (tax status "None"), but the setting "Multi-purpose vouchers as payment" is off: the vouchers are redeemed as a price reduction that lowers the VAT, so their value is taxed neither at the sale nor at the redemption. Turn on "Multi-purpose vouchers as payment" or make the products taxable, and check with your tax adviser: %s', 'voucher-captain' ), implode( '; ', $untaxed ) ) . self::truncation_note( $more )
			);
		}
		if ( $single && ! $names ) {
			// M12-28: single-purpose vouchers in a shop that sells to several countries.
			return self::result(
				'tax_status',
				'recommended',
				__( 'Single-purpose vouchers in a shop that sells to several countries', 'voucher-captain' ),
				/* translators: %s: list of products */
				sprintf( __( 'A single-purpose voucher needs the place of supply and the VAT to be fixed at the sale. Your shop sells to several countries, so the country of the later redemption is open: check with your tax adviser whether these products are single-purpose vouchers. Vouchers are only redeemed for goods with the VAT rate and country of their sale: %s', 'voucher-captain' ), implode( '; ', $single ) ) . self::truncation_note( $more )
			);
		}
		if ( ! $names ) {
			return self::result( 'tax_status', 'good', __( 'Voucher products have a tax status that fits their VAT treatment', 'voucher-captain' ), __( 'Multi-purpose vouchers are sold without VAT, single-purpose vouchers with VAT.', 'voucher-captain' ) . self::truncation_note( $more ) );
		}
		return self::result(
			'tax_status',
			'recommended',
			__( 'Some voucher products have a tax status that does not fit their VAT treatment', 'voucher-captain' ),
			/* translators: %s: list of products */
			sprintf( __( 'Multi-purpose vouchers are sold without VAT (tax status "None"), single-purpose vouchers with VAT ("Taxable"). Check with your tax adviser and correct: %s', 'voucher-captain' ), implode( '; ', $names ) ) . self::truncation_note( $more )
		);
	}

	/** Where a Site Health result sends the admin to fix it (D10): the place of the problem, not always the settings. */
	public static function action_link( string $id ): string {
		switch ( $id ) {
			case 'scheduler':
				return '<p><a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&status=pending&s=vcap' ) ) . '">' . esc_html__( 'Scheduled voucher tasks', 'voucher-captain' ) . '</a></p>';
			case 'mail':
			case 'database':
			case 'template_overrides':
			case 'ledger':
				return '';
		}
		return '<p><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=vcap' ) ) . '">' . esc_html__( 'Voucher settings', 'voucher-captain' ) . '</a></p>';
	}

	private static function result( string $id, string $status, string $label, string $text ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'Vouchers', 'voucher-captain' ), 'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ) ),
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'actions'     => 'good' === $status ? '' : self::action_link( $id ),
			'test'        => 'vcap_' . $id,
		);
	}
}
