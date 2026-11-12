<?php
/**
 * Ticket / voucher QR landing page.
 *
 * Override: yourtheme/woocommerce/voucher-captain/ticket-status.php
 *
 * @var \VCAP\Voucher|null $voucher
 * @var array|null         $data
 * @var bool               $staff
 * @var bool               $public
 * @var string             $notice
 */

defined( 'ABSPATH' ) || exit;

$colors = array(
	'ok'      => '#1e7e34',
	'warning' => '#b26a00',
	'error'   => '#b00020',
);
$state  = $data['state'] ?? 'error';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Ticket status', 'voucher-captain' ); ?> – <?php bloginfo( 'name' ); ?></title>
	<style>
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;margin:0;padding:24px;color:#1f2933}
		.card{max-width:420px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);overflow:hidden}
		.state{padding:22px;color:#fff;font-size:22px;font-weight:700;text-align:center}
		.body{padding:20px}
		dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0}
		dt{color:#6b7280}
		.screen-reader-text{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
		dd{margin:0;font-weight:600}
		.notice{background:#e8f4ea;color:#1e7e34;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-weight:600}
		form{margin-top:18px;display:flex;gap:8px}
		input[type=number]{width:80px;padding:10px;font-size:16px}
		button{flex:1;padding:12px;font-size:17px;font-weight:700;border:0;border-radius:8px;background:#1f3a4d;color:#fff;cursor:pointer}
		a{color:#1f3a4d}
	</style>
</head>
<body>
<main class="card" role="main">
	<?php if ( ! $voucher || ( ! $staff && ! $public ) ) : ?>
		<div class="state" style="background:<?php echo esc_attr( $colors['error'] ); ?>"><?php esc_html_e( 'Unknown ticket', 'voucher-captain' ); ?></div>
		<div class="body"><p><?php esc_html_e( 'This code could not be verified.', 'voucher-captain' ); ?></p></div>
	<?php else : ?>
		<div class="state" role="status" style="background:<?php echo esc_attr( $colors[ $state ] ?? $colors['error'] ); ?>"><?php echo esc_html( $data['message'] ); ?></div>
		<div class="body">
			<?php if ( $notice ) : ?>
				<div class="notice" role="alert"><?php echo esc_html( $notice ); ?></div>
			<?php endif; ?>
			<dl>
				<dt><?php esc_html_e( 'Product', 'voucher-captain' ); ?></dt><dd><?php echo esc_html( $data['product'] ); ?></dd>
				<?php if ( $voucher->is_ticket() ) : ?>
					<dt><?php esc_html_e( 'Admissions', 'voucher-captain' ); ?></dt><dd><?php echo esc_html( $data['checkins'] . ' / ' . $data['max_checkins'] ); ?></dd>
				<?php endif; ?>
				<?php if ( $data['expires'] ) : ?>
					<dt><?php esc_html_e( 'Valid until', 'voucher-captain' ); ?></dt><dd><?php echo esc_html( $data['expires'] ); ?></dd>
				<?php endif; ?>
				<?php if ( $staff ) : ?>
					<dt><?php esc_html_e( 'Code', 'voucher-captain' ); ?></dt><dd><?php echo esc_html( $data['code'] ); ?></dd>
					<dt><?php esc_html_e( 'Holder', 'voucher-captain' ); ?></dt><dd><?php echo esc_html( $data['holder'] ); ?></dd>
					<dt><?php esc_html_e( 'Order', 'voucher-captain' ); ?></dt><dd>#<?php echo esc_html( $data['order'] ); ?></dd>
					<?php if ( $data['multi_use'] ) : ?>
						<dt><?php esc_html_e( 'Balance', 'voucher-captain' ); ?></dt><dd><?php echo esc_html( $data['balance_html'] ); ?></dd>
					<?php endif; ?>
					<?php if ( $data['last_checkin'] ) : ?>
						<dt><?php esc_html_e( 'Last check-in', 'voucher-captain' ); ?></dt><dd><?php echo esc_html( $data['last_checkin'] ); ?></dd>
					<?php endif; ?>
				<?php endif; ?>
			</dl>
			<?php if ( $staff && $voucher->is_ticket() && 'ok' === $state ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'vcap_checkin_' . $voucher->id, 'vcap_checkin_nonce' ); ?>
					<?php if ( $voucher->max_checkins - $voucher->checkins > 1 ) : ?>
						<label class="screen-reader-text" for="vcap-count"><?php esc_html_e( 'Admissions', 'voucher-captain' ); ?></label>
						<input type="number" id="vcap-count" name="count" value="1" min="1" max="<?php echo esc_attr( (string) ( $voucher->max_checkins - $voucher->checkins ) ); ?>">
					<?php endif; ?>
					<button type="submit"><?php esc_html_e( 'Check in', 'voucher-captain' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( $staff ) : ?>
				<p><a href="<?php echo esc_url( $data['admin_url'] ); ?>"><?php esc_html_e( 'Open in admin', 'voucher-captain' ); ?></a> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=vcap-scanner' ) ); ?>"><?php esc_html_e( 'Scanner', 'voucher-captain' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</main>
</body>
</html>
