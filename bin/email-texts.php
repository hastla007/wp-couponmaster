<?php
/**
 * Writes EMAILS.md: every text of Voucher Captain's customer emails (WooCommerce → Settings → Emails) with its
 * German translations, as the list that is approved for a release. Source: the POT file (run bin/make-pot.php
 * first), entries referenced from src/Emails/ and templates/emails/.
 *   php bin/email-texts.php          (writes EMAILS.md)
 *   php bin/email-texts.php --check  (exit 1 if EMAILS.md is out of date)
 */
$root = dirname( __DIR__ );

function vcap_et_entries( string $file ): array {
	$out = array();
	foreach ( preg_split( "/\n\s*\n/", (string) file_get_contents( $file ) ) as $block ) {
		$f   = array( 'refs' => array(), 'comment' => '' );
		$key = null;
		foreach ( explode( "\n", $block ) as $line ) {
			if ( str_starts_with( $line, '#: ' ) ) {
				$f['refs'] = array_merge( $f['refs'], preg_split( '/\s+/', trim( substr( $line, 3 ) ) ) );
			} elseif ( str_starts_with( $line, '#. ' ) ) {
				$f['comment'] = trim( substr( $line, 3 ) );
			} elseif ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) "(.*)"$/', $line, $m ) ) {
				$key       = $m[1];
				$f[ $key ] = stripcslashes( $m[2] );
			} elseif ( null !== $key && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$f[ $key ] .= stripcslashes( $m[1] );
			}
		}
		if ( ! empty( $f['msgid'] ) ) {
			$out[ ( $f['msgctxt'] ?? '' ) . "\x04" . $f['msgid'] ] = $f;
		}
	}
	return $out;
}

$pot   = vcap_et_entries( "$root/languages/voucher-captain.pot" );
$de    = vcap_et_entries( "$root/languages/voucher-captain-de_DE.po" );
$at    = vcap_et_entries( "$root/languages/voucher-captain-de_AT.po" );
$cell  = static fn( string $s ): string => str_replace( array( '|', "\n" ), array( '\\|', '<br>' ), $s );
$group = array();
foreach ( $pot as $key => $e ) {
	foreach ( $e['refs'] as $ref ) {
		if ( preg_match( '#^(src/Emails/\w+\.php|templates/emails/[\w/]+\.php)#', $ref, $m ) ) {
			$group[ $m[1] ][ $key ] = $e;
		}
	}
}
ksort( $group );
$md  = "# E-Mail-Texte – Freigabeliste\n\n";
$md .= "Erzeugt mit `php bin/email-texts.php` aus `languages/` (nicht von Hand bearbeiten; CI prüft, dass die Liste aktuell ist).\n";
$md .= "Alle Texte der Kunden-E-Mails von Voucher Captain mit Betreff, Überschrift, Inhalt und der Beschreibung, die Shop-Betreiber unter\n";
$md .= "WooCommerce → Einstellungen → E-Mails sehen. Platzhalter in `{…}` und `%s` werden beim Versand ersetzt. Shops können Betreff,\n";
$md .= "Überschrift und Zusatztext je E-Mail in WooCommerce überschreiben; diese Liste zeigt die Standardtexte.\n\n";
$md .= "Freigabe für Release: ____ · Datum: ____ · Geprüft von: ____\n";
$seen = array();
foreach ( $group as $file => $entries ) {
	$md .= "\n## `{$file}`\n\n| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |\n|---|---|---|\n";
	foreach ( $entries as $key => $e ) {
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$src  = $e['msgid'] . ( isset( $e['msgid_plural'] ) ? ' / ' . $e['msgid_plural'] : '' );
		$tr   = static function ( array $po ) use ( $key ): string {
			$f = $po[ $key ] ?? array();
			$forms = array();
			foreach ( $f as $k => $v ) {
				if ( str_starts_with( (string) $k, 'msgstr' ) ) {
					$forms[] = $v;
				}
			}
			return $forms ? implode( ' / ', $forms ) : '**fehlt**';
		};
		$md .= '| ' . $cell( $src ) . ' | ' . $cell( $tr( $de ) ) . ' | ' . $cell( $tr( $at ) ) . " |\n";
	}
}
$md .= "\n" . count( $seen ) . " Texte.\n";
$target = "$root/EMAILS.md";
if ( in_array( '--check', $argv, true ) ) {
	if ( ! is_file( $target ) || file_get_contents( $target ) !== $md ) {
		fwrite( STDERR, "EMAILS.md is out of date: run php bin/email-texts.php\n" );
		exit( 1 );
	}
	echo "EMAILS.md is up to date (" . count( $seen ) . " texts).\n";
	exit( 0 );
}
file_put_contents( $target, $md );
echo "Wrote EMAILS.md (" . count( $seen ) . " texts).\n";
