<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk code generation for partner and campaign packages.
 *
 * - "coupons": N WooCommerce coupons that copy all settings of a template coupon (discount, restrictions,
 *   expiry, usage limits) and are tagged with a campaign name for export.
 * - "pool": N codes in an import batch, used when voucher products are sold (Product → Voucher → Code source).
 */
final class Campaigns {
	public const META    = '_vcap_campaign';
	public const MAX_RUN = 1000;

	public function __construct( private CodePool $pool, private VoucherRepository $vouchers ) {}

	public static function clean_name( string $name ): string {
		return substr( trim( sanitize_text_field( $name ) ), 0, 100 );
	}

	private function code_options( array $opts ): array {
		return array(
			'prefix'    => (string) ( $opts['prefix'] ?? '' ),
			'suffix'    => (string) ( $opts['suffix'] ?? '' ),
			'length'    => max( 6, min( 40, (int) ( $opts['length'] ?? 12 ) ) ),
			'charset'   => array_key_exists( (string) ( $opts['charset'] ?? '' ), CodeGenerator::CHARSETS ) ? (string) $opts['charset'] : 'alnum_upper',
			'separator' => (string) ( $opts['separator'] ?? '-' ),
		);
	}

	/** Whether a voucher, a code pool entry or a coupon uses the code (B4: indexed own tables first, see CodeIndex). */
	public function taken( string $code ): bool {
		return CodeIndex::taken( $code );
	}

	/** Codes made per step of a background run (and the largest run done right away in the admin request). */
	public const STEP = 100;
	/** Job type of background code generation (Jobs). */
	public const JOB = 'generate';

	/**
	 * Checks a coupon run: the template's data to copy, or an error.
	 *
	 * @return array|\WP_Error template data (without id, code, usage and meta)
	 */
	private function coupon_template( int $template_id, string $campaign, int $count ) {
		$template = new \WC_Coupon( $template_id );
		if ( ! $template->get_id() || 'shop_coupon' !== get_post_type( $template_id ) ) {
			return new \WP_Error( 'vcap_campaign_template', __( 'Please choose an existing coupon as template.', 'voucher-captain' ) );
		}
		if ( 'yes' === $template->get_meta( '_vcap_voucher' ) ) {
			return new \WP_Error( 'vcap_campaign_template', __( 'Voucher coupons cannot be used as a template. Please choose a regular coupon.', 'voucher-captain' ) );
		}
		if ( '' === $campaign || $count < 1 || $count > self::MAX_RUN ) {
			/* translators: %d: maximum number of codes */
			return new \WP_Error( 'vcap_campaign_input', sprintf( __( 'Please enter a campaign name and between 1 and %d codes.', 'voucher-captain' ), self::MAX_RUN ) );
		}
		$data = $template->get_data();
		foreach ( array( 'id', 'code', 'usage_count', 'used_by', 'date_created', 'date_modified', 'meta_data' ) as $key ) {
			unset( $data[ $key ] );
		}
		return $data;
	}

	/** Code options of a coupon run: codes that carry a discount get the minimum guessing space. */
	private function coupon_options( int $template_id, array $opts ): array {
		$template = new \WC_Coupon( $template_id );
		$options  = $this->code_options( $opts );
		if ( (float) $template->get_amount() > 0 || $template->get_free_shipping() ) {
			// Each code carries a discount and is valid on its own: as hard to guess as voucher codes, so the
			// rate limits make finding one of a thousand codes hopeless (6 characters would give about 2^30).
			$options['min_bits'] = (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS );
		}
		return $options;
	}

	/** Code options of a code pool run: pool codes become vouchers with a value when sold. */
	private function pool_options( array $opts ): array {
		$options             = $this->code_options( $opts );
		$options['min_bits'] = (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS );
		return $options;
	}

	/**
	 * Create coupons from a template coupon.
	 *
	 * @return string[]|\WP_Error generated codes
	 */
	public function generate_coupons( int $template_id, string $campaign, int $count, array $opts ) {
		$campaign = self::clean_name( $campaign );
		$data     = $this->coupon_template( $template_id, $campaign, $count );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->create_coupons( $data, $campaign, $count, $this->coupon_options( $template_id, $opts ) );
	}

