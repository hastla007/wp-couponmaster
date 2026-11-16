<?php
namespace VCAP;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a template design (JSON layout) for a voucher into a PDF via dompdf.
 * Layout is purely data-driven; this class knows nothing about coupon lifecycle.
 */
final class PdfRenderer {
	public const MM_TO_PT = 2.8346456693;

	public const FORMATS = array(
		'A4' => array( 210, 297 ),
		'A5' => array( 148, 210 ),
		'A6' => array( 105, 148 ),
		'DL' => array( 99, 210 ),
		'letter' => array( 215.9, 279.4 ),
	);

	public function __construct( private ?Settings $settings = null ) {}

	/** @var array<string,float> */
	private static array $height_ratio = array();

	/** Font metrics with the options of render() and the custom fonts registered, per font folder. */
	private static array $metrics = array();

	/**
	 * CSS font-family list for dompdf. dompdf falls back per glyph to the next family, so characters missing in
	 * the chosen font (e.g. ✓ ★ in DejaVu Serif Italic, € in the core Courier) come from DejaVu Sans instead of
	 * rendering as empty boxes, then from the fallback fonts (e.g. Noto Sans CJK, see fallback_fonts()).
	 *
	 * @return string[] Families in order.
	 */
	public static function font_families( string $font ): array {
		$font = str_replace( array( "'", '"', ';', '<', '>' ), '', $font );
		return array_values( array_unique( array_merge( array( $font, 'DejaVu Sans' ), self::fallback_fonts() ) ) );
	}

	public static function font_stack( string $font ): string {
		return implode( ', ', array_map( static fn( $family ) => "'" . $family . "'", self::font_families( $font ) ) );
	}

	/**
	 * Fallback families for scripts DejaVu does not cover (Chinese, Japanese, Korean, …): custom fonts registered
	 * with "vcap_pdf_fonts" and named with the "vcap_pdf_fallback_fonts" filter (see COMPATIBILITY.md).
	 *
	 * @return string[]
	 */
	public static function fallback_fonts(): array {
		$custom = array_keys( self::custom_fonts() );
		return array_values( array_intersect( array_map( 'strval', (array) apply_filters( 'vcap_pdf_fallback_fonts', array() ) ), $custom ) );
	}

	/**
	 * dompdf options of every render: no remote files, PHP or JavaScript, fonts cached in the private upload folder.
	 */
	public static function dompdf_options(): Options {
		$tmp = Install::upload_dir() . '/fonts';
		if ( ! is_dir( $tmp ) ) {
			wp_mkdir_p( $tmp );
		}
		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'isJavascriptEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'dpi', 150 );
		$options->set( 'fontDir', $tmp );
		$options->set( 'fontCache', $tmp );
		$options->set( 'tempDir', wp_is_writable( $tmp ) ? $tmp : sys_get_temp_dir() );
		$options->set( 'chroot', self::chroot_dirs() );
		/** Dompdf options used to render voucher PDFs (args: Dompdf\\Options). */
		return apply_filters( 'vcap_dompdf_options', $options );
	}

