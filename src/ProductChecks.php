<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Guards and warnings for voucher products, so that a product setting can never sell something worthless:
 *  - a product with a fixed expiry date that has passed (or is missing) cannot be bought;
 *  - a product drawing codes from an imported batch can be set to stop selling when the batch is empty;
 *  - a single-use voucher worth more than the cart says that its remaining value is lost;
 *  - a delivery delay or fixed delivery date after the voucher's expiry, and a customer delivery date field that
 *    does not match the delivery setting, are reported on the product screen;
 *  - a product with an invalid discount setup (ProductConfig::discount_errors(), e.g. a percentage taken from the
 *    price) cannot be bought;
 *  - admin notices (product screen, dashboard, voucher screens) and a Site Health test list such products.
 */
final class ProductChecks {
	/** Free codes from which a batch counts as running low, when the product sets no threshold. */
	public const LOW_CODES_DEFAULT = 10;

	/** @var array<string, int>|null batch => free codes (per request) */
	private static ?array $free_codes = null;

	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 20, 5 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'residual_rows' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'residual_rows' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_vcap_expired_product', array( $this, 'handle_expired_product' ) );
		add_filter( 'site_status_tests', array( $this, 'health_tests' ) );
		add_action( 'rest_api_init', array( $this, 'health_route' ) );
		add_action( 'vcap_daily_maintenance', array( self::class, 'sync_dated_products' ), 40, 0 );
		// The cached shop_problems() is recalculated after a product or its voucher settings change.
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product', 'woocommerce_trash_product', 'save_post_product' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'meta_changed' ), 10, 3 );
		}
	}

	/**
	 * A voucher setting of a product changed: forget the cached problems. AP-P1-6: only for products and
	 * variations – "_vcap_*" meta of coupons and other posts (e.g. a suspended coupon's marker) leaves them alone.
	 */
	public static function meta_changed( $meta_id, $object_id, $meta_key ): void {
		if ( is_string( $meta_key ) && str_starts_with( $meta_key, '_vcap_' ) && in_array( get_post_type( (int) $object_id ), array( 'product', 'product_variation' ), true ) ) {
			self::flush();
		}
	}

	/** Product meta: the fixed expiry date whose "expired" notice the admin hid (a new date shows it again). */
	public const EXPIRED_HIDDEN_META = '_vcap_expired_notice_hidden';

	/**
	 * U8: whether an expired fixed-date product still deserves the shop-wide notice – only products customers
	 * could otherwise buy (published, visible in the catalog, in stock) whose notice was not hidden for this date.
	 */
	public static function expired_notice_due( \WC_Product $product, array $config ): bool {
		if ( 'hidden' === $product->get_catalog_visibility() || ! $product->is_in_stock() ) {
			return false;
		}
		$hidden = (string) $product->get_meta( self::EXPIRED_HIDDEN_META );
		return '' === $hidden || (string) $config['expiry_date'] !== $hidden;
	}

	/**
	 * U8: act on an expired product from the notice: "hide" the notice for its current date, or "draft" it
	 * (unpublish the season product).
	 */
	public static function dismiss_expired( \WC_Product $product, string $how ): bool {
		if ( 'draft' === $how ) {
			$product->set_status( 'draft' );
			return (bool) $product->save();
		}
		if ( 'hide' === $how ) {
			$product->update_meta_data( self::EXPIRED_HIDDEN_META, (string) ProductConfig::get( $product )['expiry_date'] );
			return (bool) $product->save();
		}
		return false;
	}

	public function handle_expired_product(): void {
		$id = absint( $_GET['product'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		check_admin_referer( 'vcap_expired_product_' . $id );
		if ( ! current_user_can( 'edit_product', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$product = wc_get_product( $id );
		if ( $product ) {
			self::dismiss_expired( $product, sanitize_key( wp_unslash( $_GET['how'] ?? '' ) ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/** Escaped HTML: product links, each with "Hide notice" and "Set to draft" (U8). */
	private static function expired_links( array $products ): string {
		$out = array();
		foreach ( $products as $p ) {
			$url   = static fn( string $how ) => wp_nonce_url( add_query_arg( array( 'action' => 'vcap_expired_product', 'product' => $p->get_id(), 'how' => $how ), admin_url( 'admin-post.php' ) ), 'vcap_expired_product_' . $p->get_id() );
			$out[] = self::product_links( array( $p ) ) . ' (<a href="' . esc_url( $url( 'hide' ) ) . '">' . esc_html__( 'Hide notice', 'voucher-captain' ) . '</a> | <a href="' . esc_url( $url( 'draft' ) ) . '">' . esc_html__( 'Set to draft', 'voucher-captain' ) . '</a>)';
		}
		return implode( ', ', $out );
	}

	// ----------------------------------------------------------------- Sale.

	/**
	 * Why a voucher product must not be sold right now: "date_past", "date_missing", "codes_empty",
	 * "discount_invalid" or '' (fine).
	 */
	public static function unsellable_reason( \WC_Product $product ): string {
		if ( ! ProductConfig::is_voucher( $product ) ) {
			return '';
		}
		$config  = ProductConfig::get( $product );
		if ( ProductConfig::discount_errors( $config ) ) {
			return 'discount_invalid';
		}
		$problem = ProductConfig::expiry_problem( $config );
		if ( in_array( $problem, array( 'date_past', 'date_missing' ), true ) ) {
			return $problem;
		}
		if ( 'stop' === $config['code_pool_empty'] && '' !== (string) $config['code_batch'] && self::free_codes( (string) $config['code_batch'] ) < 1 ) {
			return 'codes_empty';
		}
		return '';
	}

	public function purchasable( $purchasable, $product ) {
		if ( $purchasable && $product instanceof \WC_Product && '' !== self::unsellable_reason( $product ) ) {
			return false;
		}
		return $purchasable;
	}

	/** Stop selling more vouchers than an imported batch (set to "stop selling when empty") still has codes. */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $passed || ! $product || ! ProductConfig::is_voucher( $product ) ) {
			return $passed;
		}
		$config = ProductConfig::get( $product );
		$batch  = (string) $config['code_batch'];
		if ( 'stop' !== $config['code_pool_empty'] || '' === $batch ) {
			return $passed;
		}
		$needed = max( 1, (int) $quantity );
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product && ProductConfig::is_voucher( $item['data'] ) && $batch === (string) ProductConfig::get( $item['data'] )['code_batch'] ) {
					$needed += (int) $item['quantity'];
				}
			}
		}
		$free = self::free_codes( $batch );
		if ( $needed * max( 1, (int) $config['per_item'] ) > $free ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: number of vouchers */
					_n( 'Only %d voucher of this kind is still available.', 'Only %d vouchers of this kind are still available.', max( 0, intdiv( $free, max( 1, (int) $config['per_item'] ) ) ), 'voucher-captain' ),
					max( 0, intdiv( $free, max( 1, (int) $config['per_item'] ) ) )
				),
				'error'
			);
			return false;
		}
		return $passed;
	}

	/**
	 * Single-use vouchers whose value exceeds what the cart can take: the remaining value is lost. Shared by the
	 * classic cart (residual_rows()) and the cart and checkout blocks (CartBlocks, Store API "forfeits").
	 *
	 * What a voucher uses is its coupon discount plus what it pays after tax ("Multi-purpose vouchers as payment":
	 * such a coupon discounts 0 and pays with a payment row instead, PaymentVouchers).
	 *
	 * @return array<int, array{code:string, value:float, used:float, currency:string, keep:bool}>
	 */
	public static function residual_losses( \WC_Cart $cart ): array {
		$losses = array();
		$paid   = PaymentVouchers::enabled() && function_exists( 'WC' ) && WC()->cart === $cart ? vcap()->payments->cart_payments() : array();
		$coupons = $cart->get_coupons();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = $coupons[ $code ] ?? null;
			if ( $coupon instanceof \WC_Coupon && 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
				continue; // AP-P1-5: ordinary coupons are never looked up.
			}
			$voucher = vcap()->vouchers->get_by_code( wc_format_coupon_code( $code ) );
			if ( ! $voucher ) {
				$coupon_id = $coupon instanceof \WC_Coupon ? (int) $coupon->get_id() : (int) wc_get_coupon_id_by_code( $code );
				$voucher   = $coupon_id > 0 ? vcap()->vouchers->get_by_coupon( $coupon_id ) : null;
			}
			if ( ! $voucher || 'single' !== $voucher->usage_mode || 'percent' === $voucher->discount_type || $voucher->is_ticket() || 'credit' === $voucher->type ) {
				continue;
			}
			if ( '' !== $voucher->currency && function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() !== $voucher->currency ) {
				continue; // Converted by a currency switcher: amounts are not comparable.
			}
			$used  = (float) $cart->get_coupon_discount_amount( $code, ! wc_prices_include_tax() ) + (float) ( $paid[ wc_format_coupon_code( $code ) ] ?? 0 );
			$value = (float) $voucher->initial_amount;
			if ( $value - $used > 0.009 ) {
				$losses[] = array( 'code' => $voucher->code, 'value' => $value, 'used' => $used, 'currency' => $voucher->currency, 'keep' => TaxAccounting::keeps_rest( $voucher ) );
			}
		}
		return $losses;
	}

	/** Cart and checkout totals (classic): a row per single-use voucher that loses value. */
	public function residual_rows(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( self::residual_losses( WC()->cart ) as $loss ) {
			// A note across the whole row; data-title for the responsive table (its label is hidden by frontend.css).
			echo '<tr class="vcap-residual-warning"><td colspan="2" data-title="' . esc_attr__( 'Remaining value', 'voucher-captain' ) . '"><div class="woocommerce-info" role="status">' . wp_kses_post( self::residual_notice( $loss ) ) . '</div></td></tr>';
		}
	}

	/**
	 * The notice for one residual_losses() row: HTML (classic cart) or plain text (Store API for the blocks).
	 *
	 * @param array{code:string, value:float, used:float, currency:string, keep?:bool} $loss
	 */
	public static function residual_notice( array $loss, bool $plain = false ): string {
		$args = array( 'currency' => (string) $loss['currency'] );
		if ( ! empty( $loss['keep'] ) ) {
			// M12-68: the product keeps the rest as store credit or a new gift card.
			$html = sprintf(
				/* translators: 1: voucher code, 2: amount used in this order, 3: voucher value, 4: remaining value */
				__( 'Voucher %1$s: only %2$s of %3$s is used in this order. The remaining %4$s is not lost: it is kept for you as store credit (or as a new gift card) after the order.', 'voucher-captain' ),
				esc_html( (string) $loss['code'] ),
				wc_price( (float) $loss['used'], $args ),
				wc_price( (float) $loss['value'], $args ),
				wc_price( (float) $loss['value'] - (float) $loss['used'], $args )
			);
			return $plain ? html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) : $html;
		}
		$html = sprintf(
			/* translators: 1: voucher code, 2: amount used in this order, 3: voucher value, 4: lost remaining value */
			__( 'Voucher %1$s: only %2$s of %3$s is used in this order. The voucher can be redeemed only once – the remaining %4$s will be lost. Add more items to use its full value.', 'voucher-captain' ),
			esc_html( (string) $loss['code'] ),
			wc_price( (float) $loss['used'], $args ),
			wc_price( (float) $loss['value'], $args ),
			wc_price( (float) $loss['value'] - (float) $loss['used'], $args )
		);
		return $plain ? html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) : $html;
	}

	// ----------------------------------------------------------------- Codes.

	/** Free codes of an imported batch (0 for an unknown batch). */
	public static function free_codes( string $batch ): int {
		// Counted per batch on the (batch, used_at) index, once per request – never grouped over the whole pool
		// (this runs in "woocommerce_is_purchasable" for every product shown).
		if ( null === self::$free_codes ) {
			self::$free_codes = array();
		}
		if ( ! isset( self::$free_codes[ $batch ] ) ) {
			self::$free_codes[ $batch ] = '' === $batch ? 0 : vcap()->code_pool->free_count( $batch );
		}
		return self::$free_codes[ $batch ];
	}

	/** Transient of shop_problems() (product ids; five minutes, forgotten when products or codes change). */
	public const PROBLEMS_CACHE = 'vcap_shop_problems';
	private static bool $problems_flushed = false;

	/** Forget the per-request batch counts and the cached shop problems (after codes or products changed). */
	public static function flush(): void {
		self::$free_codes = null;
		if ( ! self::$problems_flushed ) {
			self::$problems_flushed = true; // Once per request (imports add thousands of codes).
			delete_transient( self::PROBLEMS_CACHE );
		}
	}

	/** Free codes from which the product's batch counts as running low. */
	public static function low_codes_threshold( array $config ): int {
		return '' !== (string) $config['code_pool_low'] ? max( 0, (int) $config['code_pool_low'] ) : self::LOW_CODES_DEFAULT;
	}

	// ----------------------------------------------------------------- Availability changes (C12).

	/** Product meta: the last known unsellable_reason() ('' = sellable), to notice when it changes. */
	public const AVAILABILITY_META = '_vcap_availability';

	/** @var array<string, true> batches whose free codes changed in this request */
	private static array $touched_batches = array();

	/**
	 * C12: the free codes of a batch changed (code claimed, released, added, deleted). The products drawing from
	 * it are checked once at the end of the request (an import adds thousands of codes).
	 */
	public static function batch_touched( string $batch ): void {
		if ( '' === $batch ) {
			return;
		}
		if ( ! self::$touched_batches ) {
			add_action( 'shutdown', array( self::class, 'sync_availability' ), 5, 0 );
		}
		self::$touched_batches[ $batch ] = true;
	}

	/**
	 * C12: re-check the products of the batches touched in this request (or the given products) and clear the page
	 * caches of those whose purchasability changed.
	 *
	 * @param int[]|null $product_ids null = products of the touched batches
	 * @return int[] ids of the products whose availability changed
	 */
	public static function sync_availability( ?array $product_ids = null ): array {
		if ( null === $product_ids ) {
			$batches               = array_keys( self::$touched_batches );
			self::$touched_batches = array();
			if ( ! $batches ) {
				return array();
			}
			self::$free_codes = null;
			$product_ids      = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only on code changes, deferred to the end of the request.
						array(
							'key'     => ProductConfig::meta_key( 'code_batch' ),
							'value'   => array_map( 'strval', $batches ),
							'compare' => 'IN',
						),
					),
				)
			);
		}
		$changed = array();
		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product && self::refresh_availability( $product ) ) {
				$changed[] = $id;
			}
		}
		return $changed;
	}

	/**
	 * C12: daily – fixed-date products whose date passed since the last run change their purchasability without
	 * any save; their caches are cleared here.
	 *
	 * @return int[] ids of the products whose availability changed
	 */
	public static function sync_dated_products(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => ProductConfig::meta_key( 'expiry_mode' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- daily maintenance.
				'meta_value'     => 'date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- daily maintenance.
			)
		);
		return self::sync_availability( array_map( 'intval', $ids ) );
	}

	/** Compare a product's purchasability with the last known state; on a change clear its caches. */
	public static function refresh_availability( \WC_Product $product ): bool {
		if ( ! ProductConfig::is_voucher( $product ) ) {
			return false;
		}
		$id     = $product->get_id();
		$reason = self::unsellable_reason( $product );
		$known  = metadata_exists( 'post', $id, self::AVAILABILITY_META );
		$old    = $known ? (string) get_post_meta( $id, self::AVAILABILITY_META, true ) : '';
		if ( $known && $old === $reason ) {
			return false;
		}
		update_post_meta( $id, self::AVAILABILITY_META, $reason );
		$ids = array_filter( array( $id, $product->get_parent_id() ) );
		if ( $product->is_type( 'variable' ) ) {
			$ids = array_merge( $ids, $product->get_children() );
		}
		foreach ( $ids as $post_id ) {
			clean_post_cache( (int) $post_id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $post_id );
			}
		}
		if ( ! $known && '' === $reason ) {
			return false; // First check of a sellable product: caches cleared, nothing changed for listeners.
		}
		/**
		 * A voucher product became purchasable or stopped being purchasable (date passed, batch empty or refilled,
		 * discount setup). Its WordPress and WooCommerce caches have been cleared; full-page caches can purge its
		 * URL here. Arguments: the product, the new unsellable reason ('' = purchasable again; date_past,
		 * date_missing, codes_empty, discount_invalid) and the previous reason ('' = was purchasable).
		 */
		do_action( 'vcap_product_availability_changed', $product, $reason, $old );
		return true;
	}

	// ----------------------------------------------------------------- Admin.

	/**
	 * Problems of one product that the shop must fix (shown on the product screen).
	 *
	 * @return array<int, array{level:string, message:string}> level "error" or "warning"
	 */
	public static function problems( \WC_Product $product ): array {
		if ( ! ProductConfig::is_voucher( $product ) ) {
			return array();
		}
		$config = ProductConfig::get( $product );
		$out    = array();
		foreach ( CustomAmount::config_errors( $config ) as $message ) {
			$out[] = array( 'level' => 'error', 'message' => $message );
		}
		foreach ( ProductConfig::discount_errors( $config ) as $message ) {
			$out[] = array( 'level' => 'error', 'message' => $message . ' ' . __( 'The product cannot be bought until the discount setup is corrected.', 'voucher-captain' ) );
		}
		switch ( ProductConfig::expiry_problem( $config ) ) {
			case 'date_missing':
				$out[] = array( 'level' => 'error', 'message' => __( 'Expiry "Fixed date" is selected but no expiry date is set. The product cannot be bought until you enter a date (or choose another expiry).', 'voucher-captain' ) );
				break;
			case 'date_past':
				$out[] = array(
					'level'   => 'error',
					/* translators: %s: date */
					'message' => sprintf( __( 'The fixed expiry date %s has passed: vouchers sold now would already be expired, so the product can no longer be bought. Set a new date or another expiry.', 'voucher-captain' ), wp_date( (string) get_option( 'date_format' ), (int) ProductConfig::fixed_expiry_timestamp( $config ) ) ),
				);
				break;
			case 'days_missing':
				$out[] = array( 'level' => 'warning', 'message' => __( 'Expiry "Days after purchase" has no number of days: the global default validity is used.', 'voucher-captain' ) );
				break;
		}
		$late = Delivery::delivery_after_expiry( $config );
		if ( $late ) {
			$format = (string) get_option( 'date_format' );
			$out[]  = array(
				'level'   => 'error',
				'message' => sprintf(
					/* translators: 1: delivery date, 2: expiry date */
					__( 'Vouchers sold now would be delivered on %1$s but expire on %2$s: the recipient would get an expired voucher (it is then not sent; the order gets a note). Choose an earlier delivery or a longer validity.', 'voucher-captain' ),
					wp_date( $format, (int) strtotime( $late['delivery'] . ' 12:00:00 ' . wp_timezone_string() ) ),
					wp_date( $format, (int) strtotime( $late['expiry'] . ' 12:00:00 ' . wp_timezone_string() ) )
				),
			);
		}
		if ( 'customer' !== $config['delivery'] && Delivery::customer_date_enabled( $config ) ) {
			$out[] = array( 'level' => 'warning', 'message' => __( 'The product page field "Delivery date" is on, but the delivery is not "On a date chosen by the customer". A date the customer enters is used; save the product to make both settings match.', 'voucher-captain' ) );
		}
		if ( 'giftcard' === $config['type'] && 'single' === $config['usage'] ) {
			$out[] = array( 'level' => 'warning', 'message' => __( 'This gift card is set to "Single use (whole value at once)": whatever is not used in the first order is lost. Gift cards usually keep their remaining value – choose "Multi-use with stored balance (gift card)" under Usage.', 'voucher-captain' ) );
		}
		$batch = (string) $config['code_batch'];
		if ( '' !== $batch ) {
			$free = self::free_codes( $batch );
			if ( $free < 1 ) {
				$out[] = array(
					'level'   => 'stop' === $config['code_pool_empty'] ? 'error' : 'warning',
					'message' => 'stop' === $config['code_pool_empty']
						/* translators: %s: batch name */
						? sprintf( __( 'The code batch "%s" is empty: the product cannot be bought until you import more codes.', 'voucher-captain' ), $batch )
						/* translators: %s: batch name */
						: sprintf( __( 'The code batch "%s" is empty: new vouchers get random codes.', 'voucher-captain' ), $batch ),
				);
			} elseif ( $free <= self::low_codes_threshold( $config ) ) {
				$out[] = array(
					'level'   => 'warning',
					/* translators: 1: batch name, 2: number of free codes */
					'message' => sprintf( _n( 'The code batch "%1$s" has only %2$d free code left.', 'The code batch "%1$s" has only %2$d free codes left.', $free, 'voucher-captain' ), $batch, $free ),
				);
			}
		}
		// U3: a voucher that is not virtual makes the checkout ask for a shipping address and charge shipping. A
		// variable product has no virtual flag of its own; each variation is checked (they default to not virtual).
		if ( ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) && ! $product->is_virtual() && 'yes' !== ( $config['physical'] ?? 'no' ) ) {
			$out[] = array(
				'level'   => 'warning',
				'message' => $product->is_type( 'variation' )
					/* translators: %s: variation name */
					? sprintf( __( 'The variation "%s" is not virtual: WooCommerce asks for a shipping address and may charge shipping for the voucher. Tick "Virtual" on the variation.', 'voucher-captain' ), wp_strip_all_tags( $product->get_formatted_name() ) )
					: __( 'Vouchers are sent by email as PDF. Tick "Virtual" at the top of the product data box – otherwise WooCommerce asks for a shipping address and may charge shipping for the voucher.', 'voucher-captain' ),
			);
		}
		return $out;
	}

	/**
	 * Published voucher products that cannot be sold because of their fixed expiry date, or whose code batch
	 * is empty or running low.
	 *
	 * Products (or variations) with an invalid discount setup are listed under "invalid".
	 *
	 * @return array{expired: \WC_Product[], codes: array<int, array{product:\WC_Product, free:int, stop:bool}>, invalid: \WC_Product[]}
	 */
	public static function shop_problems(): array {
		// Shown on the dashboard, the product list and every voucher screen: calculated at most every five minutes
		// (and after a product, its voucher settings or a code batch changed), stored as product ids.
		$cached = get_transient( self::PROBLEMS_CACHE );
		if ( ! is_array( $cached ) || ! isset( $cached['expired'], $cached['codes'], $cached['invalid'] ) ) {
			$fresh  = self::find_shop_problems();
			$cached = array(
				'expired' => array_map( static fn( $p ) => $p->get_id(), $fresh['expired'] ),
				'codes'   => array_map( static fn( $r ) => array( 'id' => $r['product']->get_id(), 'free' => $r['free'], 'stop' => $r['stop'] ), $fresh['codes'] ),
				'invalid' => array_map( static fn( $p ) => $p->get_id(), $fresh['invalid'] ),
			);
			set_transient( self::PROBLEMS_CACHE, $cached, 5 * MINUTE_IN_SECONDS );
			self::$problems_flushed = false;
			return $fresh;
		}
		$codes = array();
		foreach ( (array) $cached['codes'] as $row ) {
			$product = wc_get_product( (int) $row['id'] );
			if ( $product ) {
				$codes[] = array( 'product' => $product, 'free' => (int) $row['free'], 'stop' => (bool) $row['stop'] );
			}
		}
		return array(
			'expired' => array_values( array_filter( array_map( 'wc_get_product', array_map( 'intval', (array) $cached['expired'] ) ) ) ),
			'codes'   => $codes,
			'invalid' => array_values( array_filter( array_map( 'wc_get_product', array_map( 'intval', (array) $cached['invalid'] ) ) ) ),
		);
	}

	/** @return array{expired: \WC_Product[], codes: array<int, array{product:\WC_Product, free:int, stop:bool}>, invalid: \WC_Product[]} */
	private static function find_shop_problems(): array {
		$out = array( 'expired' => array(), 'codes' => array(), 'invalid' => self::invalid_discount_products() );
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => 200,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array( 'key' => '_vcap_enabled', 'value' => 'yes' ),
					array(
						'relation' => 'OR',
						array( 'key' => '_vcap_expiry_mode', 'value' => 'date' ),
						array( 'key' => '_vcap_code_batch', 'value' => '', 'compare' => '!=' ),
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
				continue;
			}
			$config = ProductConfig::get( $product );
			if ( in_array( ProductConfig::expiry_problem( $config ), array( 'date_past', 'date_missing' ), true ) && self::expired_notice_due( $product, $config ) ) {
				$out['expired'][] = $product;
			}
			$batch = (string) $config['code_batch'];
			if ( '' !== $batch ) {
				$free = self::free_codes( $batch );
				if ( $free <= self::low_codes_threshold( $config ) ) {
					$out['codes'][] = array( 'product' => $product, 'free' => $free, 'stop' => 'stop' === $config['code_pool_empty'] );
				}
			}
		}
		return $out;
	}

	/** Published voucher products whose discount setup (or that of a variation) is invalid. @return \WC_Product[] */
	public static function invalid_discount_products(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => 200,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array( 'key' => '_vcap_enabled', 'value' => 'yes' ),
					array( 'key' => '_vcap_discount_type', 'value' => array( 'percent', 'fixed_product' ), 'compare' => 'IN' ),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$items = $product->is_type( 'variable' ) ? array_merge( array( $product ), array_filter( array_map( 'wc_get_product', $product->get_children() ) ) ) : array( $product );
			foreach ( $items as $item ) {
				if ( ProductConfig::is_voucher( $item ) && ProductConfig::discount_errors( ProductConfig::get( $item ) ) ) {
					$out[] = $product;
					break;
				}
			}
		}
		return $out;
	}

	private static function product_links( array $products ): string {
		return implode(
			', ',
			array_map( static fn( \WC_Product $p ) => '<a href="' . esc_url( (string) get_edit_post_link( $p->get_id() ) ) . '">' . esc_html( $p->get_name() ) . '</a>', $products )
		);
	}

	public function admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' ) ) ) {
			return;
		}
		if ( 'product' === $screen->id ) {
			$id      = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product = $id && current_user_can( 'edit_product', $id ) ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				return;
			}
			$items = array( $product );
			if ( $product->is_type( 'variable' ) ) {
				$items = array_merge( $items, array_filter( array_map( 'wc_get_product', $product->get_children() ) ) );
			}
			$seen = array();
			foreach ( $items as $item ) {
				foreach ( self::problems( $item ) as $problem ) {
					if ( isset( $seen[ $problem['message'] ] ) ) {
						continue;
					}
					$seen[ $problem['message'] ] = true;
					echo '<div class="notice notice-' . esc_attr( 'error' === $problem['level'] ? 'error' : 'warning' ) . '"><p><strong>' . esc_html( Brand::name( 'notice' ) ) . ':</strong> ' . esc_html( $problem['message'] ) . '</p></div>';
				}
			}
			return;
		}
		if ( ! in_array( $screen->id, array( 'dashboard', 'edit-product' ), true ) && ! str_contains( (string) $screen->id, 'vcap' ) ) {
			return;
		}
		$problems = self::shop_problems();
		if ( $problems['invalid'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html( Brand::name( 'notice' ) ) . ':</strong> ' . esc_html__( 'These voucher products have an invalid discount setup (a percentage that is not a fixed value between 0 and 100, or "Fixed product discount") and cannot be bought until it is corrected:', 'voucher-captain' ) . ' ' . self::product_links( $problems['invalid'] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in product_links().
		}
		if ( $problems['expired'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html( Brand::name( 'notice' ) ) . ':</strong> ' . esc_html__( 'These voucher products have a fixed expiry date that has passed (or is missing) and can no longer be bought:', 'voucher-captain' ) . ' ' . self::expired_links( $problems['expired'] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in expired_links().
		}
		$empty = array_filter( $problems['codes'], static fn( $row ) => $row['free'] < 1 );
		$low   = array_filter( $problems['codes'], static fn( $row ) => $row['free'] >= 1 );
		if ( $empty ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html( Brand::name( 'notice' ) ) . ':</strong> ' . esc_html__( 'The imported code batch of these voucher products is empty (products set to "stop selling" cannot be bought, the others get random codes). Import more codes under Vouchers → Import codes:', 'voucher-captain' ) . ' ' . self::product_links( array_column( $empty, 'product' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in product_links().
		}
		if ( $low ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html( Brand::name( 'notice' ) ) . ':</strong> ' . esc_html__( 'The imported code batch of these voucher products is running low:', 'voucher-captain' ) . ' ' . self::product_links( array_column( $low, 'product' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in product_links().
		}
	}

	/** A8: the voucher product test loads up to 200 products – asynchronous (REST), so Site Health opens at once. */
	public function health_tests( $tests ) {
		$tests = (array) $tests;
		$tests['async']['vcap_voucher_products'] = array(
			'label'             => Brand::name( 'site_health' ),
			'test'              => rest_url( 'vcap/v1/site-health/voucher-products' ),
			'has_rest'          => true,
			'async_direct_test' => array( $this, 'test_voucher_products' ),
		);
		return $tests;
	}

	/** REST endpoint of the asynchronous test (same permission as Site Health's own checks). */
	public function health_route(): void {
		register_rest_route(
			'vcap/v1',
			'/site-health/voucher-products',
			array(
				'methods'             => 'GET',
				'callback'            => fn() => rest_ensure_response( $this->test_voucher_products() ),
				'permission_callback' => static fn() => current_user_can( 'view_site_health_checks' ),
			)
		);
	}

	/** Site Health: expired fixed-date voucher products and empty code batches. */
	public function test_voucher_products(): array {
		$problems = self::shop_problems();
		$empty    = array_filter( $problems['codes'], static fn( $row ) => $row['free'] < 1 );
		$result   = array(
			'label'       => __( 'Voucher products can be sold', 'voucher-captain' ),
			'status'      => 'good',
			'badge'       => array( 'label' => __( 'Vouchers', 'voucher-captain' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html__( 'No voucher product has an expired fixed expiry date or an empty code batch.', 'voucher-captain' ) . '</p>',
			'actions'     => '',
			'test'        => 'vcap_voucher_products',
		);
		if ( ! $problems['expired'] && ! $empty && ! $problems['invalid'] ) {
			return $result;
		}
		$text = '';
		if ( $problems['invalid'] ) {
			$text .= '<p>' . esc_html__( 'Voucher products with an invalid discount setup (not purchasable):', 'voucher-captain' ) . ' ' . self::product_links( $problems['invalid'] ) . '</p>';
		}
		if ( $problems['expired'] ) {
			$text .= '<p>' . esc_html__( 'Expired voucher products (fixed expiry date passed or missing, no longer purchasable):', 'voucher-captain' ) . ' ' . self::product_links( $problems['expired'] ) . '</p>';
		}
		if ( $empty ) {
			$text .= '<p>' . esc_html__( 'Voucher products with an empty code batch:', 'voucher-captain' ) . ' ' . self::product_links( array_column( $empty, 'product' ) ) . '</p>';
		}
		$result['label']          = __( 'Some voucher products cannot be sold as configured', 'voucher-captain' );
		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		$result['description']    = $text;
		return $result;
	}
}