	/**
	 * Saves $count coupons with the template data; the codes are checked in batches (CodeIndex::unique_many()).
	 *
	 * @return string[] codes of the saved coupons
	 */
	private function create_coupons( array $data, string $campaign, int $count, array $options ): array {
		$codes = array();
		foreach ( CodeIndex::unique_many( $options, $count ) as $code ) {
			$coupon = new \WC_Coupon();
			$coupon->set_props( $data );
			$coupon->set_code( $code );
			/* translators: %s: campaign name */
			$coupon->set_description( sprintf( __( 'Campaign: %s', 'voucher-captain' ), $campaign ) );
			$coupon->update_meta_data( self::META, $campaign );
			$coupon->save();
			if ( $coupon->get_id() ) {
				$codes[] = $code;
			}
		}
		return $codes;
	}

	/**
	 * Create codes in a code pool batch.
	 *
	 * @return string[]|\WP_Error generated codes
	 */
	public function generate_pool( string $batch, int $count, array $opts ) {
		$batch = self::clean_name( $batch );
		if ( '' === $batch || $count < 1 || $count > self::MAX_RUN ) {
			/* translators: %d: maximum number of codes */
			return new \WP_Error( 'vcap_campaign_input', sprintf( __( 'Please enter a batch name and between 1 and %d codes.', 'voucher-captain' ), self::MAX_RUN ) );
		}
		return $this->create_pool( $batch, $count, $this->pool_options( $opts ) );
	}

	/** @return string[] codes added to the batch */
	private function create_pool( string $batch, int $count, array $options ): array {
		$codes = array();
		foreach ( CodeIndex::unique_many( $options, $count ) as $code ) {
			if ( $this->pool->add( $code, $batch ) ) {
				$codes[] = $code;
			}
		}
		return $codes;
	}

	/**
	 * Generates codes in the background (B4): checks the input like generate_coupons()/generate_pool(), then starts a
	 * job that makes STEP codes per step (Jobs). Refused while the user's previous run is still going.
	 *
	 * @return true|\WP_Error
	 */
	public function queue( string $target, int $template_id, string $name, int $count, array $opts, int $user_id ) {
		$name = self::clean_name( $name );
		if ( 'pool' === $target ) {
			if ( '' === $name || $count < 1 || $count > self::MAX_RUN ) {
				/* translators: %d: maximum number of codes */
				return new \WP_Error( 'vcap_campaign_input', sprintf( __( 'Please enter a batch name and between 1 and %d codes.', 'voucher-captain' ), self::MAX_RUN ) );
			}
		} else {
			$data = $this->coupon_template( $template_id, $name, $count );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}
		$started = Jobs::start(
			Jobs::id( self::JOB, $user_id ),
			array(
				'type'   => self::JOB,
				'total'  => $count,
				'params' => array(
					'target'   => 'pool' === $target ? 'pool' : 'coupons',
					'template' => $template_id,
					'name'     => $name,
					'opts'     => $opts,
				),
			)
		);
		return $started ? true : new \WP_Error( 'vcap_campaign_running', __( 'Your previous code generation is still running. Please wait until it is finished.', 'voucher-captain' ) );
	}

	/**
	 * One step of a background run (Jobs handler): up to STEP codes. The progress is saved per step, so a run cut off
	 * in the middle of a step may make up to STEP codes more than requested, never fewer.
	 */
	public function job_step( array $job ): array {
		$p    = (array) $job['params'];
		$want = min( self::STEP, (int) $job['total'] - (int) $job['done'] );
		if ( $want > 0 ) {
			if ( 'pool' === $p['target'] ) {
				$codes = $this->create_pool( (string) $p['name'], $want, $this->pool_options( (array) $p['opts'] ) );
			} else {
				$data = $this->coupon_template( (int) $p['template'], (string) $p['name'], $want );
				if ( is_wp_error( $data ) ) {
					$job['status'] = 'failed';
					$job['error']  = $data->get_error_message();
					return $job;
				}
				$codes = $this->create_coupons( $data, (string) $p['name'], $want, $this->coupon_options( (int) $p['template'], (array) $p['opts'] ) );
			}
			if ( ! $codes ) {
				$job['status'] = 'failed';
				$job['error']  = __( 'No further codes could be saved.', 'voucher-captain' );
				return $job;
			}
			$job['done'] = (int) $job['done'] + count( $codes );
		}
		if ( (int) $job['done'] >= (int) $job['total'] ) {
			$job['status'] = 'done';
		}
		return $job;
	}

