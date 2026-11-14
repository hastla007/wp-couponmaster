<?php
/**
 * Voucher download list (order received page, My Account → order, My Account → Vouchers).
 *
 * Override: yourtheme/woocommerce/voucher-captain/myaccount/vouchers-table.php
 *
 * @var \VCAP\Voucher[]  $vouchers
 * @var \VCAP\Downloads  $downloads
 * @var string           $title
 * @var bool             $show_order
 */

defined( 'ABSPATH' ) || exit;

$show_order = ! empty( $show_order );
?>
<section class="vcap-vouchers">
	<?php if ( $title ) : ?>
		<h2 class="woocommerce-column__title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>

	<?php if ( ! $vouchers ) : ?>
		<p class="woocommerce-info"><?php esc_html_e( 'You have no vouchers yet.', 'voucher-captain' ); ?></p>
	<?php else : ?>
	<table class="woocommerce-table shop_table shop_table_responsive vcap-vouchers-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Voucher', 'voucher-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Code', 'voucher-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Value', 'voucher-captain' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'voucher-captain' ); ?></th>
				<?php if ( $show_order ) : ?>
					<th scope="col"><?php esc_html_e( 'Order', 'voucher-captain' ); ?></th>
				<?php endif; ?>
				<th scope="col" class="vcap-actions-header"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'voucher-captain' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $vouchers as $voucher ) : ?>
			<?php
			$product = $voucher->product();
			$value   = 'percent' === $voucher->discount_type ? wc_format_localized_decimal( $voucher->initial_amount ) . '%' : wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) );
			if ( $voucher->is_multi_use() ) {
				/* translators: %s: remaining balance */
				$value .= '<br><small>' . sprintf( esc_html__( 'Balance: %s', 'voucher-captain' ), wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) ) ) . '</small>';
			}
			?>
			<tr>
				<td data-title="<?php esc_attr_e( 'Voucher', 'voucher-captain' ); ?>">
					<?php echo esc_html( $product ? $product->get_name() : __( 'Voucher', 'voucher-captain' ) ); ?>
					<?php if ( $voucher->recipient_name ) : ?>
						<?php // Brackets as entities: the name was typed by the buyer and must not run a shortcode (the block's output passes do_shortcode()). ?>
						<br><small><?php /* translators: %s: recipient name */ printf( esc_html__( 'For %s', 'voucher-captain' ), \VCAP\Shortcodes::esc_html_text( $voucher->recipient_name ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_html_text(). ?></small>
					<?php endif; ?>
				</td>
				<td data-title="<?php esc_attr_e( 'Code', 'voucher-captain' ); ?>"><code><?php echo esc_html( $voucher->code ); ?></code></td>
				<td data-title="<?php esc_attr_e( 'Value', 'voucher-captain' ); ?>"><?php echo wp_kses_post( $value ); ?></td>
				<td data-title="<?php esc_attr_e( 'Status', 'voucher-captain' ); ?>">
					<?php echo esc_html( $voucher->status_label() ); ?>
					<?php
					/** Extra status lines of connectors (e.g. "2 tickets for … on …, sent to …"). */
					do_action( 'vcap_account_voucher_status', $voucher );
					?>
					<?php if ( $voucher->expires_at ) : ?>
						<br><small><?php /* translators: %s: date */ printf( esc_html__( 'Valid until %s', 'voucher-captain' ), esc_html( wp_date( vcap()->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) ) ); ?></small>
					<?php endif; ?>
					<?php if ( ! $voucher->transferable ) : ?>
						<br><small><?php esc_html_e( 'Personal, not transferable', 'voucher-captain' ); ?></small>
					<?php endif; ?>
					<?php if ( '' !== $voucher->terms ) : ?>
						<details class="vcap-terms"><summary><?php esc_html_e( 'Redemption conditions', 'voucher-captain' ); ?></summary><?php echo wp_kses_post( wpautop( esc_html( $voucher->terms ) ) ); ?></details>
					<?php endif; ?>
					<?php if ( \VCAP\Voucher::STATUS_SCHEDULED === $voucher->status && $voucher->delivery_at ) : ?>
						<br><small><?php /* translators: %s: date */ printf( esc_html__( 'Delivery on %s', 'voucher-captain' ), esc_html( get_date_from_gmt( $voucher->delivery_at, vcap()->settings->date_format() ) ) ); ?></small>
					<?php endif; ?>
				</td>
				<?php if ( $show_order ) : ?>
					<td data-title="<?php esc_attr_e( 'Order', 'voucher-captain' ); ?>">
						<?php
						$order = $voucher->order();
						if ( $order && (int) $order->get_customer_id() === get_current_user_id() ) {
							echo '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
						} else {
							esc_html_e( 'Gift', 'voucher-captain' );
						}
						?>
					</td>
				<?php endif; ?>
				<td class="vcap-download actions" data-title="<?php esc_attr_e( 'Actions', 'voucher-captain' ); ?>">
					<?php
					/** Extra actions of connectors (e.g. "Exchange for tickets", "Send tickets again"). */
					do_action( 'vcap_account_voucher_actions', $voucher );
					?>
					<?php if ( \VCAP\Voucher::STATUS_VOID !== $voucher->status ) : ?>
						<a class="<?php echo esc_attr( \VCAP\Shortcodes::button_class( 'woocommerce-button button vcap-download-button' ) ); ?>" href="<?php echo esc_url( $downloads->signed_url( $voucher ) ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Download PDF', 'voucher-captain' ); ?><?php echo \VCAP\Shortcodes::voucher_suffix( $voucher->code ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in voucher_suffix(). ?>
						</a>
					<?php endif; ?>
					<?php if ( $downloads->customer_can_resend( $voucher ) ) : ?>
						<form method="post" class="vcap-resend-form">
							<input type="hidden" name="vcap_resend" value="<?php echo esc_attr( (string) $voucher->id ); ?>" />
							<?php wp_nonce_field( 'vcap_resend_' . $voucher->id, 'vcap_resend_nonce' ); ?>
							<button type="submit" class="<?php echo esc_attr( \VCAP\Shortcodes::button_class( 'woocommerce-button button vcap-resend-button' ) ); ?>"><?php esc_html_e( 'Send email again', 'voucher-captain' ); ?><?php echo \VCAP\Shortcodes::voucher_suffix( $voucher->code ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in voucher_suffix(). ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</section>
