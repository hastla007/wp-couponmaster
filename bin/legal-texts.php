<?php
/**
 * Writes LEGAL_TEXTS.md: the customer-facing texts with legal meaning (validity, transferability, residual value,
 * refunds, redemption conditions) with their German translations, as the review template for the legal check per
 * country. The shop's own conditions and per-country rules are settings (country tables with language column);
 * the template names where they live, lists the suggestions of the country profiles and the retention periods
 * (PV-P3-7) – none of them is marked as legally checked unless the data says so.
 *   php bin/legal-texts.php          (writes LEGAL_TEXTS.md)
 *   php bin/legal-texts.php --check  (exit 1 if LEGAL_TEXTS.md is out of date or a listed text no longer exists)
 */
$root = dirname( __DIR__ );

/** Texts per topic (source strings as in the code). */
$topics = array(
	'Gültigkeit und Ablauf'                       => array( 'Valid until %s', 'Valid until: %s', 'unlimited', 'no expiry', 'Your voucher from {site_title} expires on {expiry_date}', 'Your voucher {coupon_code} is valid until {expiry_date}. Remaining value: {coupon_balance}.', 'This voucher has expired.' ),
	'Übertragbarkeit'                             => array( 'Transferable – anyone with the code can redeem it.', 'Personal – only the buyer or the named recipient can redeem it.', 'Personal – only the named recipient can redeem it.', 'Personal – only the buyer can redeem it.' ),
	'Restwert'                                    => array( 'Balance: %s', 'Remaining balance', 'Voucher %1$s: only %2$s of %3$s is used in this order. The voucher can be redeemed only once – the remaining %4$s will be lost. Add more items to use its full value.' ),
	'Erstattung und Widerruf'                     => array( 'Vouchers from this order were already used (%1$s, value used: %2$s). At most %3$s can be refunded; the unused vouchers are voided.', '%s refunded as store credit.', 'This voucher has been cancelled.' ),
	'Einlösung'                                   => array( 'Enter the code in the cart or at checkout on %s to redeem it.', 'This voucher has already been fully redeemed.' ),
);

function vcap_lt_entries( string $file ): array {
	$out = array();
	foreach ( preg_split( "/\n\s*\n/", (string) file_get_contents( $file ) ) as $block ) {
		$f   = array( 'refs' => array() );
		$key = null;
		foreach ( explode( "\n", $block ) as $line ) {
			if ( str_starts_with( $line, '#: ' ) ) {
				$f['refs'] = array_merge( $f['refs'], preg_split( '/\s+/', trim( substr( $line, 3 ) ) ) );
			} elseif ( preg_match( '/^(msgid|msgstr) "(.*)"$/', $line, $m ) ) {
				$key       = $m[1];
				$f[ $key ] = stripcslashes( $m[2] );
			} elseif ( null !== $key && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$f[ $key ] .= stripcslashes( $m[1] );
			}
		}
		if ( ! empty( $f['msgid'] ) ) {
			$out[ $f['msgid'] ] = $f;
		}
	}
	return $out;
}

$pot     = vcap_lt_entries( "$root/languages/voucher-captain.pot" );
$du      = vcap_lt_entries( "$root/languages/voucher-captain-de_DE.po" ); // S4: "du".
$de      = vcap_lt_entries( "$root/languages/voucher-captain-de_DE_formal.po" ); // "Sie".
$at      = vcap_lt_entries( "$root/languages/voucher-captain-de_AT.po" );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
// The register (config/settings.php) names constants of plugin classes: load them from src/ (data only, no WordPress).
spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		$file = "$root/src/" . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
		if ( str_starts_with( $class, 'VCAP\\' ) && is_readable( $file ) ) {
			require_once $file;
		}
	}
);
$markets  = ( static fn() => include "$root/config/markets.php" )();
$profiles = ( static fn() => include "$root/config/country-profiles.php" )();
$register = (array) ( ( ( static fn() => include "$root/config/settings.php" )() )['settings'] ?? array() );
$cell     = static fn( string $s ): string => str_replace( array( '|', "\n" ), array( '\\|', '<br>' ), $s );
$missing  = array();

