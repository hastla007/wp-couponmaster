<?php
/**
 * Bundled voucher email (HTML, Z6): several vouchers of one order with a code table.
 *
 * Override: yourtheme/woocommerce/voucher-captain/emails/voucher-bundle.php
 * @version  1.2.0
 *
 * @var \VCAP\Voucher[]    $vouchers
 * @var \VCAP\Voucher      $voucher            The first voucher (texts, placeholders).
 * @var string             $role               'recipient' or 'buyer'.
 * @var string             $email_heading
 * @var string             $intro
 * @var string             $additional_content
 * @var bool               $pdf_attached       Whether one PDF with all vouchers is attached.
 * @var array<int, string> $download_urls      Signed download link per voucher id ('' = none).
 * @var string             $account_url        My Account page with the vouchers ('' = none).
 * @var bool               $show_product       Vouchers of several products.
 * @var bool               $show_holder        Buyer copy of gifts: the recipient per voucher.
 * @var \WC_Email          $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

// F15: the start side for right-to-left languages (email clients know no logical CSS properties).
$vcap_start   = is_rtl() ? 'right' : 'left';
$vcap_cell    = 'padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:' . $vcap_start . ';vertical-align:top;';
$vcap_links   = (bool) array_filter( $download_urls );
$vcap_expiry  = (bool) array_filter( array_map( static fn( $v ) => $v->expires_at, $vouchers ) );
$vcap_message = count( array_unique( array_map( static fn( $v ) => $v->message, $vouchers ) ) ) === 1 ? $voucher->message : '';
$vcap_terms   = count( array_unique( array_map( static fn( $v ) => $v->terms, $vouchers ) ) ) === 1 ? $voucher->terms : '';
// M12-74: extra links of add-ons per voucher (filter vcap_voucher_links), e.g. "Add to Wallet" badges.
$vcap_extra = array();
foreach ( $vouchers as $vcap_v ) {
	$vcap_extra[ $vcap_v->id ] = \VCAP\Downloads::voucher_links( $vcap_v, 'email' );
}
$vcap_extra_any = (bool) array_filter( $vcap_extra );
?>

<div><?php echo wp_kses_post( wpautop( wptexturize( $intro ) ) ); ?></div>

<?php if ( $pdf_attached ) : ?>
	<p style="font-size:13px;color:#555;"><?php esc_html_e( 'All vouchers are attached as one PDF, one page per voucher, ready to print.', 'voucher-captain' ); ?></p>
<?php elseif ( $vcap_links ) : ?>
	<p style="font-size:13px;color:#555;"><?php esc_html_e( 'Download each voucher as a PDF with the links in the table.', 'voucher-captain' ); ?></p>
<?php endif; ?>

<table cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">
	<thead>
		<tr>
			<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>">#</th>
			<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php esc_html_e( 'Code', 'voucher-captain' ); ?></th>
			<?php if ( $show_product ) : ?>
				<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php esc_html_e( 'Product', 'voucher-captain' ); ?></th>
			<?php endif; ?>
			<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php esc_html_e( 'Value', 'voucher-captain' ); ?></th>
			<?php if ( $vcap_expiry ) : ?>
				<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php esc_html_e( 'Valid until', 'voucher-captain' ); ?></th>
			<?php endif; ?>
			<?php if ( $show_holder ) : ?>
				<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php esc_html_e( 'Recipient', 'voucher-captain' ); ?></th>
			<?php endif; ?>
			<?php if ( $vcap_links ) : ?>
				<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php esc_html_e( 'PDF', 'voucher-captain' ); ?></th>
			<?php endif; ?>
			<?php if ( $vcap_extra_any ) : ?>
				<th scope="col" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php esc_html_e( 'Links', 'voucher-captain' ); ?></th>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( array_values( $vouchers ) as $vcap_i => $vcap_v ) : ?>
			<tr>
				<td style="<?php echo esc_attr( $vcap_cell ); ?>color:#888;"><?php echo esc_html( (string) ( $vcap_i + 1 ) ); ?></td>
				<td style="<?php echo esc_attr( $vcap_cell ); ?>font-family:monospace;font-weight:bold;letter-spacing:1px;"><?php echo esc_html( $vcap_v->code ); ?></td>
				<?php if ( $show_product ) : ?>
					<td style="<?php echo esc_attr( $vcap_cell ); ?>"><?php echo esc_html( $vcap_v->product() ? wp_strip_all_tags( $vcap_v->product()->get_name() ) : '' ); ?></td>
				<?php endif; ?>
				<td style="<?php echo esc_attr( $vcap_cell ); ?>">
					<?php
					if ( ! $vcap_v->is_ticket() || $vcap_v->initial_amount > 0 ) {
						echo wp_kses_post( 'percent' === $vcap_v->discount_type ? \VCAP\Format::percent( $vcap_v->initial_amount ) : wc_price( $vcap_v->initial_amount, array( 'currency' => $vcap_v->currency ) ) );
					}
					?>
				</td>
				<?php if ( $vcap_expiry ) : ?>
					<td style="<?php echo esc_attr( $vcap_cell ); ?>"><?php echo esc_html( $vcap_v->expires_at ? wp_date( vcap()->settings->date_format(), strtotime( $vcap_v->expires_at . ' UTC' ) ) : '' ); ?></td>
				<?php endif; ?>
				<?php if ( $show_holder ) : ?>
					<td style="<?php echo esc_attr( $vcap_cell ); ?>"><?php echo esc_html( \VCAP\Delivery::to_recipient( $vcap_v ) ? ( '' !== trim( $vcap_v->recipient_name ) ? $vcap_v->recipient_name : $vcap_v->recipient_email ) : '' ); ?></td>
				<?php endif; ?>
				<?php if ( $vcap_links ) : ?>
					<td style="<?php echo esc_attr( $vcap_cell ); ?>">
						<?php if ( ! empty( $download_urls[ $vcap_v->id ] ) ) : ?>
							<a href="<?php echo esc_url( $download_urls[ $vcap_v->id ] ); ?>"><?php esc_html_e( 'Download', 'voucher-captain' ); ?></a>
						<?php endif; ?>
					</td>
				<?php endif; ?>
				<?php if ( $vcap_extra_any ) : ?>
					<td class="vcap-voucher-links" style="<?php echo esc_attr( $vcap_cell ); ?>"><?php echo \VCAP\Downloads::voucher_links_html( $vcap_extra[ $vcap_v->id ] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in voucher_links_html(). ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( ! $voucher->transferable || '' !== $vcap_terms ) : ?>
	<p style="font-size:12px;color:#666;margin:0 0 16px;">
		<?php if ( ! $voucher->transferable ) : ?>
			<?php echo esc_html( $voucher->transferable_label() ); ?><br>
		<?php endif; ?>
		<?php echo wp_kses_post( nl2br( esc_html( $vcap_terms ) ) ); ?>
	</p>
<?php endif; ?>

<?php if ( $vcap_message ) : ?>
	<blockquote style="border-<?php echo esc_attr( $vcap_start ); ?>:4px solid #c9b28a;margin:0 0 16px;padding:8px 14px;font-style:italic;">
		<?php echo wp_kses_post( nl2br( esc_html( $vcap_message ) ) ); ?>
		<?php if ( $voucher->buyer_name ) : ?>
			<br>— <?php echo esc_html( $voucher->buyer_name ); ?>
		<?php endif; ?>
	</blockquote>
<?php endif; ?>

<?php if ( $account_url ) : ?>
	<?php // F16: "bulletproof" button – the colour sits on the table cell, so Outlook shows it as well. ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:20px auto;"><tr>
		<td bgcolor="#1f3a4d" style="background:#1f3a4d;border-radius:4px;text-align:center;">
			<a class="vcap-email-button" href="<?php echo esc_url( $account_url ); ?>" style="display:inline-block;padding:12px 22px;border:1px solid #1f3a4d;border-radius:4px;background:#1f3a4d;color:#ffffff;text-decoration:none;font-weight:bold;"><?php esc_html_e( 'All vouchers in your account', 'voucher-captain' ); ?></a>
		</td>
	</tr></table>
<?php endif; ?>

<?php if ( '' !== \VCAP\Channels::instructions( $voucher ) ) : ?>
	<p style="font-size:13px;color:#555;">
		<?php echo \VCAP\Channels::instructions_html( $voucher ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in instructions_html(). ?>
	</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
