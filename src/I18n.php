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
	 * Whether a stored settings text is a default text of the plugin – in the current language, in English (the
	 * source) or in any translation shipped with the plugin or installed as a language pack. A default saved once
	 * (WooCommerce stores the form as shown, in the admin's language) is then treated like an empty setting, so
	 * every mail gets the default in its own language.
	 *
	 * @param callable(): string $default Returns the default text through __().
	 */
	public static function is_default_text( string $stored, callable $default ): bool {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return true;
		}
		foreach ( self::in_all_languages( $default ) as $text ) {
			if ( trim( $text ) === $stored ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A text of the plugin in the current language, in English and in every available translation.
	 *
	 * @param callable(): string $text Returns the text through __().
	 * @return string[]
	 */
	public static function in_all_languages( callable $text ): array {
		$source = static function ( $translation, $original = '', $domain = '' ) {
			return self::DOMAIN === $domain ? $original : $translation;
		};
		$current = (string) $text();
		add_filter( 'gettext', $source, PHP_INT_MAX, 3 );
		try {
			$english = (string) $text();
		} finally {
			remove_filter( 'gettext', $source, PHP_INT_MAX );
		}
		$texts = array( $current, $english );
		foreach ( self::catalogs() as $catalog ) {
			if ( isset( $catalog[ $english ] ) && '' !== $catalog[ $english ] ) {
				$texts[] = $catalog[ $english ];
			}
		}
		return array_values( array_unique( $texts ) );
	}

	/**
	 * The plugin's translation files (languages/ and the language packs), read once per request.
	 *
	 * @return array<string, array<string, string>> file => msgid => translation (singular, without context)
	 */
	private static function catalogs(): array {
		static $catalogs = null;
		if ( null === $catalogs ) {
			$catalogs = array();
			$files    = glob( self::own_dir() . self::DOMAIN . '-*.mo' );
			$files    = is_array( $files ) ? $files : array();
			if ( defined( 'WP_LANG_DIR' ) ) {
				$packs = glob( WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-*.mo' );
				$files = array_merge( $files, is_array( $packs ) ? $packs : array() );
			}
			foreach ( $files as $file ) {
				$catalogs[ $file ] = self::read_mo( $file );
			}
		}
		return $catalogs;
	}

	/**
	 * Singular entries without context of a .mo file.
	 *
	 * @return array<string, string>
	 */
	public static function read_mo( string $file ): array {
		$data = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( strlen( $data ) < 28 ) {
			return array();
		}
		$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
		$order = 0x950412de === $magic ? 'V' : ( 0xde120495 === $magic ? 'N' : '' );
		if ( '' === $order ) {
			return array();
		}
		$head  = unpack( $order . '5', substr( $data, 4, 20 ) ); // revision, count, originals, translations, hash size.
		$count = (int) $head[2];
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$o = unpack( $order . '2', substr( $data, (int) $head[3] + 8 * $i, 8 ) );
			$t = unpack( $order . '2', substr( $data, (int) $head[4] + 8 * $i, 8 ) );
			if ( ! $o || ! $t ) {
				break;
			}
			$original = substr( $data, (int) $o[2], (int) $o[1] );
			if ( '' === $original || str_contains( $original, "\x04" ) ) {
				continue; // Header, or an entry with context.
			}
			$out[ explode( "\0", $original )[0] ] = explode( "\0", substr( $data, (int) $t[2], (int) $t[1] ) )[0];
		}
		return $out;
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