$md  = "# Rechtlich relevante Kundentexte – Prüfvorlage je Land\n\n";
$md .= "Erzeugt mit `php bin/legal-texts.php` aus `languages/`, `config/markets.php`, `config/country-profiles.php` und\n";
$md .= "`config/settings.php` (nicht von Hand bearbeiten; CI prüft, dass die Vorlage aktuell ist und jeder Text noch existiert).\n";
$md .= "Prüfung durch die Rechtsberatung je Verkaufsland. Kein Wert dieser Vorlage ist rechtlich geprüft, solange die\n";
$md .= "Spalte „Rechtlich geprüft“ nicht abgehakt ist.\n\n";
$md .= "## 1. Texte, die der Shop selbst festlegt (Einstellungen, je Shop prüfen)\n\n";
$md .= "| Was | Wo | Wirkung |\n|---|---|---|\n";
$md .= "| Einlösebedingungen (allgemein) | WooCommerce → Einstellungen → Gutscheine → „Default redemption conditions“ | stehen auf Gutschein/PDF (`{redemption_terms}`), wenn das Produkt keine eigenen hat |\n";
$md .= "| Einlösebedingungen pro Land | ebenda → „Redemption conditions per country“: Tabelle mit einer Zeile je Land und Sprache (Spalten **Land**, **Sprache** – „All languages“ oder ein Sprachcode wie `de_AT` –, **Text**) | Rechnungsland entscheidet, innerhalb des Landes die Sprache des Kunden (sonst die Zeile „All languages“); Text wird am Gutschein festgeschrieben |\n";
$md .= "| Gesetzliche Gültigkeit pro Land | ebenda → „Statutory validity per country“: Tabelle mit einer Zeile je Land (Spalten **Land**, **Jahre** – 0 = unbegrenzt –, **zum Jahresende**, **Quelle/Notiz**) | Ablaufdatum neuer Gutscheine mit gesetzlicher Gültigkeit; überschreibt die Standardwerte aus Abschnitt 2 |\n";
$md .= "| Bedingungen je Produkt | Produkt → Gutschein → „Redemption conditions“ | ersetzt die allgemeinen Bedingungen |\n";
// SET-VC-11: issuer and legal texts (block "Issuer and legal texts"; printed only where a template uses the placeholder).
$legal_info = array(
	'vcap_issuer_name'    => array( 'Aussteller: Name („Issuer name“)', '`{issuer}` (1. Zeile); leer = Name der Website' ),
	'vcap_issuer_address' => array( 'Aussteller: Anschrift („Issuer address“)', '`{issuer}`; leer = Shop-Adresse aus WooCommerce (Einstellungen → Allgemein) mit Land' ),
	'vcap_issuer_contact' => array( 'Aussteller: Kontakt („Issuer contact“)', '`{issuer}` (letzte Zeile); leer = keine Kontaktzeile' ),
	'vcap_terms_url'      => array( 'Link AGB („Terms and conditions (link)“)', '`{terms_url}`; leer = AGB-Seite von WooCommerce' ),
	'vcap_privacy_url'    => array( 'Link Datenschutz („Privacy policy (link)“)', '`{privacy_url}`; leer = Datenschutzseite von WordPress' ),
	'vcap_withdrawal_url' => array( 'Link Widerruf („Right of withdrawal (link)“)', '`{withdrawal_url}`; leer = kein Link' ),
	'vcap_legal_text'     => array( 'Freier Rechtstext für PDF und E-Mail („Legal text for PDF and email“)', '`{legal_text}`; leer = nichts' ),
);
foreach ( $legal_info as $option => $what ) {
	if ( ! isset( $register[ $option ] ) ) {
		$missing[] = $option;
		continue;
	}
	$md .= '| ' . $cell( $what[0] ) . ' | ebenda → Block „Issuer and legal texts“ (`' . $option . '`, je Sprache über WPML/Polylang) | ' . $cell( $what[1] ) . "; nur wo eine Vorlage oder ein E-Mail-Text den Platzhalter nutzt – **Rechtsprüfung offen** |\n";
}
$md .= "| Restwert gekaufter Einmal-Wertgutscheine | Produkt → Gutschein → „Keep the rest“ (Standard aus) | aus: der nicht genutzte Rest verfällt, der Kunde wird im Warenkorb gewarnt (Text unten „Restwert“; Verfallklausel in AGB nach § 307 BGB bzw. ABGB prüfen); an: der Rest wird Kundenguthaben bzw. eine neue Geschenkkarte (TAX.md 4.3) |\n\n";
$md .= "## 2. Gesetzliche Gültigkeit (Standardwerte, `config/markets.php`)\n\n| Land | Regel | Quelle | Rechtlich geprüft |\n|---|---|---|---|\n";
foreach ( (array) $markets as $country => $m ) {
	$rule = $m['voucher_expiry'] ?? null;
	$text = is_array( $rule ) ? ( 0 === (int) $rule['years'] ? 'unbegrenzt' : (int) $rule['years'] . ' Jahre' . ( ! empty( $rule['end_of_year'] ) ? ' zum Jahresende' : '' ) ) : '– (Standard: 3 Jahre zum Jahresende)';
	$md  .= "| {$country} | {$text} | " . $cell( is_array( $rule ) ? (string) ( $rule['source'] ?? '' ) : 'nicht geprüft' ) . " | ☐ |\n";
}
// Section 3: what the country profiles suggest (applied only after a preview the admin confirms; SET-VC-02).
$years_text = static fn( array $rule ): string => 0 === (int) ( $rule['years'] ?? 0 ) ? 'unbegrenzt' : (int) $rule['years'] . ' Jahre' . ( ! empty( $rule['end_of_year'] ) ? ' zum Jahresende' : '' );
$md        .= "\n## 3. Vorschläge der Länderprofile (`config/country-profiles.php`)\n\n";
$md        .= "Ein Länderprofil (Einstellungen → Gutscheine → Allgemein → „Country profile“) ändert nichts von selbst: Es zeigt\n";
$md        .= "„aktuell → vorgeschlagen“, übernommen wird nur, was der Admin abhakt. Werte mit rechtlichem Gewicht tragen den\n";
$md        .= "Hinweis „Rechtsprüfung“, bis die Prüfung im Profil vermerkt ist (`checked`). EU und OTHER sind neutral.\n\n";
$md        .= "| Profil | Gesetzliche Gültigkeit (Zeile) | Gültigkeit ohne eigene Angabe | Belegnummer an der Kasse | Empfängerdaten anonymisieren nach | Hinweis Aufbewahrung Buchhaltung (SET-VC-09) | Rechtlich geprüft |\n|---|---|---|---|---|---|---|\n";
foreach ( (array) $profiles as $id => $p ) {
	$country  = (string) ( $p['country'] ?? '' );
	$settings = (array) ( $p['settings'] ?? array() );
	$row      = $p['expiry_row'] ?? null;
	if ( 'market' === $row ) {
		$rule = $markets[ $country ]['voucher_expiry'] ?? null;
		$row  = is_array( $rule ) ? $years_text( $rule ) . ' – Quelle: ' . (string) ( $rule['source'] ?? '' ) : '– (keine Länderdaten)';
	} elseif ( is_array( $row ) ) {
		$row = $years_text( $row ) . ( '' !== (string) ( $row['note'] ?? '' ) ? ' – Notiz: ' . (string) $row['note'] : '' );
	} else {
		$row = '– (kein Vorschlag)';
	}
	$rule_s  = array(
		'statutory' => 'gesetzlich (Zeile links)',
		'days'      => 'feste Anzahl Tage',
	)[ (string) ( $settings['vcap_default_expiry_rule'] ?? '' ) ] ?? '– (bleibt)';
	$receipt = $settings['vcap_counter_require_receipt'] ?? null;
	if ( 'market' === $receipt ) {
		$receipt = ! empty( $markets[ $country ]['fiscal_receipt'] ) ? 'Pflicht (Länderdaten `fiscal_receipt`)' : '– (kein Vorschlag)';
	} else {
		$receipt = null === $receipt ? '– (bleibt)' : (string) $receipt;
	}
	$retention = $settings['vcap_retention_days'] ?? null;
	$retention = null === $retention ? '– (bleibt)' : (int) $retention . ' Tage nach Ablauf/Einlösung';
	// SET-VC-09: hint at "Delete data on uninstall" (texts in VCAP\CountryProfiles::retention_hint()).
	$hint      = (string) ( $p['retention_hint'] ?? '' );
	$hint      = array(
		'de'      => '8 bzw. 10 Jahre (§ 147 AO, § 257 HGB)',
		'at'      => '7 Jahre (§ 132 BAO)',
		'neutral' => 'neutral: „Buchungsunterlagen unterliegen gesetzlichen Aufbewahrungsfristen.“ (kein Paragraf)',
		''        => 'neutral: „Buchungsunterlagen unterliegen gesetzlichen Aufbewahrungsfristen.“ (kein Paragraf)',
	)[ $hint ] ?? $hint;
	$md       .= '| ' . $cell( (string) $id ) . ' | ' . $cell( $row ) . ' | ' . $cell( $rule_s ) . ' | ' . $cell( $receipt ) . ' | ' . $cell( $retention ) . ' | ' . $cell( $hint ) . ' | ' . ( empty( $p['checked'] ) ? '☐ nicht geprüft' : '☑' ) . " |\n";
}

