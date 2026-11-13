<?php
/**
 * Writes LEGAL_TEXTS.md: the customer-facing texts with legal meaning (validity, transferability, residual value,
 * refunds, redemption conditions) with their German translations, as the review template for the legal check per
 * country. The shop's own conditions and per-country rules are settings; the template names where they live.
 *   php bin/legal-texts.php          (writes LEGAL_TEXTS.md)
 *   php bin/legal-texts.php --check  (exit 1 if LEGAL_TEXTS.md is out of date or a listed text no longer exists)
 */
$root = dirname( __DIR__ );

/** Texts per topic (source strings as in the code). */
$topics = array(
	'Gültigkeit und Ablauf'                       => array( 'Valid until %s', 'Valid until: %s', 'unlimited', 'no expiry', 'Your voucher from {site_title} expires on {expiry_date}', 'Your voucher {coupon_code} is valid until {expiry_date}. Remaining value: {coupon_balance}.', 'This voucher has expired.' ),
	'Übertragbarkeit'                             => array( 'Transferable – anyone with the code can redeem it.', 'Personal – only the buyer or the named recipient can redeem it.', 'Personal, not transferable' ),
	'Restwert'                                    => array( 'Balance: %s', 'Remaining balance' ),
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
$de      = vcap_lt_entries( "$root/languages/voucher-captain-de_DE.po" );
$at      = vcap_lt_entries( "$root/languages/voucher-captain-de_AT.po" );
$markets = ( static function () use ( $root ) {
	define( 'ABSPATH', __DIR__ );
	return include "$root/config/markets.php";
} )();
$cell    = static fn( string $s ): string => str_replace( array( '|', "\n" ), array( '\\|', '<br>' ), $s );
$missing = array();

$md  = "# Rechtlich relevante Kundentexte – Prüfvorlage je Land\n\n";
$md .= "Erzeugt mit `php bin/legal-texts.php` aus `languages/` und `config/markets.php` (nicht von Hand bearbeiten; CI prüft,\n";
$md .= "dass die Vorlage aktuell ist und jeder Text noch existiert). Prüfung durch die Rechtsberatung je Verkaufsland.\n\n";
$md .= "## 1. Texte, die der Shop selbst festlegt (Einstellungen, je Shop prüfen)\n\n";
$md .= "| Was | Wo | Wirkung |\n|---|---|---|\n";
$md .= "| Einlösebedingungen (allgemein) | WooCommerce → Einstellungen → Gutscheine → „Default redemption conditions“ | stehen auf Gutschein/PDF (`{redemption_terms}`), wenn das Produkt keine eigenen hat |\n";
$md .= "| Einlösebedingungen pro Land | ebenda → „Redemption conditions per country“ (`AT: …`) | Rechnungsland entscheidet; Text wird am Gutschein festgeschrieben |\n";
$md .= "| Gesetzliche Gültigkeit pro Land | ebenda → „Statutory validity per country“ (`DE: 3 end`) | Ablaufdatum neuer Gutscheine mit gesetzlicher Gültigkeit |\n";
$md .= "| Bedingungen je Produkt | Produkt → Gutschein → „Redemption conditions“ | ersetzt die allgemeinen Bedingungen |\n\n";
$md .= "## 2. Gesetzliche Gültigkeit (Standardwerte, `config/markets.php`)\n\n| Land | Regel | Quelle | Rechtlich geprüft |\n|---|---|---|---|\n";
foreach ( (array) $markets as $country => $m ) {
	$rule = $m['voucher_expiry'] ?? null;
	$text = is_array( $rule ) ? ( 0 === (int) $rule['years'] ? 'unbegrenzt' : (int) $rule['years'] . ' Jahre' . ( ! empty( $rule['end_of_year'] ) ? ' zum Jahresende' : '' ) ) : '– (Standard: 3 Jahre zum Jahresende)';
	$md  .= "| {$country} | {$text} | " . $cell( is_array( $rule ) ? (string) ( $rule['source'] ?? '' ) : 'nicht geprüft' ) . " | ☐ |\n";
}
$md .= "\n## 3. Feste Texte des Plugins\n";
foreach ( $topics as $topic => $ids ) {
	$md .= "\n### {$topic}\n\n| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) | Fundstelle | Freigabe |\n|---|---|---|---|---|\n";
	foreach ( $ids as $id ) {
		if ( ! isset( $pot[ $id ] ) ) {
			$missing[] = $id;
			continue;
		}
		$md .= '| ' . $cell( $id ) . ' | ' . $cell( $de[ $id ]['msgstr'] ?? '**fehlt**' ) . ' | ' . $cell( $at[ $id ]['msgstr'] ?? '**fehlt**' ) . ' | ' . $cell( implode( ' ', array_slice( $pot[ $id ]['refs'], 0, 2 ) ) ) . " | ☐ |\n";
	}
}
$md .= "\n## 4. Freigabe\n\n| Land | Geprüft von | Datum | Ergebnis |\n|---|---|---|---|\n| DE | | | ☐ freigegeben ☐ mit Änderungen |\n| AT | | | ☐ freigegeben ☐ mit Änderungen |\n| | | | |\n";

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
