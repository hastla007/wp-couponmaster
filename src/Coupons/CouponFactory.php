<?php
namespace VCAP\Coupons;

use VCAP\CodeIndex;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the WooCommerce coupon of a voucher and keeps its email restriction and enabled state (D14, split from
 * CouponManager).
 */
final class CouponFactory {
	public function __construct( private VoucherRepository $repo ) {}

	/**
	 * The WooCommerce coupon of a code, as the cart resolves it. `new WC_Coupon( $code )` falls back to reading the
	 * code as a post id when no coupon has it (backwards compatibility in WooCommerce): a code that no longer exists
	 * but starts with digits ("45ABC…") would load coupon #45. Like WC_Cart::apply_coupon(), the loaded code must be
	 * the asked one; otherwise an empty coupon with that code is returned (id 0). Virtual coupons
	 * (woocommerce_get_shop_coupon_data) keep working.
	 */
	public static function for_code( string $code ): \WC_Coupon {
		$coupon = new \WC_Coupon( $code );
		if ( wc_strtolower( wc_format_coupon_code( (string) $coupon->get_code() ) ) !== wc_strtolower( wc_format_coupon_code( $code ) ) ) {
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
		}
		return $coupon;
	}

	public function code_exists( string $code ): bool {
		return CodeIndex::taken( $code ); // B4: own tables first (indexed), WooCommerce last, misses cached.
	}

	/**
	 * Creates the WooCommerce coupon of a voucher. A failure (e.g. WC_Data_Exception for an invalid amount) removes
	 * a coupon that was saved already and is passed on, so the caller can roll the voucher back.
	 *
	 * @param string $description Coupon description instead of "… from order #…" (standalone vouchers, store credit).
	 * @throws \Throwable When WooCommerce refuses the coupon data.
	 */
	public function create_coupon( Voucher $voucher, array $config, string $description = '' ): int {
		$coupon = new \WC_Coupon();
		try {
			return $this->build_coupon( $coupon, $voucher, $config, $description );
		} catch ( \Throwable $e ) {
			if ( $coupon->get_id() ) {
				wp_delete_post( $coupon->get_id(), true );
			}
			throw $e;
		}
	}

	private function build_coupon( \WC_Coupon $coupon, Voucher $voucher, array $config, string $description ): int {
		$coupon->set_code( $voucher->code );
		$type = in_array( $voucher->discount_type, array( 'fixed_cart', 'percent', 'fixed_product' ), true ) ? $voucher->discount_type : 'fixed_cart';
		if ( $voucher->is_multi_use() ) {
			$type = 'fixed_cart'; // A stored balance is one amount for the whole cart, never per item.
		}
		$coupon->set_discount_type( $type );
		$coupon->set_amount( $voucher->is_multi_use() ? $voucher->balance : $voucher->initial_amount );
		$coupon->set_description(
			'' !== $description ? $description : sprintf(
				/* translators: 1: voucher type, 2: order number */
				__( '%1$s from order #%2$s (Voucher Captain)', 'voucher-captain' ),
				Voucher::types()[ $voucher->type ] ?? $voucher->type,
				$voucher->order() ? $voucher->order()->get_order_number() : $voucher->order_id
			)
		);
		if ( $voucher->expires_at ) {
			$coupon->set_date_expires( strtotime( $voucher->expires_at . ' UTC' ) );
		}
		switch ( $voucher->usage_mode ) {
			case 'balance':
				$coupon->set_usage_limit( 0 );
				break;
			case 'unlimited':
				$coupon->set_usage_limit( (int) $config['usage_limit'] > 0 ? (int) $config['usage_limit'] : 0 );
				break;
			default:
				$coupon->set_usage_limit( 1 );
		}
		$coupon->set_individual_use( 'yes' === $config['individual_use'] );
		$coupon->set_exclude_sale_items( 'yes' === $config['exclude_sale'] );
		$coupon->set_free_shipping( 'yes' === $config['free_shipping'] );
		if ( '' !== (string) $config['min_spend'] ) {
			$coupon->set_minimum_amount( wc_format_decimal( $config['min_spend'] ) );
		}
		if ( '' !== (string) $config['max_spend'] ) {
			$coupon->set_maximum_amount( wc_format_decimal( $config['max_spend'] ) );
		}
		$coupon->set_product_ids( array_map( 'intval', (array) $config['product_ids'] ) );
		$coupon->set_excluded_product_ids( array_map( 'intval', (array) $config['exclude_product_ids'] ) );
		$coupon->set_product_categories( array_map( 'intval', (array) $config['category_ids'] ) );
		$coupon->set_excluded_product_categories( array_map( 'intval', (array) $config['exclude_category_ids'] ) );

		$restrict = (string) $config['email_restrict'];
		if ( ! $voucher->transferable && 'none' === $restrict ) {
			$restrict = 'both'; // Personal voucher: only buyer and named recipient.
		}
		$config['email_restrict'] = $restrict;
		$coupon->set_email_restrictions( self::email_restrictions( $voucher, $restrict ) );
		$coupon->update_meta_data( '_vcap_email_restrict', $restrict );
		if ( 'recipient' === $restrict && ! is_email( $voucher->recipient_email ) && $voucher->id ) {
			$this->repo->log( $voucher->id, 'note', null, null, __( 'No recipient email was given: redemption is limited to the buyer email instead.', 'voucher-captain' ) );
		}

		$coupon->update_meta_data( '_vcap_voucher', 'yes' );
		$coupon->update_meta_data( '_vcap_voucher_code', $voucher->code );
		if ( $voucher->is_ticket() ) {
			// Tickets are admission passes: do not give a cart discount unless an amount was configured.
			$coupon->update_meta_data( '_vcap_ticket', 'yes' );
		}

		$coupon = apply_filters( 'vcap_coupon_before_save', $coupon, $voucher, $config );
		$coupon->save();
		return (int) $coupon->get_id();
	}

