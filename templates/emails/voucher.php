<?php
/**
 * Voucher email (HTML).
 *
 * Override: yourtheme/woocommerce/voucher-captain/emails/voucher.php
 * @version  1.2.0
 *
 * @var \VCAP\Voucher  $voucher
 * @var string         $email_heading
 * @var string         $intro
 * @var string         $additional_content
 * @var string         $download_url
 * @var \WC_Email      $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

// F15: the start side for right-to-left languages (email clients know no logical CSS properties).
$vcap_start = is_rtl() ? 'right' : 'left';
// M12-83: colours of WooCommerce → Settings → Emails (base and text colour).
$vcap_c     = isset( $colors ) && is_array( $colors ) ? $colors : \VCAP\Emails\VoucherEmail::colors();
$vcap_muted = $vcap_c['muted'];
$value = 'percent' === $voucher->discount_type
	? \VCAP\Format::percent( $voucher->initial_amount )
	: wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) );
?>

<div><?php echo wp_kses_post( wpautop( wptexturize( $intro ) ) ); ?></div>

<table cellspacing="0" cellpadding="12" border="0" style="width:100%;border:2px dashed <?php echo esc_attr( $vcap_c['accent'] ); ?>;margin:16px 0;" role="presentation">
	<tr>
		<td style="text-align:center;">
			<?php if ( ! $voucher->is_ticket() || $voucher->initial_amount > 0 ) : ?>
				<div style="font-size:28px;font-weight:bold;margin-bottom:6px;"><?php echo wp_kses_post( $value ); ?></div>
			<?php endif; ?>
			<div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:<?php echo esc_attr( $vcap_c['muted'] ); ?>;"><?php esc_html_e( 'Your code', 'voucher-captain' ); ?></div>
			<div style="font-family:monospace;font-size:22px;font-weight:bold;letter-spacing:2px;margin:4px 0 8px;"><?php echo esc_html( $voucher->code ); ?></div>
			<?php if ( $voucher->expires_at ) : ?>
				<div style="font-size:13px;color:<?php echo esc_attr( $vcap_c['muted'] ); ?>;">
					<?php /* translators: %s: date */ printf( esc_html__( 'Valid until %s', 'voucher-captain' ), esc_html( wp_date( vcap()->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) ) ); ?>
				</div>
			<?php endif; ?>
		</td>
	</tr>
</table>

<?php if ( ! $voucher->transferable || '' !== $voucher->terms ) : ?>
	<p style="font-size:12px;color:<?php echo esc_attr( $vcap_c['muted'] ); ?>;margin:0 0 16px;">
		<?php if ( ! $voucher->transferable ) : ?>
			<?php echo esc_html( $voucher->transferable_label() ); ?><br>
		<?php endif; ?>
		<?php echo wp_kses_post( nl2br( esc_html( $voucher->terms ) ) ); ?>
	</p>
<?php endif; ?>

<?php if ( $voucher->message ) : ?>
	<blockquote style="border-<?php echo esc_attr( $vcap_start ); ?>:4px solid <?php echo esc_attr( $vcap_c['accent'] ); ?>;margin:0 0 16px;padding:8px 14px;font-style:italic;">
		<?php echo wp_kses_post( nl2br( esc_html( $voucher->message ) ) ); ?>
		<?php $vcap_sender = \VCAP\Emails\VoucherEmail::sender_name( $voucher ); // M12-55. ?>
		<?php if ( $vcap_sender ) : ?>
			<br>— <?php echo esc_html( $vcap_sender ); ?>
		<?php endif; ?>
	</blockquote>
<?php endif; ?>

