<?php
namespace VCAP\Admin;

use VCAP\SpreadsheetReader;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * M12-56: takes over existing gift cards and vouchers with their remaining value (from YITH, PW, WooCommerce Gift
 * Cards, paper vouchers) from a CSV/XLSX/ODS file. Columns (header row, English or German names): code, initial
 * value, balance, expiry, recipient email, recipient name, type. Each row is issued through
 * Issuer::issue_standalone() (no email, marked as sold) with a random reference "import:<uuid>" (VC-10, R-4.7: the
 * reference has no relation to the code, so neither the history note nor the claim can lead back to it). A second run
 * skips what was imported already: a code whose voucher has the history entry "imported" is reported as "already
 * imported", any other existing code as taken – the code itself (unique in the shop) makes the import idempotent, not
 * the reference. Cards imported before VC-10 keep their old reference "import:<md5 of the code>" in their history and
 * claim; they are recognised the same way (entry "imported"), nothing is rewritten. A dry run checks every row and
 * changes nothing. Shared with "wp vcap import <file>".
 */
final class CardImport {
	public const SLUG = 'vcap-import-cards';
	/** Ledger event written after each imported card. */
	public const EVENT = 'imported';
	private const REPORT = 'vcap_card_import_';
	/** Rows of one admin upload (larger files: wp vcap import). */
	public const MAX_ADMIN_ROWS = 2000;

	/** Accepted header names per column. */
	private const COLUMNS = array(
		'code'            => array( 'code', 'gutscheincode', 'card', 'card number', 'kartennummer', 'gift card', 'coupon' ),
		'initial'         => array( 'initial', 'initial value', 'value', 'amount', 'wert', 'betrag', 'ursprungswert', 'nennwert' ),
		'balance'         => array( 'balance', 'remaining', 'remaining value', 'restwert', 'guthaben', 'saldo' ),
		'expiry'          => array( 'expiry', 'expires', 'expiry date', 'expiration', 'ablauf', 'gültig bis', 'gueltig bis', 'ablaufdatum' ),
		'recipient_email' => array( 'recipient', 'email', 'recipient email', 'empfänger', 'empfaenger', 'e-mail', 'empfänger e-mail' ),
		'recipient_name'  => array( 'recipient name', 'name', 'empfängername', 'name empfänger' ),
		'type'            => array( 'type', 'art', 'typ' ),
	);

	public function register(): void {
		add_action( 'admin_post_vcap_import_cards', array( $this, 'handle' ) );
	}