	/**
	 * Email addresses a voucher's coupon is limited to for a "who may redeem" setting (none, recipient, buyer,
	 * both). "recipient" without a valid recipient address falls back to the buyer – never silently unrestricted.
	 *
	 * @return string[]
	 */
	public static function email_restrictions( Voucher $voucher, string $restrict ): array {
		$emails = array();
		if ( 'recipient' === $restrict ) {
			$emails[] = is_email( $voucher->recipient_email ) ? $voucher->recipient_email : $voucher->buyer_email;
		} elseif ( 'buyer' === $restrict ) {
			$emails[] = $voucher->buyer_email;
		} elseif ( 'both' === $restrict ) {
			$emails = array( $voucher->buyer_email, $voucher->recipient_email );
		}
		return array_values( array_unique( array_filter( $emails, 'is_email' ) ) );
	}

	/**
	 * Recompute the email restriction of a voucher's coupon after buyer or recipient addresses changed. The
	 * setting comes from the coupon (meta "_vcap_email_restrict"); older coupons are read from their addresses.
	 *
	 * @param string $old_recipient Recipient email before the change.
	 */
	public function refresh_email_restrictions( Voucher $voucher, string $old_recipient = '' ): bool {
		if ( ! $voucher->coupon_id ) {
			return true;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		if ( ! $coupon->get_id() ) {
			return false;
		}
		$current  = array_map( 'strtolower', (array) $coupon->get_email_restrictions() );
		$restrict = (string) $coupon->get_meta( '_vcap_email_restrict' );
		if ( '' === $restrict ) {
			if ( ! $current ) {
				return true; // Never restricted: stays transferable.
			}
			$has_buyer     = in_array( strtolower( $voucher->buyer_email ), $current, true );
			$has_recipient = '' !== $old_recipient && in_array( strtolower( $old_recipient ), $current, true );
			$restrict      = $has_buyer && $has_recipient ? 'both' : ( $has_recipient ? 'recipient' : ( $has_buyer ? 'buyer' : '' ) );
			if ( '' === $restrict ) {
				return true; // Addresses set by hand: leave them alone.
			}
		}
		$emails                 = self::email_restrictions( $voucher, $restrict );
		$this->restriction_kept = false;
		if ( ! $emails ) {
			// Neither address is usable (erased buyer, placeholder): a personal voucher must never silently become
			// transferable. The previous restriction stays, else an address nobody can have, until an
			// administrator enters a valid address (VoucherAdmin shows a warning).
			$emails                 = $current ? $current : array( 'unassigned-' . $voucher->id . '@invalid.invalid' );
			$this->restriction_kept = true;
		}
		$coupon->set_email_restrictions( $emails );
		$coupon->update_meta_data( '_vcap_email_restrict', $restrict );
		$coupon->save();
		return true;
	}

	public function set_coupon_enabled( Voucher $voucher, bool $enabled ): bool {
		if ( ! $voucher->coupon_id ) {
			return true;
		}
		return CouponSync::set_coupon_post_status( (int) $voucher->coupon_id, (string) $voucher->code, $enabled );
	}

	/** Whether the last refresh_email_restrictions() kept the old restriction for want of a valid address. */
	public bool $restriction_kept = false;
}
