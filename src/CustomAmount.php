<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Native "customer chooses the amount" gift cards (min/max/step and preset buttons).
 */
final class CustomAmount {
	/** S4: currency the amount was entered in (order line meta; the cart item keeps it as vcap_currency). */
	public const CURRENCY_META = '_vcap_custom_currency';

	/** Error of the amount refused in this request (the field is marked when the form shows again). */
	private string $error = '';

	/** Whether a Store API add-item of a customer-chosen amount is being handled in this request. */
	private bool $store_api = false;

	public function register(): void {
		add_action( 'vcap_before_fields', array( $this, 'render' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 5, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 5, 3 );
		// M12-20: Store API cart/add-item (blocks, headless clients) sends the amount as request parameters.
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'store_api_data' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'store_api_validate' ), 10, 2 );
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
		$typed   = isset( $_POST['vcap_amount'] ) && is_scalar( $_POST['vcap_amount'] ) ? self::normalise( sanitize_text_field( wp_unslash( (string) $_POST['vcap_amount'] ) ) ) : '';
		$chosen  = isset( $_POST['vcap_amount_preset'] ) && is_scalar( $_POST['vcap_amount_preset'] ) ? self::normalise( sanitize_text_field( wp_unslash( (string) $_POST['vcap_amount_preset'] ) ) ) : '';
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
				if ( (float) $config['custom_step'] > 0 && abs( (float) $config['custom_step'] - 1.0 ) > 1e-9 ) {
					/* translators: %s: step, e.g. 5,00 € */
					echo ' ' . esc_html( sprintf( __( 'In steps of %s.', 'voucher-captain' ), self::plain_price( (float) $config['custom_step'] ) ) );
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
			// Parsed strictly: what cannot be read unambiguously is handed on as typed and refused by error_for().
			return self::normalise( $typed );
		}
		$chosen = isset( $request['vcap_amount_preset'] ) && is_scalar( $request['vcap_amount_preset'] ) ? self::normalise( (string) $request['vcap_amount_preset'] ) : '';
		foreach ( '' !== $chosen && is_numeric( $chosen ) ? self::presets( $config ) : array() as $preset ) {
			if ( abs( (float) $preset - (float) $chosen ) < 0.0001 ) {
				return $preset;
			}
		}
		return '';
	}

	/**
	 * A typed amount as a plain number ("12,50 €" → "12.5"), parsed strictly with the shop's decimals (see
	 * Amount::parse()); anything ambiguous or invalid is returned unchanged, so error_for() names the problem.
	 */
	public static function normalise( string $raw ): string {
		$parsed = Amount::parse( $raw, array( 'allow_zero' => true ) );
		return $parsed['ok'] ? self::number( $parsed['value'] ) : trim( $raw );
	}

	private static function number( float $value ): string {
		return (string) round( $value, wc_get_price_decimals() );
	}

	/** Price without markup, for messages ("5,00 €"). */
	private static function plain_price( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Requested amount: from existing cart item data ("order again", programmatic adds) or the request.
	 */
	private function requested_amount( $cart_item_data, \WC_Product $product ): string {
		if ( is_array( $cart_item_data ) && isset( $cart_item_data['vcap_amount'] ) ) {
			$given = $cart_item_data['vcap_amount'];
			// Numbers come from code ("order again", Store API extensions); text is parsed like a typed amount.
			return is_int( $given ) || is_float( $given ) ? self::number( (float) $given ) : ( is_scalar( $given ) ? self::normalise( (string) $given ) : '' );
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return ''; // M12-20: REST clients send the amount through the Store API (store_api_data()), never $_REQUEST.
		}
		return self::posted_amount( ProductConfig::get( $product ), wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- add to cart; WooCommerce handles the request.
	}

	/**
	 * M12-20: Store API cart/add-item takes the amount from the request parameters "vcap_amount" (typed amount) or
	 * "vcap_amount_preset" (one of the suggested amounts) into the cart item data – also for top-ups with a
	 * customer-chosen amount. A request without either gets an empty amount, refused by store_api_validate().
	 *
	 * @param mixed                 $data    Add-to-cart data (id, quantity, variation, cart_item_data).
	 * @param \WP_REST_Request|mixed $request Store API request.
	 * @return mixed
	 */
	public function store_api_data( $data, $request ) {
		if ( ! is_array( $data ) || ! $request instanceof \WP_REST_Request ) {
			return $data;
		}
		$product = wc_get_product( (int) ( ! empty( $data['variation_id'] ) ? $data['variation_id'] : ( $data['id'] ?? 0 ) ) );
		if ( ! $product || ! $this->is_custom( $product ) ) {
			return $data;
		}
		$params = array(
			'vcap_amount'        => $request->get_param( 'vcap_amount' ),
			'vcap_amount_preset' => $request->get_param( 'vcap_amount_preset' ),
		);
		$this->store_api                       = true;
		$data['cart_item_data']                = (array) ( $data['cart_item_data'] ?? array() );
		$data['cart_item_data']['vcap_amount'] = self::posted_amount( ProductConfig::get( $product ), array_filter( $params, 'is_scalar' ) );
		return $data;
	}

	/**
	 * M12-20: a Store API add-item of a customer-chosen amount is refused with a 400 and the reason, like the form.
	 *
	 * @param \WC_Product $product Product being added.
	 * @param mixed       $request Add-to-cart data (WooCommerce passes the array with cart_item_data).
	 */
	public function store_api_validate( $product, $request ): void {
		if ( ! $product instanceof \WC_Product || ! $this->is_custom( $product ) ) {
			return;
		}
		$given = is_array( $request ) ? ( $request['cart_item_data']['vcap_amount'] ?? '' ) : '';
		$error = $this->amount_error( $product, is_scalar( $given ) ? (string) $given : '' );
		if ( $error && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_invalid_amount', esc_html( $error ), 400 );
		}
	}

	/**
	 * @return string|null error message, null when the amount is acceptable
	 */
	private function amount_error( \WC_Product $product, string $raw ): ?string {
		return self::error_for( ProductConfig::get( $product ), $raw );
	}

	/**
	 * Suggested amounts of a configuration as plain numbers: separated by ";" or line breaks, so "12,50" is one
	 * amount (legacy comma lists such as "10, 25, 50" still work, see Amount::parse_list()). @return string[]
	 */
	public static function presets( array $config ): array {
		return array_map( array( self::class, 'number' ), Amount::parse_list( (string) $config['custom_presets'] )['values'] );
	}

	/**
	 * Is an amount acceptable for a "customer chooses the amount" configuration?
	 *
	 * @return string|null error message, null when the amount is acceptable
	 */
	public static function error_for( array $config, string $raw ): ?string {
		$min  = (float) $config['custom_min'];
		$max  = (float) $config['custom_max'];
		$step = (float) $config['custom_step'];
		$parsed = Amount::parse( $raw );
		if ( ! $parsed['ok'] ) {
			return 'empty' === $parsed['error'] ? __( 'Please enter a voucher amount.', 'voucher-captain' ) : Amount::message( $parsed['error'] );
		}
		$amount = $parsed['value'];
		if ( ( $min > 0 && $amount < $min - 1e-9 ) || ( $max > 0 && $amount > $max + 1e-9 ) ) {
			if ( $min > 0 && $max > 0 ) {
				/* translators: 1: minimum amount, 2: maximum amount */
				return sprintf( __( 'The voucher amount must be between %1$s and %2$s.', 'voucher-captain' ), self::plain_price( $min ), self::plain_price( $max ) );
			}
			return $min > 0
				/* translators: %s: minimum amount */
				? sprintf( __( 'The voucher amount must be at least %s.', 'voucher-captain' ), self::plain_price( $min ) )
				/* translators: %s: maximum amount */
				: sprintf( __( 'The voucher amount must not be more than %s.', 'voucher-captain' ), self::plain_price( $max ) );
		}
		if ( $step > 0 ) {
			// Match the HTML number input: steps count from the minimum, or from 0 when there is none.
			$step_base = $min > 0 ? $min : 0.0;
			$steps     = ( $amount - $step_base ) / $step;
			if ( abs( $steps - round( $steps ) ) > 1e-6 ) {
				$below = $step_base + floor( $steps ) * $step;
				$above = $step_base + ceil( $steps ) * $step;
				return $below > 0
					/* translators: 1: step, 2: nearest allowed amount below, 3: nearest allowed amount above */
					? sprintf( __( 'Please enter an amount in steps of %1$s, e.g. %2$s or %3$s.', 'voucher-captain' ), self::plain_price( $step ), self::plain_price( $below ), self::plain_price( $above ) )
					/* translators: %s: step */
					: sprintf( __( 'Please enter an amount in steps of %s.', 'voucher-captain' ), self::plain_price( $step ) );
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
		$unreadable = Amount::parse_list( (string) $config['custom_presets'] )['invalid'];
		if ( $unreadable ) {
			$errors[] = sprintf(
				/* translators: %s: entries of the suggested amounts that are not amounts */
				__( 'Customer chooses the amount: the suggested amounts %s cannot be read as amounts and are not shown. Separate amounts with a semicolon, e.g. 12,50; 25; 50.', 'voucher-captain' ),
				'"' . implode( '", "', $unreadable ) . '"'
			);
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
		if ( $this->store_api && ! ( is_array( $cart_item_data ) && isset( $cart_item_data['vcap_amount'] ) ) ) {
			// Store API before WooCommerce 9.x calls this filter without the cart item data; the amount taken by
			// store_api_data() is checked by store_api_validate() and cart_item_data() instead.
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
		if ( $error && $this->store_api && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			// Newer WooCommerce filters the cart item data before woocommerce_store_api_validate_add_to_cart: a 400.
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_invalid_amount', esc_html( $error ), 400 );
		}
		if ( $error ) {
			throw new \Exception( esc_html( $error ) );
		}
		$data['vcap_amount'] = round( Amount::parse( $raw )['value'], wc_get_price_decimals() );
		// S4: the amount is fixed together with the currency it was entered in (switchers change it per request).
		$data['vcap_currency'] = (string) get_woocommerce_currency();
		return $data;
	}

	public function from_session( $item, $values ) {
		if ( isset( $values['vcap_amount'] ) ) {
			$item['vcap_amount'] = (float) $values['vcap_amount'];
		}
		if ( isset( $values['vcap_currency'] ) ) {
			$item['vcap_currency'] = (string) $values['vcap_currency'];
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
			if ( ! empty( $values['vcap_currency'] ) ) {
				$item->add_meta_data( self::CURRENCY_META, strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $values['vcap_currency'] ) ), true );
			}
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
