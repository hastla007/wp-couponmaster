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
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! ProductConfig::is_voucher( $product ) || TopUp::is_topup( $product ) ) {
				continue; // A top-up credits an existing card, nothing is mailed.
			}
			$config  = ProductConfig::get( $product );
			$fields  = is_array( $item->get_meta( '_vcap_fields' ) ) ? $item->get_meta( '_vcap_fields' ) : array();
			$lines[] = array(
				'name' => (string) $item->get_name(),
				'text' => self::summary( $config, $fields, $buyer, Delivery::compute_time( $config, $fields, $order ) ),
			);
		}
		return $lines;
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
