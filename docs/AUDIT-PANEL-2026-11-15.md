# Audit-Panel Voucher Captain – 15.11.2026

Stand: Branch `claude/wp-plugin-analyse-todo-hvollo-refactors`, Commit 884e415 (Schema 1.8.0). Jeder Befund wurde vom
Lead-Developer im Code nachgeprüft. Alle Befunde stehen mit Maßnahme in `TODO.md` → „Audit-Panel 15.11.2026“.

## 1. Vorstellung

| Prüfer | Fokus |
|---|---|
| **S1 – Security-Auditor, öffentliche Einstiegspunkte** | REST-Routen, nopriv-AJAX, Shortcodes, Mein Konto, signierte Downloads, Rate-Limits, IDOR, Code-Enumeration |
| **S2 – Security-Auditor, Admin & Personal** | admin_post-Handler, Capabilities (Rechte-Eskalation), Nonces, Designer, Import/Export, CLI |
| **S3 – Security-Auditor, Daten/Krypto/Datenschutz** | `$wpdb`-Abfragen, Migrationen/Uninstall, Zufall/Signaturen, Logging, DSGVO |
| **Q1 – QA-Engineer, Geldflüsse** | Kasse, Reservierung, API-Einlösung, Schalter, Aufladen, Guthaben, Erstattungen, Race-Conditions |
| **Q2 – QA-Engineer, Lebenszyklus & Robustheit** | Ausstellung/Zustellung/PDF, Action Scheduler, Upgrades, Multisite, Konflikte, PHP 8.1–8.4 |
| **P1 – Core- & Performance-Architekt** | Kosten pro Anfrage, Autoload, Assets, N+1, Indizes, Exporte, Cron-Last |
| **W1 – WP.org-Richtlinien & Standards** | 18 Richtlinien, GPL, readme, i18n, HPOS/Blocks, Uninstall, PHPCS |
| **F1 – Frontend & Ausgabe** | DOM-XSS, Escaping in Templates/E-Mails/PDF, Header-Injection |
| **Lead-Developer** | Gewichtung nach realem Risiko, Fixes |

## 2. Runde 1 – Befunde je Prüfer

**S1:** persönliche Gutscheine über geänderte Kontoadresse einlösbar (AP-S1-1, `PersonalVoucher::verified_email()`);
REST `/redemptions/check` und das Gutscheinfeld verraten fremde Guthaben-Codes (AP-S1-2/-3, Regression V15).
Sauber: Rate-Limits (nur REMOTE_ADDR, IPv6 /64+/48, atomar), CouponGuard, Download-Signaturen (HMAC, `hash_equals`,
Ablauf, Version), REST-Berechtigungen, keine SQLi/`unserialize` aus Eingaben.

**S2:** Profil-Guthaben ohne `vcap_increase_balance` (AP-S2-1, auch im eigenen Profil); weitere wertsteigernde Wege
ohne dieses Recht (AP-S2-2); Reaktivieren ohne Statusprüfung (AP-S2-3); Coupon-Kopien ohne Coupon-Recht (AP-S2-4).
Sauber: alle 18 admin_post-Handler (Nonce + Recht), Designer-Whitelist, dompdf ohne Remote/PHP/JS mit chroot,
XLSX-Import ohne XXE/Zip-Bombe, CSV-Formel-Neutralisierung, Kassierer-Rolle ohne Admin-Zugriff.

**S3:** Empfängerdaten in Bestellpositions-Meta überleben Löschung und Aufbewahrung (AP-S3-1); Coupon-E-Mail-Beschränkung
überlebt die Aufbewahrung (AP-S3-2); volle Codes in Bestellnotizen (AP-S3-3, Regression V11); Kundenschlüssel hängen am
WordPress-Salt (AP-S3-4); Kassen-Sperrname vs. Sortierung (AP-S3-5).
Sauber: alle ~350 `$wpdb`-Abfragen, Migrationen idempotent unter Sperre, `random_int`/`random_bytes`, Tokens 192 Bit,
Signaturen, Db-Savepoints und verzögerte Hooks.

**Q1:** Bestell-Zustandsautomat durch `refund_credit`/`topup` gestört → Guthaben nicht zurückgebucht bzw. doppelt
abgebucht (AP-Q1-1); Neu-Ausstellen sperrt den gestohlenen Code nicht für offene Bestellungen (AP-Q1-2, Regression V10);
Aufladung nach Neu-Ausstellen ungeschützt erstattbar (AP-Q1-3); Einzelgutschein nach „erstattet → storniert“ wieder
aktiv (AP-Q1-4); Rundungstoleranz (AP-Q1-5); Rückbuchung/Storno ohne gemeinsame Sperre (AP-Q1-6).
Sauber: Zeilensperren (`FOR UPDATE`) bei Reservieren/Abbuchen, Idempotenz der API, Schalter-Einlösung, R6-Limits.