	/** Column index per field from the header row (-1 = column missing). @return array<string, int> */
	public static function map_header( array $header ): array {
		$map = array_fill_keys( array_keys( self::COLUMNS ), -1 );
		foreach ( $header as $index => $label ) {
			$label = trim( strtolower( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $label ) ?? '' ) );
			$label = trim( (string) preg_replace( '/\s+/', ' ', str_replace( array( '_', '-' ), ' ', $label ) ) );
			foreach ( self::COLUMNS as $field => $names ) {
				$names = array_map( static fn( $n ) => str_replace( '-', ' ', $n ), $names );
				if ( -1 === $map[ $field ] && in_array( $label, $names, true ) ) {
					$map[ $field ] = (int) $index;
					break;
				}
			}
		}
		return $map;
	}

	/** "1.234,50", "1,234.50", "50,00 €" → 1234.5; null when not a number. */
	public static function parse_amount( string $raw ): ?float {
		$raw = trim( (string) preg_replace( '/[^\d,.\-]/', '', $raw ) );
		if ( '' === $raw || ! preg_match( '/\d/', $raw ) ) {
			return null;
		}
		$comma = strrpos( $raw, ',' );
		$dot   = strrpos( $raw, '.' );
		if ( false !== $comma && ( false === $dot || $comma > $dot ) ) {
			$raw = str_replace( array( '.', ',' ), array( '', '.' ), $raw ); // German: comma is the decimal mark.
		} else {
			$raw = str_replace( ',', '', $raw );
		}
		return is_numeric( $raw ) ? (float) $raw : null;
	}

	/** "2027-12-31", "31.12.2027", "12/31/2027" → "2027-12-31"; '' for empty; null when not a date. */
	public static function parse_date( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m ) ) {
			list( $y, $mo, $d ) = array( (int) $m[1], (int) $m[2], (int) $m[3] );
		} elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m ) ) {
			list( $d, $mo, $y ) = array( (int) $m[1], (int) $m[2], (int) $m[3] );
		} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m ) ) {
			list( $mo, $d, $y ) = array( (int) $m[1], (int) $m[2], (int) $m[3] );
		} else {
			return null;
		}
		return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	/**
	 * Reference of one imported card for issue_standalone(): random, without relation to the code (VC-10, R-4.7).
	 * Before VC-10 it was "import:" plus the MD5 of the code, which a short code could be guessed back from.
	 */
	public static function reference(): string {
		return 'import:' . wp_generate_uuid4();
	}

	/**
	 * Checks one row. @return array{args:array, error:string, skip:string} issue_standalone() arguments, or why
	 * the row is refused (error) or left out (skip: already imported, nothing left).
	 */
	public static function check_row( array $row, array $map ): array {
		$cell = static fn( string $f ): string => $map[ $f ] >= 0 ? trim( (string) ( $row[ $map[ $f ] ] ?? '' ) ) : '';
		$out  = array( 'args' => array(), 'error' => '', 'skip' => '' );
		$code = wc_format_coupon_code( sanitize_text_field( $cell( 'code' ) ) );
		if ( '' === $code || ! preg_match( '/^\S{1,100}$/u', $code ) ) {
			$out['error'] = __( 'no valid code (1–100 characters without spaces)', 'voucher-captain' );
			return $out;
		}
		$initial = self::parse_amount( $cell( 'initial' ) );
		$balance = '' === $cell( 'balance' ) ? $initial : self::parse_amount( $cell( 'balance' ) );
		if ( null === $initial || $initial <= 0 ) {
			$out['error'] = __( 'no valid initial value', 'voucher-captain' );
			return $out;
		}
		if ( null === $balance || $balance < 0 || $balance > $initial + 0.00001 ) {
			$out['error'] = __( 'the balance must be a number between 0 and the initial value', 'voucher-captain' );
			return $out;
		}
		if ( $balance < 0.005 ) {
			$out['skip'] = __( 'nothing left (balance 0)', 'voucher-captain' );
			return $out;
		}
		$type = sanitize_key( $cell( 'type' ) );
		$type = in_array( $type, array( 'voucher', 'gutschein', 'single' ), true ) ? 'voucher' : 'giftcard';
		if ( 'voucher' === $type && $balance < $initial - 0.00001 ) {
			$out['error'] = __( 'a single-use voucher cannot be partly used: import it as gift card', 'voucher-captain' );
			return $out;
		}
		$expiry = self::parse_date( $cell( 'expiry' ) );
		if ( null === $expiry ) {
			/* translators: %s: value in the file */
			$out['error'] = sprintf( __( 'expiry "%s" is not a date (use YYYY-MM-DD or DD.MM.YYYY)', 'voucher-captain' ), $cell( 'expiry' ) );
			return $out;
		}
		if ( '' !== $expiry && $expiry < wp_date( 'Y-m-d' ) ) {
			$out['skip'] = __( 'already expired', 'voucher-captain' );
			return $out;
		}
		$email = $cell( 'recipient_email' );
		if ( '' !== $email && ! is_email( sanitize_email( $email ) ) ) {
			/* translators: %s: value in the file */
			$out['error'] = sprintf( __( '"%s" is not a valid email address', 'voucher-captain' ), $email );
			return $out;
		}
		$existing = vcap()->vouchers->get_by_code( $code );
		if ( $existing && vcap()->vouchers->has_event( $existing->id, self::EVENT ) ) {
			$out['skip'] = __( 'already imported', 'voucher-captain' );
			return $out;
		}
		if ( $existing || vcap()->coupons->code_exists( $code ) ) {
			$out['error'] = __( 'the code is already used by another voucher or coupon', 'voucher-captain' );
			return $out;
		}
		$out['args'] = array(
			'code'            => $code,
			'type'            => $type,
			'usage'           => 'giftcard' === $type ? 'balance' : 'single',
			'amount'          => $initial,
			'balance'         => $balance,
			'expires_at'      => '' !== $expiry ? get_gmt_from_date( $expiry . ' 23:59:59' ) : null,
			'recipient_email' => sanitize_email( $email ),
			'recipient_name'  => sanitize_text_field( $cell( 'recipient_name' ) ),
			'send'            => false, // Existing cards are with their holders already.
			'paid'            => true, // Sold before (in the old system or on paper).
			'source'          => 'import',
			'reference'       => self::reference(),
		);
		return $out;
	}

	/**
	 * Imports (or with $dry only checks) the rows of a file; the first row is the header.
	 *
	 * @param array<int, array<int, string>> $rows
	 * @return array{dry:bool, imported:int, value:float, skipped:array<int,string>, errors:array<int,string>, error:string}
	 */
	public static function run( array $rows, bool $dry, string $label = '' ): array {
		$report = array( 'dry' => $dry, 'imported' => 0, 'value' => 0.0, 'skipped' => array(), 'errors' => array(), 'error' => '' );
		$header = array_shift( $rows );
		$map    = self::map_header( (array) $header );
		if ( $map['code'] < 0 || $map['initial'] < 0 ) {
			$report['error'] = __( 'The first row must name the columns; "code" and "initial" (value) are required, "balance", "expiry", "recipient", "recipient name" and "type" are optional.', 'voucher-captain' );
			return $report;
		}
		$seen = array();
		foreach ( $rows as $i => $row ) {
			$line = (int) $i + 2;
			if ( ! array_filter( array_map( 'strval', (array) $row ), static fn( $c ) => '' !== trim( $c ) ) ) {
				continue;
			}
			$check = self::check_row( (array) $row, $map );
			$code  = (string) ( $check['args']['code'] ?? '' );
			if ( '' !== $code && isset( $seen[ strtolower( $code ) ] ) ) {
				$check = array( 'args' => array(), 'error' => '', 'skip' => __( 'duplicate within the file', 'voucher-captain' ) );
			}
			if ( '' !== $check['error'] ) {
				$report['errors'][ $line ] = $check['error'];
				continue;
			}
			if ( '' !== $check['skip'] ) {
				$report['skipped'][ $line ] = $check['skip'];
				continue;
			}
			$seen[ strtolower( $code ) ] = true;
			if ( $dry ) {
				++$report['imported'];
				$report['value'] += (float) $check['args']['balance'];
				continue;
			}
			$args           = $check['args'];
			$args['reason'] = '' !== $label ? $label : __( 'Import of existing cards', 'voucher-captain' );
			$voucher        = vcap()->issuer->issue_standalone( $args );
			if ( is_wp_error( $voucher ) ) {
				if ( 'vcap_reference_used' === $voucher->get_error_code() ) {
					$report['skipped'][ $line ] = __( 'already imported', 'voucher-captain' );
				} else {
					$report['errors'][ $line ] = $voucher->get_error_message();
				}
				continue;
			}
			vcap()->vouchers->log( $voucher->id, self::EVENT, null, $voucher->balance, \VCAP\Note::make( 'imported', array( (string) $args['amount'], (string) $voucher->balance, $label ) ) );
			++$report['imported'];
			$report['value'] += (float) $voucher->balance;
		}
		$report['value'] = round( $report['value'], 2 );
		return $report;
	}

	public function page(): void {
		if ( ! IssueVoucher::can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$report = get_transient( self::REPORT . get_current_user_id() );
		delete_transient( self::REPORT . get_current_user_id() );
		?>
		<div class="wrap vcap-wrap">
			<h1><?php esc_html_e( 'Import existing cards', 'voucher-captain' ); ?></h1>
			<p><?php esc_html_e( 'Take over gift cards and vouchers that were sold before (another plugin, paper vouchers) with their remaining value. They keep their codes, get no email and appear in the reports and the outstanding value like any other card.', 'voucher-captain' ); ?></p>
			<p><?php esc_html_e( 'First row: column names. Required: "code", "initial". Optional: "balance" (remaining value, default the initial value), "expiry" (YYYY-MM-DD or DD.MM.YYYY, empty = never expires), "recipient" (email), "recipient name", "type" (giftcard or voucher). Cards already imported are skipped, so the same file can be uploaded again.', 'voucher-captain' ); ?></p>
			<?php if ( is_array( $report ) ) : ?>
				<div class="notice <?php echo '' !== $report['error'] || $report['errors'] ? 'notice-warning' : 'notice-success'; ?>">
					<?php if ( '' !== $report['error'] ) : ?>
						<p><?php echo esc_html( $report['error'] ); ?></p>
					<?php else : ?>
						<p><strong>
						<?php
						echo esc_html(
							$report['dry']
								/* translators: 1: number of cards, 2: total remaining value */
								? sprintf( __( 'Dry run: %1$d cards with %2$s remaining value would be imported. Nothing was changed.', 'voucher-captain' ), (int) $report['imported'], html_entity_decode( wp_strip_all_tags( wc_price( (float) $report['value'] ) ) ) )
								/* translators: 1: number of cards, 2: total remaining value */
								: sprintf( __( '%1$d cards with %2$s remaining value imported.', 'voucher-captain' ), (int) $report['imported'], html_entity_decode( wp_strip_all_tags( wc_price( (float) $report['value'] ) ) ) )
						);
						?>
						</strong></p>
						<?php foreach ( array( 'skipped' => __( 'Skipped rows', 'voucher-captain' ), 'errors' => __( 'Rows with errors (not imported)', 'voucher-captain' ) ) as $key => $title ) : ?>
							<?php if ( $report[ $key ] ) : ?>
								<details <?php echo 'errors' === $key ? 'open' : ''; ?>><summary><?php echo esc_html( $title . ' (' . count( $report[ $key ] ) . ')' ); ?></summary><ul>
									<?php foreach ( array_slice( $report[ $key ], 0, 200, true ) as $line => $text ) : ?>
										<?php /* translators: 1: row number, 2: reason */ ?>
										<li><?php echo esc_html( sprintf( __( 'Row %1$d: %2$s', 'voucher-captain' ), (int) $line, (string) $text ) ); ?></li>
									<?php endforeach; ?>
								</ul></details>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vcap-card">
				<input type="hidden" name="action" value="vcap_import_cards" />
				<?php wp_nonce_field( 'vcap_import_cards' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vcap-cards-file"><?php esc_html_e( 'File', 'voucher-captain' ); ?></label></th>
						<td><input type="file" id="vcap-cards-file" name="file" required accept=".csv,.txt,.xls,.xlsx,.ods" />
						<?php /* translators: %d: maximum number of rows */ ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'CSV, XLS, XLSX or ODS, up to %d rows per upload (larger files: wp vcap import).', 'voucher-captain' ), self::MAX_ADMIN_ROWS ) ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dry run', 'voucher-captain' ); ?></th>
						<td><label><input type="checkbox" name="dry_run" value="1" checked /> <?php esc_html_e( 'Only check the file, import nothing', 'voucher-captain' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Upload', 'voucher-captain' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle(): void {
		check_admin_referer( 'vcap_import_cards' );
		if ( ! IssueVoucher::can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$report = array( 'dry' => true, 'imported' => 0, 'value' => 0.0, 'skipped' => array(), 'errors' => array(), 'error' => '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		$file = $_FILES['file'] ?? null;
		$ext  = is_array( $file ) ? strtolower( pathinfo( sanitize_file_name( (string) ( $file['name'] ?? '' ) ), PATHINFO_EXTENSION ) ) : '';
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$report['error'] = __( 'The file could not be uploaded.', 'voucher-captain' );
		} elseif ( ! in_array( $ext, array( 'csv', 'txt', 'xls', 'xlsx', 'ods' ), true ) ) {
			$report['error'] = __( 'Unsupported file type. Please upload CSV, XLS, XLSX or ODS.', 'voucher-captain' );
		} else {
			try {
				$rows = SpreadsheetReader::read( (string) $file['tmp_name'], $ext );
				if ( count( $rows ) > self::MAX_ADMIN_ROWS + 1 ) {
					/* translators: %d: maximum number of rows */
					$report['error'] = sprintf( __( 'The file has more than %d rows: split it or use "wp vcap import".', 'voucher-captain' ), self::MAX_ADMIN_ROWS );
				} else {
					/* translators: %s: file name */
					$report = self::run( $rows, ! empty( $_POST['dry_run'] ), sprintf( __( 'Import of %s', 'voucher-captain' ), sanitize_file_name( (string) $file['name'] ) ) );
				}
			} catch ( \Throwable $e ) {
				$report['error'] = __( 'The file could not be read:', 'voucher-captain' ) . ' ' . esc_html( $e->getMessage() );
			}
		}
		set_transient( self::REPORT . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
