<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\Compat\PassCaptain;
use VCAP\Issuer;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket voucher products (D14, split from PassCaptainTickets): the product settings, the capacity check of a
 * fixed date before sale, the expiry at the event start and the ticket target stored with each sold line.
 */
final class ProductSetup {

	public function product_fields( $product ): void {
		$service = TicketContract::service();
		if ( ! $service || ! method_exists( $service, 'targets' ) ) {
			return;
		}
		if ( $product instanceof \WC_Product && 'ticket' === $product->get_meta( '_vcap_type' ) ) {
			echo '<p class="form-field vcap-passcaptain-hint"><span class="description">' . esc_html__( 'Tip: the voucher type "Ticket" is a simple admission pass without dates or capacity, checked in at Voucher Captain. For events with dates, capacity and admission rules, create them in WP Pass Captain and use a voucher with "WP Pass Captain ticket" below (or sell Pass Captain tickets directly).', 'voucher-captain' ) . '</span></p>';
		}
		$current = $product instanceof \WC_Product ? absint( $product->get_meta( TicketContract::META_TYPE ) ) : 0;
		$event   = $product instanceof \WC_Product ? absint( $product->get_meta( TicketContract::META_EVENT ) ) : 0;
		$options = array( '' => __( '— No ticket (regular voucher) —', 'voucher-captain' ) );
		if ( method_exists( $service, 'events' ) ) {
			foreach ( (array) $service->events() as $e ) {
				/* translators: 1: event, 2: number of upcoming dates */
				$options[ 'event:' . $e['event_id'] ] = sprintf( __( 'Customer chooses the date: %1$s (%2$d dates)', 'voucher-captain' ), $e['label'], (int) $e['dates'] );
			}
		}
		foreach ( (array) $service->targets() as $t ) {
			/* translators: %s: event, date and ticket type */
			$options[ (string) $t['ticket_type_id'] ] = sprintf( __( 'Fixed date: %s', 'voucher-captain' ), $t['label'] );
		}
		if ( $event && ! isset( $options[ 'event:' . $event ] ) ) {
			/* translators: %d: event id */
			$options[ 'event:' . $event ] = sprintf( __( 'Event #%d (no upcoming dates)', 'voucher-captain' ), $event );
		}
		if ( $current && ! isset( $options[ (string) $current ] ) ) {
			/* translators: %d: ticket type id */
			$options[ (string) $current ] = sprintf( __( 'Ticket type #%d (no longer on sale)', 'voucher-captain' ), $current );
		}
		woocommerce_wp_select(
			array(
				'id'          => TicketContract::META_TYPE,
				'label'       => __( 'WP Pass Captain ticket', 'voucher-captain' ),
				'options'     => $options,
				'value'       => $event ? 'event:' . $event : ( $current ? (string) $current : '' ),
				'desc_tip'    => true,
				'description' => __( 'Exchange each voucher for tickets of this ticket type (redeem page or voucher admin). The voucher is then only exchanged for its tickets; to let it also pay as a value voucher, tick "Shop" under "Redeemable via".', 'voucher-captain' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => TicketContract::META_QTY,
				'label'             => __( 'Tickets per voucher', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => (string) max( 1, absint( $product instanceof \WC_Product ? $product->get_meta( TicketContract::META_QTY ) : 1 ) ),
				'custom_attributes' => array( 'min' => 1, 'max' => 10, 'step' => 1 ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => TicketContract::META_NAME,
				'label'       => __( 'Ticket name (date choice)', 'voucher-captain' ),
				'value'       => $product instanceof \WC_Product ? (string) $product->get_meta( TicketContract::META_NAME ) : '',
				'placeholder' => __( 'Any ticket type', 'voucher-captain' ),
				'desc_tip'    => true,
				'description' => __( 'Only for "customer chooses the date": offer only ticket types with exactly this name (e.g. "Standard").', 'voucher-captain' ),
			)
		);
	}

	public function product_save( \WC_Product $product, $post ): void {
		if ( ! is_array( $post ) || ! array_key_exists( TicketContract::META_TYPE, $post ) ) {
			return; // Field not shown (Pass Captain inactive): keep what is stored.
		}
		$value = sanitize_text_field( (string) $post[ TicketContract::META_TYPE ] );
		$event = 0 === strpos( $value, 'event:' ) ? absint( substr( $value, 6 ) ) : 0;
		$type  = $event ? 0 : absint( $value );
		$product->update_meta_data( TicketContract::META_TYPE, $type ? $type : '' );
		$product->update_meta_data( TicketContract::META_EVENT, $event ? $event : '' );
		$product->update_meta_data( TicketContract::META_NAME, $event ? sanitize_text_field( (string) ( $post[ TicketContract::META_NAME ] ?? '' ) ) : '' );
		$product->update_meta_data( TicketContract::META_QTY, max( 1, min( 10, absint( $post[ TicketContract::META_QTY ] ?? 1 ) ) ) );
		$channels = array_values( array_filter( (array) $product->get_meta( '_vcap_channels' ) ) );
		if ( ( $type || $event ) && $channels && ! in_array( PassCaptain::CHANNEL, $channels, true ) ) {
			// A ticket voucher must be redeemable via Pass Captain, whatever else was ticked.
			$channels[] = PassCaptain::CHANNEL;
			$product->update_meta_data( '_vcap_channels', $channels );
		}
	}

	/** A fixed-date ticket voucher expires when its event starts (no exchange after the date). */
	public function expires_at_event( $expires, $item ) {
		$line   = $item instanceof \WC_Order_Item_Product ? $item->get_meta( Issuer::LINE_META ) : null;
		$starts = is_array( $line ) ? (string) ( $line[ TicketContract::SNAPSHOT_KEY ]['starts_at'] ?? '' ) : '';
		if ( '' === $starts || ! strtotime( $starts . ' UTC' ) ) {
			return $expires;
		}
		return ! $expires || strtotime( $starts . ' UTC' ) < strtotime( $expires . ' UTC' ) ? $starts : $expires;
	}

	/**
	 * Fixed-date ticket vouchers are only sold while the date has room for their tickets (all of this product in
	 * the cart). The places are taken when the voucher is exchanged; until then Pass Captain may sell them too.
	 */
	public function check_fixed_date_capacity( $passed, $product_id, $quantity = 1 ) {
		if ( ! $passed ) {
			return $passed;
		}
		$product = wc_get_product( (int) $product_id );
		$in_cart = 0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $line ) {
				$in_cart += (int) $line['product_id'] === (int) $product_id ? (int) $line['quantity'] : 0;
			}
		}
		$problem = $product instanceof \WC_Product ? $this->fixed_date_problem( $product, $in_cart + max( 1, (int) $quantity ) ) : '';
		if ( '' !== $problem ) {
			wc_add_notice( $problem, 'error' );
			return false;
		}
		return $passed;
	}

	public function check_cart_capacity(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$counts = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			$counts[ (int) $line['product_id'] ] = ( $counts[ (int) $line['product_id'] ] ?? 0 ) + (int) $line['quantity'];
		}
		foreach ( $counts as $product_id => $qty ) {
			$product = wc_get_product( $product_id );
			$problem = $product instanceof \WC_Product ? $this->fixed_date_problem( $product, $qty ) : '';
			if ( '' !== $problem ) {
				wc_add_notice( $problem, 'error' );
			}
		}
	}