// Section 4: retention periods (privacy settings with legal weight, defaults from the register).
$retention_rows = array(
	'vcap_retention_days'                 => array( 'Empfängerdaten (Name, E-Mail, Nachricht, eigene Felder) anonymisieren nach', 'Tage nach Ablauf oder vollständiger Einlösung; 0 = behalten' ),
	'vcap_voucher_requests_open_months'   => array( 'Offene Gutscheinanfragen (Form Captain) löschen nach', 'Monate ab Eingang bzw. letzter Bestätigung' ),
	'vcap_voucher_requests_closed_months' => array( 'Erledigte Gutscheinanfragen löschen nach', 'Monate ab Ausgabe bzw. Übernahme' ),
	'vcap_review_retention_months'        => array( 'Notizen zu Bewertungseinladungen löschen nach', 'Monate ab Einlösung' ),
	'vcap_review_revoked_retention_days'  => array( 'Zurückgezogene Bewertungsnotizen löschen nach', 'Tage' ),
	'vcap_command_log_days'               => array( 'Protokoll der Gutscheinbefehle (ohne Codes) behalten', 'Tage' ),
	'vcap_scanner_failed_lookup_days'     => array( 'Fehlgeschlagene Kassenabfragen je Mitarbeiter behalten', 'Tage' ),
);
$md .= "\n## 4. Aufbewahrungsfristen (Einstellungen → Gutscheine → „Privacy & advanced“ → „Retention“)\n\n";
$md .= "Standardwerte aus `config/settings.php`; ein Filter gleichen Namens gewinnt. Ob eine Frist zu Datenschutz- und\n";
$md .= "Aufbewahrungspflichten des Verkaufslandes passt, ist je Land zu prüfen (der Vorschlag der Länderprofile steht in\n";
$md .= "Abschnitt 3).\n\n";
$md .= "| Option | Was | Standard | Bereich | Rechtlich geprüft |\n|---|---|---|---|---|\n";
foreach ( $retention_rows as $option => $what ) {
	if ( ! isset( $register[ $option ] ) ) {
		$missing[] = $option;
		continue;
	}
	$entry = $register[ $option ];
	$range = ( isset( $entry['min'] ) ? (string) $entry['min'] : '0' ) . ' – ' . ( isset( $entry['max'] ) ? (string) $entry['max'] : 'offen' );
	$md   .= '| `' . $option . '` | ' . $cell( $what[0] ) . ' | ' . $cell( (string) ( $entry['default'] ?? '' ) . ' (' . $what[1] . ')' ) . ' | ' . $range . " | ☐ |\n";
}

