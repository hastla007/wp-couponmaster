<?php
/**
 * Voucher download list (order received page, My Account → order, My Account → Vouchers).
 *
 * Override: yourtheme/woocommerce/wc-voucher-studio/myaccount/vouchers-table.php
 *
 * @var \WCVS\Voucher[]  $vouchers
 * @var \WCVS\Downloads  $downloads
 * @var string           $title
 * @var bool             $show_order
 */

defined( 'ABSPATH' ) || exit;

$show_order = ! empty( $show_order );
?>
<section class="wcvs-vouchers">
	<?php if ( $title ) : ?>
		<h2 class="woocommerce-column__title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>

	<?php if ( ! $vouchers ) : ?>
		<p class="woocommerce-info"><?php esc_html_e( 'You have no vouchers yet.', 'wc-voucher-studio' ); ?></p>
	<?php else : ?>
	<table class="woocommerce-table shop_table shop_table_responsive wcvs-vouchers-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Voucher', 'wc-voucher-studio' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Code', 'wc-voucher-studio' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Value', 'wc-voucher-studio' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'wc-voucher-studio' ); ?></th>
				<?php if ( $show_order ) : ?>
					<th scope="col"><?php esc_html_e( 'Order', 'wc-voucher-studio' ); ?></th>
				<?php endif; ?>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Download', 'wc-voucher-studio' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $vouchers as $voucher ) : ?>
			<?php
			$product = $voucher->product();
			$value   = 'percent' === $voucher->discount_type ? wc_format_localized_decimal( $voucher->initial_amount ) . '%' : wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) );
			if ( $voucher->is_multi_use() ) {
				/* translators: %s: remaining balance */
				$value .= '<br><small>' . sprintf( esc_html__( 'Balance: %s', 'wc-voucher-studio' ), wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) ) ) . '</small>';
			}
			?>
			<tr>
				<td data-title="<?php esc_attr_e( 'Voucher', 'wc-voucher-studio' ); ?>">
					<?php echo esc_html( $product ? $product->get_name() : __( 'Voucher', 'wc-voucher-studio' ) ); ?>
					<?php if ( $voucher->recipient_name ) : ?>
						<br><small><?php /* translators: %s: recipient name */ printf( esc_html__( 'For %s', 'wc-voucher-studio' ), esc_html( $voucher->recipient_name ) ); ?></small>
					<?php endif; ?>
				</td>
				<td data-title="<?php esc_attr_e( 'Code', 'wc-voucher-studio' ); ?>"><code><?php echo esc_html( $voucher->code ); ?></code></td>
				<td data-title="<?php esc_attr_e( 'Value', 'wc-voucher-studio' ); ?>"><?php echo wp_kses_post( $value ); ?></td>
				<td data-title="<?php esc_attr_e( 'Status', 'wc-voucher-studio' ); ?>">
					<?php echo esc_html( $voucher->status_label() ); ?>
					<?php if ( $voucher->expires_at ) : ?>
						<br><small><?php /* translators: %s: date */ printf( esc_html__( 'Valid until %s', 'wc-voucher-studio' ), esc_html( wp_date( wcvs()->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) ) ); ?></small>
					<?php endif; ?>
					<?php if ( \WCVS\Voucher::STATUS_SCHEDULED === $voucher->status && $voucher->delivery_at ) : ?>
						<br><small><?php /* translators: %s: date */ printf( esc_html__( 'Delivery on %s', 'wc-voucher-studio' ), esc_html( get_date_from_gmt( $voucher->delivery_at, wcvs()->settings->date_format() ) ) ); ?></small>
					<?php endif; ?>
				</td>
				<?php if ( $show_order ) : ?>
					<td data-title="<?php esc_attr_e( 'Order', 'wc-voucher-studio' ); ?>">
						<?php
						$order = $voucher->order();
						if ( $order && (int) $order->get_customer_id() === get_current_user_id() ) {
							echo '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
						} else {
							esc_html_e( 'Gift', 'wc-voucher-studio' );
						}
						?>
					</td>
				<?php endif; ?>
				<td class="wcvs-download">
					<?php if ( \WCVS\Voucher::STATUS_VOID !== $voucher->status ) : ?>
						<a class="woocommerce-button button wcvs-download-button" href="<?php echo esc_url( $downloads->signed_url( $voucher ) ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Download PDF', 'wc-voucher-studio' ); ?>
						</a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</section>
