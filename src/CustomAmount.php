<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Native "customer chooses the amount" gift cards (min/max/step and preset buttons).
 */
final class CustomAmount {
	public function register(): void {
		add_action( 'vcap_before_fields', array( $this, 'render' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 5, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 5, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'from_session' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display' ), 5, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save' ), 10, 4 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'purchasable' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 10, 2 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'order_again' ), 10, 3 );
	}

	private function is_custom( \WC_Product $product ): bool {
		return ProductConfig::is_voucher( $product ) && 'custom' === ProductConfig::get( $product )['amount_source'];
	}

	public function render( \WC_Product $product ): void {
		if ( ! $this->is_custom( $product ) ) {
			return;
		}
		$config  = ProductConfig::get( $product );
		$min     = (float) $config['custom_min'];
		$max     = (float) $config['custom_max'];
		$step    = (float) $config['custom_step'] ?: 1;
		// Only suggestions the customer can actually buy (a misconfigured one would be refused at add to cart).
		$presets = array_filter( self::presets( $config ), static fn( $preset ) => null === self::error_for( $config, $preset ) );
		$value   = isset( $_POST['vcap_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['vcap_amount'] ) ) : ( $presets ? reset( $presets ) : ( $min ?: '' ) ); // phpcs:ignore
		?>
		<div class="vcap-amount">
			<label for="vcap-amount"><?php echo esc_html( sprintf( /* translators: %s: currency symbol */ __( 'Voucher amount (%s)', 'voucher-captain' ), html_entity_decode( get_woocommerce_currency_symbol() ) ) ); ?> <abbr class="required" title="<?php esc_attr_e( 'required', 'voucher-captain' ); ?>">*</abbr></label>
			<?php if ( $presets ) : ?>
				<div class="vcap-amount-presets" role="group" aria-label="<?php esc_attr_e( 'Suggested amounts', 'voucher-captain' ); ?>">
					<?php foreach ( $presets as $preset ) : ?>
						<button type="button" class="button vcap-amount-preset" data-amount="<?php echo esc_attr( $preset ); ?>"><?php echo wp_kses_post( wc_price( (float) $preset ) ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<input type="number" id="vcap-amount" name="vcap_amount" class="input-text" required aria-describedby="vcap-amount-help"
				value="<?php echo esc_attr( (string) $value ); ?>"
				step="<?php echo esc_attr( (string) $step ); ?>"
				<?php // The browser counts steps from min: without a minimum from 0, so 25, 50, 100 fit a step of 1 or 5. ?>
				min="<?php echo esc_attr( (string) ( $min > 0 ? $min : 0 ) ); ?>"
				<?php echo $max > 0 ? 'max="' . esc_attr( (string) $max ) . '"' : ''; ?> />
			<span class="description" id="vcap-amount-help">
				<?php
				if ( $min > 0 && $max > 0 ) {
					/* translators: 1: min, 2: max */
					printf( esc_html__( 'Choose an amount between %1$s and %2$s.', 'voucher-captain' ), wp_kses_post( wc_price( $min ) ), wp_kses_post( wc_price( $max ) ) );
				} elseif ( $min > 0 ) {
					/* translators: %s: min */
					printf( esc_html__( 'Minimum %s.', 'voucher-captain' ), wp_kses_post( wc_price( $min ) ) );
				}
				?>
			</span>
		</div>
		<?php
	}

	/**
	 * Requested amount: from existing cart item data ("order again", programmatic adds) or the request.
	 */
	private function requested_amount( $cart_item_data ): string {
		if ( is_array( $cart_item_data ) && isset( $cart_item_data['vcap_amount'] ) ) {
			return (string) wc_format_decimal( $cart_item_data['vcap_amount'] );
		}
		return isset( $_REQUEST['vcap_amount'] ) && is_scalar( $_REQUEST['vcap_amount'] ) ? (string) wc_format_decimal( wp_unslash( $_REQUEST['vcap_amount'] ) ) : ''; // phpcs:ignore
	}

	/**
	 * @return string|null error message, null when the amount is acceptable
	 */
	private function amount_error( \WC_Product $product, string $raw ): ?string {
		return self::error_for( ProductConfig::get( $product ), $raw );
	}

	/** Suggested amounts of a configuration (numeric entries of the comma separated list). @return string[] */
	public static function presets( array $config ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', str_replace( ';', ',', (string) $config['custom_presets'] ) ) ), 'is_numeric' ) );
	}

	/**
	 * Is an amount acceptable for a "customer chooses the amount" configuration?
	 *
	 * @return string|null error message, null when the amount is acceptable
	 */
	public static function error_for( array $config, string $raw ): ?string {
		$amount = (float) $raw;
		$min    = (float) $config['custom_min'];
		$max    = (float) $config['custom_max'];
		$step   = (float) $config['custom_step'];
		if ( '' === $raw || ! is_numeric( $raw ) || $amount <= 0 ) {
			return __( 'Please enter a voucher amount.', 'voucher-captain' );
		}
		if ( ( $min > 0 && $amount < $min ) || ( $max > 0 && $amount > $max ) ) {
			return __( 'The voucher amount is outside the allowed range.', 'voucher-captain' );
		}
		if ( $step > 0 ) {
			// Match the HTML number input: steps count from the minimum, or from 0 when there is none.
			$step_base = $min > 0 ? $min : 0.0;
			$steps     = ( $amount - $step_base ) / $step;
			if ( abs( $steps - round( $steps ) ) > 1e-6 ) {
				return __( 'Please enter an amount matching the allowed increment.', 'voucher-captain' );
			}
		}
		return null;
	}

	/**
	 * Configuration mistakes that make amounts unbuyable: minimum above maximum, suggested amounts outside the
	 * range or off the step grid. Shown on the product screen.
	 *
	 * @return string[]
	 */
	public static function config_errors( array $config ): array {
		if ( 'custom' !== $config['amount_source'] ) {
			return array();
		}
		$errors = array();
		$min    = (float) $config['custom_min'];
		$max    = (float) $config['custom_max'];
		if ( $min > 0 && $max > 0 && $min > $max ) {
			$errors[] = __( 'Customer chooses the amount: the minimum amount is higher than the maximum amount, so no amount can be bought.', 'voucher-captain' );
		}
		if ( '' !== (string) $config['custom_step'] && (float) $config['custom_step'] <= 0 ) {
			$errors[] = __( 'Customer chooses the amount: the step must be greater than 0 (e.g. 1 for whole amounts).', 'voucher-captain' );
		}
		$invalid = array_filter( self::presets( $config ), static fn( $preset ) => null !== self::error_for( $config, $preset ) );
		if ( $invalid ) {
			$errors[] = sprintf(
				/* translators: %s: comma separated amounts */
				__( 'Customer chooses the amount: the suggested amounts %s are outside the minimum/maximum or do not match the step and are not shown.', 'voucher-captain' ),
				implode( ', ', $invalid )
			);
		}
		return $errors;
	}

	public function validate( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! $this->is_custom( $product ) ) {
			return $passed;
		}
		$error = $this->amount_error( $product, $this->requested_amount( $cart_item_data ) );
		if ( $error ) {
			wc_add_notice( $error, 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Enforced here as well, because the Store API and direct WC()->cart->add_to_cart() calls skip
	 * woocommerce_add_to_cart_validation. WooCommerce turns the exception into an error notice / API error.
	 */
	public function cart_item_data( $data, $product_id, $variation_id ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! $this->is_custom( $product ) ) {
			unset( $data['vcap_amount'] );
			return $data;
		}
		$raw   = $this->requested_amount( $data );
		$error = $this->amount_error( $product, $raw );
		if ( $error ) {
			throw new \Exception( esc_html( $error ) );
		}
		$data['vcap_amount'] = round( (float) $raw, wc_get_price_decimals() );
		return $data;
	}

	public function from_session( $item, $values ) {
		if ( isset( $values['vcap_amount'] ) ) {
			$item['vcap_amount'] = (float) $values['vcap_amount'];
		}
		return $item;
	}

	public function apply_prices( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['vcap_amount'] ) && $item['data'] instanceof \WC_Product ) {
				$item['data']->set_price( max( 0.0, (float) apply_filters( 'vcap_custom_amount_price', $item['vcap_amount'], $item ) ) );
			}
		}
	}

	public function display( $item_data, $cart_item ) {
		if ( isset( $cart_item['vcap_amount'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Voucher amount', 'voucher-captain' ),
				'value' => wp_strip_all_tags( wc_price( (float) $cart_item['vcap_amount'] ) ),
			);
		}
		return $item_data;
	}

	public function save( $item, $cart_item_key, $values, $order ): void {
		if ( isset( $values['vcap_amount'] ) ) {
			$item->add_meta_data( '_vcap_custom_amount', (float) $values['vcap_amount'], true );
		}
	}

	public function order_again( $data, $item, $order ) {
		$amount = $item->get_meta( '_vcap_custom_amount' );
		if ( '' !== (string) $amount ) {
			$data['vcap_amount'] = (float) $amount;
		}
		return $data;
	}

	public function purchasable( $purchasable, $product ) {
		if ( ! $purchasable && $product instanceof \WC_Product && '' === $product->get_price() && $this->is_custom( $product ) && $product->exists() && 'publish' === get_post_status( $product->get_parent_id() ?: $product->get_id() ) ) {
			return true;
		}
		return $purchasable;
	}

	public function price_html( $html, $product ) {
		if ( $product instanceof \WC_Product && ! $product->is_type( 'variable' ) && $this->is_custom( $product ) ) {
			$config = ProductConfig::get( $product );
			$min    = (float) $config['custom_min'];
			$max    = (float) $config['custom_max'];
			if ( $min > 0 && $max > 0 ) {
				return wc_format_price_range( $min, $max );
			}
			if ( $min > 0 ) {
				/* translators: %s: min price */
				return sprintf( __( 'From %s', 'voucher-captain' ), wc_price( $min ) );
			}
			return __( 'Choose your amount', 'voucher-captain' );
		}
		return $html;
	}
}
