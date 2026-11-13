<?php
namespace VCAP\Admin;

use VCAP\Campaigns;
use VCAP\Ledger;
use VCAP\Reports;
use VCAP\Voucher;

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

	/** Current report view: campaigns (default), liability or journal. */
	private static function view(): string {
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$views = array( 'liability', 'journal' );
		if ( \VCAP\Compat\PassCaptainReconcile::available() ) {
			$views[] = 'reconcile';
		}
		return in_array( $view, $views, true ) ? $view : 'campaigns';
	}

	private static function tabs( string $current ): void {
		$tabs = array(
			'campaigns' => __( 'Campaigns and codes', 'voucher-captain' ),
			'liability' => __( 'Outstanding value', 'voucher-captain' ),
			'journal'   => __( 'Redemption journal', 'voucher-captain' ),
		);
		if ( \VCAP\Compat\PassCaptainReconcile::available() ) {
			$tabs['reconcile'] = __( 'Pass Captain reconciliation', 'voucher-captain' );
		}
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Reports', 'voucher-captain' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg( array( 'page' => 'vcap-reports', 'view' => $key ), admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $current === $key ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	public function page(): void {
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$view = self::view();
		if ( 'liability' === $view ) {
			$this->liability_page();
			return;
		}
		if ( 'journal' === $view ) {
			$this->journal_page();
			return;
		}
		if ( 'reconcile' === $view ) {
			$this->reconcile_page();
			return;
		}
		list( $from, $to ) = self::range( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$reports           = new Reports();
		$rows              = $reports->collect( $from, $to );
		$csv               = wp_nonce_url( add_query_arg( array( 'action' => 'vcap_report_csv', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'vcap_report_csv' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'voucher-captain' ); ?></h1>
			<?php self::tabs( 'campaigns' ); ?>
			<form method="get" class="vcap-report-filter">
				<input type="hidden" name="page" value="vcap-reports" />
				<label><?php esc_html_e( 'From', 'voucher-captain' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
				<label><?php esc_html_e( 'until', 'voucher-captain' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
				<button class="button"><?php esc_html_e( 'Show', 'voucher-captain' ); ?></button>
				<a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV', 'voucher-captain' ); ?></a>
			</form>
			<p class="description"><?php esc_html_e( 'Paid and refunded orders created in this period. Discount = what customers saved (incl. tax); revenue = order total minus refunds. An order with several codes counts for each of them. "Codes issued" is the total for code campaigns and bonus promotions.', 'voucher-captain' ); ?> <?php esc_html_e( 'Vouchers used as a payment after tax count with the amount paid.', 'voucher-captain' ); ?></p>
			<?php if ( $reports->truncated ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: %s: maximum number of orders */
					echo esc_html( sprintf( __( 'Only the newest %s orders of this period were evaluated. Choose a shorter period for complete figures.', 'voucher-captain' ), number_format_i18n( Reports::MAX_ORDERS ) ) );
					?>
				</p></div>
			<?php endif; ?>
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

	/** Stichtag: outstanding voucher value at the end of a day. */
	private function liability_page(): void {
		$date   = self::date_param( 'date', wp_date( 'Y-m-d' ) );
		$report = ( new Ledger( vcap()->vouchers ) )->liability( $date );
		$csv    = wp_nonce_url( add_query_arg( array( 'action' => 'vcap_report_csv', 'view' => 'liability', 'date' => $date ), admin_url( 'admin-post.php' ) ), 'vcap_report_csv' );
		$types  = Voucher::types();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'voucher-captain' ); ?></h1>
			<?php self::tabs( 'liability' ); ?>
			<form method="get" class="vcap-report-filter">
				<input type="hidden" name="page" value="vcap-reports" /><input type="hidden" name="view" value="liability" />
				<label><?php esc_html_e( 'At the end of', 'voucher-captain' ); ?> <input type="date" name="date" value="<?php echo esc_attr( $date ); ?>" /></label>
				<button class="button"><?php esc_html_e( 'Show', 'voucher-captain' ); ?></button>
				<a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV (per voucher)', 'voucher-captain' ); ?></a>
			</form>
			<p class="description"><?php esc_html_e( 'Value customers could still redeem at the end of the chosen day: the balance of gift cards and store credit, and the value of single-use vouchers not yet redeemed or voided. Expired value is shown separately. Percentage and ticket vouchers carry no monetary value and are not included. Have your accountant decide how to book it.', 'voucher-captain' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Currency', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Type', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Valid vouchers', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Outstanding value', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Expired vouchers', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Expired value', 'voucher-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $report['totals'] ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No outstanding voucher value on this day.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $report['totals'] as $currency => $by_type ) : ?>
					<?php foreach ( $by_type as $type => $t ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $currency ); ?></td>
							<td><?php echo esc_html( $types[ $type ] ?? $type ); ?></td>
							<td><?php echo esc_html( (string) $t['count'] ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $t['amount'], array( 'currency' => $currency ) ) ); ?></td>
							<td><?php echo esc_html( (string) $t['expired_count'] ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $t['expired_amount'], array( 'currency' => $currency ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Every booking that moved voucher value in a period. */
	private function journal_page(): void {
		list( $from, $to ) = self::range( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$ledger  = new Ledger( vcap()->vouchers );
		$journal = $ledger->journal( $from, $to, 1000 );
		$total   = count( $journal ) < 1000 ? count( $journal ) : $ledger->journal_count( $from, $to );
		$csv     = wp_nonce_url( add_query_arg( array( 'action' => 'vcap_report_csv', 'view' => 'journal', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'vcap_report_csv' );
		$labels  = \VCAP\VoucherRepository::event_labels();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'voucher-captain' ); ?></h1>
			<?php self::tabs( 'journal' ); ?>
			<form method="get" class="vcap-report-filter">
				<input type="hidden" name="page" value="vcap-reports" /><input type="hidden" name="view" value="journal" />
				<label><?php esc_html_e( 'From', 'voucher-captain' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
				<label><?php esc_html_e( 'until', 'voucher-captain' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
				<button class="button"><?php esc_html_e( 'Show', 'voucher-captain' ); ?></button>
				<a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV', 'voucher-captain' ); ?></a>
			</form>
			<p class="description"><?php esc_html_e( 'Redemptions, refunds, credits and corrections with their amount, in booking order. The page shows the first 1,000 bookings; the CSV export contains all of them.', 'voucher-captain' ); ?></p>
			<?php if ( $total > count( $journal ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: 1: bookings shown, 2: bookings in the period */
					echo esc_html( sprintf( __( 'Showing %1$s of %2$s bookings. Export the CSV for the complete journal.', 'voucher-captain' ), number_format_i18n( count( $journal ) ), number_format_i18n( $total ) ) );
					?>
				</p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Date', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Code', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Booking', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Balance after', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Order', 'voucher-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $journal ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No bookings in this period.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $journal as $r ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $r['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo esc_html( strtoupper( (string) $r['code'] ) ); ?></td>
						<td><?php echo esc_html( $labels[ $r['event'] ] ?? $r['event'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( (float) $r['amount'], array( 'currency' => $r['currency'] ) ) ); ?></td>
						<td><?php echo null === $r['balance_after'] ? '–' : wp_kses_post( wc_price( (float) $r['balance_after'], array( 'currency' => $r['currency'] ) ) ); ?></td>
						<td><?php echo $r['order_id'] ? '#' . esc_html( (string) $r['order_id'] ) : '–'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Differences between Voucher Captain's redemptions and WP Pass Captain's orders. */
	private function reconcile_page(): void {
		$result = ( new \VCAP\Compat\PassCaptainReconcile( vcap()->redemptions ) )->run();
		$kinds  = array(
			'checkout' => __( 'Ticket checkout', 'voucher-captain' ),
			'exchange' => __( 'Ticket voucher', 'voucher-captain' ),
			'orphan'   => __( 'Without order', 'voucher-captain' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'voucher-captain' ); ?></h1>
			<?php self::tabs( 'reconcile' ); ?>
			<p class="description">
				<?php
				/* translators: %d: number of Pass Captain orders checked */
				echo esc_html( sprintf( __( 'Compares the vouchers used in WP Pass Captain (ticket checkout and ticket vouchers) with the redemptions in Voucher Captain: state, amount and refunds. %d Pass Captain orders checked; only differences are listed, nothing is changed.', 'voucher-captain' ), (int) $result['checked'] ) );
				?>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Kind', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Pass Captain order', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Redemption key', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Difference', 'voucher-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $result['issues'] ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No differences found.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['issues'] as $issue ) : ?>
					<tr>
						<td><?php echo esc_html( $kinds[ $issue['kind'] ] ?? $issue['kind'] ); ?></td>
						<td><code><?php echo esc_html( $issue['order'] ); ?></code></td>
						<td><code><?php echo esc_html( $issue['key'] ); ?></code></td>
						<td><?php echo esc_html( $issue['problem'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function date_param( string $key, string $default ): string {
		$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : $default;
	}

	public function export(): void {
		check_admin_referer( 'vcap_report_csv' );
		if ( ! current_user_can( Menu::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$view   = self::view();
		$ledger = new Ledger( vcap()->vouchers );
		// Streamed in batches: no row limit and no full report in memory.
		if ( 'liability' === $view ) {
			$date = self::date_param( 'date', wp_date( 'Y-m-d' ) );
			Campaigns::send_csv( 'voucher-liability-' . $date . '.csv', Ledger::liability_csv( $ledger->liability_rows( $date ), $date ) );
			exit;
		}
		list( $from, $to ) = self::range( $_GET );
		if ( 'journal' === $view ) {
			$out = \VCAP\Csv::open( 'voucher-journal-' . $from . '-' . $to . '.csv' );
			\VCAP\Csv::write( $out, Ledger::journal_header() );
			foreach ( $ledger->journal_all( $from, $to ) as $row ) {
				\VCAP\Csv::write( $out, Ledger::journal_line( $row ) );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			exit;
		}
		Campaigns::send_csv( 'voucher-report-' . $from . '-' . $to . '.csv', Reports::csv_rows( ( new Reports() )->collect( $from, $to ) ) );
		exit;
	}
}
