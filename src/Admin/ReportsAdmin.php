<?php
namespace VCAP\Admin;

use VCAP\Campaigns;
use VCAP\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Vouchers → Reports: redemptions, discount and revenue per code campaign/partner, promotion, bonus and coupon.
 */
final class ReportsAdmin {
	public function register(): void {
		add_action( 'admin_post_vcap_report_csv', array( $this, 'export' ) );
	}

	/** @return array{0:string,1:string} sanitised from/to (Y-m-d), default the last 30 days */
	private static function range( array $in ): array {
		$valid = static fn( $v ) => is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		$to    = $valid( sanitize_text_field( wp_unslash( $in['to'] ?? '' ) ) );
		$from  = $valid( sanitize_text_field( wp_unslash( $in['from'] ?? '' ) ) );
		$to    = '' !== $to ? $to : wp_date( 'Y-m-d' );
		$from  = '' !== $from ? $from : wp_date( 'Y-m-d', strtotime( $to . ' -30 days' ) );
		return $from <= $to ? array( $from, $to ) : array( $to, $from );
	}

	public function page(): void {
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		list( $from, $to ) = self::range( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$rows              = ( new Reports() )->collect( $from, $to );
		$csv               = wp_nonce_url( add_query_arg( array( 'action' => 'vcap_report_csv', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'vcap_report_csv' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'voucher-captain' ); ?></h1>
			<form method="get" class="vcap-report-filter">
				<input type="hidden" name="page" value="vcap-reports" />
				<label><?php esc_html_e( 'From', 'voucher-captain' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
				<label><?php esc_html_e( 'until', 'voucher-captain' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
				<button class="button"><?php esc_html_e( 'Show', 'voucher-captain' ); ?></button>
				<a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV', 'voucher-captain' ); ?></a>
			</form>
			<p class="description"><?php esc_html_e( 'Paid and refunded orders created in this period. Discount = what customers saved (incl. tax); revenue = order total minus refunds. An order with several codes counts for each of them. "Codes issued" is the total for code campaigns and bonus promotions.', 'voucher-captain' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Type', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Name', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Codes issued', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Uses', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Orders', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Discount', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Revenue', 'voucher-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No redemptions in this period.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( Reports::type_label( $r['type'] ) ); ?></td>
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td><?php echo esc_html( null === $r['issued'] ? '–' : (string) $r['issued'] ); ?></td>
						<td><?php echo esc_html( (string) $r['uses'] ); ?></td>
						<td><?php echo esc_html( (string) $r['orders'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $r['discount'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $r['revenue'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function export(): void {
		check_admin_referer( 'vcap_report_csv' );
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		list( $from, $to ) = self::range( $_GET );
		Campaigns::send_csv( 'voucher-report-' . $from . '-' . $to . '.csv', Reports::csv_rows( ( new Reports() )->collect( $from, $to ) ) );
		exit;
	}
}
