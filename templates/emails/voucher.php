<?php
/**
 * Voucher email (HTML).
 *
 * Override: yourtheme/woocommerce/wc-voucher-studio/emails/voucher.php
 *
 * @var \WCVS\Voucher  $voucher
 * @var string         $email_heading
 * @var string         $intro
 * @var string         $additional_content
 * @var string         $download_url
 * @var \WC_Email      $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$value = 'percent' === $voucher->discount_type
	? wc_format_localized_decimal( $voucher->initial_amount ) . '%'
	: wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) );
?>

<p><?php echo wp_kses_post( wpautop( wptexturize( $intro ) ) ); ?></p>

<table cellspacing="0" cellpadding="12" border="0" style="width:100%;border:2px dashed #c9b28a;margin:16px 0;" role="presentation">
	<tr>
		<td style="text-align:center;">
			<?php if ( ! $voucher->is_ticket() || $voucher->initial_amount > 0 ) : ?>
				<div style="font-size:28px;font-weight:bold;margin-bottom:6px;"><?php echo wp_kses_post( $value ); ?></div>
			<?php endif; ?>
			<div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#777;"><?php esc_html_e( 'Your code', 'wc-voucher-studio' ); ?></div>
			<div style="font-family:monospace;font-size:22px;font-weight:bold;letter-spacing:2px;margin:4px 0 8px;"><?php echo esc_html( $voucher->code ); ?></div>
			<?php if ( $voucher->expires_at ) : ?>
				<div style="font-size:13px;color:#555;">
					<?php /* translators: %s: date */ printf( esc_html__( 'Valid until %s', 'wc-voucher-studio' ), esc_html( wp_date( wcvs()->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) ) ); ?>
				</div>
			<?php endif; ?>
		</td>
	</tr>
</table>

<?php if ( $voucher->message ) : ?>
	<blockquote style="border-left:4px solid #c9b28a;margin:0 0 16px;padding:8px 14px;font-style:italic;">
		<?php echo wp_kses_post( nl2br( esc_html( $voucher->message ) ) ); ?>
		<?php if ( $voucher->buyer_name ) : ?>
			<br>— <?php echo esc_html( $voucher->buyer_name ); ?>
		<?php endif; ?>
	</blockquote>
<?php endif; ?>

<?php if ( $download_url ) : ?>
	<p style="text-align:center;margin:20px 0;">
		<a href="<?php echo esc_url( $download_url ); ?>" style="display:inline-block;padding:12px 22px;background:#1f3a4d;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">
			<?php echo $voucher->is_ticket() ? esc_html__( 'Download ticket (PDF)', 'wc-voucher-studio' ) : esc_html__( 'Download voucher (PDF)', 'wc-voucher-studio' ); ?>
		</a>
	</p>
<?php endif; ?>

<?php if ( ! $voucher->is_ticket() ) : ?>
	<p style="font-size:13px;color:#555;">
		<?php
		printf(
			/* translators: %s: shop link */
			esc_html__( 'Enter the code in the cart or at checkout on %s to redeem it.', 'wc-voucher-studio' ),
			'<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</a>'
		);
		?>
	</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
