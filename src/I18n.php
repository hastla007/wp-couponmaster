<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Locale fallback for the plugin's own translations.
 *
 * WordPress has no fallback between related locales: a shop on "Deutsch (Sie)" (de_DE_formal), Swiss German (de_CH,
 * de_CH_informal) or – without its own file – Austrian German (de_AT) would show the plugin in English, although the
 * German translation (formal "Sie") fits. When the requested file for such a locale does not exist, the German
 * (de_DE) file of the same folder or of the plugin's languages/ folder is loaded instead. A locale with its own file
 * (shipped or a language pack) always wins. The filter "vcap_translation_fallback_locales" changes the map.
 */
final class I18n {
	public const DOMAIN = 'voucher-captain';

	/** Locale => locale whose file is used when the locale has none. */
	public const FALLBACKS = array(
		'de_DE_formal'   => 'de_DE',
		'de_AT'          => 'de_DE',
		'de_CH'          => 'de_DE',
		'de_CH_informal' => 'de_DE',
	);

	public static function register(): void {
		if ( ! has_filter( 'load_textdomain_mofile', array( self::class, 'mofile' ) ) ) {
			add_filter( 'load_textdomain_mofile', array( self::class, 'mofile' ), 10, 2 );
			// Just-in-time loading (e.g. emails after switch_to_locale()) asks for a folder first (WordPress 6.6+).
			add_filter( 'lang_dir_for_domain', array( self::class, 'lang_dir' ), 10, 3 );
		}
	}

	/** @return array<string, string> */
	private static function fallbacks(): array {
		$map = apply_filters( 'vcap_translation_fallback_locales', self::FALLBACKS );
		return is_array( $map ) ? $map : self::FALLBACKS;
	}

	private static function exists( string $mofile ): bool {
		return is_readable( $mofile ) || is_readable( substr( $mofile, 0, -3 ) . '.l10n.php' );
	}

	/** The plugin's own languages folder, with trailing slash. */
	private static function own_dir(): string {
		return ( defined( 'VCAP_PATH' ) ? VCAP_PATH : dirname( __DIR__ ) . '/' ) . 'languages/';
	}

	/**
	 * Filter "load_textdomain_mofile": voucher-captain-de_DE_formal.mo → voucher-captain-de_DE.mo when only that one
	 * exists (same folder first, then the plugin's languages/ folder).
	 */
	public static function mofile( $mofile, $domain ) {
		if ( self::DOMAIN !== $domain || ! is_string( $mofile ) || ! str_ends_with( $mofile, '.mo' ) || self::exists( $mofile ) ) {
			return $mofile;
		}
		$prefix = self::DOMAIN . '-';
		$base   = basename( $mofile, '.mo' );
		if ( ! str_starts_with( $base, $prefix ) ) {
			return $mofile;
		}
		$locale = substr( $base, strlen( $prefix ) );
		$map    = self::fallbacks();
		$seen   = array( $locale => true );
		while ( isset( $map[ $locale ] ) && ! isset( $seen[ $map[ $locale ] ] ) ) {
			$locale          = (string) $map[ $locale ];
			$seen[ $locale ] = true;
			foreach ( array( dirname( $mofile ) . '/', self::own_dir() ) as $dir ) {
				$candidate = $dir . $prefix . $locale . '.mo';
				if ( self::exists( $candidate ) ) {
					return $candidate;
				}
			}
		}
		return $mofile;
	}

	/**
	 * Filter "lang_dir_for_domain": without a file for the locale WordPress finds no folder and loads nothing just in
	 * time; point it to the plugin's languages/ folder when a fallback file exists there (mofile() then picks it).
	 */
	public static function lang_dir( $path, $domain, $locale ) {
		if ( self::DOMAIN !== $domain || $path || ! is_string( $locale ) || ! isset( self::fallbacks()[ $locale ] ) ) {
			return $path;
		}
		$dir = self::own_dir();
		return self::mofile( $dir . self::DOMAIN . '-' . $locale . '.mo', self::DOMAIN ) !== $dir . self::DOMAIN . '-' . $locale . '.mo' ? $dir : $path;
	}
}
