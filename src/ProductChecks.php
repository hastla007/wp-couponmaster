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
		add_filter( 'site_status_tests', array( $this, 'health_tests' ) );
	}

	// ----------------------------------------------------------------- Sale.

	/**
	 * Why a voucher product must not be sold right now: "date_past", "date_missing", "codes_empty" or '' (fine).
	 */
	public static function unsellable_reason( \WC_Product $product ): string {
		if ( ! ProductConfig::is_voucher( $product ) ) {
			return '';
		}
		$config  = ProductConfig::get( $product );
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
	 * @return array<int, array{code:string, value:float, used:float, currency:string}>
	 */
	public static function residual_losses( \WC_Cart $cart ): array {
		$losses = array();
		$paid   = PaymentVouchers::enabled() && function_exists( 'WC' ) && WC()->cart === $cart ? vcap()->payments->cart_payments() : array();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$voucher = vcap()->vouchers->get_by_code( wc_format_coupon_code( $code ) );
			if ( ! $voucher ) {
				$coupon_id = (int) wc_get_coupon_id_by_code( $code );
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
				$losses[] = array( 'code' => $voucher->code, 'value' => $value, 'used' => $used, 'currency' => $voucher->currency );
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
	 * @param array{code:string, value:float, used:float, currency:string} $loss
	 */
	public static function residual_notice( array $loss, bool $plain = false ): string {
		$args = array( 'currency' => (string) $loss['currency'] );
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
		if ( null === self::$free_codes ) {
			self::$free_codes = array();
			foreach ( vcap()->code_pool->batches() as $row ) {
				self::$free_codes[ (string) $row['batch'] ] = (int) $row['free'];
			}
		}
		return self::$free_codes[ $batch ] ?? 0;
	}

	/** Forget the per-request batch counts (after codes were claimed or imported). */
	public static function flush(): void {
		self::$free_codes = null;
	}

	/** Free codes from which the product's batch counts as running low. */
	public static function low_codes_threshold( array $config ): int {
		return '' !== (string) $config['code_pool_low'] ? max( 0, (int) $config['code_pool_low'] ) : self::LOW_CODES_DEFAULT;
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
		return $out;
	}

	/**
	 * Published voucher products that cannot be sold because of their fixed expiry date, or whose code batch
	 * is empty or running low.
	 *
	 * @return array{expired: \WC_Product[], codes: array<int, array{product:\WC_Product, free:int, stop:bool}>}
	 */
	public static function shop_problems(): array {
		$out = array( 'expired' => array(), 'codes' => array() );
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
			if ( in_array( ProductConfig::expiry_problem( $config ), array( 'date_past', 'date_missing' ), true ) ) {
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
					echo '<div class="notice notice-' . esc_attr( 'error' === $problem['level'] ? 'error' : 'warning' ) . '"><p><strong>' . esc_html__( 'Voucher Captain', 'voucher-captain' ) . ':</strong> ' . esc_html( $problem['message'] ) . '</p></div>';
				}
			}
			return;
		}
		if ( ! in_array( $screen->id, array( 'dashboard', 'edit-product' ), true ) && ! str_contains( (string) $screen->id, 'vcap' ) ) {
			return;
		}
		$problems = self::shop_problems();
		if ( $problems['expired'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Voucher Captain', 'voucher-captain' ) . ':</strong> ' . esc_html__( 'These voucher products have a fixed expiry date that has passed (or is missing) and can no longer be bought:', 'voucher-captain' ) . ' ' . self::product_links( $problems['expired'] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in product_links().
		}
		$empty = array_filter( $problems['codes'], static fn( $row ) => $row['free'] < 1 );
		$low   = array_filter( $problems['codes'], static fn( $row ) => $row['free'] >= 1 );
		if ( $empty ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Voucher Captain', 'voucher-captain' ) . ':</strong> ' . esc_html__( 'The imported code batch of these voucher products is empty (products set to "stop selling" cannot be bought, the others get random codes). Import more codes under Vouchers → Import codes:', 'voucher-captain' ) . ' ' . self::product_links( array_column( $empty, 'product' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in product_links().
		}
		if ( $low ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Voucher Captain', 'voucher-captain' ) . ':</strong> ' . esc_html__( 'The imported code batch of these voucher products is running low:', 'voucher-captain' ) . ' ' . self::product_links( array_column( $low, 'product' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in product_links().
		}
	}

	public function health_tests( $tests ) {
		$tests = (array) $tests;
		$tests['direct']['vcap_voucher_products'] = array(
			'label' => __( 'Voucher Captain', 'voucher-captain' ),
			'test'  => array( $this, 'test_voucher_products' ),
		);
		return $tests;
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
		if ( ! $problems['expired'] && ! $empty ) {
			return $result;
		}
		$text = '';
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