	/**
	 * E2: the only directories dompdf may read local files from – the plugin (its images and fonts), the uploads
	 * (template images, the font cache) and the folders of custom fonts (vcap_pdf_fonts); not the whole WordPress
	 * install (ABSPATH holds wp-config.php). Each entry is a real path; dompdf compares at directory boundaries,
	 * so ".../uploads" does not allow a sibling ".../uploads-private".
	 *
	 * @return string[]
	 */
	public static function chroot_dirs(): array {
		$uploads = wp_upload_dir( null, false );
		$dirs    = array( VCAP_PATH, (string) ( $uploads['basedir'] ?? '' ) );
		foreach ( self::custom_fonts() as $variants ) {
			foreach ( (array) $variants as $path ) {
				if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
					$dirs[] = dirname( $path );
				}
			}
		}
		$real = array();
		foreach ( $dirs as $dir ) {
			$path = '' !== $dir ? realpath( $dir ) : false;
			if ( false !== $path && is_dir( $path ) ) {
				$real[ $path ] = $path;
			}
		}
		return array_values( $real );
	}

	/**
	 * dompdf font metrics with the same options as render() and the custom fonts registered, so that measuring
	 * (line height, text fitting, glyph coverage) knows the fonts the PDF really uses.
	 */
	public static function font_metrics(): ?\Dompdf\FontMetrics {
		$options = self::dompdf_options();
		$key     = (string) $options->getFontDir() . '|' . md5( (string) wp_json_encode( self::custom_fonts() ) );
		if ( ! array_key_exists( $key, self::$metrics ) ) {
			try {
				$metrics = ( new Dompdf( $options ) )->getFontMetrics();
				foreach ( self::custom_fonts() as $family => $variants ) {
					foreach ( (array) $variants as $variant => $path ) {
						$style = self::style_of( (string) $variant );
						if ( is_readable( (string) $path ) && ! self::has_font( $metrics, (string) $family, $style ) ) {
							$metrics->registerFont(
								array(
									'family' => (string) $family,
									'weight' => str_contains( $style, 'bold' ) ? 'bold' : 'normal',
									'style'  => str_contains( $style, 'italic' ) ? 'italic' : 'normal',
								),
								(string) $path
							);
						}
					}
				}
			} catch ( \Throwable $e ) {
				$metrics = null;
			}
			self::$metrics[ $key ] = $metrics;
			self::$height_ratio    = array();
		}
		return self::$metrics[ $key ];
	}

	private static function has_font( \Dompdf\FontMetrics $metrics, string $family, string $style ): bool {
		$families = $metrics->getFontFamilies();
		return isset( $families[ mb_strtolower( $family, 'UTF-8' ) ][ $style ] );
	}

	private static function style_of( string $variant ): string {
		$bold   = str_contains( $variant, 'bold' );
		$italic = str_contains( $variant, 'italic' );
		return $bold && $italic ? 'bold_italic' : ( $bold ? 'bold' : ( $italic ? 'italic' : 'normal' ) );
	}

	/**
	 * dompdf multiplies CSS line-height by the font's own height ratio (≈1.28 for DejaVu, 0.86 for Courier),
	 * browsers do not. Dividing by this ratio makes the PDF line pitch equal to the designer canvas. Measured with
	 * the render options, so custom fonts get their own ratio.
	 */
	public static function font_height_ratio( string $family, bool $bold, bool $italic ): float {
		$style   = $bold && $italic ? 'bold_italic' : ( $bold ? 'bold' : ( $italic ? 'italic' : 'normal' ) );
		$metrics = self::font_metrics();
		$key     = $family . '|' . $style;
		if ( ! isset( self::$height_ratio[ $key ] ) ) {
			$ratio = 1.0;
			try {
				$font = $metrics ? $metrics->getFont( $family, $style ) : null;
				if ( $font ) {
					$ratio = (float) $metrics->getFontHeight( $font, 100 ) / 100;
				}
			} catch ( \Throwable $e ) {
				$ratio = 1.0;
			}
			self::$height_ratio[ $key ] = $ratio > 0.5 && $ratio < 2 ? $ratio : 1.0;
		}
		return self::$height_ratio[ $key ];
	}

	public static function fonts(): array {
		$fonts = array(
			'DejaVu Sans'      => 'DejaVu Sans',
			'DejaVu Serif'     => 'DejaVu Serif',
			'DejaVu Sans Mono' => 'DejaVu Sans Mono',
			'Helvetica'        => 'Helvetica',
			'Times'            => 'Times',
			'Courier'          => 'Courier',
		);
		foreach ( array_keys( self::custom_fonts() ) as $family ) {
			$fonts[ $family ] = $family;
		}
		return $fonts;
	}

	/**
	 * Custom TTF fonts: [ 'Family' => [ 'normal' => '/path.ttf', 'bold' => ..., 'italic' => ..., 'bold_italic' => ... ] ].
	 */
	public static function custom_fonts(): array {
		return (array) apply_filters( 'vcap_pdf_fonts', array() );
	}

	/**
	 * Page size in mm [w, h] after orientation.
	 */
	public static function page_size( array $page ): array {
		$format = $page['format'] ?? 'A5';
		if ( 'custom' === $format ) {
			$w = max( 20, min( 1000, (float) ( $page['width'] ?? 148 ) ) );
			$h = max( 20, min( 1000, (float) ( $page['height'] ?? 210 ) ) );
		} else {
			list( $w, $h ) = self::FORMATS[ $format ] ?? self::FORMATS['A5'];
		}
		$landscape = 'landscape' === ( $page['orientation'] ?? 'portrait' );
		if ( 'custom' !== $format && $landscape ) {
			return array( max( $w, $h ), min( $w, $h ) );
		}
		if ( 'custom' !== $format ) {
			return array( min( $w, $h ), max( $w, $h ) );
		}
		return array( $w, $h );
	}

	public function render( Voucher $voucher, ?array $design = null ): string {
		/** The PDF template a voucher is printed with (args: template id, Voucher), e.g. a translation of the template. */
		$design = $design ?? TemplatePostType::design_for_voucher( (int) apply_filters( 'vcap_render_template_id', $voucher->template_id, $voucher ), $voucher );
		$html   = $this->html( $voucher, $design );
		list( $w, $h ) = self::sheet_size( $design['page'] ?? array() );

		$dompdf = new Dompdf( self::dompdf_options() );
		$dompdf->setPaper( array( 0, 0, $w * self::MM_TO_PT, $h * self::MM_TO_PT ) );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->render();
		$dompdf->addInfo( 'Title', self::pdf_title( $voucher ) );
		$dompdf->addInfo( 'Creator', Brand::name( 'pdf' ) );
		return (string) $dompdf->output();
	}

	/**
	 * M12-76: document title of a voucher PDF: "Product – Shop" (the shop name alone without a product). Never the
	 * voucher code: PDF viewers, mail clients and screen readers show the title, and the code is a means of payment.
	 */
	public static function pdf_title( Voucher $voucher ): string {
		$product = $voucher->product();
		$name    = $product ? trim( wp_strip_all_tags( (string) $product->get_name() ) ) : '';
		$shop    = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		/** Title of a voucher PDF (args: string $title, Voucher $voucher). */
		return (string) apply_filters( 'vcap_pdf_title', '' !== $name && '' !== $shop ? $name . ' – ' . $shop : ( '' !== $name ? $name : $shop ), $voucher );
	}

	/** M12-75: bleed of a page design in mm (0–10), added on every side of the printed sheet. */
	public static function bleed( array $page ): float {
		return max( 0.0, min( 10.0, (float) ( $page['bleed'] ?? 0 ) ) );
	}

	/** Size of the PDF sheet in mm: the page size plus the bleed on every side (M12-75). */
	public static function sheet_size( array $page ): array {
		list( $w, $h ) = self::page_size( $page );
		$bleed         = self::bleed( $page );
		return array( $w + 2 * $bleed, $h + 2 * $bleed );
	}

	/** Language of the PDF document (html lang, M12-76): the current locale, e.g. "de-DE". */
	public static function document_lang(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return (string) preg_replace( '/[^A-Za-z0-9-]/', '', str_replace( '_', '-', (string) $locale ) );
	}

	/**
	 * Write the PDF to a private temp file (for email attachments). Caller deletes it.
	 *
	 * The file is created exclusively (fopen "x") after rendering: two deliveries at the same time with the same
	 * file name pattern (e.g. "{order_number}") can never share, overwrite or attach each other's file. M12-43: the
	 * file always gets a random prefix, so its URL cannot be guessed from the code or order number should the
	 * private folder be reachable from the web; the mail attaches it under the configured name
	 * (attachment_name(), see attachments()).
	 */
	public function to_temp_file( Voucher $voucher, ?array $design = null ): ?string {
		Install::ensure_upload_dir();
		$bytes = $this->render( $voucher, $design );
		$dir   = Install::upload_dir() . '/tmp';
		$name  = $this->filename( $voucher );
		for ( $try = 0; $try < 5; $try++ ) {
			$file   = $dir . '/' . self::temp_prefix() . $name;
			$handle = @fopen( $file, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors -- exclusive create: fails when the file exists.
			if ( false === $handle ) {
				continue;
			}
			$ok = false !== fwrite( $handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $ok ) {
				return $file;
			}
			wp_delete_file( $file );
			self::write_failed( $voucher );
			return null;
		}
		self::write_failed( $voucher );
		return null;
	}

	/**
	 * Z6: several vouchers as PDF documents with one page per voucher (bundled delivery of a large quantity). A PDF
	 * has a single paper size here, so vouchers whose designs differ in page size get a document of their own
	 * (normally all vouchers of an order line share one template and one document results).
	 *
	 * M12-75: with $nup_a4 the vouchers are set side by side on A4 sheets instead (batch print in the admin): as
	 * many per sheet as fit in portrait or landscape A4 (at least one), with the bleed and crop marks of their
	 * designs; vouchers of the same page size share the sheets.
	 *
	 * @param Voucher[] $vouchers
	 * @return array<int, array{vouchers: Voucher[], pdf: string}> One entry per page size, in voucher order.
	 */
	public function render_many( array $vouchers, bool $nup_a4 = false ): array {
		$groups = array();
		foreach ( array_values( $vouchers ) as $voucher ) {
			$design        = TemplatePostType::design_for_voucher( (int) apply_filters( 'vcap_render_template_id', $voucher->template_id, $voucher ), $voucher );
			list( $w, $h ) = self::sheet_size( $design['page'] ?? array() );
			$key           = $this->num( $w ) . 'x' . $this->num( $h );
			$html          = $this->html( $voucher, $design );
			if ( ! preg_match( '#<body[^>]*>(.*)</body>#is', $html, $body ) ) {
				// A filter (vcap_pdf_html) produced a document without a body: that voucher is rendered on its own.
				$key  .= '#' . $voucher->id;
				$body  = array( 1 => $html );
			}
			preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $styles );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'size' => array( $w, $h ), 'vouchers' => array(), 'pages' => array(), 'styles' => array(), 'raw' => str_contains( $key, '#' ) ? $html : '' );
			}
			$groups[ $key ]['vouchers'][] = $voucher;
			$groups[ $key ]['pages'][]    = $body[1];
			foreach ( $styles[1] as $css ) {
				// N-up: the sheet size of the design (@page) is replaced by A4.
				$groups[ $key ]['styles'][ md5( $css ) ] = $nup_a4 ? (string) preg_replace( '/@page\{[^}]*\}/', '', $css ) : $css;
			}
		}
		$out = array();
		foreach ( $groups as $group ) {
			list( $w, $h ) = $group['size'];
			$layout        = $nup_a4 && '' === $group['raw'] ? self::nup_layout( $w, $h ) : null;
			if ( '' !== $group['raw'] ) {
				$html = $group['raw'];
			} elseif ( $layout ) {
				list( $w, $h ) = array( $layout['sheet'][0], $layout['sheet'][1] );
				$html          = $this->nup_html( $group['pages'], $group['styles'], $group['size'], $layout );
			} else {
				$html  = '<!DOCTYPE html><html lang="' . esc_attr( self::document_lang() ) . '"><head><meta charset="utf-8"><style>' . implode( '', $group['styles'] ) . '.vcap-sheet{page-break-after:always;}.vcap-sheet-last{page-break-after:auto;}</style></head><body>';
				$count = count( $group['pages'] );
				foreach ( $group['pages'] as $i => $page ) {
					$html .= '<div class="vcap-sheet' . ( $i === $count - 1 ? ' vcap-sheet-last' : '' ) . '">' . $page . '</div>';
				}
				$html .= '</body></html>';
			}
			$dompdf = new Dompdf( self::dompdf_options() );
			$dompdf->setPaper( array( 0, 0, $w * self::MM_TO_PT, $h * self::MM_TO_PT ) );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->render();
			$dompdf->addInfo( 'Title', self::pdf_title( $group['vouchers'][0] ) );
			$dompdf->addInfo( 'Creator', Brand::name( 'pdf' ) );
			$out[] = array(
				'vouchers' => $group['vouchers'],
				'pdf'      => (string) $dompdf->output(),
			);
		}
		return $out;
	}

	/** Gap between vouchers on an N-up sheet and its margin in mm (cutting room, printer margin). */
	private const NUP_GAP    = 4;
	private const NUP_MARGIN = 5;

	/**
	 * M12-75: how vouchers of a size are set on A4: columns, rows and sheet orientation with the most vouchers per
	 * sheet (portrait wins a tie); a voucher larger than the sheet gets a sheet of its own size.
	 *
	 * @return array{cols: int, rows: int, sheet: array{0: float, 1: float}, gap: float}
	 */
	public static function nup_layout( float $w, float $h ): array {
		$best = null;
		// With margin and gaps where they cost no voucher; formats that tile A4 exactly (A6, A5) without them.
		foreach ( array( array( self::NUP_MARGIN, self::NUP_GAP ), array( 0, 0 ) ) as $spacing ) {
			foreach ( array( array( 210.0, 297.0 ), array( 297.0, 210.0 ) ) as $sheet ) {
				$cols = (int) floor( ( $sheet[0] - 2 * $spacing[0] + $spacing[1] + 0.01 ) / ( $w + $spacing[1] ) );
				$rows = (int) floor( ( $sheet[1] - 2 * $spacing[0] + $spacing[1] + 0.01 ) / ( $h + $spacing[1] ) );
				if ( $cols >= 1 && $rows >= 1 && ( null === $best || $cols * $rows > $best['cols'] * $best['rows'] ) ) {
					$best = array( 'cols' => $cols, 'rows' => $rows, 'sheet' => $sheet, 'gap' => (float) $spacing[1] );
				}
			}
		}
		return $best ?? array( 'cols' => 1, 'rows' => 1, 'sheet' => array( max( 210.0, $w ), max( 297.0, $h ) ), 'gap' => 0.0 );
	}

	/** HTML of N-up sheets: the voucher pages placed in a centred grid, a page break after each full sheet. */
	private function nup_html( array $pages, array $styles, array $size, array $layout ): string {
		list( $w, $h )   = $size;
		list( $sw, $sh ) = $layout['sheet'];
		$per             = $layout['cols'] * $layout['rows'];
		$left            = ( $sw - $layout['cols'] * $w - ( $layout['cols'] - 1 ) * $layout['gap'] ) / 2;
		$top             = ( $sh - $layout['rows'] * $h - ( $layout['rows'] - 1 ) * $layout['gap'] ) / 2;
		$html            = '<!DOCTYPE html><html lang="' . esc_attr( self::document_lang() ) . '"><head><meta charset="utf-8"><style>' . sprintf( '@page{margin:0;size:%1$smm %2$smm;}', $this->num( $sw ), $this->num( $sh ) ) . implode( '', $styles );
		$html           .= sprintf( '.vcap-nup{position:relative;width:%1$smm;height:%2$smm;overflow:hidden;page-break-after:always;}.vcap-nup-last{page-break-after:auto;}.vcap-cell{position:absolute;width:%3$smm;height:%4$smm;overflow:hidden;}', $this->num( $sw ), $this->num( $sh ), $this->num( $w ), $this->num( $h ) );
		$html           .= '</style></head><body>';
		$sheets          = array_chunk( $pages, max( 1, $per ) );
		foreach ( $sheets as $s => $chunk ) {
			$html .= '<div class="vcap-nup' . ( count( $sheets ) - 1 === $s ? ' vcap-nup-last' : '' ) . '">';
			foreach ( $chunk as $i => $page ) {
				$col   = $i % $layout['cols'];
				$row   = intdiv( $i, $layout['cols'] );
				$html .= sprintf( '<div class="vcap-cell" style="left:%smm;top:%smm;">%s</div>', $this->num( $left + $col * ( $w + $layout['gap'] ) ), $this->num( $top + $row * ( $h + $layout['gap'] ) ), $page );
			}
			$html .= '</div>';
		}
		return $html . '</body></html>';
	}

	/**
	 * Z6: the multi-page PDF(s) of a bundle as private temp files (caller deletes them), named after the first
	 * voucher's file name with the number of vouchers ("voucher-ABC-and-49-more.pdf").
	 *
	 * @param Voucher[] $vouchers
	 * @return string[]|null Paths, null when a file could not be written (logged).
	 */
	public function bundle_to_temp_files( array $vouchers ): ?array {
		Install::ensure_upload_dir();
		$dir   = Install::upload_dir() . '/tmp';
		$files = array();
		foreach ( $this->render_many( $vouchers ) as $part ) {
			$first = $part['vouchers'][0];
			$more  = count( $part['vouchers'] ) - 1;
			$name  = $more > 0 ? preg_replace( '/\.pdf$/', '', $this->filename( $first ) ) . '-' . sprintf( 'and-%d-more', $more ) . '.pdf' : $this->filename( $first );
			$file  = null;
			for ( $try = 0; $try < 5 && null === $file; $try++ ) {
				$path   = $dir . '/' . self::temp_prefix() . $name;
				$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors -- exclusive create: fails when the file exists.
				if ( false === $handle ) {
					continue;
				}
				$ok = false !== fwrite( $handle, $part['pdf'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( ! $ok ) {
					wp_delete_file( $path );
					break;
				}
				$file = $path;
			}
			if ( null === $file ) {
				self::write_failed( $first );
				foreach ( $files as $written ) {
					wp_delete_file( $written );
				}
				return null;
			}
			$files[] = $file;
		}
		return $files;
	}

	/** Length of the random prefix of a temp PDF (M12-43). */
	private const TEMP_PREFIX_LENGTH = 24;

	/** Random, unguessable file name prefix of a temp PDF ("<24 letters and digits>-"). */
	private static function temp_prefix(): string {
		return wp_generate_password( self::TEMP_PREFIX_LENGTH, false ) . '-';
	}

	/** The name a temp PDF is attached under: its file name without the random prefix (M12-43). */
	public static function attachment_name( string $file ): string {
		$base = basename( $file );
		return (string) preg_replace( '/^[A-Za-z0-9]{' . self::TEMP_PREFIX_LENGTH . '}-(?=.)/', '', $base );
	}

	/**
	 * M12-43: temp PDFs as wp_mail() attachments keyed by the name the customer sees (WordPress 6.2+ names
	 * attachments after string keys; the plugin requires 6.4). Older versions or mailers that ignore the key
	 * attach the file under its own name, which still ends with the configured name.
	 *
	 * @param string[] $files Paths of temp PDFs.
	 * @return array<int|string, string>
	 */
	public static function attachments( array $files ): array {
		$out = array();
		foreach ( array_values( $files ) as $file ) {
			$name = self::attachment_name( (string) $file );
			if ( isset( $out[ $name ] ) || ! function_exists( 'get_bloginfo' ) || version_compare( (string) get_bloginfo( 'version' ), '6.2', '<' ) ) {
				$out[] = (string) $file;
				continue;
			}
			$out[ $name ] = (string) $file;
		}
		return $out;
	}

	/** D5: the mail would go out without its PDF – leave a trace with the voucher. */
	private static function write_failed( Voucher $voucher ): void {
		Logger::error( 'Voucher PDF could not be written to the private temp folder (disk full or no write permission).', array( 'voucher_id' => (int) $voucher->id, 'element' => 'pdf_file' ) );
	}

	/**
	 * Why a barcode type cannot encode this value ('' when it can): EAN-13 takes 12 or 13 digits only, Code 39
	 * upper-case letters, digits and - . $ / + % and space. Shown in the template preview and logged on render (D5).
	 */
	public static function barcode_problem( string $type, string $payload ): string {
		switch ( $type ) {
			case 'EAN13':
				return preg_match( '/^\d{12,13}$/', $payload ) ? '' : __( 'EAN-13 barcodes need a code of 12 or 13 digits; this code cannot be printed as EAN-13 (use Code 128).', 'voucher-captain' );
			case 'C39':
				return preg_match( '/^[A-Z0-9\-. $\/+%]+$/', $payload ) ? '' : __( 'Code 39 barcodes only take upper-case letters, digits and - . $ / + % (use Code 128).', 'voucher-captain' );
		}
		return '';
	}

	/** An element (QR code, barcode) could not be drawn: logged with the voucher and the element type (D5). */
	private static function element_failed( string $element, Voucher $voucher, string $why ): void {
		Logger::error( sprintf( 'Voucher PDF: the %s could not be drawn: %s', $element, Mask::addresses( $why ) ), array( 'voucher_id' => (int) $voucher->id, 'element' => $element ) );
	}

	public function filename( Voucher $voucher ): string {
		$pattern = $this->settings ? (string) $this->settings->get( 'vcap_pdf_filename' ) : 'voucher-{coupon_code}';
		$name    = sanitize_file_name( Placeholders::replace( $pattern ?: 'voucher-{coupon_code}', $voucher ) );
		return ( '' !== $name ? $name : 'voucher-' . $voucher->id ) . '.pdf';
	}

	// ------------------------------------------------------------------ HTML generation.

	public function html( Voucher $voucher, array $design ): string {
		$page = $design['page'] ?? array();
		list( $w, $h ) = self::page_size( $page );
		$values = Placeholders::values( $voucher );

		$font_css = '';
		foreach ( self::custom_fonts() as $family => $variants ) {
			foreach ( (array) $variants as $variant => $path ) {
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$weight    = str_contains( (string) $variant, 'bold' ) ? 'bold' : 'normal';
				$style     = str_contains( (string) $variant, 'italic' ) ? 'italic' : 'normal';
				$font_css .= sprintf( "@font-face{font-family:'%s';font-weight:%s;font-style:%s;src:url('%s') format('truetype');}", esc_attr( $family ), $weight, $style, esc_attr( $path ) );
			}
		}

		// M12-75: with a bleed the sheet is larger than the page; the page sits in its middle, the background and
		// every element touching an edge reach into the bleed, so no white border remains after trimming.
		$bleed = self::bleed( $page );
		$sw    = $w + 2 * $bleed;
		$sh    = $h + 2 * $bleed;

		$bg     = $this->color( $page['background'] ?? '#ffffff', '#ffffff' );
		$out    = '<!DOCTYPE html><html lang="' . esc_attr( self::document_lang() ) . '"><head><meta charset="utf-8"><title>' . esc_html( self::pdf_title( $voucher ) ) . '</title><style>' . $font_css;
		$out   .= sprintf( '@page{margin:0;size:%1$smm %2$smm;}html,body{margin:0;padding:0;}', $this->num( $sw ), $this->num( $sh ) );
		$out   .= sprintf( '.page{position:relative;width:%1$smm;height:%2$smm;overflow:hidden;background:%3$s;}', $this->num( $sw ), $this->num( $sh ), $bg );
		$out   .= '.el{position:absolute;overflow:hidden;}.el img{display:block;}.txt{white-space:normal;word-wrap:break-word;}';
		$out   .= '</style></head><body><div class="page">';

		$bg_path = $this->image_path( $page['backgroundImageId'] ?? 0, $page['backgroundImage'] ?? '' );
		if ( $bg_path ) {
			$out .= $this->image_box( $bg_path, 0, 0, $sw, $sh, (string) ( $page['backgroundFit'] ?? 'cover' ), 1.0, 0 );
		}

		foreach ( (array) ( $design['elements'] ?? array() ) as $el ) {
			if ( ! empty( $el['hidden'] ) ) {
				continue;
			}
			$out .= $this->element( $bleed > 0 ? self::bleed_element( (array) $el, $w, $h, $bleed ) : (array) $el, $voucher, $values );
		}
		if ( $bleed >= 2 && ! empty( $page['cropMarks'] ) ) {
			$out .= $this->crop_marks( $w, $h, $bleed );
		}
		$out .= '</div></body></html>';
		/** HTML a voucher PDF is rendered from (args: string $html, Voucher $voucher, array $design). */
		return (string) apply_filters( 'vcap_pdf_html', $out, $voucher, $design );
	}

	/**
	 * M12-75: an element in the coordinates of the sheet with bleed. Images, shapes and lines that touch a page
	 * edge (within 0.5 mm, not rotated) are extended into the bleed on that side; texts and codes only move.
	 */
	public static function bleed_element( array $el, float $w, float $h, float $bleed ): array {
		$x       = (float) ( $el['x'] ?? 0 );
		$y       = (float) ( $el['y'] ?? 0 );
		$ew      = (float) ( $el['w'] ?? 50 );
		$eh      = (float) ( $el['h'] ?? 10 );
		$el['x'] = $x + $bleed;
		$el['y'] = $y + $bleed;
		if ( ! in_array( $el['type'] ?? '', array( 'image', 'rect', 'line' ), true ) || 0.0 !== (float) ( $el['rotation'] ?? 0 ) ) {
			return $el;
		}
		$edge = 0.5;
		$nx   = $x + $bleed;
		$ny   = $y + $bleed;
		$nw   = $ew;
		$nh   = $eh;
		if ( $x <= $edge ) {
			$nw += $nx;
			$nx  = 0.0;
		}
		if ( $x + $ew >= $w - $edge ) {
			$nw = $w + 2 * $bleed - $nx;
		}
		if ( 'line' !== $el['type'] ) { // A line is as high as its stroke: only its length grows.
			if ( $y <= $edge ) {
				$nh += $ny;
				$ny  = 0.0;
			}
			if ( $y + $eh >= $h - $edge ) {
				$nh = $h + 2 * $bleed - $ny;
			}
		}
		$el['x'] = round( $nx, 3 );
		$el['y'] = round( $ny, 3 );
		$el['w'] = round( $nw, 3 );
		$el['h'] = round( $nh, 3 );
		return $el;
	}

	/** M12-75: crop marks in the bleed at the four corners of the page (0.25 pt black hairlines). */
	private function crop_marks( float $w, float $h, float $bleed ): string {
		$len   = max( 1.0, $bleed - 1.0 );
		$out   = '';
		$marks = array();
		foreach ( array( array( 0, 0 ), array( $w, 0 ), array( 0, $h ), array( $w, $h ) ) as $corner ) {
			list( $cx, $cy ) = $corner;
			$cx    += $bleed;
			$cy    += $bleed;
			$dir_x  = 0.0 === (float) $corner[0] ? -1 : 1;
			$dir_y  = 0.0 === (float) $corner[1] ? -1 : 1;
			// Horizontal mark beside the corner, vertical mark above/below it, both 1 mm away from the page.
			$marks[] = array( $dir_x < 0 ? $cx - 1 - $len : $cx + 1, $cy, $len, 0.1 );
			$marks[] = array( $cx, $dir_y < 0 ? $cy - 1 - $len : $cy + 1, 0.1, $len );
		}
		foreach ( $marks as $m ) {
			$out .= sprintf( '<div class="el" style="left:%smm;top:%smm;width:%smm;height:%smm;background:#000000;"></div>', $this->num( $m[0] ), $this->num( $m[1] ), $this->num( $m[2] ), $this->num( $m[3] ) );
		}
		return $out;
	}

	private function num( $v ): string {
		return rtrim( rtrim( number_format( (float) $v, 3, '.', '' ), '0' ), '.' ) ?: '0';
	}

	private function color( $value, string $fallback = '#000000' ): string {
		$value = (string) $value;
		if ( 'transparent' === $value ) {
			return 'transparent';
		}
		return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : $fallback;
	}

	private function box_style( array $el, bool $opaque = false ): string {
		$x   = (float) ( $el['x'] ?? 0 );
		$y   = (float) ( $el['y'] ?? 0 );
		$w   = max( 1, (float) ( $el['w'] ?? 50 ) );
		$h   = max( 1, (float) ( $el['h'] ?? 10 ) );
		$rot = (float) ( $el['rotation'] ?? 0 );
		$css = sprintf( 'left:%smm;top:%smm;width:%smm;height:%smm;', $this->num( $x ), $this->num( $y ), $this->num( $w ), $this->num( $h ) );
		if ( 0.0 !== $rot ) {
			$css .= sprintf( 'transform:rotate(%sdeg);transform-origin:50%% 50%%;', $this->num( $rot ) );
		}
		$opacity = isset( $el['opacity'] ) && ! $opaque ? max( 0, min( 1, (float) $el['opacity'] ) ) : 1;
		if ( $opacity < 1 ) {
			$css .= 'opacity:' . $this->num( $opacity ) . ';';
		}
		return $css;
	}

	private function element( array $el, Voucher $voucher, array $values ): string {
		$type = (string) ( $el['type'] ?? 'text' );
		switch ( $type ) {
			case 'text':
			case 'heading':
				return $this->text_element( $el, $voucher, $values );
			case 'image':
				$path = $this->image_path( $el['imageId'] ?? 0, $el['src'] ?? '' );
				if ( ! $path ) {
					return '';
				}
				return $this->image_box( $path, (float) $el['x'], (float) $el['y'], (float) $el['w'], (float) $el['h'], (string) ( $el['fit'] ?? 'contain' ), isset( $el['opacity'] ) ? (float) $el['opacity'] : 1.0, (float) ( $el['rotation'] ?? 0 ) );
			case 'qrcode':
				return $this->qr_element( $el, $voucher, $values );
			case 'barcode':
				return $this->barcode_element( $el, $voucher, $values );
			case 'rect':
				$style  = $this->box_style( $el );
				$style .= 'background:' . $this->color( $el['fill'] ?? 'transparent', 'transparent' ) . ';';
				if ( ! empty( $el['borderWidth'] ) ) {
					$style .= sprintf( 'border:%smm solid %s;', $this->num( $el['borderWidth'] ), $this->color( $el['borderColor'] ?? '#000000' ) );
					// Keep outer size equal to the designer box.
					$bw     = (float) $el['borderWidth'];
					$style .= sprintf( 'width:%smm;height:%smm;', $this->num( max( 0, (float) $el['w'] - 2 * $bw ) ), $this->num( max( 0, (float) $el['h'] - 2 * $bw ) ) );
				}
				if ( ! empty( $el['radius'] ) ) {
					$style .= 'border-radius:' . $this->num( $el['radius'] ) . 'mm;';
				}
				return '<div class="el" style="' . esc_attr( $style ) . '"></div>';
			case 'line':
				$style  = $this->box_style( array_merge( $el, array( 'h' => max( 0.1, (float) ( $el['borderWidth'] ?? 0.5 ) ) ) ) );
				$style .= 'background:' . $this->color( $el['borderColor'] ?? ( $el['fill'] ?? '#000000' ) ) . ';';
				return '<div class="el" style="' . esc_attr( $style ) . '"></div>';
		}
		return (string) apply_filters( 'vcap_pdf_element_html', '', $el, $voucher, $values );
	}

	/** @var array<string,bool> "font|style|char" => whether a font of the stack has the glyph. */
	private static array $glyphs = array();

	/**
	 * Characters of a text that no font of the PDF font stack can draw (they would print as empty boxes), e.g.
	 * Chinese names or emoji with the bundled DejaVu fonts. Spaces, control and zero-width characters are ignored.
	 *
	 * @return string[]
	 */
	public static function unsupported_chars( string $text, string $font = 'DejaVu Sans', bool $bold = false, bool $italic = false ): array {
		if ( ! preg_match( '/[^\x00-\x7E]/', $text ) ) {
			return array(); // ASCII: every font has it.
		}
		$metrics = self::font_metrics();
		if ( ! $metrics ) {
			return array();
		}
		$style   = $bold && $italic ? 'bold_italic' : ( $bold ? 'bold' : ( $italic ? 'italic' : 'normal' ) );
		$canvas  = $metrics->getCanvas();
		$files   = array();
		foreach ( self::font_families( $font ) as $family ) {
			$file = $metrics->getFont( $family, $style ) ?: $metrics->getFont( $family, 'normal' );
			if ( $file ) {
				$files[] = (string) $file;
			}
		}
		$missing = array();
		foreach ( array_unique( mb_str_split( $text ) ) as $char ) {
			if ( preg_match( '/^[\x00-\x20\x7F\x{00A0}\x{00AD}\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2060}\x{FE00}-\x{FE0F}\x{FEFF}]$/u', $char ) ) {
				continue;
			}
			$key = $font . '|' . $style . '|' . $char;
			if ( ! isset( self::$glyphs[ $key ] ) ) {
				self::$glyphs[ $key ] = false;
				foreach ( $files as $file ) {
					try {
						if ( $canvas->font_supports_char( $file, $char ) ) {
							self::$glyphs[ $key ] = true;
							break;
						}
					} catch ( \Throwable $e ) {
						self::$glyphs[ $key ] = true; // Unknown: do not warn.
						break;
					}
				}
			}
			if ( ! self::$glyphs[ $key ] ) {
				$missing[] = $char;
			}
		}
		return $missing;
	}

	/** Right-to-left scripts: dompdf prints them left to right and, for Arabic, without joining the letters. */
	public static function has_rtl( string $text ): bool {
		return (bool) preg_match( '/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text );
	}

	/**
	 * Emoji and pictographs (with their joiners, variation selectors and skin tones) that no font can draw are
	 * left out instead of printing as empty boxes. Other missing characters (e.g. a Chinese name) are kept: the
	 * customer was warned when entering them, and a fallback font can be added (COMPATIBILITY.md).
	 */
	public static function strip_unprintable_emoji( string $text, string $font = 'DejaVu Sans', bool $bold = false, bool $italic = false ): string {
		// M12-72: also the tag characters of subdivision flags (🏴 + U+E0020–E007F, e.g. Scotland), the keycap
		// mark U+20E3 (1️⃣) and the other emoji blocks; they are left out together with their joiners.
		$missing = array_filter( self::unsupported_chars( $text, $font, $bold, $italic ), static fn( $c ) => (bool) preg_match( '/^[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F1E6}-\x{1F1FF}\x{E0000}-\x{E007F}\x{20E3}\x{3030}\x{303D}\x{3297}\x{3299}]$/u', $c ) );
		if ( ! $missing ) {
			return $text;
		}
		$text = str_replace( array_merge( $missing, array( "\u{200D}", "\u{FE0F}" ) ), '', $text );
		$text = (string) preg_replace( '/[\x{E0000}-\x{E007F}]/u', '', $text ); // Tag characters never print.

		return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $text ), " \t" );
	}

	/**
	 * Warning for a personalisation value the PDF cannot print as entered ('' when it can): characters without a
	 * glyph (boxes; emoji are left out) or right-to-left text (printed reversed and unjoined).
	 */
	public static function print_warning( string $label, string $text ): string {
		$missing = self::unsupported_chars( $text );
		if ( $missing ) {
			/* translators: 1: field label, 2: the characters */
			return sprintf( __( '%1$s contains characters the voucher PDF cannot print (%2$s). Emoji are left out, other characters appear as empty boxes.', 'voucher-captain' ), $label, implode( ' ', array_slice( $missing, 0, 10 ) ) );
		}
		if ( self::has_rtl( $text ) ) {
			/* translators: %s: field label */
			return sprintf( __( '%s contains right-to-left text (e.g. Arabic or Hebrew). The voucher PDF prints it left to right and with unjoined letters.', 'voucher-captain' ), $label );
		}
		return '';
	}

	/** Smallest share of the designed font size a text is shrunk to before it is cut off. */
	public const MIN_FIT_SCALE = 0.5;

	/**
	 * Font size at which a text fits its box (lines × line pitch ≤ box height), measured with dompdf's metrics:
	 * the designed size when it fits, else smaller in steps down to MIN_FIT_SCALE (at least 4 pt).
	 *
	 * @param float $line_height CSS line height as designed (the pitch is size × line height, as on the canvas).
	 * @return array{size: float, fits: bool}
	 */
	public static function fit_size( string $text, string $font, float $size, float $line_height, bool $bold, bool $italic, float $spacing, float $w_mm, float $h_mm ): array {
		$metrics = '' !== trim( $text ) ? self::font_metrics() : null;
		$style   = $bold && $italic ? 'bold_italic' : ( $bold ? 'bold' : ( $italic ? 'italic' : 'normal' ) );
		$file    = $metrics ? $metrics->getFont( $font, $style ) : null;
		if ( ! $metrics || ! $file ) {
			return array( 'size' => $size, 'fits' => true );
		}
		$width  = $w_mm * self::MM_TO_PT - 0.5; // A little room for rounding in dompdf's own line breaking.
		$height = $h_mm * self::MM_TO_PT + 0.5;
		$min    = min( $size, max( 4, $size * self::MIN_FIT_SCALE ) );
		for ( $s = $size; ; $s = max( $min, round( $s * 0.94, 2 ) ) ) {
			if ( self::count_lines( $metrics, (string) $file, $text, $s, $spacing, $width ) * $s * $line_height <= $height ) {
				return array( 'size' => $s, 'fits' => true );
			}
			if ( $s <= $min ) {
				return array( 'size' => $min, 'fits' => false );
			}
		}
	}

	/**
	 * Lines dompdf needs for a text in a box of $width pt: breaks at line breaks, after spaces and hyphens, and
	 * inside words longer than a line (word-wrap: break-word).
	 */
	private static function count_lines( \Dompdf\FontMetrics $metrics, string $file, string $text, float $size, float $spacing, float $width ): int {
		$lines = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $para ) {
			$para  = trim( (string) preg_replace( '/[ \t]+/', ' ', (string) $para ) );
			$count = 1;
			$line  = 0.0;
			foreach ( '' === $para ? array() : (array) preg_split( '/(?<=[ -])/u', $para, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
				$full = $metrics->getTextWidth( (string) $token, $file, $size, 0.0, $spacing );
				$trim = $metrics->getTextWidth( rtrim( (string) $token ), $file, $size, 0.0, $spacing );
				if ( $line > 0 && $line + $trim > $width ) {
					++$count;
					$line = 0.0;
				}
				if ( $trim > $width ) {
					foreach ( mb_str_split( (string) $token ) as $char ) {
						$w = $metrics->getTextWidth( $char, $file, $size, 0.0, $spacing );
						if ( $line > 0 && $line + $w > $width ) {
							++$count;
							$line = 0.0;
						}
						$line += $w;
					}
					continue;
				}
				$line += $full;
			}
			$lines += $count;
		}
		return max( 1, $lines );
	}

	/**
	 * Text of a text element as the PDF prints it ('' when the element is left out): placeholders replaced, lines
	 * with {expiry_date} dropped for vouchers without expiry (M12-77), upper case, unprintable emoji left out.
	 */
	private static function element_text( array $el, Voucher $voucher, array $values, array $params ): string {
		$raw = Placeholders::drop_undated_lines( (string) ( $el['text'] ?? '' ), $voucher );
		if ( '' === trim( $raw ) || Placeholders::all_empty( $raw, $voucher, $values ) ) {
			return ''; // "For: {recipient_name}" without a name: no lone label.
		}
		$text = Placeholders::replace( $raw, $voucher, $values );
		if ( ! empty( $el['uppercase'] ) ) {
			$text = mb_strtoupper( $text );
		}
		return self::strip_unprintable_emoji( $text, $params['font'], $params['bold'], $params['italic'] );
	}

	/**
	 * Typography of a text element as the PDF uses it.
	 *
	 * @return array{font: string, size: float, lh: float, bold: bool, italic: bool, spacing: float, w: float, h: float}
	 */
	private static function text_params( array $el ): array {
		$fonts = self::fonts();
		$type  = (string) ( $el['type'] ?? 'text' );
		return array(
			'font'    => isset( $fonts[ $el['font'] ?? '' ] ) ? (string) $el['font'] : 'DejaVu Sans',
			'size'    => max( 4, min( 300, (float) ( $el['size'] ?? ( 'heading' === $type ? 24 : 11 ) ) ) ),
			'lh'      => max( 0.8, min( 3, (float) ( $el['lineHeight'] ?? 1.25 ) ) ),
			'bold'    => ! empty( $el['bold'] ) || ( 'heading' === $type && ! isset( $el['bold'] ) ),
			'italic'  => ! empty( $el['italic'] ),
			'spacing' => (float) ( $el['letterSpacing'] ?? 0 ),
			'w'       => max( 1, (float) ( $el['w'] ?? 50 ) ),
			'h'       => max( 1, (float) ( $el['h'] ?? 10 ) ),
		);
	}

	/** Share of the designed font size below which the customer is told that the text is printed small (M12-39). */
	public const SHRINK_NOTICE = 0.75;

	/** Placeholders a customer fills in: their texts are checked against the box before the purchase (M12-39). */
	public const CUSTOMER_TOKENS = '/\{(message|recipient_name|field:[^}]+)\}/i';

	/**
	 * M12-39: how the customer's texts fit the design: every visible text element with {message},
	 * {recipient_name} or {field:…}, measured as the PDF sets it (fit_size()).
	 *
	 * @return array<int, array{id: string, name: string, tokens: string[], fits: bool, scale: float, max_lines: int, lines: int}>
	 *         scale: printed size ÷ designed size; max_lines: lines the box holds at the smallest size;
	 *         lines: lines of the text at the printed size.
	 */
	public static function fit_report( array $design, Voucher $voucher, ?array $values = null ): array {
		$values = $values ?? Placeholders::values( $voucher );
		$out    = array();
		foreach ( (array) ( $design['elements'] ?? array() ) as $el ) {
			$el = (array) $el;
			if ( ! empty( $el['hidden'] ) || ! in_array( $el['type'] ?? '', array( 'text', 'heading' ), true ) || ! preg_match_all( self::CUSTOMER_TOKENS, (string) ( $el['text'] ?? '' ), $m ) ) {
				continue;
			}
			$params = self::text_params( $el );
			$text   = self::element_text( $el, $voucher, $values, $params );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$fitted  = self::fit_size( $text, $params['font'], $params['size'], $params['lh'], $params['bold'], $params['italic'], $params['spacing'], $params['w'], $params['h'] );
			$min     = min( $params['size'], max( 4, $params['size'] * self::MIN_FIT_SCALE ) );
			$metrics = self::font_metrics();
			$file    = $metrics ? $metrics->getFont( $params['font'], self::style_of( ( $params['bold'] ? 'bold' : '' ) . ( $params['italic'] ? 'italic' : '' ) ) ) : null;
			$out[]   = array(
				'id'        => (string) ( $el['id'] ?? '' ),
				'name'      => (string) ( $el['name'] ?? ( $el['id'] ?? '' ) ),
				'tokens'    => array_values( array_unique( array_map( 'strtolower', $m[1] ) ) ),
				'fits'      => $fitted['fits'],
				'scale'     => round( $fitted['size'] / $params['size'], 3 ),
				'max_lines' => max( 1, (int) floor( ( $params['h'] * self::MM_TO_PT + 0.5 ) / ( $min * $params['lh'] ) ) ),
				'lines'     => $metrics && $file ? self::count_lines( $metrics, (string) $file, $text, $fitted['size'], $params['spacing'], $params['w'] * self::MM_TO_PT - 0.5 ) : 1,
			);
		}
		return $out;
	}

	private function text_element( array $el, Voucher $voucher, array $values ): string {
		$params = self::text_params( $el );
		$text   = self::element_text( $el, $voucher, $values, $params );
		if ( '' === $text ) {
			return '';
		}
		$font   = $params['font'];
		$size   = $params['size'];
		$align  = (string) ( $el['align'] ?? 'left' );
		$align  = in_array( $align, array( 'left', 'center', 'right', 'justify' ), true ) ? $align : 'left';
		$valign = (string) ( $el['valign'] ?? 'top' );
		$valign = in_array( $valign, array( 'top', 'middle', 'bottom' ), true ) ? $valign : 'top';
		$lh     = $params['lh'];
		$bold   = $params['bold'];
		// A long message or code is set smaller until it fits its box instead of being cut off without a trace.
		$fitted = self::fit_size( $text, (string) $font, $size, $lh, $bold, $params['italic'], $params['spacing'], $params['w'], $params['h'] );
		if ( $fitted['size'] < $size ) {
			$size = $fitted['size'];
		}
		if ( ! $fitted['fits'] ) {
			Logger::warning( sprintf( 'PDF text element "%s" does not fit its box even at %s pt and is cut off.', (string) ( $el['name'] ?? ( $el['id'] ?? '' ) ), $this->num( $size ) ), array( 'voucher' => $voucher->id ) );
		}
		$lh    /= self::font_height_ratio( (string) $font, $bold, ! empty( $el['italic'] ) );

		$style  = $this->box_style( $el );
		$inner  = sprintf(
			'font-family:%s;font-size:%spt;line-height:%s;color:%s;text-align:%s;font-weight:%s;font-style:%s;%s',
			self::font_stack( (string) $font ),
			$this->num( $size ),
			$this->num( $lh ),
			$this->color( $el['color'] ?? '#000000' ),
			$align,
			$bold ? 'bold' : 'normal',
			! empty( $el['italic'] ) ? 'italic' : 'normal',
			! empty( $el['underline'] ) ? 'text-decoration:underline;' : ''
		);
		if ( ! empty( $el['letterSpacing'] ) ) {
			$inner .= 'letter-spacing:' . $this->num( $el['letterSpacing'] ) . 'pt;';
		}
		if ( ! empty( $el['fill'] ) && 'transparent' !== $el['fill'] ) {
			$style .= 'background:' . $this->color( $el['fill'], 'transparent' ) . ';';
		}

		$body = nl2br( esc_html( $text ) );
		if ( 'top' !== $valign ) {
			// dompdf supports table-cell vertical alignment.
			$h    = max( 1, (float) ( $el['h'] ?? 10 ) );
			$body = sprintf( '<table style="width:100%%;border-collapse:collapse;"><tr><td style="height:%smm;vertical-align:%s;padding:0;%s">%s</td></tr></table>', $this->num( $h ), $valign, esc_attr( $inner ), $body ); // dompdf ignores height on <table>, not on <td>.
			return '<div class="el txt" style="' . esc_attr( $style ) . '">' . $body . '</div>';
		}
		return '<div class="el txt" style="' . esc_attr( $style . $inner ) . '">' . $body . '</div>';
	}

	public function qr_payload( array $el, Voucher $voucher, array $values ): string {
		switch ( $el['qrContent'] ?? ( $voucher->is_ticket() ? 'checkin' : 'code' ) ) {
			case 'checkin':
				return Tickets::checkin_url( $voucher );
			case 'download':
				// M12-41: a printed link lives as long as the voucher (+30 days), not as long as an email link.
				return $voucher->id ? vcap()->downloads->printed_url( $voucher ) : home_url( '/' );
			case 'custom':
				return Placeholders::replace( (string) ( $el['qrValue'] ?? '{coupon_code}' ), $voucher, $values );
			default:
				return $voucher->code;
		}
	}

	private function qr_element( array $el, Voucher $voucher, array $values ): string {
		if ( ! class_exists( QRCode::class ) ) {
			return '';
		}
		$payload = $this->qr_payload( $el, $voucher, $values );
		if ( '' === $payload ) {
			return '';
		}
		// SVG (vector): needs no GD, prints sharp at any size, and dompdf draws it as paths. M12-40: the quiet zone
		// of 4 modules and the light modules are white on a white ground, whatever lies beneath (a dark band, a
		// photo), and the element's opacity is ignored, so scanners find and read the code.
		$dark    = $this->color( $el['color'] ?? '#000000' );
		$dark    = 'transparent' === $dark ? '#000000' : $dark;
		$options = new QROptions(
			array(
				'outputType'       => QROutputInterface::MARKUP_SVG,
				'outputBase64'     => true,
				'eccLevel'         => EccLevel::M,
				'addQuietzone'     => true,
				'quietzoneSize'    => self::QR_QUIET_ZONE,
				'drawLightModules' => true,
				'connectPaths'     => true,
				'moduleValues'     => array(
					// Dark modules in the chosen colour.
					\chillerlan\QRCode\Data\QRMatrix::M_DATA_DARK      => $dark,
					\chillerlan\QRCode\Data\QRMatrix::M_FINDER_DARK    => $dark,
					\chillerlan\QRCode\Data\QRMatrix::M_ALIGNMENT_DARK => $dark,
					\chillerlan\QRCode\Data\QRMatrix::M_TIMING_DARK    => $dark,
					\chillerlan\QRCode\Data\QRMatrix::M_FORMAT_DARK    => $dark,
					\chillerlan\QRCode\Data\QRMatrix::M_VERSION_DARK   => $dark,
					\chillerlan\QRCode\Data\QRMatrix::M_FINDER_DOT     => $dark,
					\chillerlan\QRCode\Data\QRMatrix::M_DARKMODULE     => $dark,
				),
			)
		);
		try {
			$uri = ( new QRCode( $options ) )->render( $payload );
		} catch ( \Throwable $e ) {
			self::element_failed( 'qr', $voucher, $e->getMessage() );
			return '';
		}
		$size  = min( (float) $el['w'], (float) $el['h'] );
		$style = $this->box_style( $el, true );
		return sprintf(
			'<div class="el" style="%s"><img src="%s" style="width:%smm;height:%smm;margin-left:%smm;margin-top:%smm;background:#ffffff;" /></div>',
			esc_attr( $style ),
			esc_attr( $uri ),
			$this->num( $size ),
			$this->num( $size ),
			$this->num( ( (float) $el['w'] - $size ) / 2 ),
			$this->num( ( (float) $el['h'] - $size ) / 2 )
		);
	}

	/** Quiet zone of a QR code in modules (ISO/IEC 18004: 4). */
	public const QR_QUIET_ZONE = 4;
	/** Quiet zone left and right of a barcode in modules (ISO/IEC 15417: 10). */
	public const BARCODE_QUIET_ZONE = 10;
	/** Smallest module sizes in mm that phone cameras and hand scanners read reliably (M12-40). */
	public const MIN_QR_MODULE_MM      = 0.33;
	public const MIN_BARCODE_MODULE_MM = 0.19;
	/** Smallest contrast of a code to its (white) ground (WCAG ratio, M12-40). */
	public const MIN_CODE_CONTRAST = 4.5;

	/** Modules per side of the QR code of a payload, quiet zone included (0 when it cannot be encoded). */
	public static function qr_modules( string $payload ): int {
		if ( ! class_exists( QRCode::class ) || '' === $payload ) {
			return 0;
		}
		try {
			$options = new QROptions( array( 'eccLevel' => EccLevel::M, 'addQuietzone' => false ) );
			return ( new QRCode( $options ) )->addByteSegment( $payload )->getQRMatrix()->getSize() + 2 * self::QR_QUIET_ZONE;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** Modules of the barcode of a payload, quiet zones included (0 when it cannot be encoded). */
	public static function barcode_modules( string $type, string $payload ): int {
		if ( ! class_exists( '\Picqer\Barcode\BarcodeGeneratorSVG' ) || '' === $payload ) {
			return 0;
		}
		$types = array(
			'C128'  => \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128,
			'C39'   => \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_39,
			'EAN13' => \Picqer\Barcode\BarcodeGenerator::TYPE_EAN_13,
		);
		try {
			$svg = ( new \Picqer\Barcode\BarcodeGeneratorSVG() )->getBarcode( $payload, $types[ $type ] ?? $types['C128'], 1, 10 );
		} catch ( \Throwable $e ) {
			return 0;
		}
		return preg_match( '/<svg[^>]*\swidth="([\d.]+)"/', $svg, $m ) ? (int) round( (float) $m[1] ) + 2 * self::BARCODE_QUIET_ZONE : 0;
	}

	/** Contrast ratio of two colours (#rgb or #rrggbb) as defined by WCAG 2 (1 to 21). */
	public static function contrast_ratio( string $a, string $b ): float {
		$lum = static function ( string $hex ): float {
			$hex = ltrim( $hex, '#' );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
				return 0.0;
			}
			$c = array();
			foreach ( str_split( $hex, 2 ) as $part ) {
				$v   = hexdec( $part ) / 255;
				$c[] = $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			}
			return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		};
		$la = $lum( $a );
		$lb = $lum( $b );
		return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
	}

	/**
	 * Whether a value can be printed as the given barcode type. EAN-13 takes only 12 digits (check digit added)
	 * or 13 digits with a valid check digit; Code 39 only upper-case letters, digits and - . $ / + % space.
	 */
	public static function barcode_fits( string $type, string $value ): bool {
		switch ( $type ) {
			case 'EAN13':
				return (bool) preg_match( '/^\d{12}$/', $value ) || ( (bool) preg_match( '/^\d{13}$/', $value ) && CodeGenerator::gs1_valid( $value ) );
			case 'C39':
				return (bool) preg_match( '/^[0-9A-Z \-.$\/+%]+$/', $value );
		}
		return '' !== $value;
	}

	private function barcode_element( array $el, Voucher $voucher, array $values ): string {
		if ( ! class_exists( '\Picqer\Barcode\BarcodeGeneratorSVG' ) ) {
			return '';
		}
		$payload = Placeholders::replace( (string) ( $el['qrValue'] ?? '{coupon_code}' ), $voucher, $values );
		if ( '' === $payload ) {
			return '';
		}
		$types = array(
			'C128' => \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128,
			'C39'  => \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_39,
			'EAN13' => \Picqer\Barcode\BarcodeGenerator::TYPE_EAN_13,
		);
		$chosen = isset( $types[ $el['barcodeType'] ?? '' ] ) ? (string) $el['barcodeType'] : 'C128';
		if ( ! self::barcode_fits( $chosen, $payload ) ) {
			// A code the chosen type cannot carry (e.g. a 16-character voucher code as EAN-13) is printed as
			// Code 128, which every scanner reads, instead of leaving the voucher without a barcode.
			Logger::warning( sprintf( 'PDF barcode: the value does not fit %s, printed as Code 128 instead.', $chosen ), array( 'voucher' => $voucher->id ) );
			$chosen = 'C128';
		}
		$color = $this->color( $el['color'] ?? '#000000' );
		try {
			// SVG (vector): needs no GD and prints sharp.
			$svg = ( new \Picqer\Barcode\BarcodeGeneratorSVG() )->getBarcode( $payload, $types[ $chosen ], 3, 120, 'transparent' === $color ? '#000000' : $color );
		} catch ( \Throwable $e ) {
			$problem = self::barcode_problem( (string) ( $el['barcodeType'] ?? 'C128' ), $payload );
			self::element_failed( 'barcode', $voucher, '' !== $problem ? $problem : $e->getMessage() );
			return '';
		}
		// No external DTD; the bars stretch to the element box like the designer shows them. M12-40: a quiet zone of
		// 10 modules on a white ground left and right of the bars, the element's opacity is ignored.
		$modules = preg_match( '/<svg[^>]*\swidth="([\d.]+)"/', $svg, $mw ) ? max( 1, (float) $mw[1] / 3 ) : 0;
		$margin  = $modules > 0 ? (float) $el['w'] * self::BARCODE_QUIET_ZONE / ( $modules + 2 * self::BARCODE_QUIET_ZONE ) : 0;
		$svg     = (string) preg_replace( '/<!DOCTYPE[^>]*>\s*/i', '', $svg );
		$svg     = (string) preg_replace( '/<svg /', '<svg preserveAspectRatio="none" ', $svg, 1 );
		$style   = $this->box_style( $el, true ) . 'background:#ffffff;';
		$show    = ! isset( $el['showText'] ) || ! empty( $el['showText'] );
		$bar_h   = $show ? max( 1, (float) $el['h'] - 4.5 ) : (float) $el['h'];
		$html    = sprintf( '<img src="data:image/svg+xml;base64,%s" style="width:%smm;height:%smm;margin-left:%smm;" />', base64_encode( $svg ), $this->num( max( 1, (float) $el['w'] - 2 * $margin ) ), $this->num( $bar_h ), $this->num( $margin ) );
		if ( $show ) {
			$html .= sprintf( '<div style="font-family:\'DejaVu Sans Mono\';font-size:8pt;text-align:center;color:%s;line-height:1.2;">%s</div>', $this->color( $el['color'] ?? '#000000' ), esc_html( $payload ) );
		}
		return '<div class="el" style="' . esc_attr( $style ) . '">' . $html . '</div>';
	}

	/**
	 * Image types dompdf can draw: JPEG, PNG, GIF, and WebP only with GD's WebP support. AVIF and HEIC (accepted by
	 * the media library since WordPress 6.5) would print as a "broken image" frame.
	 *
	 * @return string[] MIME types
	 */
	public static function image_mimes(): array {
		$mimes = array( 'image/jpeg', 'image/png', 'image/gif' );
		if ( function_exists( 'imagecreatefromwebp' ) ) {
			$mimes[] = 'image/webp';
		}
		return array_values( array_intersect( (array) apply_filters( 'vcap_pdf_image_mimes', $mimes ), array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ) ) );
	}

	/**
	 * Resolve an image to a local file the PDF can draw, or null (logged): a missing file (offloaded media, CDN
	 * URL, other server) or an image type dompdf cannot draw.
	 */
	public function image_path( $attachment_id, $url ): ?string {
		$status = $this->image_status( $attachment_id, $url );
		if ( '' !== $status['problem'] ) {
			static $logged = array();
			$key = $status['problem'] . '|' . (int) $attachment_id . '|' . $url;
			if ( ! isset( $logged[ $key ] ) ) {
				$logged[ $key ] = true;
				Logger::warning( sprintf( 'PDF template image left out (%s): attachment %d, %s', 'format' === $status['problem'] ? 'image type ' . $status['mime'] . ' cannot be printed' : 'no readable local file', (int) $attachment_id, (string) $url ) );
			}
		}
		return $status['path'];
	}

	/**
	 * @return array{path: ?string, problem: string, mime: string} problem: '' (fine), 'none' (no image set),
	 *                                                              'missing' or 'format'.
	 */
	public function image_status( $attachment_id, $url ): array {
		$url  = (string) $url;
		$file = $this->local_image( (int) $attachment_id, $url ) ?? $this->remote_image( (int) $attachment_id, $url );
		if ( null === $file ) {
			return array( 'path' => null, 'problem' => (int) $attachment_id || '' !== $url ? 'missing' : 'none', 'mime' => '' );
		}
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unreadable images are reported below.
		$mime = is_array( $info ) ? (string) $info['mime'] : '';
		if ( ! in_array( $mime, self::image_mimes(), true ) ) {
			return array( 'path' => null, 'problem' => 'format', 'mime' => '' !== $mime ? $mime : (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		}
		return array( 'path' => $file, 'problem' => '', 'mime' => $mime );
	}

	/**
	 * Images whose local file is missing (WP Offload Media, WP-Stateless, CDN-rewritten URLs, servers without shared
	 * uploads) are downloaded – only from hosts named with "vcap_pdf_remote_hosts" – into the private temp folder
	 * and reused for an hour.
	 */
	private function remote_image( int $attachment_id, string $url ): ?string {
		$hosts = array_filter( array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), (array) apply_filters( 'vcap_pdf_remote_hosts', array() ) ) );
		if ( ! $hosts ) {
			return null;
		}
		$candidates = array( $url );
		if ( $attachment_id && function_exists( 'wp_get_attachment_url' ) ) {
			$candidates[] = (string) wp_get_attachment_url( $attachment_id );
		}
		foreach ( array_unique( array_filter( $candidates ) ) as $candidate ) {
			$scheme = strtolower( (string) wp_parse_url( $candidate, PHP_URL_SCHEME ) );
			$host   = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
			if ( ! in_array( $scheme, array( 'https', 'http' ), true ) || ! in_array( $host, $hosts, true ) ) {
				continue;
			}
			$dir   = Install::upload_dir() . '/tmp';
			$cache = $dir . '/img-' . md5( $candidate ) . '.img';
			if ( is_readable( $cache ) && filemtime( $cache ) > time() - HOUR_IN_SECONDS ) {
				return $cache;
			}
			$response = wp_safe_remote_get(
				$candidate,
				array(
					'timeout'             => 10,
					'redirection'         => 2,
					'limit_response_size' => 10 * 1024 * 1024,
				)
			);
			$body = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			$info = '' !== $body ? @getimagesizefromstring( $body ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not an image: skipped.
			if ( ! is_array( $info ) ) {
				Logger::warning( sprintf( 'PDF template image could not be downloaded from %s.', $host ) );
				continue;
			}
			Install::ensure_upload_dir();
			$part = $cache . '.' . wp_generate_password( 8, false );
			if ( false !== file_put_contents( $part, $body ) && rename( $part, $cache ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return $cache;
			}
			wp_delete_file( $part );
		}
		return null;
	}

	private function local_image( int $attachment_id, string $url ): ?string {
		if ( $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file && is_readable( $file ) ) {
				return $file;
			}
		}
		if ( '' === $url ) {
			return null;
		}
		if ( str_starts_with( $url, 'vcap-asset:' ) ) {
			$file = VCAP_PATH . 'assets/images/' . basename( substr( $url, 11 ) );
			return is_readable( $file ) ? $file : null;
		}
		$uploads = wp_upload_dir( null, false );
		foreach ( array( array( $uploads['baseurl'], $uploads['basedir'] ), array( VCAP_URL, VCAP_PATH ) ) as $map ) {
			$base = set_url_scheme( trailingslashit( $map[0] ) );
			$test = set_url_scheme( $url );
			if ( str_starts_with( $test, $base ) ) {
				$rel  = rawurldecode( substr( $test, strlen( $base ) ) );
				$file = realpath( trailingslashit( $map[1] ) . $rel );
				$root = realpath( $map[1] );
				// Directory boundary: ".../uploads" must not match a sibling ".../uploads-private".
				if ( $file && $root && str_starts_with( $file, trailingslashit( $root ) ) && is_readable( $file ) ) {
					return $file;
				}
			}
		}
		return null;
	}

	/** Resolution in dpi template images are reduced to for printing (M12-73). */
	public const IMAGE_DPI = 200;

	/**
	 * Whether a PNG file has an alpha channel or transparency (colour type 4 or 6, or a tRNS chunk). dompdf
	 * composes such images pixel by pixel in PHP: a large one costs seconds per PDF.
	 */
	public static function png_has_alpha( string $file ): bool {
		$head = (string) @file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors -- local image header.
		if ( strlen( $head ) < 26 || "\x89PNG" !== substr( $head, 0, 4 ) ) {
			return false;
		}
		return in_array( ord( $head[25] ), array( 4, 6 ), true ) || str_contains( $head, 'tRNS' );
	}

	/**
	 * M12-73: a PNG template image as the PDF should embed it, made once and kept in the private image cache:
	 * PNGs without transparency become JPEGs (dompdf embeds them as they are), PNGs with transparency larger than
	 * the box needs at IMAGE_DPI are scaled down. Other images, small PNGs and servers without GD use the original.
	 *
	 * @param float $w_mm Width of the box the image fills (mm).
	 * @param float $h_mm Height of the box (mm).
	 */
	public static function optimized_image( string $path, float $w_mm, float $h_mm ): string {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not an image: original.
		if ( ! is_array( $info ) || 'image/png' !== ( $info['mime'] ?? '' ) || ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagejpeg' ) ) {
			return $path;
		}
		$iw     = (int) $info[0];
		$ih     = (int) $info[1];
		$alpha  = self::png_has_alpha( $path );
		$need   = (int) ceil( max( $w_mm, $h_mm, 1 ) / 25.4 * self::IMAGE_DPI );
		$scale  = min( 1.0, $need / max( 1, $iw, $ih ) );
		$small  = filesize( $path ) < 100 * 1024;
		if ( ( $alpha && $scale > 0.8 ) || ( ! $alpha && $small && $scale > 0.8 ) || (int) $iw * (int) $ih > 40000000 ) {
			return $path; // Nothing to gain, or too large to load into memory safely.
		}
		$dir  = Install::upload_dir() . '/img';
		$file = $dir . '/' . md5( $path . '|' . (int) filemtime( $path ) . '|' . (int) filesize( $path ) . '|' . $need ) . ( $alpha ? '.png' : '.jpg' );
		if ( is_readable( $file ) ) {
			if ( (int) filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				@touch( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- in use: kept by the maintenance (Install::purge_temp_files()).
			}
			return $file;
		}
		if ( ! Install::ensure_upload_dir() ) {
			return $path;
		}
		try {
			$src = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- broken file: original.
			if ( ! $src ) {
				return $path;
			}
			$nw  = max( 1, (int) round( $iw * $scale ) );
			$nh  = max( 1, (int) round( $ih * $scale ) );
			$dst = imagecreatetruecolor( $nw, $nh );
			if ( $alpha ) {
				imagealphablending( $dst, false );
				imagesavealpha( $dst, true );
				imagefill( $dst, 0, 0, (int) imagecolorallocatealpha( $dst, 255, 255, 255, 127 ) );
			} else {
				imagefill( $dst, 0, 0, (int) imagecolorallocate( $dst, 255, 255, 255 ) );
			}
			imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $iw, $ih );
			$part = $file . '.' . wp_generate_password( 8, false ) . '.part';
			$ok   = $alpha ? imagepng( $dst, $part, 6 ) : imagejpeg( $dst, $part, 90 );
			if ( $ok && rename( $part, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return $file;
			}
			wp_delete_file( $part );
		} catch ( \Throwable $e ) {
			Logger::warning( 'PDF template image could not be optimised: ' . $e->getMessage() );
		}
		return $path;
	}

	private function image_box( string $path, float $x, float $y, float $w, float $h, string $fit, float $opacity, float $rotation ): string {
		$size = @getimagesize( $path ); // phpcs:ignore
		if ( $size ) {
			// The box the image really fills (cover overflows the box on one side).
			$cover = 'cover' === $fit && $size[0] > 0 && $size[1] > 0 ? max( $w / $size[0], $h / $size[1] ) : 0;
			$path  = self::optimized_image( $path, $cover ? $size[0] * $cover : $w, $cover ? $size[1] * $cover : $h );
		}
		$iw   = $size ? (float) $size[0] : $w;
		$ih   = $size ? (float) $size[1] : $h;
		$dw   = $w;
		$dh   = $h;
		if ( 'stretch' !== $fit && $iw > 0 && $ih > 0 ) {
			$scale = 'cover' === $fit ? max( $w / $iw, $h / $ih ) : min( $w / $iw, $h / $ih );
			$dw    = $iw * $scale;
			$dh    = $ih * $scale;
		}
		$style = $this->box_style(
			array(
				'x'        => $x,
				'y'        => $y,
				'w'        => $w,
				'h'        => $h,
				'rotation' => $rotation,
				'opacity'  => $opacity,
			)
		);
		return sprintf(
			'<div class="el" style="%s"><img src="%s" style="position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;" /></div>',
			esc_attr( $style ),
			esc_attr( $path ),
			$this->num( ( $w - $dw ) / 2 ),
			$this->num( ( $h - $dh ) / 2 ),
			$this->num( $dw ),
			$this->num( $dh )
		);
	}
}
