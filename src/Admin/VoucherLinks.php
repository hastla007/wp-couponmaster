<?php
namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * M12-54: links to the voucher list filtered for a customer (user profile) and for a product (Voucher tab of the
 * product), so "which vouchers does this customer / product have" is one click away.
 */
final class VoucherLinks {
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'profile' ), 5 );
		add_action( 'edit_user_profile', array( $this, 'profile' ), 5 );
	}

	/** Voucher list searched for the customer's email address (bought for or sent to it). */
	public static function customer_url( \WP_User $user ): string {
		return add_query_arg( array( 'page' => 'vcap-vouchers', 's' => rawurlencode( (string) $user->user_email ) ), admin_url( 'admin.php' ) );
	}

	/** Voucher list filtered by a product (its variations included). */
	public static function product_url( int $product_id ): string {
		return add_query_arg( array( 'page' => 'vcap-vouchers', 'product' => $product_id ), admin_url( 'admin.php' ) );
	}

	/** @param mixed $user The profile's user. */
	public function profile( $user ): void {
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email || ! ( current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
			return;
		}
		echo '<h2>' . esc_html__( 'Vouchers', 'voucher-captain' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Vouchers of this customer', 'voucher-captain' ) . '</th><td>';
		echo '<a class="button" href="' . esc_url( self::customer_url( $user ) ) . '">' . esc_html__( 'Show vouchers', 'voucher-captain' ) . '</a>';
		echo '<p class="description">' . esc_html__( 'Vouchers bought with or sent to this customer\'s email address.', 'voucher-captain' ) . '</p></td></tr></table>';
	}
}