	/** Why $vouchers of a fixed-date ticket voucher product cannot be sold right now ('' = fine). */
	public function fixed_date_problem( \WC_Product $product, int $vouchers ): string {
		$type    = absint( $product->get_meta( TicketContract::META_TYPE ) );
		$service = TicketContract::service();
		if ( ! $type || ! $service || ! method_exists( $service, 'targets' ) || ! apply_filters( 'vcap_passcaptain_check_fixed_date_capacity', true, $product ) ) {
			return '';
		}
		$needed = $vouchers * max( 1, min( 10, absint( $product->get_meta( TicketContract::META_QTY ) ) ?: 1 ) );
		$open   = array_map( 'intval', array_column( (array) $service->targets( array( 'quantity' => $needed ) ), 'ticket_type_id' ) );
		/* translators: %s: product name */
		return in_array( $type, $open, true ) ? '' : sprintf( __( '"%s" is not available: the date is sold out or already over.', 'voucher-captain' ), $product->get_name() );
	}

	/** Stores the ticket target with each sold voucher line (later product edits never change it). */
	public function snapshot( $extra, $item ) {
		$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
		$base    = $product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$type    = $base ? absint( $base->get_meta( TicketContract::META_TYPE ) ) : 0;
		$event   = $base ? absint( $base->get_meta( TicketContract::META_EVENT ) ) : 0;
		if ( ! $type && ! $event ) {
			return $extra;
		}
		$label     = '';
		$starts_at = '';
		$service = TicketContract::service();
		if ( $service && $event && method_exists( $service, 'events' ) ) {
			foreach ( (array) $service->events() as $e ) {
				if ( (int) $e['event_id'] === $event ) {
					$label = (string) $e['label'];
				}
			}
		} elseif ( $service && method_exists( $service, 'targets' ) ) {
			foreach ( (array) $service->targets() as $t ) {
				if ( (int) $t['ticket_type_id'] === $type ) {
					$label     = (string) $t['label'];
					$starts_at = (string) ( $t['starts_at'] ?? '' );
				}
			}
		}
		$extra[ TicketContract::SNAPSHOT_KEY ] = array(
			'ticket_type_id' => $event ? 0 : $type,
			'event_id'       => $event,
			'ticket_name'    => $event ? (string) $base->get_meta( TicketContract::META_NAME ) : '',
			'quantity'       => max( 1, min( 10, absint( $base->get_meta( TicketContract::META_QTY ) ) ?: 1 ) ),
			'label'          => $label,
			// Fixed date: the voucher can be exchanged until the event starts (UTC).
			'starts_at'      => $starts_at,
		);
		return $extra;
	}
}