	/** @return array<int, array{campaign:string,total:int}> */
	public function campaigns(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS campaign, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY meta_value", self::META ), ARRAY_A ); // phpcs:ignore
		return array_map( static fn( $r ) => array( 'campaign' => (string) $r['campaign'], 'total' => (int) $r['total'] ), $rows ? $rows : array() );
	}

	/**
	 * Export rows for a campaign of coupons (header first), read in chunks of 500 coupons whose posts and meta are
	 * loaded together, so campaigns of any size neither run one query per field nor keep every coupon in memory.
	 *
	 * @return \Generator<int, array<int, string|int|float>>
	 */
	public function campaign_rows( string $campaign ): \Generator {
		global $wpdb;
		yield array( __( 'Code', 'voucher-captain' ), __( 'Discount type', 'voucher-captain' ), __( 'Amount', 'voucher-captain' ), __( 'Expires', 'voucher-captain' ), __( 'Uses', 'voucher-captain' ), __( 'Usage limit', 'voucher-captain' ), __( 'Status', 'voucher-captain' ) );
		$types    = wc_get_coupon_types();
		$statuses = get_post_statuses();
		$after    = 0;
		do {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
						WHERE p.post_type = 'shop_coupon' AND p.post_status IN ('publish','draft','pending','private','future') AND p.ID > %d
						GROUP BY p.ID ORDER BY p.ID ASC LIMIT 500",
						self::META,
						$campaign,
						$after
					)
				)
			);
			if ( ! $ids ) {
				break;
			}
			$after = (int) end( $ids );
			_prime_post_caches( $ids, false, true );
			foreach ( $ids as $id ) {
				$c      = new \WC_Coupon( $id );
				$status = (string) get_post_status( $id );
				yield array(
					$c->get_code(),
					$types[ $c->get_discount_type() ] ?? $c->get_discount_type(),
					Csv::number( $c->get_amount(), wc_get_price_decimals() ),
					$c->get_date_expires() ? $c->get_date_expires()->date_i18n( 'Y-m-d' ) : '',
					(int) $c->get_usage_count(),
					(int) $c->get_usage_limit(),
					$statuses[ $status ] ?? $status,
				);
			}
			Ledger::flush_runtime_cache(); // Products and orders read for the rows are not kept for the whole export.
			$more = count( $ids ) === 500;
		} while ( $more );
	}

	/**
	 * Export rows for a code pool batch (header first), read by id in batches of POOL_EXPORT_BATCH (AP-P1-2: a pool
	 * of hundreds of thousands of codes is never held in memory at once).
	 *
	 * @return \Generator<int, array<int, string>>
	 */
	public function pool_rows( string $batch, int $limit = self::POOL_EXPORT_BATCH ): \Generator {
		global $wpdb;
		$table = Install::tables()['pool'];
		$v     = Install::tables()['vouchers'];
		$limit = max( 1, $limit );
		yield array( __( 'Code', 'voucher-captain' ), __( 'Status', 'voucher-captain' ), __( 'Used on', 'voucher-captain' ), __( 'Order number', 'voucher-captain' ), __( 'Order ID', 'voucher-captain' ) );
		$after = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.code, p.used_at, v.order_id FROM {$table} p LEFT JOIN {$v} v ON v.id = p.voucher_id WHERE p.batch = %s AND p.id > %d ORDER BY p.id LIMIT %d", $batch, $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$got = count( $rows );
			if ( ! $got ) {
				break;
			}
			$after = (int) end( $rows )['id'];
			Format::prime_order_numbers( array_column( $rows, 'order_id' ) );
			foreach ( $rows as $r ) {
				yield array( (string) $r['code'], $r['used_at'] ? __( 'Used', 'voucher-captain' ) : __( 'Free', 'voucher-captain' ), Csv::date( $r['used_at'] ), Format::order_number( $r['order_id'] ), $r['order_id'] ? (string) $r['order_id'] : '' );
			}
			Ledger::flush_runtime_cache();
		} while ( $got === $limit );
	}

	/** Pool codes read per query of the pool export. */
	public const POOL_EXPORT_BATCH = 1000;

	/**
	 * Stream rows as CSV (see Csv: UTF-8 BOM, separator and decimal comma per locale, formula injection neutralised).
	 *
	 * @param iterable<array> $rows Header row first.
	 */
	public static function send_csv( string $filename, iterable $rows ): void {
		Csv::send( $filename, $rows );
	}

	public static function safe_row( array $row ): array {
		return Csv::safe_row( $row );
	}
}
