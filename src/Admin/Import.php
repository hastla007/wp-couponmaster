<?php
namespace VCAP\Admin;

use VCAP\CodePool;
use VCAP\SpreadsheetReader;

defined( 'ABSPATH' ) || exit;

/**
 * Import pre-generated coupon codes (CSV, XLS, XLSX, ODS) into named batches with validation and duplicate handling.
 */
final class Import {
	private const REPORT_TRANSIENT = 'vcap_import_report_';

	public function __construct( private CodePool $pool ) {}

	public function register(): void {
		add_action( 'admin_post_vcap_import', array( $this, 'handle' ) );
		add_action( 'admin_post_vcap_delete_batch', array( $this, 'delete_batch' ) );
	}

	private function can(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
	}

	public function page(): void {
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ) );
		}
		$report = get_transient( self::REPORT_TRANSIENT . get_current_user_id() );
		delete_transient( self::REPORT_TRANSIENT . get_current_user_id() );
		?>
		<div class="wrap vcap-wrap">
			<h1><?php esc_html_e( 'Import coupon codes', 'voucher-captain' ); ?></h1>
			<p><?php esc_html_e( 'Upload a list of pre-generated codes (e.g. printed cards or codes from a partner). Assign the batch to a voucher product under Product data → Voucher → Code source; each sale uses the next free code.', 'voucher-captain' ); ?></p>

			<?php if ( is_array( $report ) ) : ?>
				<div class="notice <?php echo $report['error'] ? 'notice-error' : 'notice-success'; ?>">
					<?php if ( $report['error'] ) : ?>
						<p><?php echo esc_html( $report['error'] ); ?></p>
					<?php else : ?>
						<p><strong><?php echo esc_html( sprintf( /* translators: 1: imported, 2: batch */ __( '%1$d codes imported into batch "%2$s".', 'voucher-captain' ), $report['imported'], $report['batch'] ) ); ?></strong></p>
						<ul>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Duplicates within the file skipped: %d', 'voucher-captain' ), $report['dupes_file'] ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Already existing codes skipped: %d', 'voucher-captain' ), $report['dupes_existing'] ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Invalid codes skipped: %d', 'voucher-captain' ), count( $report['invalid'] ) ) ); ?></li>
						</ul>
						<?php if ( $report['invalid'] ) : ?>
							<details><summary><?php esc_html_e( 'Show invalid rows', 'voucher-captain' ); ?></summary>
								<ul><?php foreach ( array_slice( $report['invalid'], 0, 100 ) as $row ) : ?><li><?php echo esc_html( $row ); ?></li><?php endforeach; ?></ul>
							</details>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="vcap-card">
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vcap_import" />
					<?php wp_nonce_field( 'vcap_import' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="vcap-file"><?php esc_html_e( 'File', 'voucher-captain' ); ?></label></th>
							<td><input type="file" id="vcap-file" name="file" required accept=".csv,.txt,.xls,.xlsx,.ods" />
								<p class="description"><?php esc_html_e( 'CSV, XLS, XLSX or ODS. The first worksheet is read.', 'voucher-captain' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="vcap-batch"><?php esc_html_e( 'Batch name', 'voucher-captain' ); ?></label></th>
							<td><input type="text" id="vcap-batch" name="batch" class="regular-text" required maxlength="100" list="vcap-batches" />
								<datalist id="vcap-batches"><?php foreach ( $this->pool->batches() as $b ) : ?><option value="<?php echo esc_attr( $b['batch'] ); ?>"></option><?php endforeach; ?></datalist>
								<p class="description"><?php esc_html_e( 'Use an existing name to add codes to that batch.', 'voucher-captain' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="vcap-column"><?php esc_html_e( 'Column', 'voucher-captain' ); ?></label></th>
							<td><input type="text" id="vcap-column" name="column" value="A" class="small-text" />
								<p class="description"><?php esc_html_e( 'Column letter (A, B, …) or header name containing the codes.', 'voucher-captain' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Options', 'voucher-captain' ); ?></th>
							<td>
								<label><input type="checkbox" name="header" value="1" checked /> <?php esc_html_e( 'First row is a header', 'voucher-captain' ); ?></label><br>
								<label><input type="checkbox" name="uppercase" value="1" /> <?php esc_html_e( 'Convert codes to uppercase', 'voucher-captain' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vcap-pattern"><?php esc_html_e( 'Allowed format', 'voucher-captain' ); ?></label></th>
							<td><input type="text" id="vcap-pattern" name="pattern" value="^[A-Za-z0-9._-]{4,64}$" class="regular-text code" />
								<p class="description"><?php esc_html_e( 'Regular expression each code must match.', 'voucher-captain' ); ?></p></td>
						</tr>
					</table>
					<?php submit_button( __( 'Import codes', 'voucher-captain' ) ); ?>
				</form>
			</div>

			<h2><?php esc_html_e( 'Batches', 'voucher-captain' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Batch', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Total', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Free', 'voucher-captain' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php
				$batches = $this->pool->batches();
				if ( ! $batches ) {
					echo '<tr><td colspan="4">' . esc_html__( 'No codes imported yet.', 'voucher-captain' ) . '</td></tr>';
				}
				foreach ( $batches as $b ) :
					?>
					<tr>
						<td><strong><?php echo esc_html( $b['batch'] ); ?></strong></td>
						<td><?php echo esc_html( (string) $b['total'] ); ?></td>
						<td><?php echo esc_html( (string) $b['free'] ); ?><?php echo $b['free'] < 10 ? ' <span class="vcap-low">' . esc_html__( 'running low', 'voucher-captain' ) . '</span>' : ''; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( ( new CampaignAdmin( new \VCAP\Campaigns( $this->pool, vcap()->vouchers ) ) )->export_url( 'pool', $b['batch'] ) ); ?>"><?php esc_html_e( 'Download CSV', 'voucher-captain' ); ?></a>
							<?php if ( $b['free'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all unused codes of this batch?', 'voucher-captain' ) ); ?>');">
									<input type="hidden" name="action" value="vcap_delete_batch" />
									<input type="hidden" name="batch" value="<?php echo esc_attr( $b['batch'] ); ?>" />
									<?php wp_nonce_field( 'vcap_delete_batch' ); ?>
									<button class="button button-link-delete"><?php esc_html_e( 'Delete unused codes', 'voucher-captain' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function finish( array $report ): void {
		set_transient( self::REPORT_TRANSIENT . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=vcap-import' ) );
		exit;
	}

	public function handle(): void {
		check_admin_referer( 'vcap_import' );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$report = array(
			'error'          => '',
			'batch'          => '',
			'imported'       => 0,
			'dupes_file'     => 0,
			'dupes_existing' => 0,
			'invalid'        => array(),
		);
		$batch = sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) );
		if ( '' === $batch ) {
			$report['error'] = __( 'Please enter a batch name.', 'voucher-captain' );
			$this->finish( $report );
		}
		$report['batch'] = $batch;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		$file = $_FILES['file'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$report['error'] = __( 'The file could not be uploaded.', 'voucher-captain' );
			$this->finish( $report );
		}
		$ext = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'xls', 'xlsx', 'ods' ), true ) ) {
			$report['error'] = __( 'Unsupported file type. Please upload CSV, XLS, XLSX or ODS.', 'voucher-captain' );
			$this->finish( $report );
		}
		if ( (int) $file['size'] > 20 * MB_IN_BYTES ) {
			$report['error'] = __( 'The file is too large (max. 20 MB).', 'voucher-captain' );
			$this->finish( $report );
		}

		try {
			$rows = SpreadsheetReader::read( $file['tmp_name'], $ext );
		} catch ( \Throwable $e ) {
			$report['error'] = __( 'The file could not be read:', 'voucher-captain' ) . ' ' . $e->getMessage();
			$this->finish( $report );
		}

		$pattern = wp_unslash( (string) ( $_POST['pattern'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- used as regex only.
		$regex   = "\x01" . str_replace( "\x01", '', $pattern ?: '^[A-Za-z0-9._-]{4,64}$' ) . "\x01u"; // Control-char delimiter: the pattern needs no escaping.
		if ( false === @preg_match( $regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$report['error'] = __( 'The "Allowed format" is not a valid regular expression.', 'voucher-captain' );
			$this->finish( $report );
		}

		$column = trim( sanitize_text_field( wp_unslash( $_POST['column'] ?? 'A' ) ) );
		$index  = 0;
		$header = ! empty( $_POST['header'] );
		if ( preg_match( '/^[A-Za-z]{1,2}$/', $column ) ) {
			$index = 0;
			foreach ( str_split( strtoupper( $column ) ) as $ch ) {
				$index = $index * 26 + ( ord( $ch ) - 64 );
			}
			--$index;
		} elseif ( '' !== $column && $rows ) {
			$found = array_search( strtolower( $column ), array_map( 'strtolower', $rows[0] ), true );
			if ( false === $found ) {
				$report['error'] = __( 'The column header was not found in the first row.', 'voucher-captain' );
				$this->finish( $report );
			}
			$index  = (int) $found;
			$header = true;
		}
		if ( $header ) {
			array_shift( $rows );
		}

		$upper = ! empty( $_POST['uppercase'] );
		$seen  = array();
		foreach ( $rows as $n => $row ) {
			$code = trim( (string) ( $row[ $index ] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$code = $upper ? strtoupper( $code ) : $code;
			$key  = wc_format_coupon_code( $code ); // WooCommerce codes are case-insensitive.
			if ( ! preg_match( $regex, $code ) ) {
				/* translators: 1: row number, 2: value */
				$report['invalid'][] = sprintf( __( 'Row %1$d: "%2$s"', 'voucher-captain' ), $n + ( $header ? 2 : 1 ), mb_substr( $code, 0, 80 ) );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				++$report['dupes_file'];
				continue;
			}
			$seen[ $key ] = true;
			if ( $this->pool->exists( $code ) || wc_get_coupon_id_by_code( $code ) || vcap()->vouchers->code_exists( $code ) ) {
				++$report['dupes_existing'];
				continue;
			}
			if ( $this->pool->add( $code, $batch ) ) {
				++$report['imported'];
			} else {
				++$report['dupes_existing'];
			}
		}
		$this->finish( $report );
	}

	public function delete_batch(): void {
		check_admin_referer( 'vcap_delete_batch' );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$this->pool->delete_free( sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=vcap-import' ) );
		exit;
	}
}
