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
$value = 'percent' === $voucher->discount_type
	? \VCAP\Format::percent( $voucher->initial_amount )
	: wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) );
?>

<div><?php echo wp_kses_post( wpautop( wptexturize( $intro ) ) ); ?></div>

<table cellspacing="0" cellpadding="12" border="0" style="width:100%;border:2px dashed #c9b28a;margin:16px 0;" role="presentation">
	<tr>
		<td style="text-align:center;">
			<?php if ( ! $voucher->is_ticket() || $voucher->initial_amount > 0 ) : ?>
				<div style="font-size:28px;font-weight:bold;margin-bottom:6px;"><?php echo wp_kses_post( $value ); ?></div>
			<?php endif; ?>
			<div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#666;"><?php esc_html_e( 'Your code', 'voucher-captain' ); ?></div>
			<div style="font-family:monospace;font-size:22px;font-weight:bold;letter-spacing:2px;margin:4px 0 8px;"><?php echo esc_html( $voucher->code ); ?></div>
			<?php if ( $voucher->expires_at ) : ?>
				<div style="font-size:13px;color:#555;">
					<?php /* translators: %s: date */ printf( esc_html__( 'Valid until %s', 'voucher-captain' ), esc_html( wp_date( vcap()->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) ) ); ?>
				</div>
			<?php endif; ?>
		</td>
	</tr>
</table>

<?php if ( ! $voucher->transferable || '' !== $voucher->terms ) : ?>
	<p style="font-size:12px;color:#666;margin:0 0 16px;">
		<?php if ( ! $voucher->transferable ) : ?>
			<?php echo esc_html( $voucher->transferable_label() ); ?><br>
		<?php endif; ?>
		<?php echo wp_kses_post( nl2br( esc_html( $voucher->terms ) ) ); ?>
	</p>
<?php endif; ?>

<?php if ( $voucher->message ) : ?>
	<blockquote style="border-<?php echo esc_attr( $vcap_start ); ?>:4px solid #c9b28a;margin:0 0 16px;padding:8px 14px;font-style:italic;">
		<?php echo wp_kses_post( nl2br( esc_html( $voucher->message ) ) ); ?>
		<?php if ( $voucher->buyer_name ) : ?>
			<br>— <?php echo esc_html( $voucher->buyer_name ); ?>
		<?php endif; ?>
	</blockquote>
<?php endif; ?>

<?php if ( $download_url ) : ?>
	<?php // F16: "bulletproof" button – the colour sits on the table cell, so Outlook shows it as well. ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:20px auto;"><tr>
		<td bgcolor="#1f3a4d" style="background:#1f3a4d;border-radius:4px;text-align:center;">
			<a class="vcap-email-button" href="<?php echo esc_url( $download_url ); ?>" style="display:inline-block;padding:12px 22px;border:1px solid #1f3a4d;border-radius:4px;background:#1f3a4d;color:#ffffff;text-decoration:none;font-weight:bold;">
				<?php echo $voucher->is_ticket() ? esc_html__( 'Download ticket (PDF)', 'voucher-captain' ) : esc_html__( 'Download voucher (PDF)', 'voucher-captain' ); ?>
			</a>
		</td>
	</tr></table>
<?php endif; ?>

<?php $vcap_links = \VCAP\Downloads::voucher_links( $voucher, 'email' ); // Extra links of add-ons (filter vcap_voucher_links). ?>
<?php if ( $vcap_links ) : ?>
	<p class="vcap-voucher-links" style="text-align:center;margin:0 0 16px;">
		<?php foreach ( $vcap_links as $vcap_i => $vcap_link ) : ?>
			<?php echo esc_html( $vcap_i ? ' · ' : '' ); ?><a href="<?php echo esc_url( $vcap_link['url'] ); ?>"><?php echo esc_html( $vcap_link['label'] ); ?></a>
		<?php endforeach; ?>
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
			<td bgcolor="#1f3a4d" style="background:#1f3a4d;border-radius:4px;text-align:center;">
				<a class="vcap-email-button" href="<?php echo esc_url( $redeem_url ); ?>" style="display:inline-block;padding:12px 22px;border:1px solid #1f3a4d;border-radius:4px;background:#1f3a4d;color:#ffffff;text-decoration:none;font-weight:bold;"><?php esc_html_e( 'Redeem now', 'voucher-captain' ); ?></a>
			</td>
		</tr></table>
	<?php endif; ?>
<?php elseif ( '' !== \VCAP\Channels::instructions( $voucher ) ) : ?>
	<p style="font-size:13px;color:#555;">
		<?php echo \VCAP\Channels::instructions_html( $voucher ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in instructions_html(). ?>
	</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
