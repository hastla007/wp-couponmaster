<?php
namespace VCAP\Admin;

use VCAP\CodeGenerator;
use VCAP\CodeLookup;
use VCAP\CodePool;
use VCAP\CodeIndex;
use VCAP\Jobs;
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

	/**
	 * Formats a code must match to be imported ("custom": the admin's own regular expression).
	 *
	 * @return array<string, array{label:string, regex:string}>
	 */
	public static function formats(): array {
		return array(
			'standard' => array( 'label' => __( 'Letters, digits, dot, hyphen, underscore (4–64 characters)', 'voucher-captain' ), 'regex' => '^[A-Za-z0-9._-]{4,64}$' ),
			'alnum'    => array( 'label' => __( 'Letters and digits only (4–64 characters)', 'voucher-captain' ), 'regex' => '^[A-Za-z0-9]{4,64}$' ),
			'digits'   => array( 'label' => __( 'Digits only (4–64 characters)', 'voucher-captain' ), 'regex' => '^[0-9]{4,64}$' ),
			'any'      => array( 'label' => __( 'Any code without spaces (1–100 characters)', 'voucher-captain' ), 'regex' => '^\S{1,100}$' ),
			'custom'   => array( 'label' => __( 'Own format (regular expression below)', 'voucher-captain' ), 'regex' => '' ),
		);
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
		list( $job, $running ) = JobProgress::load( CodePool::IMPORT_JOB );
		if ( $job && ! $running ) {
			$report = (array) $job['report'];
			if ( 'failed' === $job['status'] ) {
				/* translators: %s: error message */
				$report['error'] = sprintf( __( 'The import stopped: %s', 'voucher-captain' ), (string) $job['error'] );
			}
		}
		?>
		<div class="wrap vcap-wrap">
			<h1><?php esc_html_e( 'Import coupon codes', 'voucher-captain' ); ?></h1>
			<p><?php esc_html_e( 'Upload a list of pre-generated codes (e.g. printed cards or codes from a partner). Assign the batch to a voucher product under Product data → Voucher → Code source; each sale uses the next free code.', 'voucher-captain' ); ?></p>

			<?php
			if ( $running ) {
				/* translators: %s: batch name */
				JobProgress::render( $job, sprintf( __( 'Importing codes into batch "%s" …', 'voucher-captain' ), (string) ( $job['report']['batch'] ?? '' ) ) );
			}
			?>

			<?php if ( is_array( $report ) ) : ?>
				<?php $report += self::empty_report(); ?>
				<div class="notice <?php echo $report['error'] || ! $report['imported'] ? 'notice-error' : 'notice-success'; ?>">
					<?php if ( $report['error'] ) : ?>
						<p><?php echo esc_html( $report['error'] ); ?></p>
					<?php else : ?>
						<p><strong><?php echo esc_html( sprintf( /* translators: 1: imported, 2: batch */ __( '%1$d codes imported into batch "%2$s".', 'voucher-captain' ), $report['imported'], $report['batch'] ) ); ?></strong></p>
						<ul>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Duplicates within the file skipped: %d', 'voucher-captain' ), $report['dupes_file'] ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Already existing codes skipped: %d', 'voucher-captain' ), $report['dupes_existing'] ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Invalid codes skipped: %d', 'voucher-captain' ), Jobs::count( $report, 'invalid' ) ) ); ?></li>
							<?php if ( $report['not_saved'] ) : ?>
								<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Codes not saved (database error): %d', 'voucher-captain' ), Jobs::count( $report, 'not_saved' ) ) ); ?></li>
							<?php endif; ?>
							<?php if ( ! empty( $report['weak'] ) ) : ?>
								<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Codes too easy to guess skipped: %d', 'voucher-captain' ), Jobs::count( $report, 'weak' ) ) ); ?></li>
							<?php endif; ?>
							<?php if ( ! empty( $report['pins'] ) ) : ?>
								<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Codes protected by a PIN: %d', 'voucher-captain' ), (int) $report['pins'] ) ); ?></li>
							<?php endif; ?>
						</ul>
						<?php if ( ! empty( $report['sequential'] ) ) : ?>
							<p class="vcap-warning"><strong><?php esc_html_e( 'The codes form a numbered series (consecutive or evenly spaced numbers): whoever buys or sees one code can work out others. They count as easy to guess.', 'voucher-captain' ); ?></strong></p>
						<?php endif; ?>
						<?php if ( ! empty( $report['check_digit'] ) ) : ?>
							<p><?php esc_html_e( 'The codes end with a check digit; it follows from the other digits and does not count towards the guessing space.', 'voucher-captain' ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $report['weak_unconfirmed'] ) && ! empty( $report['weak'] ) ) : ?>
							<p class="vcap-warning"><?php esc_html_e( 'Codes that are easy to guess were skipped although "Accept codes that are easy to guess" was ticked: please also tick the confirmation below it.', 'voucher-captain' ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $report['weak'] ) ) : ?>
							<p><?php esc_html_e( 'Codes of vouchers with a value must have at least about 2^50 combinations (e.g. 10 letters and digits or 16 digits besides a common prefix), otherwise they can be found by trying. Use longer codes, or tick "Accept codes that are easy to guess" if the codes are protected otherwise.', 'voucher-captain' ); ?></p>
							<details><summary><?php esc_html_e( 'Show codes that are too easy to guess', 'voucher-captain' ); ?></summary>
								<ul><?php foreach ( array_slice( $report['weak'], 0, 100 ) as $row ) : ?><li><?php echo esc_html( $row ); ?></li><?php endforeach; ?></ul>
							</details>
						<?php endif; ?>
						<?php if ( ! empty( $report['weak_imported'] ) ) : ?>
							<p class="vcap-warning"><strong><?php echo esc_html( sprintf( /* translators: %d: count */ _n( 'Warning: %d imported code is easy to guess.', 'Warning: %d imported codes are easy to guess.', (int) $report['weak_imported'], 'voucher-captain' ), (int) $report['weak_imported'] ) ); ?></strong></p>
						<?php endif; ?>
						<?php if ( $report['invalid'] ) : ?>
							<details><summary><?php esc_html_e( 'Show invalid rows', 'voucher-captain' ); ?></summary>
								<ul><?php foreach ( array_slice( $report['invalid'], 0, 100 ) as $row ) : ?><li><?php echo esc_html( $row ); ?></li><?php endforeach; ?></ul>
							</details>
						<?php endif; ?>
						<?php if ( $report['not_saved'] ) : ?>
							<details><summary><?php esc_html_e( 'Show codes that were not saved', 'voucher-captain' ); ?></summary>
								<ul><?php foreach ( array_slice( $report['not_saved'], 0, 100 ) as $row ) : ?><li><?php echo esc_html( $row ); ?></li><?php endforeach; ?></ul>
							</details>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( $report['preview'] ) : ?>
						<p><?php echo esc_html( sprintf( /* translators: 1: column, 2: first values of the column */ __( 'First values read from column %1$s: %2$s', 'voucher-captain' ), $report['column'], implode( ', ', $report['preview'] ) ) ); ?></p>
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
							<th scope="row"><label for="vcap-pin-column"><?php esc_html_e( 'PIN column (optional)', 'voucher-captain' ); ?></label></th>
							<td><input type="text" id="vcap-pin-column" name="pin_column" value="" class="small-text" />
								<p class="description"><?php esc_html_e( 'Column letter or header name with a PIN per card (3–16 letters or digits, e.g. under a scratch-off field). The PIN is stored hashed only. Cards with a PIN are redeemed, checked and topped up only with it: customers enter CODE#PIN.', 'voucher-captain' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Options', 'voucher-captain' ); ?></th>
							<td>
								<label><input type="checkbox" name="header" value="1" checked /> <?php esc_html_e( 'First row is a header', 'voucher-captain' ); ?></label><br>
								<label><input type="checkbox" name="uppercase" value="1" /> <?php esc_html_e( 'Convert codes to uppercase', 'voucher-captain' ); ?></label><br>
								<label><input type="checkbox" name="allow_weak" value="1" /> <?php esc_html_e( 'Accept codes that are easy to guess (not recommended)', 'voucher-captain' ); ?></label><br>
								<label><input type="checkbox" name="confirm_weak" value="1" /> <?php esc_html_e( 'I confirm: codes sold as vouchers with a value can be redeemed by anyone who guesses them, and I accept this risk (e.g. because the cards are protected by a separate PIN).', 'voucher-captain' ); ?></label>
								<p class="description"><?php esc_html_e( 'Codes with fewer than about 2^50 combinations (e.g. 8 digits) are skipped by default: they could be found by trying.', 'voucher-captain' ); ?> <?php esc_html_e( 'Numbered series (e.g. consecutive card numbers) and check digits count as easy to guess: whoever knows one code can work out others. Both boxes must be ticked to import such codes.', 'voucher-captain' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vcap-format"><?php esc_html_e( 'Allowed format', 'voucher-captain' ); ?></label></th>
							<td><select id="vcap-format" name="format">
									<?php foreach ( self::formats() as $key => $format ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $format['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p><label for="vcap-pattern"><?php esc_html_e( 'Own regular expression (only for "Own format")', 'voucher-captain' ); ?></label><br>
								<input type="text" id="vcap-pattern" name="pattern" value="" placeholder="^[A-Z0-9]{8}$" class="regular-text code" /></p>
								<p class="description"><?php esc_html_e( 'Codes that do not match are skipped and listed in the import report.', 'voucher-captain' ); ?></p></td>
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
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Delete all unused codes of this batch?', 'voucher-captain' ); ?>">
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
		$report = self::empty_report();
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

		list( $report, $codes ) = $this->prepare(
			$rows,
			array(
				'batch'        => $batch,
				'column'       => sanitize_text_field( wp_unslash( $_POST['column'] ?? 'A' ) ),
				'pin_column'   => sanitize_text_field( wp_unslash( $_POST['pin_column'] ?? '' ) ),
				'header'       => ! empty( $_POST['header'] ),
				'uppercase'    => ! empty( $_POST['uppercase'] ),
				'allow_weak'   => ! empty( $_POST['allow_weak'] ),
				'confirm_weak' => ! empty( $_POST['confirm_weak'] ),
				'format'       => sanitize_key( wp_unslash( (string) ( $_POST['format'] ?? '' ) ) ),
				'pattern'      => wp_unslash( (string) ( $_POST['pattern'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- used as regex only.
			)
		);
		unset( $rows );
		$report = $this->import_or_queue( $report, $codes, get_current_user_id() );
		if ( empty( $report['queued'] ) ) {
			$this->finish( $report );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=vcap-import' ) );
		exit;
	}

	/**
	 * Imports checked codes (prepare()) right away, or – more than CodeIndex::CHUNK codes – starts a background
	 * import that adds one chunk per step (B4; progress on this screen). A queued import returns the report with
	 * "queued" set.
	 *
	 * @param array<int, string> $codes Row number => code.
	 */
	public function import_or_queue( array $report, array $codes, int $user_id ): array {
		if ( '' !== $report['error'] ) {
			return $report;
		}
		if ( count( $codes ) <= CodeIndex::CHUNK ) {
			$this->pool->import_codes( $codes, (string) $report['batch'], $report );
			return $report;
		}
		$started = Jobs::start(
			Jobs::id( CodePool::IMPORT_JOB, $user_id ),
			array(
				'type'   => CodePool::IMPORT_JOB,
				'total'  => count( $codes ),
				'report' => $report,
			),
			array_chunk( $codes, CodeIndex::CHUNK, true )
		);
		if ( ! $started ) {
			$report          = self::empty_report( (string) $report['batch'] );
			$report['error'] = __( 'Your previous import is still running. Please wait until it is finished, then upload the file again.', 'voucher-captain' );
			return $report;
		}
		$report['queued'] = true;
		return $report;
	}

	/** Empty import report. */
	public static function empty_report( string $batch = '' ): array {
		return array(
			'error'            => '',
			'batch'            => $batch,
			'imported'         => 0,
			'dupes_file'       => 0,
			'dupes_existing'   => 0,
			'invalid'          => array(),
			'invalid_count'    => 0,
			'not_saved'        => array(),
			'not_saved_count'  => 0,
			'weak'             => array(),
			'weak_count'       => 0,
			'weak_imported'    => 0,
			'pins'             => 0,
			'sequential'       => false,
			'check_digit'      => false,
			'weak_unconfirmed' => false,
			'column'           => '',
			'preview'          => array(),
		);
	}

	/**
	 * Column of the codes: a header name is looked for first ("ID", "Nr" are names, not column letters), then
	 * a column letter (A … XFD). A header name means the first row is a header.
	 *
	 * @param array<int, array<int, string>> $rows
	 * @return array{index:int, header:bool, label:string, error:string}
	 */
	public static function resolve_column( array $rows, string $column, bool $header ): array {
		$column = trim( $column );
		if ( '' === $column ) {
			return array( 'index' => 0, 'header' => $header, 'label' => 'A', 'error' => '' );
		}
		$first = $rows ? array_map( static fn( $cell ) => strtolower( trim( (string) $cell ) ), (array) reset( $rows ) ) : array();
		$found = array_search( strtolower( $column ), $first, true );
		if ( false !== $found ) {
			return array( 'index' => (int) $found, 'header' => true, 'label' => $column, 'error' => '' );
		}
		if ( preg_match( '/^[A-Za-z]{1,3}$/', $column ) ) {
			$index = 0;
			foreach ( str_split( strtoupper( $column ) ) as $ch ) {
				$index = $index * 26 + ( ord( $ch ) - 64 );
			}
			if ( $index <= SpreadsheetReader::MAX_COLUMNS ) {
				return array( 'index' => $index - 1, 'header' => $header, 'label' => strtoupper( $column ), 'error' => '' );
			}
		}
		return array(
			'index'  => 0,
			'header' => $header,
			'label'  => $column,
			/* translators: %s: column name entered */
			'error'  => sprintf( __( 'The column "%s" was not found: it is neither a header in the first row nor a column letter (A, B, …).', 'voucher-captain' ), $column ),
		);
	}

	/**
	 * Why a code cannot be imported ('' when it can): control characters (e.g. a "Unicode text" file read the
	 * wrong way), more than 100 characters (the code column), characters WooCommerce's code formatting changes
	 * ("B&B" becomes "b&amp;b": the code would never be found again), or not the allowed format.
	 */
	public static function code_problem( string $code, string $regex ): string {
		if ( preg_match( '/[\x00-\x1F\x7F]/', $code ) || ! mb_check_encoding( $code, 'UTF-8' ) || preg_match( '/\p{Cc}/u', $code ) ) {
			return __( 'contains control characters or invalid characters', 'voucher-captain' );
		}
		if ( strlen( $code ) > CodeGenerator::MAX_CODE_LENGTH ) {
			/* translators: %d: maximum length */
			return sprintf( __( 'longer than %d characters', 'voucher-captain' ), CodeGenerator::MAX_CODE_LENGTH );
		}
		$formatted = (string) wc_format_coupon_code( $code );
		if ( $formatted !== mb_strtolower( $code, 'UTF-8' ) ) {
			/* translators: %s: code as WooCommerce would store it */
			return sprintf( __( 'WooCommerce would change it to "%s" (characters such as & < > or quotes)', 'voucher-captain' ), mb_substr( $formatted, 0, 80 ) );
		}
		if ( ! preg_match( $regex, $code ) ) {
			return __( 'does not match the allowed format', 'voucher-captain' );
		}
		return '';
	}

	/**
	 * Imports the codes of the read rows into a batch and returns the report.
	 *
	 * @param array<int, array<int, string>> $rows
	 * @param array{batch:string, column?:string, header?:bool, uppercase?:bool, allow_weak?:bool, confirm_weak?:bool, format?:string, pattern?:string} $opts
	 */
	public function process( array $rows, array $opts ): array {
		list( $report, $codes ) = $this->prepare( $rows, $opts );
		if ( '' === $report['error'] ) {
			$this->pool->import_codes( $codes, (string) $report['batch'], $report );
		}
		return $report;
	}

	/**
	 * Reads and checks the codes of the rows (format, duplicates in the file, guessing space of the whole file) – no
	 * database lookups per code. The report lists at most Jobs::MAX_ERRORS rows per list and counts all of them.
	 *
	 * @param array<int, array<int, string>> $rows
	 * @param array{batch:string, column?:string, pin_column?:string, header?:bool, uppercase?:bool, allow_weak?:bool, confirm_weak?:bool, format?:string, pattern?:string} $opts
	 * @return array{0:array, 1:array<int, string|array{0:string, 1:string}>} report, codes to import (row number => code, or code and PIN hash)
	 */
	public function prepare( array $rows, array $opts ): array {
		$batch  = (string) $opts['batch'];
		$report = self::empty_report( $batch );
		$codes  = array();

		$pattern = (string) ( $opts['pattern'] ?? '' );
		$format  = (string) ( $opts['format'] ?? '' );
		if ( isset( self::formats()[ $format ] ) && 'custom' !== $format ) {
			$pattern = self::formats()[ $format ]['regex'];
		}
		$regex = "\x01" . str_replace( "\x01", '', $pattern ?: '^[A-Za-z0-9._-]{4,64}$' ) . "\x01u"; // Control-char delimiter: the pattern needs no escaping.
		if ( false === @preg_match( $regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$report['error'] = __( 'The "Allowed format" is not a valid regular expression.', 'voucher-captain' );
			return array( $report, $codes );
		}

		$col = self::resolve_column( $rows, (string) ( $opts['column'] ?? 'A' ), ! empty( $opts['header'] ) );
		if ( '' !== $col['error'] ) {
			$report['error'] = $col['error'];
			return array( $report, $codes );
		}
		$index            = $col['index'];
		$header           = $col['header'];
		// M12-99: optional PIN column (a header name makes the first row a header too).
		$pin_index = null;
		if ( '' !== trim( (string) ( $opts['pin_column'] ?? '' ) ) ) {
			$pin_col = self::resolve_column( $rows, (string) $opts['pin_column'], $header );
			if ( '' !== $pin_col['error'] ) {
				$report['error'] = $pin_col['error'];
				return array( $report, $codes );
			}
			$pin_index = $pin_col['index'];
			$header    = $header || $pin_col['header'];
		}
		$report['column'] = $col['label'];
		$rows             = array_values( $rows );
		if ( $header ) {
			array_shift( $rows );
		}
		foreach ( $rows as $row ) {
			$value = trim( (string) ( $row[ $index ] ?? '' ) );
			if ( '' !== $value ) {
				$report['preview'][] = mb_substr( $value, 0, 80 );
				if ( count( $report['preview'] ) >= 5 ) {
					break;
				}
			}
		}
		if ( ! $report['preview'] ) {
			$report['error'] = sprintf(
				/* translators: %s: column letter or header name */
				__( 'Column %s is empty: no codes were found, nothing was imported. Check the column (letter or header name) and the "First row is a header" option.', 'voucher-captain' ),
				$col['label']
			);
			return array( $report, $codes );
		}

		$upper      = ! empty( $opts['uppercase'] );
		$allow_weak = ! empty( $opts['allow_weak'] );
		$seen       = array();
		$candidates = array();
		$pins       = array();
		$row_number = static fn( int $n ): int => $n + ( $header ? 2 : 1 );
		foreach ( $rows as $n => $row ) {
			$code = trim( (string) ( $row[ $index ] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$code    = $upper ? strtoupper( $code ) : $code;
			$problem = self::code_problem( $code, $regex );
			if ( '' !== $problem ) {
				/* translators: 1: row number, 2: value, 3: reason */
				Jobs::add_error( $report, 'invalid', sprintf( __( 'Row %1$d: "%2$s" – %3$s', 'voucher-captain' ), $row_number( $n ), mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '?', $code ), 0, 80 ), $problem ) );
				continue;
			}
			$pin = null !== $pin_index ? trim( (string) ( $row[ $pin_index ] ?? '' ) ) : '';
			if ( '' !== $pin && ( ! CodeLookup::valid_pin( $pin ) || str_contains( $code, CodeLookup::PIN_SEPARATOR ) ) ) {
				/* translators: 1: row number, 2: value, 3: reason */
				Jobs::add_error( $report, 'invalid', sprintf( __( 'Row %1$d: "%2$s" – %3$s', 'voucher-captain' ), $row_number( $n ), mb_substr( $code, 0, 80 ), __( 'the PIN must be 3–16 letters or digits (and the code must not contain "#")', 'voucher-captain' ) ) );
				continue;
			}
			$key = wc_format_coupon_code( $code ); // WooCommerce codes are case-insensitive.
			if ( isset( $seen[ $key ] ) ) {
				++$report['dupes_file'];
				continue;
			}
			$seen[ $key ]     = true;
			$candidates[ $n ] = $code;
			if ( '' !== $pin ) {
				$pins[ $n ] = $pin;
			}
		}

		// Guessing space without what makes the codes predictable from each other: the parts all codes of the file
		// share (e.g. "SHOP-2026-"), a check digit, numbered series. A file with a single code is compared with the
		// codes already in the batch.
		/** This filter is documented in src/Issuer.php */
		$min_bits = (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS );
		$analysis = CodeGenerator::analyse_batch( $candidates, $this->pool->sample( $batch ), $min_bits );
		// Weak codes carry money once sold: "Accept" alone is not enough, the risk must be confirmed as well.
		$accept_weak                = $allow_weak && ! empty( $opts['confirm_weak'] );
		$report['sequential']       = $analysis['sequential'];
		$report['check_digit']      = $analysis['check_digit'];
		$report['weak_unconfirmed'] = $allow_weak && ! $accept_weak;
		foreach ( $candidates as $n => $code ) {
			if ( $analysis['bits'][ $n ] < $min_bits ) {
				if ( ! $accept_weak ) {
					/* translators: 1: row number, 2: value */
					Jobs::add_error( $report, 'weak', sprintf( __( 'Row %1$d: "%2$s"', 'voucher-captain' ), $row_number( $n ), mb_substr( $code, 0, 80 ) ) );
					continue;
				}
				++$report['weak_imported'];
			}
			// The PIN leaves this request only as a hash (also in the chunks of a background import).
			$codes[ $row_number( $n ) ] = isset( $pins[ $n ] ) ? array( $code, CodeLookup::hash_pin( $code, $pins[ $n ] ) ) : $code;
		}
		return array( $report, $codes );
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