$md .= "\n## 5. Feste Texte des Plugins\n";
foreach ( $topics as $topic => $ids ) {
	$md .= "\n### {$topic}\n\n| Englisch (Quelltext) | Deutsch (DE, du) | Deutsch (DE, Sie) | Deutsch (AT) | Fundstelle | Freigabe |\n|---|---|---|---|---|---|\n";
	foreach ( $ids as $id ) {
		if ( ! isset( $pot[ $id ] ) ) {
			$missing[] = $id;
			continue;
		}
		$md .= '| ' . $cell( $id ) . ' | ' . $cell( $du[ $id ]['msgstr'] ?? '**fehlt**' ) . ' | ' . $cell( $de[ $id ]['msgstr'] ?? '**fehlt**' ) . ' | ' . $cell( $at[ $id ]['msgstr'] ?? '**fehlt**' ) . ' | ' . $cell( implode( ' ', array_slice( $pot[ $id ]['refs'], 0, 2 ) ) ) . " | ☐ |\n";
	}
}
$md .= "\n## 6. Freigabe\n\n| Land | Geprüft von | Datum | Ergebnis |\n|---|---|---|---|\n| DE | | | ☐ freigegeben ☐ mit Änderungen |\n| AT | | | ☐ freigegeben ☐ mit Änderungen |\n| CH | | | ☐ freigegeben ☐ mit Änderungen |\n| | | | |\n";

if ( $missing ) {
	fwrite( STDERR, "Texts no longer in the plugin (update bin/legal-texts.php): \n- " . implode( "\n- ", $missing ) . "\n" );
	exit( 1 );
}
$target = "$root/LEGAL_TEXTS.md";
if ( in_array( '--check', $argv, true ) ) {
	if ( ! is_file( $target ) || file_get_contents( $target ) !== $md ) {
		fwrite( STDERR, "LEGAL_TEXTS.md is out of date: run php bin/legal-texts.php\n" );
		exit( 1 );
	}
	echo "LEGAL_TEXTS.md is up to date.\n";
	exit( 0 );
}
file_put_contents( $target, $md );
echo "Wrote LEGAL_TEXTS.md.\n";