**Q2:** endlose Hintergrund-Wiederholung nach Aufgeben (AP-Q2-1); Deaktivieren verliert laufende Ausstellungen
(AP-Q2-2); doppelte Empfänger-Mail bei Fehler der Käuferkopie (AP-Q2-3); weiße Seite bei „Jetzt zustellen“ (AP-Q2-4);
unscoped Sperrnamen (AP-Q2-5); `array`-Typen an Filtern (AP-Q2-6); Templates ohne `@version` (AP-Q2-7).
Kein Frontend-/Checkout-Fatal gefunden; PHP 8.1–8.4 robust; 50-seitiges Sammel-PDF: 0,5 s / 16 MB.

**P1:** Exporte brechen bei großen Datenmengen still ab (AP-P1-2); Tageslimit scannt alle Bestellungen bei jedem
Warenkorbaufruf (AP-P1-1); Ausstellen mit Referenz O(n²) (AP-P1-3); N+1 in der Gutscheinliste, Mehrfachabfragen im
Warenkorb, zu breite Cache-Löschung, Optionsabfrage je Anfrage, wirkungslose Zustell-Aktionen (AP-P1-4 … -8).

**W1:** kein Blocker für wordpress.org. Übersetzung zu früh bei `add_role` (AP-W1-1), Templates ohne `@version`
(AP-W1-2), Inline-Skripte (AP-W1-3), CSV ohne `nosniff` (AP-W1-4), readme-Aussagen (AP-W1-5), Produkt-Editor-Angabe
(AP-W1-6). Lizenzen aller gebündelten Bibliotheken GPL-kompatibel, kein Phoning-home.

**F1:** Personalisierungsfelder aus GET ohne Klammer-Escaping (AP-F1-1, G1 unvollständig); Apostrophe verschwinden in
Text-Mails (AP-F1-2); Aktionstitel unescaped (AP-F1-3). JS (scanner/designer) sauber, keine Header-Injection.

## 3. Runde 2 – Risiko-Debatte

- **S2-1 ist der einfachste Geldverlust:** ein Mitarbeiter mit „Gutscheine verwalten“ (absichtlich *ohne* Recht zum
  Erhöhen, so in SECURITY.md versprochen) gibt im eigenen Profil `+1000` ein und bezahlt damit. Kein Exploit-Know-how
  nötig, keine Spur außer dem Ledger.
- **Q1-1 trifft ehrliche Kunden im Normalbetrieb:** „Als Guthaben erstatten“ ist der Standardweg bei Retouren; das in
  der Bestellung eingesetzte Guthaben verschwindet, der Shop sieht nur eine Bestellnotiz.
- **Q1-2 entwertet die Diebstahl-Abhilfe:** Dieb legt Bestellung an, Reservierung wird nach 60 min freigegeben,
  Inhaber meldet Diebstahl, Admin stellt neu aus – der Dieb zahlt die alte Bestellung trotzdem mit dem alten Code.
- **S3-3 macht jede REST-Anbindung zum Leck:** ERP/Versand/CRM mit Bestell-Lesezugriff sehen volle Codes mit Restguthaben.
- **P1-2 ist für die Buchhaltung gefährlich, weil es nicht auffällt:** HTTP 200, gültige CSV, aber ab Zeile ~25 000 fehlt alles.
- **Q2-2:** „Kurz deaktivieren zum Konflikttest“ während einer 200-Stück-Bestellung → 150 bezahlte Gutscheine
  entstehen nie, kein Hinweis.
- **F1-1:** Landingpage mit WooCommerce-Produktblock + Link `?vcap_fields[message]=[vcap_referral]` → Shortcode läuft im
  Kontext des eingeloggten Besuchers.
- **S1-1:** Angreifer braucht den Code, aber genau dagegen soll „nur für den Empfänger“ schützen.
- **Debatte P1 vs. Q1 zu P1-5 (Mehrfachabfragen):** alle indiziert – Optimierung, kein Risiko.
- **W1:** keine Ablehnung durch das Review-Team zu erwarten; Inline-Skripte werden typischerweise angemerkt.

## 4. Runde 3 – Priorisierung

| Kritisch (sofort) | Mittel | Nice-to-have |
|---|---|---|
| AP-Q1-1, AP-Q1-2, AP-S2-1, AP-S3-1, AP-S3-3, AP-P1-2, AP-Q2-2, AP-F1-1, AP-S1-1 | AP-Q1-3, AP-Q1-4, AP-S2-2, AP-S3-2, AP-P1-1, AP-P1-3, AP-Q2-1, AP-Q2-3 | AP-S1-2, -S1-3, -S2-3, -S2-4, -S3-4, -S3-5, -Q1-5, -Q1-6, -Q2-4 … -Q2-7, -P1-4 … -P1-8, -F1-2, -F1-3, -W1-1 … -W1-6 |

### Fix-Skizzen des Lead-Developers (kritisch)

