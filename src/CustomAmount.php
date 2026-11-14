<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Native "customer chooses the amount" gift cards (min/max/step and preset buttons).
 */
final class CustomAmount {
	/** Error of the amount refused in this request (the field is marked when the form shows again). */
	private string $error = '';

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

	/**
	 * Amount input with the suggested amounts as a radio group (works without JavaScript: a typed amount wins,
	 * else the chosen suggestion; frontend.js keeps both in step). Ids are unique on the page (two product forms,
	 * quick views).
	 */
	public function render( \WC_Product $product ): void {
		if ( ! $this->is_custom( $product ) ) {
			return;
		}
		$config  = ProductConfig::get( $product );
		$min     = (float) $config['custom_min'];
		$max     = (float) $config['custom_max'];
		$step    = (float) $config['custom_step'] ?: 1;
		// Only suggestions the customer can actually buy (a misconfigured one would be refused at add to cart).
		$presets = array_values( array_filter( self::presets( $config ), static fn( $preset ) => null === self::error_for( $config, $preset ) ) );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- refills the form after a failed add to cart.
		$typed   = isset( $_POST['vcap_amount'] ) && is_scalar( $_POST['vcap_amount'] ) ? (string) wc_format_decimal( wp_unslash( $_POST['vcap_amount'] ) ) : '';
		$chosen  = isset( $_POST['vcap_amount_preset'] ) && is_scalar( $_POST['vcap_amount_preset'] ) ? (string) wc_format_decimal( wp_unslash( $_POST['vcap_amount_preset'] ) ) : '';
		// phpcs:enable
		if ( ! isset( $_POST['vcap_amount'] ) && ! isset( $_POST['vcap_amount_preset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$chosen = $presets ? (string) $presets[0] : '';
			$typed  = $presets ? '' : ( $min ? (string) $min : '' );
		}
		$id      = Shortcodes::unique_id( 'vcap-amount' );
		?>
		<div class="vcap-amount">
			<?php if ( $presets ) : ?>
				<fieldset class="vcap-amount-presets">
					<legend><?php esc_html_e( 'Suggested amounts', 'voucher-captain' ); ?></legend>
					<?php foreach ( $presets as $i => $preset ) : ?>
						<span class="vcap-amount-preset">
							<input type="radio" id="<?php echo esc_attr( $id . '-preset-' . $i ); ?>" name="vcap_amount_preset" value="<?php echo esc_attr( $preset ); ?>"<?php checked( '' === $typed && abs( (float) $chosen - (float) $preset ) < 0.0001 ); ?> />
							<label for="<?php echo esc_attr( $id . '-preset-' . $i ); ?>"><?php echo wp_kses_post( wc_price( (float) $preset ) ); ?></label>
						</span>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( sprintf( /* translators: %s: currency symbol */ __( 'Voucher amount (%s)', 'voucher-captain' ), html_entity_decode( get_woocommerce_currency_symbol() ) ) ); ?><?php echo $presets ? '' : ' <abbr class="required" title="' . esc_attr__( 'required', 'voucher-captain' ) . '">*</abbr>'; ?></label>
			<?php // With suggestions, an empty field means "the chosen suggestion" (the server checks the amount either way). ?>
			<input type="number" id="<?php echo esc_attr( $id ); ?>" name="vcap_amount" class="input-text"<?php echo $presets ? '' : ' required'; ?> aria-describedby="<?php echo esc_attr( trim( $id . '-help' . ( '' !== $this->error ? ' ' . $id . '-error' : '' ) ) ); ?>"<?php echo '' !== $this->error ? ' aria-invalid="true" data-vcap-focus="1"' : ''; ?>
				value="<?php echo esc_attr( $typed ); ?>"
				step="<?php echo esc_attr( (string) $step ); ?>"
				<?php // The browser counts steps from min: without a minimum from 0, so 25, 50, 100 fit a step of 1 or 5. ?>
				min="<?php echo esc_attr( (string) ( $min > 0 ? $min : 0 ) ); ?>"
				<?php echo $max > 0 ? 'max="' . esc_attr( (string) $max ) . '"' : ''; ?> />
			<span class="description" id="<?php echo esc_attr( $id . '-help' ); ?>">
				<?php
				if ( $min > 0 && $max > 0 ) {
					/* translators: 1: min, 2: max */
					printf( esc_html__( 'Choose an amount between %1$s and %2$s.', 'voucher-captain' ), wp_kses_post( wc_price( $min ) ), wp_kses_post( wc_price( $max ) ) );
				} elseif ( $min > 0 ) {
					/* translators: %s: min */
					printf( esc_html__( 'Minimum %s.', 'voucher-captain' ), wp_kses_post( wc_price( $min ) ) );
				} elseif ( $max > 0 ) {
					/* translators: %s: max */
					printf( esc_html__( 'Maximum %s.', 'voucher-captain' ), wp_kses_post( wc_price( $max ) ) );
				}
				?>
			</span>
			<?php if ( '' !== $this->error ) : ?>
				<span class="vcap-field-error" id="<?php echo esc_attr( $id . '-error' ); ?>"><?php echo esc_html( $this->error ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Amount of an add-to-cart request: a typed amount wins, else the chosen suggestion (only one of the
	 * configuration's suggested amounts counts).
	 */
	public static function posted_amount( array $config, array $request ): string {
		$typed = isset( $request['vcap_amount'] ) && is_scalar( $request['vcap_amount'] ) ? trim( (string) $request['vcap_amount'] ) : '';
		if ( '' !== $typed ) {
			return (string) wc_format_decimal( $typed );
		}
		$chosen = isset( $request['vcap_amount_preset'] ) && is_scalar( $request['vcap_amount_preset'] ) ? (string) wc_format_decimal( (string) $request['vcap_amount_preset'] ) : '';
		foreach ( '' !== $chosen && is_numeric( $chosen ) ? self::presets( $config ) : array() as $preset ) {
			if ( abs( (float) $preset - (float) $chosen ) < 0.0001 ) {
				return (string) wc_format_decimal( $preset );
			}
		}
		return '';
	}

	/**
	 * Requested amount: from existing cart item data ("order again", programmatic adds) or the request.
	 */
	private function requested_amount( $cart_item_data, \WC_Product $product ): string {
		if ( is_array( $cart_item_data ) && isset( $cart_item_data['vcap_amount'] ) ) {
			return (string) wc_format_decimal( $cart_item_data['vcap_amount'] );
		}
		return self::posted_amount( ProductConfig::get( $product ), wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- add to cart; WooCommerce handles the request.
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
		$error = $this->amount_error( $product, $this->requested_amount( $cart_item_data, $product ) );
		if ( $error ) {
			$this->error = $error;
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
		$raw   = $this->requested_amount( $data, $product );
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
