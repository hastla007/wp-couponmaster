<?php
namespace WCVS;

defined( 'ABSPATH' ) || exit;

/**
 * Native "customer chooses the amount" gift cards (min/max/step and preset buttons).
 */
final class CustomAmount {
	public function register(): void {
		add_action( 'wcvs_before_fields', array( $this, 'render' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 5, 4 );
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
		$presets = array_filter( array_map( 'trim', explode( ',', (string) $config['custom_presets'] ) ), 'is_numeric' );
		$value   = isset( $_POST['wcvs_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['wcvs_amount'] ) ) : ( $presets ? reset( $presets ) : ( $min ?: '' ) ); // phpcs:ignore
		?>
		<div class="wcvs-amount">
			<label for="wcvs-amount"><?php echo esc_html( sprintf( /* translators: %s: currency symbol */ __( 'Voucher amount (%s)', 'voucher-captain' ), html_entity_decode( get_woocommerce_currency_symbol() ) ) ); ?> <abbr class="required" title="<?php esc_attr_e( 'required', 'voucher-captain' ); ?>">*</abbr></label>
			<?php if ( $presets ) : ?>
				<div class="wcvs-amount-presets" role="group" aria-label="<?php esc_attr_e( 'Suggested amounts', 'voucher-captain' ); ?>">
					<?php foreach ( $presets as $preset ) : ?>
						<button type="button" class="button wcvs-amount-preset" data-amount="<?php echo esc_attr( $preset ); ?>"><?php echo wp_kses_post( wc_price( (float) $preset ) ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<input type="number" id="wcvs-amount" name="wcvs_amount" class="input-text" required aria-describedby="wcvs-amount-help"
				value="<?php echo esc_attr( (string) $value ); ?>"
				step="<?php echo esc_attr( (string) $step ); ?>"
				<?php echo $min > 0 ? 'min="' . esc_attr( (string) $min ) . '"' : 'min="0.01"'; ?>
				<?php echo $max > 0 ? 'max="' . esc_attr( (string) $max ) . '"' : ''; ?> />
			<span class="description" id="wcvs-amount-help">
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

	public function validate( $passed, $product_id, $quantity, $variation_id = 0 ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! $this->is_custom( $product ) ) {
			return $passed;
		}
		$config = ProductConfig::get( $product );
		$raw    = isset( $_REQUEST['wcvs_amount'] ) ? wc_format_decimal( wp_unslash( $_REQUEST['wcvs_amount'] ) ) : ''; // phpcs:ignore
		$amount = (float) $raw;
		$min    = (float) $config['custom_min'];
		$max    = (float) $config['custom_max'];
		if ( '' === $raw || $amount <= 0 ) {
			wc_add_notice( __( 'Please enter a voucher amount.', 'voucher-captain' ), 'error' );
			return false;
		}
		if ( ( $min > 0 && $amount < $min ) || ( $max > 0 && $amount > $max ) ) {
			wc_add_notice( __( 'The voucher amount is outside the allowed range.', 'voucher-captain' ), 'error' );
			return false;
		}
		return $passed;
	}

	public function cart_item_data( $data, $product_id, $variation_id ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( $product && $this->is_custom( $product ) && isset( $_REQUEST['wcvs_amount'] ) ) { // phpcs:ignore
			$data['wcvs_amount'] = (float) wc_format_decimal( wp_unslash( $_REQUEST['wcvs_amount'] ), wc_get_price_decimals() ); // phpcs:ignore
		}
		return $data;
	}

	public function from_session( $item, $values ) {
		if ( isset( $values['wcvs_amount'] ) ) {
			$item['wcvs_amount'] = (float) $values['wcvs_amount'];
		}
		return $item;
	}

	public function apply_prices( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['wcvs_amount'] ) && $item['data'] instanceof \WC_Product ) {
				$item['data']->set_price( (float) apply_filters( 'wcvs_custom_amount_price', $item['wcvs_amount'], $item ) );
			}
		}
	}

	public function display( $item_data, $cart_item ) {
		if ( isset( $cart_item['wcvs_amount'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Voucher amount', 'voucher-captain' ),
				'value' => wp_strip_all_tags( wc_price( (float) $cart_item['wcvs_amount'] ) ),
			);
		}
		return $item_data;
	}

	public function save( $item, $cart_item_key, $values, $order ): void {
		if ( isset( $values['wcvs_amount'] ) ) {
			$item->add_meta_data( '_wcvs_custom_amount', (float) $values['wcvs_amount'], true );
		}
	}

	public function order_again( $data, $item, $order ) {
		$amount = $item->get_meta( '_wcvs_custom_amount' );
		if ( '' !== (string) $amount ) {
			$data['wcvs_amount'] = (float) $amount;
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
