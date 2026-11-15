<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the buyer when and where the vouchers go: on the product page (delivery delay or date), in the cart, in
 * WooCommerce's order emails to the customer and on the order received page ("What happens next").
 */
final class NextSteps {
	public function register(): void {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'order_email' ), 20, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'thankyou' ), 4 );
		add_action( 'woocommerce_before_cart', array( $this, 'cart_hint' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'cart_hint' ), 5 );
	}

	/**
	 * M12-52: the security hold of a new customer's first voucher order above "vcap_hold_new_customer_above"
	 * (V7, Delivery::hold_until()) explained before the purchase: '' when the hold is off or a voucher of this
	 * product (price, fixed amount or highest custom amount) cannot be worth more than the threshold.
	 */
	public static function hold_hint( array $config, float $price = 0.0 ): string {
		$above = (float) Settings::value( 'vcap_hold_new_customer_above' );
		$hours = (float) Settings::value( 'vcap_hold_hours' );
		if ( $above <= 0 || $hours <= 0 ) {
			return '';
		}
		$custom = 'custom' === (string) ( $config['amount_source'] ?? '' );
		$max    = $custom ? ProductConfig::effective_custom_max( $config ) : '';
		$value  = max( $price, (float) ( $config['amount'] ?? 0 ), '' !== $max ? (float) $max : 0.0 );
		if ( ! ( $custom && '' === $max ) && $value <= $above + 0.00001 ) {
			return '';
		}
		return self::hold_text( $above, $hours );
	}

	private static function hold_text( float $above, float $hours ): string {
		return sprintf(
			/* translators: 1: amount, 2: number of hours */
			_n( 'For your security, vouchers of a first order above %1$s are delivered and redeemable %2$s hour after the payment.', 'For your security, vouchers of a first order above %1$s are delivered and redeemable %2$s hours after the payment.', (int) ceil( $hours ), 'voucher-captain' ),
			wp_strip_all_tags( wc_price( $above ) ),
			wc_format_localized_decimal( (string) round( $hours, 1 ) )
		);
	}

	/**
	 * M12-52: cart and checkout name the security hold when the vouchers in the cart are worth more than the
	 * threshold and the customer has no established order yet (guests without a known address included).
	 */
	public function cart_hint(): void {
		$above = (float) Settings::value( 'vcap_hold_new_customer_above' );
		$hours = (float) Settings::value( 'vcap_hold_hours' );
		$cart  = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		if ( $above <= 0 || $hours <= 0 || ! $cart || VoucherPurchaseRules::cart_voucher_value( $cart ) <= $above + 0.00001 ) {
			return;
		}
		$email = WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		$user  = get_current_user_id();
		if ( ( $user > 0 || '' !== $email ) && VoucherPurchaseRules::customer_orders( $user, $email, 0, time() - (int) round( $hours * HOUR_IN_SECONDS ) ) ) {
			return; // An established customer: not held.
		}
		wc_print_notice( self::hold_text( $above, $hours ), 'notice' );
	}

	/**
	 * Product page: when the product sends its vouchers later ('' for "right after payment").
	 */
	public static function product_hint( array $config ): string {
		switch ( (string) ( $config['delivery'] ?? '' ) ) {
			case 'delay':
				$days = (int) $config['delivery_delay_days'];
				/* translators: %d: number of days */
				return $days > 0 ? sprintf( _n( 'The voucher is sent by email %d day after the purchase.', 'The voucher is sent by email %d days after the purchase.', $days, 'voucher-captain' ), $days ) : '';
			case 'date':
				$ts = '' !== (string) $config['delivery_date'] ? LocalDate::timestamp( (string) $config['delivery_date'] ) : null;
				/* translators: %s: delivery date */
				return $ts && $ts > time() - DAY_IN_SECONDS ? sprintf( __( 'The voucher is sent by email on %s.', 'voucher-captain' ), wp_date( vcap()->settings->date_format(), $ts ) ) : '';
		}
		return '';
	}

	/**
	 * Where and when one order line's vouchers go: "The voucher will be emailed to anna@example.com on 24.12.2026."
	 *
	 * @param array       $config      Product voucher settings.
	 * @param array       $fields      Personalisation values of the line (recipient_email, delivery_date, …).
	 * @param string      $buyer_email The buyer's address ('' when not known yet, e.g. in the cart).
	 * @param string|null $when_utc    Delivery time (UTC), null = right after payment.
	 */
	public static function summary( array $config, array $fields, string $buyer_email, ?string $when_utc ): string {
		$recipient = trim( (string) ( $fields['recipient_email']['value'] ?? '' ) );
		$routed    = in_array( (string) ( $config['send_to'] ?? '' ), array( 'recipient', 'both' ), true ) && is_email( $recipient ) && ! Delivery::same_address( $recipient, $buyer_email );
		$date      = $when_utc ? LocalDate::from_utc( $when_utc, vcap()->settings->date_format() ) : '';
		if ( $routed ) {
			return '' !== $date
				/* translators: 1: recipient email address, 2: delivery date */
				? sprintf( __( 'The voucher will be emailed to %1$s on %2$s.', 'voucher-captain' ), $recipient, $date )
				/* translators: %s: recipient email address */
				: sprintf( __( 'The voucher will be emailed to %s right after payment.', 'voucher-captain' ), $recipient );
		}
		return '' !== $date
			/* translators: %s: delivery date */
			? sprintf( __( 'The voucher will be emailed to you on %s.', 'voucher-captain' ), $date )
			: __( 'The voucher will be emailed to you right after payment.', 'voucher-captain' );
	}

	/**
	 * One line per voucher product of an order: product name => summary.
	 *
	 * @return array<int, array{name:string, text:string}>
	 */
	public static function for_order( \WC_Order $order ): array {
		$lines = array();
		$buyer = (string) $order->get_billing_email();
		// M12-82: once the vouchers exist, their own delivery time and addresses (changed in My Account, by the
		// shop or by a recipient correction) are shown instead of what the order line said at checkout.
		$issued = array();
		foreach ( $order->get_id() ? vcap()->vouchers->for_order( $order->get_id() ) : array() as $voucher ) {
			$issued[ (int) $voucher->order_item_id ][] = $voucher;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && isset( $issued[ $item->get_id() ] ) ) {
				$texts   = array_values( array_unique( array_map( static fn( Voucher $v ) => self::voucher_summary( $v, $buyer ), $issued[ $item->get_id() ] ) ) );
				$lines[] = array(
					'name' => (string) $item->get_name(),
					'text' => implode( ' ', $texts ),
				);
				continue;
			}
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! ProductConfig::is_voucher( $product ) || TopUp::is_topup( $product ) ) {
				continue; // A top-up credits an existing card, nothing is mailed.
			}
			$config  = ProductConfig::get( $product );
			$fields  = is_array( $item->get_meta( '_vcap_fields' ) ) ? $item->get_meta( '_vcap_fields' ) : array();
			$when    = Delivery::compute_time( $config, $fields, $order );
			$held    = Delivery::with_hold( $when, $order );
			$text    = self::summary( $config, $fields, $buyer, $held );
			if ( $held !== $when ) {
				// V7: say why a voucher bought for "right away" comes later.
				$text .= ' ' . __( 'For your security, vouchers of a first order of this value are delivered and redeemable after a short waiting period.', 'voucher-captain' );
			}
			$lines[] = array(
				'name' => (string) $item->get_name(),
				'text' => $text,
			);
		}
		return $lines;
	}

	/** M12-82: where and when one issued voucher goes (or went), from the voucher itself. */
	public static function voucher_summary( Voucher $voucher, string $buyer_email ): string {
		$routed = Delivery::to_recipient( $voucher );
		if ( $voucher->delivered_at ) {
			return $routed
				/* translators: %s: recipient email address */
				? sprintf( __( 'The voucher was emailed to %s.', 'voucher-captain' ), $voucher->recipient_email )
				: __( 'The voucher was emailed to you.', 'voucher-captain' );
		}
		$when = $voucher->delivery_at && strtotime( $voucher->delivery_at . ' UTC' ) > time() ? $voucher->delivery_at : null;
		$text = self::summary(
			array( 'send_to' => $voucher->send_to ),
			array( 'recipient_email' => array( 'value' => $routed ? $voucher->recipient_email : '' ) ),
			$buyer_email,
			$when
		);
		if ( Delivery::is_held( $voucher ) ) {
			$text .= ' ' . __( 'For your security, vouchers of a first order of this value are delivered and redeemable after a short waiting period.', 'voucher-captain' );
		}
		return $text;
	}

	/** "What happens next" in WooCommerce's order emails to the customer (not in the shop's own copies). */
	public function order_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order || $sent_to_admin || ! $email instanceof \WC_Email ) {
			return;
		}
		/** WooCommerce order emails that show the delivery of the order's vouchers. */
		$ids = (array) apply_filters( 'vcap_next_steps_emails', array( 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order' ) );
		if ( ! in_array( $email->id, $ids, true ) ) {
			return;
		}
		$this->output( self::for_order( $order ), (bool) $plain_text );
	}

	/** "What happens next" on the order received page. */
	public function thankyou( $order ): void {
		if ( ! $order instanceof \WC_Order || ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		$this->output( self::for_order( $order ), false );
	}

	/** @param array<int, array{name:string, text:string}> $lines */
	private function output( array $lines, bool $plain ): void {
		if ( ! $lines ) {
			return;
		}
		$title = __( 'What happens next', 'voucher-captain' );
		if ( $plain ) {
			echo "\n" . esc_html( wp_strip_all_tags( $title ) ) . "\n\n";
			foreach ( $lines as $line ) {
				echo '- ' . esc_html( wp_strip_all_tags( $line['name'] ) ) . ': ' . esc_html( $line['text'] ) . "\n";
			}
			echo "\n";
			return;
		}
		echo '<section class="vcap-next-steps"><h2>' . esc_html( $title ) . '</h2><ul>';
		foreach ( $lines as $line ) {
			echo '<li><strong>' . esc_html( $line['name'] ) . ':</strong> ' . esc_html( $line['text'] ) . '</li>';
		}
		echo '</ul></section>';
	}
}
