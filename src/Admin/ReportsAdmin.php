<?php
namespace VCAP\Admin;

use VCAP\Campaigns;
use VCAP\Ledger;
use VCAP\Reports;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Vouchers → Reports: redemptions, discount and revenue per code campaign/partner, promotion, bonus and coupon,
 * the outstanding value at a date, the redemption journal and the Pass Captain reconciliation: the accounting
 * record of vouchers already sold.
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
		if ( '' === $from ) {
			// "to" is a local date: count back from it in the shop's time zone (strtotime() would read it as UTC).
			$end  = \VCAP\LocalDate::parse( $to );
			$from = $end ? $end->modify( '-30 days' )->format( 'Y-m-d' ) : wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		}
		return $from <= $to ? array( $from, $to ) : array( $to, $from );
	}

	/**
	 * Current report view: campaigns (default), liability, journal or reconcile. A CSV link without a view is the
	 * campaign report.
	 */
	private static function view(): string {
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$views = array( 'overview', 'campaigns', 'liability', 'journal', 'counter' );
		if ( \VCAP\Compat\PassCaptainReconcile::available() ) {
			$views[] = 'reconcile';
		}
		if ( in_array( $view, $views, true ) ) {
			return $view;
		}
		return 'overview'; // M12-92: the overview is the first page (a CSV link without a view stays the campaign report).
	}

	private static function tabs( string $current ): void {
		$tabs = array(
			'overview'  => __( 'Overview', 'voucher-captain' ),
			'campaigns' => __( 'Campaigns and codes', 'voucher-captain' ),
			'liability' => __( 'Outstanding value', 'voucher-captain' ),
			'journal'   => __( 'Redemption journal', 'voucher-captain' ),
			'counter'   => __( 'Tills and staff', 'voucher-captain' ),
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
		if ( 'counter' === $view ) {
			$this->counter_page();
			return;
		}
		if ( 'overview' === $view ) {
			$this->overview_page();
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
				<input type="hidden" name="page" value="vcap-reports" /><input type="hidden" name="view" value="campaigns" />
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

	/** M12-92: key figures of a period (sold, redeemed, open, expired, best-selling products). */
	private function overview_page(): void {
		list( $from, $to ) = self::range( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$o     = Reports::overview( $from, $to );
		$money = static fn( float $v ): string => wp_kses_post( wc_price( $v, array( 'currency' => $o['currency'] ) ) );
		$cards = array(
			array( __( 'Sold', 'voucher-captain' ), $money( $o['sold']['value'] ), sprintf( /* translators: %d: number of vouchers */ _n( '%d voucher from orders', '%d vouchers from orders', $o['sold']['count'], 'voucher-captain' ), $o['sold']['count'] ) ),
			array( __( 'Issued without order', 'voucher-captain' ), $money( $o['free']['value'] ), sprintf( /* translators: %d: number of vouchers */ _n( '%d voucher (goodwill, prizes, imports)', '%d vouchers (goodwill, prizes, imports)', $o['free']['count'], 'voucher-captain' ), $o['free']['count'] ) ),
			array( __( 'Redeemed', 'voucher-captain' ), $money( $o['redeemed'] ), __( 'net of cancelled redemptions and refunds', 'voucher-captain' ) ),
			array( __( 'Open', 'voucher-captain' ), $money( $o['open']['value'] ), sprintf( /* translators: 1: number of vouchers, 2: date */ _n( '%1$d voucher with value left on %2$s', '%1$d vouchers with value left on %2$s', $o['open']['count'], 'voucher-captain' ), $o['open']['count'], $to ) ),
			array( __( 'Expired', 'voucher-captain' ), $money( $o['expired']['value'] ), sprintf( /* translators: %d: number of vouchers */ _n( '%d voucher expired with value left', '%d vouchers expired with value left', $o['expired']['count'], 'voucher-captain' ), $o['expired']['count'] ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'voucher-captain' ); ?></h1>
			<?php self::tabs( 'overview' ); ?>
			<form method="get" class="vcap-report-filter">
				<input type="hidden" name="page" value="vcap-reports" /><input type="hidden" name="view" value="overview" />
				<label><?php esc_html_e( 'From', 'voucher-captain' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
				<label><?php esc_html_e( 'until', 'voucher-captain' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
				<button class="button"><?php esc_html_e( 'Show', 'voucher-captain' ); ?></button>
			</form>
			<?php /* translators: %s: currency code */ ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'Vouchers in the shop currency (%s); store credit is not counted as sold. Details: "Outstanding value" (per day) and "Redemption journal" (every booking).', 'voucher-captain' ), $o['currency'] ) ); ?></p>
			<div class="vcap-report-cards" style="display:flex;flex-wrap:wrap;gap:12px;margin:1em 0">
				<?php foreach ( $cards as $card ) : ?>
					<div class="vcap-card" style="flex:1 1 180px;margin:0">
						<h2 style="margin-top:0"><?php echo esc_html( $card[0] ); ?></h2>
						<p style="font-size:1.6em;margin:.2em 0"><strong><?php echo $card[1]; // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() through wp_kses_post(). ?></strong></p>
						<p class="description"><?php echo esc_html( $card[2] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
			<h2><?php esc_html_e( 'Best-selling voucher products', 'voucher-captain' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Product', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Vouchers sold', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Value', 'voucher-captain' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $o['top'] ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No vouchers were sold in this period.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $o['top'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( VoucherLinks::product_url( $row['product_id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
						<td><?php echo esc_html( (string) $row['count'] ); ?></td>
						<td><?php echo $money( $row['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() through wp_kses_post(). ?></td>
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
			<p class="description"><?php esc_html_e( 'Value customers could still redeem at the end of the chosen day: the balance of gift cards and store credit, and the value of single-use vouchers not yet redeemed or voided. Expired value is shown separately, and vouchers issued free of charge (goodwill, prizes) are shown as part of the total. Percentage vouchers and tickets without a value carry no monetary value and are not included. Have your accountant decide how to book it.', 'voucher-captain' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Currency', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Type', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Valid vouchers', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Outstanding value', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Of which free of charge', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Expired vouchers', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Expired value', 'voucher-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $report['totals'] ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No outstanding voucher value on this day.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $report['totals'] as $currency => $by_type ) : ?>
					<?php foreach ( $by_type as $type => $t ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $currency ); ?></td>
							<td><?php echo esc_html( $types[ $type ] ?? $type ); ?></td>
							<td><?php echo esc_html( (string) $t['count'] ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $t['amount'], array( 'currency' => $currency ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $t['free_count'] ?? 0 ) ) . ' / ' . wp_kses_post( wc_price( (float) ( $t['free_amount'] ?? 0 ), array( 'currency' => $currency ) ) ); ?></td>
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
				<?php if ( \VCAP\DatevExport::configured() ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'format', 'datev', $csv ) ); ?>"><?php esc_html_e( 'Export DATEV (EXTF)', 'voucher-captain' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=vcap&section=api' ) ); ?>"><?php esc_html_e( 'Set up the DATEV export', 'voucher-captain' ); ?></a>
				<?php endif; ?>
			</form>
			<p class="description"><?php esc_html_e( 'Redemptions, refunds, credits and corrections with their amount, in booking order. Value taken off a voucher is negative, value added is positive. The page shows the first 1,000 bookings; the CSV export contains all of them.', 'voucher-captain' ); ?></p>
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
					<th><?php esc_html_e( 'Till / branch', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Receipt no.', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'VAT treatment', 'voucher-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $journal ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No bookings in this period.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $journal as $r ) : ?>
					<tr>
						<td><?php echo esc_html( \VCAP\LocalDate::from_utc( $r['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo esc_html( strtoupper( (string) $r['code'] ) ); ?></td>
						<td><?php echo esc_html( $labels[ $r['event'] ] ?? $r['event'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( \VCAP\Ledger::signed_amount( (string) $r['event'], $r['amount'] ), array( 'currency' => $r['currency'] ) ) ); ?></td>
						<td><?php echo null === $r['balance_after'] ? '–' : wp_kses_post( wc_price( (float) $r['balance_after'], array( 'currency' => $r['currency'] ) ) ); ?></td>
						<td><?php echo \VCAP\Format::order_link( $r['order_id'], '–' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in order_link(). ?></td>
						<td><?php echo esc_html( '' !== (string) ( $r['till'] ?? '' ) ? (string) $r['till'] : '–' ); ?></td>
						<td><?php echo esc_html( '' !== (string) ( $r['receipt'] ?? '' ) ? (string) $r['receipt'] : '–' ); ?></td>
						<td><?php echo esc_html( \VCAP\ProductConfig::treatment_label( \VCAP\Ledger::row_treatment( $r ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Report grouping of the counter report: 'till' (default), 'user' or 'both'. */
	private static function counter_group(): string {
		$group = sanitize_key( wp_unslash( $_GET['group'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		return in_array( $group, array( 'till', 'user', 'both' ), true ) ? $group : 'till';
	}

	/** R6: counter redemptions per till/branch and staff member in a period. */
	private function counter_page(): void {
		list( $from, $to ) = self::range( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$group  = self::counter_group();
		$rows   = ( new Ledger( vcap()->vouchers ) )->counter_totals( $from, $to, $group );
		$csv    = wp_nonce_url( add_query_arg( array( 'action' => 'vcap_report_csv', 'view' => 'counter', 'from' => $from, 'to' => $to, 'group' => $group ), admin_url( 'admin-post.php' ) ), 'vcap_report_csv' );
		$groups = array(
			'till' => __( 'Till / branch', 'voucher-captain' ),
			'user' => __( 'Staff member', 'voucher-captain' ),
			'both' => __( 'Till and staff member', 'voucher-captain' ),
		);
		$cols   = 6 + ( 'both' === $group ? 2 : 1 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'voucher-captain' ); ?></h1>
			<?php self::tabs( 'counter' ); ?>
			<form method="get" class="vcap-report-filter">
				<input type="hidden" name="page" value="vcap-reports" /><input type="hidden" name="view" value="counter" />
				<label><?php esc_html_e( 'From', 'voucher-captain' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
				<label><?php esc_html_e( 'until', 'voucher-captain' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
				<label><?php esc_html_e( 'Group by', 'voucher-captain' ); ?>
					<select name="group">
						<?php foreach ( $groups as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $group, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button class="button"><?php esc_html_e( 'Show', 'voucher-captain' ); ?></button>
				<a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV', 'voucher-captain' ); ?></a>
			</form>
			<p class="description"><?php esc_html_e( 'Redemptions at the counter (scanner and "Mark as redeemed (store)") with the till/branch set on the device, per currency. Cancellations count at the till of the redemption they cancel. Amounts include gift cards and value vouchers; percentage, ticket and reusable vouchers count only as redemptions.', 'voucher-captain' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<?php if ( 'user' !== $group ) : ?>
						<th><?php esc_html_e( 'Till / branch', 'voucher-captain' ); ?></th>
					<?php endif; ?>
					<?php if ( 'till' !== $group ) : ?>
						<th><?php esc_html_e( 'Staff member', 'voucher-captain' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Currency', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Redemptions', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Amount redeemed', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Cancellations', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Amount cancelled', 'voucher-captain' ); ?></th>
					<th><?php esc_html_e( 'Net amount', 'voucher-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="<?php echo esc_attr( (string) $cols ); ?>"><?php esc_html_e( 'No counter redemptions in this period.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<?php if ( 'user' !== $group ) : ?>
							<td><?php echo esc_html( '' !== $r['till'] ? $r['till'] : __( '(no till set)', 'voucher-captain' ) ); ?></td>
						<?php endif; ?>
						<?php if ( 'till' !== $group ) : ?>
							<td><?php echo esc_html( Ledger::staff_name( (int) $r['user_id'] ) ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( $r['currency'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['count'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $r['amount'], array( 'currency' => $r['currency'] ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['reversed_count'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $r['reversed_amount'], array( 'currency' => $r['currency'] ) ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $r['net'], array( 'currency' => $r['currency'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php $failed = \VCAP\Counter::failed_lookups( $from, $to ); ?>
			<h2><?php esc_html_e( 'Unknown codes looked up in the scanner', 'voucher-captain' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Lookups of codes that do not exist, per staff member. Many of them can mean typing errors or a damaged print – or someone trying out codes. Above 100 within 15 minutes the scanner of that staff member pauses.', 'voucher-captain' ); ?></p>
			<table class="widefat striped vcap-failed-lookups">
				<thead><tr><th><?php esc_html_e( 'Staff member', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Unknown codes', 'voucher-captain' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $failed ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'None in this period.', 'voucher-captain' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $failed as $user_id => $count ) : ?>
					<tr><td><?php echo esc_html( Ledger::staff_name( (int) $user_id ) ); ?></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
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
			<?php if ( ! empty( $result['unavailable'] ) ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( $result['unavailable'] ); ?></p></div>
			</div>
				<?php
				return;
			endif;
			?>
			<?php if ( 'table' === ( $result['source'] ?? '' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'This version of WP Pass Captain has no reconciliation interface yet, so its order table is read directly as a fallback. Please update WP Pass Captain.', 'voucher-captain' ); ?></p></div>
			<?php endif; ?>
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
		// Streamed in batches: no row limit and no full report in memory; each file ends with "End of export: N".
		if ( 'liability' === $view ) {
			$date = self::date_param( 'date', wp_date( 'Y-m-d' ) );
			\VCAP\Csv::send( 'voucher-liability-' . $date . '.csv', $ledger->liability_export( $date ) );
			exit;
		}
		list( $from, $to ) = self::range( $_GET );
		if ( 'counter' === $view ) {
			$group = self::counter_group();
			\VCAP\Csv::send( 'voucher-counter-' . $group . '-' . $from . '-' . $to . '.csv', $ledger->counter_export( $from, $to, $group ) );
			exit;
		}
		if ( 'journal' === $view ) {
			if ( 'datev' === sanitize_key( wp_unslash( $_GET['format'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
				if ( ! \VCAP\DatevExport::configured() ) {
					wp_die( esc_html__( 'Please set the consultant and client number and the three accounts of the DATEV export first (WooCommerce → Settings → Vouchers → Integrations).', 'voucher-captain' ), 400 );
				}
				\VCAP\DatevExport::send( $ledger, $from, $to ); // M12-69.
				exit;
			}
			\VCAP\Csv::send( 'voucher-journal-' . $from . '-' . $to . '.csv', $ledger->journal_export( $from, $to ) );
			exit;
		}
		Campaigns::send_csv( 'voucher-report-' . $from . '-' . $to . '.csv', Reports::csv_rows( ( new Reports() )->collect( $from, $to ) ) );
		exit;
	}
}