**AP-S2-1 – Erhöhen nur mit Recht, nie an sich selbst:**
```php
// StoreCreditAdmin::save_profile(), nach parse_amount():
if ( $parsed['ok'] && $parsed['value'] > 0 && ( ! VoucherAdmin::can_increase()
	|| ( get_current_user_id() === $user_id && ! current_user_can( 'manage_woocommerce' ) ) ) ) {
	$error = __( 'You may remove store credit but not add it (this needs the right to increase balances). Nothing was changed.', 'voucher-captain' );
}
```

**AP-Q1-1 – Zustandsautomat nur über Abbuchungs-Ereignisse:**
```php
private const ORDER_CHARGE_EVENTS = array( 'reserved', 'committed', 'redeemed', 'reservation_released', 'restored' );
$in     = implode( ',', array_fill( 0, count( self::ORDER_CHARGE_EVENTS ), '%s' ) );
$latest = $wpdb->get_row( $wpdb->prepare(
	"SELECT event, amount FROM {$events} WHERE voucher_id = %d AND order_id = %d AND event IN ({$in}) ORDER BY id DESC LIMIT 1",
	array_merge( array( $voucher->id, $order_id ), self::ORDER_CHARGE_EVENTS ) ), ARRAY_A );
```

**AP-Q1-2 – Neu-Ausstellen sperren, früheren Code nur zum Zurückbuchen:**
```php
// Reissue::refusal()
if ( $this->coupons->open_holds( $voucher ) > 0 || CouponManager::open_orders_with( $voucher ) ) { return …; }
// CouponManager
private function find_voucher_for_code( string $code, bool $booking_back = false ): ?Voucher {
	$voucher = $this->repo->get_by_code( $code );
	return $voucher ?? ( $booking_back ? Reissue::voucher_of_previous_code( $this->repo, $code ) : null );
}
```

**AP-S3-3 – Codes in Bestellnotizen maskieren:** `$voucher->code` → `Mask::code( $voucher->code )` in allen
`add_order_note()`-Aufrufen, plus statischer Test, der ungemaskte Codes in Notizen verbietet.

**AP-S3-1 – Positions-Meta löschen:**
```php
$item = $voucher->order_item_id ? \WC_Order_Factory::get_order_item( $voucher->order_item_id ) : null;
if ( $item instanceof \WC_Order_Item_Product ) {
	$fields = (array) $item->get_meta( '_vcap_fields' );
	foreach ( array( 'recipient_email', 'recipient_name', 'message' ) as $k ) {
		if ( isset( $fields[ $k ] ) ) { $fields[ $k ]['value'] = ''; }
		$item->delete_meta_data( Fields::item_meta_key( $k ) );
	}
	$item->update_meta_data( '_vcap_fields', $fields );
	$item->save();
}
```

**AP-F1-1 – nur POST, Klammern escapen:**
```php
$raw = isset( $_POST['vcap_fields'][ $key ] ) ? wp_unslash( $_POST['vcap_fields'][ $key ] ) : null;
echo '<input … value="' . Shortcodes::esc_attr_text( $value ) . '" />';
```

**AP-P1-2 – Exporte:** Bestellnummern je Stapel vorladen, `wp_cache_flush_runtime()` nach jedem Stapel, Abschlusszeile
`End of export: N rows`.

**AP-Q2-2 – Wiederherstellung nach Reaktivierung:** Bestellungen mit `Issuer::ASYNC_META`/`ISSUE_FAILED_META` über
`OrderLookup::ids_with_meta()` finden und `vcap_issue_order` neu einplanen; laufende Jobs und offene Storno-Wiederholungen ebenso.

**AP-S1-1 – Nachweis des Postfachs:** Meta `_vcap_verified_email` bei Passwort-Link oder eigenem Bestätigungslink
setzen, bei Adressänderung löschen; ohne Nachweis bietet die Ablehnung einen Bestätigungslink an.

## 5. Fazit – Checkliste vor dem Livegang

- [ ] 9 kritische Befunde behoben und mit Tests belegt (AP-Q1-1, -Q1-2, -S2-1, -S3-1, -S3-3, -P1-2, -Q2-2, -F1-1, -S1-1)
- [ ] 8 mittlere Befunde behoben
- [ ] Nice-to-have abgearbeitet oder bewusst begründet
- [ ] Alle Integrationssuiten in beiden Bestellspeichern grün, CI grün (inkl. Plugin Check, minimum-versions, upgrade)
- [ ] SECURITY.md (persönliche Gutscheine, Rechte zum Erhöhen) und readme.txt aktualisiert
- [ ] Menschliche Freigaben: Steuerberatung (TAX.md), Rechtsprüfung (LEGAL_TEXTS.md), E-Mail-Texte (EMAILS.md), Screenreader-Hörprüfung

**Nicht einsehbar:** Pass Captain und Affiliate Captain (Drittcode), Verhalten fremder Währungsumschalter, MySQL-Ausführungspläne auf Echtdaten.