<?php if ( $download_url ) : ?>
	<?php // F16: "bulletproof" button – the colour sits on the table cell, so Outlook shows it as well. ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:20px auto;"><tr>
		<td bgcolor="<?php echo esc_attr( $vcap_c['base'] ); ?>" style="background:<?php echo esc_attr( $vcap_c['base'] ); ?>;border-radius:4px;text-align:center;">
			<a class="vcap-email-button" href="<?php echo esc_url( $download_url ); ?>" style="display:inline-block;padding:12px 22px;border:1px solid <?php echo esc_attr( $vcap_c['base'] ); ?>;border-radius:4px;background:<?php echo esc_attr( $vcap_c['base'] ); ?>;color:<?php echo esc_attr( $vcap_c['on_base'] ); ?>;text-decoration:none;font-weight:bold;">
				<?php echo $voucher->is_ticket() ? esc_html__( 'Download ticket (PDF)', 'voucher-captain' ) : esc_html__( 'Download voucher (PDF)', 'voucher-captain' ); ?>
			</a>
		</td>
	</tr></table>
<?php endif; ?>

<?php $vcap_links = \VCAP\Downloads::voucher_links( $voucher, 'email' ); // Extra links of add-ons (filter vcap_voucher_links). ?>
<?php if ( $vcap_links ) : ?>
	<p class="vcap-voucher-links" style="text-align:center;margin:0 0 16px;">
		<?php echo \VCAP\Downloads::voucher_links_html( $vcap_links ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in voucher_links_html(); badge images since M12-74. ?>
	</p>
<?php endif; ?>

<?php
/**
 * Steps after receiving the voucher (e.g. "exchange it for tickets on the redeem page"); connectors add them
 * for their voucher types. Empty: the usual "enter the code at checkout" hint.
 */
$next_steps = array_filter( array_map( 'strval', (array) apply_filters( 'vcap_voucher_next_steps', array(), $voucher ) ) );
$redeem_url = $voucher->id ? \VCAP\Shortcodes::redeem_url( $voucher->code ) : '';
?>
<?php if ( $next_steps ) : ?>
	<h3 style="margin:16px 0 8px;"><?php esc_html_e( 'What happens next', 'voucher-captain' ); ?></h3>
	<ol style="margin:0 0 16px;padding-<?php echo esc_attr( $vcap_start ); ?>:20px;">
		<?php foreach ( $next_steps as $step ) : ?>
			<li style="margin-bottom:4px;"><?php echo esc_html( $step ); ?></li>
		<?php endforeach; ?>
	</ol>
	<?php if ( $redeem_url ) : ?>
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:20px auto;"><tr>
			<td bgcolor="<?php echo esc_attr( $vcap_c['base'] ); ?>" style="background:<?php echo esc_attr( $vcap_c['base'] ); ?>;border-radius:4px;text-align:center;">
				<a class="vcap-email-button" href="<?php echo esc_url( $redeem_url ); ?>" style="display:inline-block;padding:12px 22px;border:1px solid <?php echo esc_attr( $vcap_c['base'] ); ?>;border-radius:4px;background:<?php echo esc_attr( $vcap_c['base'] ); ?>;color:<?php echo esc_attr( $vcap_c['on_base'] ); ?>;text-decoration:none;font-weight:bold;"><?php esc_html_e( 'Redeem now', 'voucher-captain' ); ?></a>
			</td>
		</tr></table>
	<?php endif; ?>
<?php elseif ( '' !== \VCAP\Channels::instructions( $voucher ) ) : ?>
	<p style="font-size:13px;color:<?php echo esc_attr( $vcap_c['muted'] ); ?>;">
		<?php echo \VCAP\Channels::instructions_html( $voucher ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in instructions_html(). ?>
	</p>
<?php endif; ?>

<?php if ( ! empty( $correction_url ) ) : ?>
	<?php // M12-47: the buyer's copy of a gift: a mistyped recipient address can be corrected. ?>
	<p class="vcap-fix-recipient" style="font-size:13px;color:<?php echo esc_attr( $vcap_muted ); ?>;">
		<?php esc_html_e( 'Mistyped the recipient address?', 'voucher-captain' ); ?>
		<a href="<?php echo esc_url( $correction_url ); ?>"><?php esc_html_e( 'Correct the recipient', 'voucher-captain' ); ?></a>
	</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
