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

	public function taken( string $code ): bool {
		return $this->pool->exists( $code ) || $this->vouchers->code_exists( $code ) || wc_get_coupon_id_by_code( $code ) > 0;
	}

	/**
	 * Create coupons from a template coupon.
	 *
	 * @return string[]|\WP_Error generated codes
	 */
	public function generate_coupons( int $template_id, string $campaign, int $count, array $opts ) {
		$template = new \WC_Coupon( $template_id );
		$campaign = self::clean_name( $campaign );
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
		$options = $this->code_options( $opts );
		$codes   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$code   = CodeGenerator::unique( $options, array( $this, 'taken' ) );
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
		// Pool codes become vouchers with a value when sold: as hard to guess as generated voucher codes.
		$options             = $this->code_options( $opts );
		$options['min_bits'] = (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS );
		$codes               = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$code = CodeGenerator::unique( $options, array( $this, 'taken' ) );
			if ( $this->pool->add( $code, $batch ) ) {
				$codes[] = $code;
			}
		}
		return $codes;
	}

	/** @return array<int, array{campaign:string,total:int}> */
	public function campaigns(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS campaign, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY meta_value", self::META ), ARRAY_A ); // phpcs:ignore
		return array_map( static fn( $r ) => array( 'campaign' => (string) $r['campaign'], 'total' => (int) $r['total'] ), $rows ? $rows : array() );
	}

	/**
	 * Export rows for a campaign of coupons.
	 *
	 * @return array<int, array<int, string|int|float>>
	 */
	public function campaign_rows( string $campaign ): array {
		$ids  = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $campaign, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		$rows = array( array( 'code', 'discount_type', 'amount', 'expires', 'usage_count', 'usage_limit', 'status' ) );
		foreach ( $ids as $id ) {
			$c      = new \WC_Coupon( $id );
			$rows[] = array(
				$c->get_code(),
				$c->get_discount_type(),
				(float) $c->get_amount(),
				$c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : '',
				(int) $c->get_usage_count(),
				(int) $c->get_usage_limit(),
				(string) get_post_status( $id ),
			);
		}
		return $rows;
	}

	/**
	 * Export rows for a code pool batch.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function pool_rows( string $batch ): array {
		global $wpdb;
		$table = Install::tables()['pool'];
		$v     = Install::tables()['vouchers'];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.code, p.used_at, v.order_id FROM {$table} p LEFT JOIN {$v} v ON v.id = p.voucher_id WHERE p.batch = %s ORDER BY p.id", $batch ), ARRAY_A ); // phpcs:ignore
		$out   = array( array( 'code', 'status', 'used_at', 'order' ) );
		foreach ( $rows ? $rows : array() as $r ) {
			$out[] = array( (string) $r['code'], $r['used_at'] ? 'used' : 'free', (string) $r['used_at'], $r['order_id'] ? (string) $r['order_id'] : '' );
		}
		return $out;
	}

	/**
	 * Stream rows as CSV (UTF-8 BOM for Excel, formula injection neutralised).
	 */
	public static function send_csv( string $filename, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore
		foreach ( $rows as $row ) {
			fputcsv( $out, self::safe_row( $row ) );
		}
		fclose( $out ); // phpcs:ignore
	}

	public static function safe_row( array $row ): array {
		return array_map( static fn( $c ) => is_string( $c ) && preg_match( '/^[=+\-@\t\r]/', $c ) ? "'" . $c : $c, $row );
	}
}
