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

	/**
	 * dompdf multiplies CSS line-height by the font's own height ratio (≈1.28 for DejaVu, 0.86 for Courier),
	 * browsers do not. Dividing by this ratio makes the PDF line pitch equal to the designer canvas.
	 */
	private static function font_height_ratio( string $family, bool $bold, bool $italic ): float {
		$style = $bold && $italic ? 'bold_italic' : ( $bold ? 'bold' : ( $italic ? 'italic' : 'normal' ) );
		$key   = $family . '|' . $style;
		if ( ! isset( self::$height_ratio[ $key ] ) ) {
			$ratio = 1.0;
			try {
				static $metrics = null;
				$metrics = $metrics ?? ( new Dompdf() )->getFontMetrics();
				$font    = $metrics->getFont( $family, $style );
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
		$design = $design ?? TemplatePostType::get_design( (int) apply_filters( 'vcap_render_template_id', $voucher->template_id, $voucher ) );
		$html   = $this->html( $voucher, $design );
		list( $w, $h ) = self::page_size( $design['page'] ?? array() );

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
		$options->set( 'tempDir', is_writable( $tmp ) ? $tmp : sys_get_temp_dir() );
		$uploads = wp_upload_dir( null, false );
		$options->set( 'chroot', array_values( array_filter( array( VCAP_PATH, $uploads['basedir'], ABSPATH ) ) ) );
		$options = apply_filters( 'vcap_dompdf_options', $options );

		$dompdf = new Dompdf( $options );
		$dompdf->setPaper( array( 0, 0, $w * self::MM_TO_PT, $h * self::MM_TO_PT ) );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->render();
		$dompdf->addInfo( 'Title', $voucher->code );
		$dompdf->addInfo( 'Creator', 'Voucher Captain' );
		return (string) $dompdf->output();
	}

	/**
	 * Write the PDF to a private temp file (for email attachments). Caller deletes it.
	 */
	public function to_temp_file( Voucher $voucher ): ?string {
		Install::ensure_upload_dir();
		$dir  = Install::upload_dir() . '/tmp';
		$file = $dir . '/' . $this->filename( $voucher ) ;
		if ( file_exists( $file ) ) {
			$file = $dir . '/' . wp_generate_password( 6, false ) . '-' . $this->filename( $voucher );
		}
		$bytes = $this->render( $voucher );
		return false !== file_put_contents( $file, $bytes ) ? $file : null; // phpcs:ignore
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

		$bg     = $this->color( $page['background'] ?? '#ffffff', '#ffffff' );
		$out    = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $font_css;
		$out   .= sprintf( '@page{margin:0;size:%1$smm %2$smm;}html,body{margin:0;padding:0;}', $this->num( $w ), $this->num( $h ) );
		$out   .= sprintf( '.page{position:relative;width:%1$smm;height:%2$smm;overflow:hidden;background:%3$s;}', $this->num( $w ), $this->num( $h ), $bg );
		$out   .= '.el{position:absolute;overflow:hidden;}.el img{display:block;}.txt{white-space:normal;word-wrap:break-word;}';
		$out   .= '</style></head><body><div class="page">';

		$bg_path = $this->image_path( $page['backgroundImageId'] ?? 0, $page['backgroundImage'] ?? '' );
		if ( $bg_path ) {
			$out .= $this->image_box( $bg_path, 0, 0, $w, $h, (string) ( $page['backgroundFit'] ?? 'cover' ), 1.0, 0 );
		}

		foreach ( (array) ( $design['elements'] ?? array() ) as $el ) {
			if ( ! empty( $el['hidden'] ) ) {
				continue;
			}
			$out .= $this->element( (array) $el, $voucher, $values );
		}
		$out .= '</div></body></html>';
		return (string) apply_filters( 'vcap_pdf_html', $out, $voucher, $design );
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

	private function box_style( array $el ): string {
		$x   = (float) ( $el['x'] ?? 0 );
		$y   = (float) ( $el['y'] ?? 0 );
		$w   = max( 1, (float) ( $el['w'] ?? 50 ) );
		$h   = max( 1, (float) ( $el['h'] ?? 10 ) );
		$rot = (float) ( $el['rotation'] ?? 0 );
		$css = sprintf( 'left:%smm;top:%smm;width:%smm;height:%smm;', $this->num( $x ), $this->num( $y ), $this->num( $w ), $this->num( $h ) );
		if ( 0.0 !== $rot ) {
			$css .= sprintf( 'transform:rotate(%sdeg);transform-origin:50%% 50%%;', $this->num( $rot ) );
		}
		$opacity = isset( $el['opacity'] ) ? max( 0, min( 1, (float) $el['opacity'] ) ) : 1;
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

	private function text_element( array $el, Voucher $voucher, array $values ): string {
		$text = Placeholders::replace( (string) ( $el['text'] ?? '' ), $voucher, $values );
		if ( ! empty( $el['uppercase'] ) ) {
			$text = mb_strtoupper( $text );
		}
		$fonts  = self::fonts();
		$font   = isset( $fonts[ $el['font'] ?? '' ] ) ? $el['font'] : 'DejaVu Sans';
		$size   = max( 4, min( 300, (float) ( $el['size'] ?? ( 'heading' === $el['type'] ? 24 : 11 ) ) ) );
		$align  = (string) ( $el['align'] ?? 'left' );
		$align  = in_array( $align, array( 'left', 'center', 'right', 'justify' ), true ) ? $align : 'left';
		$valign = (string) ( $el['valign'] ?? 'top' );
		$valign = in_array( $valign, array( 'top', 'middle', 'bottom' ), true ) ? $valign : 'top';
		$lh     = max( 0.8, min( 3, (float) ( $el['lineHeight'] ?? 1.25 ) ) );
		$bold   = ! empty( $el['bold'] ) || ( 'heading' === $el['type'] && ! isset( $el['bold'] ) );
		$lh    /= self::font_height_ratio( (string) $font, $bold, ! empty( $el['italic'] ) );

		$style  = $this->box_style( $el );
		$inner  = sprintf(
			"font-family:'%s';font-size:%spt;line-height:%s;color:%s;text-align:%s;font-weight:%s;font-style:%s;%s",
			esc_attr( $font ),
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
				return $voucher->id ? vcap()->downloads->signed_url( $voucher, true ) : home_url( '/' );
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
		$dark    = $this->rgb( $el['color'] ?? '#000000' );
		$options = new QROptions(
			array(
				'outputType'       => QROutputInterface::GDIMAGE_PNG,
				'outputBase64'     => true,
				'eccLevel'         => EccLevel::M,
				'scale'            => 12,
				'addQuietzone'     => true,
				'quietzoneSize'    => 2,
				'imageTransparent' => true,
				'drawLightModules' => false,
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
			return '';
		}
		$size  = min( (float) $el['w'], (float) $el['h'] );
		$style = $this->box_style( $el );
		return sprintf(
			'<div class="el" style="%s"><img src="%s" style="width:%smm;height:%smm;margin-left:%smm;margin-top:%smm;" /></div>',
			esc_attr( $style ),
			esc_attr( $uri ),
			$this->num( $size ),
			$this->num( $size ),
			$this->num( ( (float) $el['w'] - $size ) / 2 ),
			$this->num( ( (float) $el['h'] - $size ) / 2 )
		);
	}

	private function barcode_element( array $el, Voucher $voucher, array $values ): string {
		if ( ! class_exists( '\Picqer\Barcode\BarcodeGeneratorPNG' ) ) {
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
		$type = $types[ $el['barcodeType'] ?? 'C128' ] ?? $types['C128'];
		try {
			$png = ( new \Picqer\Barcode\BarcodeGeneratorPNG() )->getBarcode( $payload, $type, 3, 120, $this->rgb( $el['color'] ?? '#000000' ) );
		} catch ( \Throwable $e ) {
			return '';
		}
		$style = $this->box_style( $el );
		$show  = ! isset( $el['showText'] ) || ! empty( $el['showText'] );
		$bar_h = $show ? max( 1, (float) $el['h'] - 4.5 ) : (float) $el['h'];
		$html  = sprintf( '<img src="data:image/png;base64,%s" style="width:%smm;height:%smm;" />', base64_encode( $png ), $this->num( $el['w'] ), $this->num( $bar_h ) );
		if ( $show ) {
			$html .= sprintf( '<div style="font-family:\'DejaVu Sans Mono\';font-size:8pt;text-align:center;color:%s;line-height:1.2;">%s</div>', $this->color( $el['color'] ?? '#000000' ), esc_html( $payload ) );
		}
		return '<div class="el" style="' . esc_attr( $style ) . '">' . $html . '</div>';
	}

	private function rgb( $hex ): array {
		$hex = ltrim( $this->color( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * Resolve an image to a local file (remote fetching is disabled for security).
	 */
	public function image_path( $attachment_id, $url ): ?string {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file && is_readable( $file ) ) {
				return $file;
			}
		}
		$url = (string) $url;
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
				if ( $file && str_starts_with( $file, realpath( $map[1] ) ) && is_readable( $file ) ) {
					return $file;
				}
			}
		}
		return null;
	}

	private function image_box( string $path, float $x, float $y, float $w, float $h, string $fit, float $opacity, float $rotation ): string {
		$size = @getimagesize( $path ); // phpcs:ignore
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
