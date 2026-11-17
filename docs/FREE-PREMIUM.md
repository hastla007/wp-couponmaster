# Freier Kern und getrenntes Premium-Add-on

Entscheidung des Betreibers vom 13.11.2026 (verbindlich). Dieses Dokument ersetzt das frühere LICENSING.md.

## Regeln

1. **Der Kern ist frei.** Voucher Captain steht unter GPL-2.0-or-later und wird über WordPress.org verteilt. Jede
   Funktion, die heute im Code steht (Inventar unten), gehört zum Kern und bleibt dauerhaft frei. **Ausgelieferte
   Funktionen werden nie nachträglich gesperrt, eingeschränkt oder ins Add-on verschoben** – auch nicht Aktionen,
   Freunde werben, Aufladung, Auswertungen oder die WP-Pass-Captain-Integration. Im Kern ist nichts gesperrt, abgestuft
   oder „in Premium enthalten“. Neue Funktionen dürfen im Add-on entstehen; was einmal im Kern ausgeliefert wurde,
   bleibt dort.
2. **Kein Lizenz- oder Update-Code im Kern.** Kein Lizenz-Client, keine Aufrufe von `wplc/v1`, kein `Update URI`-Header,
   keine eigenen Filter auf `pre_set_site_transient_update_plugins` oder `plugins_api`, keine Berechtigungsprüfungen
   (`allows_entitlement` o. Ä.), keine Lizenzseite und kein Lizenzmenü, keine Upgrade-Hinweise („Upgrade to Premium“),
   keine Konstanten `VCAP_EDITION`/`VCAP_LICENSE_*`, keine Aufteilung in Free- und Premium-Build. Es gibt **einen** Build
   (`bin/build.sh` → `build/voucher-captain.zip`); Updates kommen von WordPress.org.
   Der Kern ruft keinen Lizenz- oder Update-Server auf und braucht kein Add-on; seine einzigen HTTP-Anfragen stehen in
   readme.txt → *External services*.
3. **WordPress.org-Richtlinie 11:** keine gesperrten Bedienelemente. Erlaubt wäre höchstens ein ausblendbarer Hinweis
   auf den eigenen Admin-Seiten; der Kern verzichtet ganz darauf.
4. **Bestandsdaten.** Ein Update räumt einmal je Site auf, was Vorabversionen mit Lizenzcode hinterlassen haben
   (`VCAP\Install::remove_license_data()`, Merker `vcap_license_data_removed`): die Optionen `vcap_wplc_*` (auch als
   Netzwerk-Optionen), das WP-Cron-Ereignis und die Action-Scheduler-Aktion `vcap_license_refresh` sowie den
   Update-Cache `vcap_update_check`. `uninstall.php` entfernt dasselbe bei jeder Deinstallation, auch wenn die Daten
   behalten werden. Gutscheindaten werden dabei nicht angefasst.
5. **Premium ist ein getrenntes Add-on** (eigenes Plugin, eigenes Repository, eigener Slug): `voucher-captain-pro` im
   privaten Repository `hastla007/captain-pro` (Ordner `addons/voucher-captain-pro`). Es nutzt ausschließlich die
   öffentlichen Erweiterungspunkte unten und bringt seinen Lizenz- und Update-Code selbst mit (gepflegte Fassung des
   Clients dort unter `packages/license-client`, Herkunft WP License Captain `hastla007/wp-license`). Der frühere
   Lizenzcode dieses Repositorys steht nur noch in den offenen, nicht zu mergenden PRs #10–#13. Ein Add-on darf keine
   Kernfunktion sperren oder ersetzen.
6. **Absicherung:** `tests/license-guard.php` (CI-Job `smoke`, zusätzlich mit Selbsttest aus `tests/run.php`) durchsucht
   die ausgelieferten Dateien (`voucher-captain.php`, `uninstall.php`, `readme.txt`, `src/`, `templates/`, `assets/` ohne
   `assets/vendor/`, `examples/`, `config/`) nach Lizenz- und Update-Code, `bin/build.sh` nach einer zweiten Edition und
   prüft jede HTTP-Anfrage des Kerns gegen eine Allowlist; Treffer mit Datei:Zeile lassen den Job scheitern.
   `bin/build.sh` verweigert außerdem einen Plugin-Kopf mit `Update URI` oder Editions-/Lizenzkonstanten. Dass die
   Kernabläufe ohne Add-on und ohne Netz laufen, prüfen `tests/run.php` (jede Anfrage des Unit-Laufs wird protokolliert,
   ohne Testtransport gibt es kein Netz) und die Integrationssuiten `woocommerce` und `order-lifecycle`
   (`tests/no-network.php` sperrt jede ausgehende Anfrage).

## Funktionsumfang „Kern, dauerhaft frei“

Stand 1.3.0 (Schema 1.8.0), abgeleitet aus `src/` und readme.txt. Alles in dieser Tabelle ist und bleibt frei.

| Bereich | Umfang |
|---|---|
| Gutscheintypen | Gutschein, Geschenkkarte (Restwert), Ticket-/Leistungspass, Kundenguthaben; eigene Typen über `vcap_voucher_types`; Prozent- und Fixrabatt; einmalig, mit Restwert oder wiederverwendbar |
| Verkauf & Ausstellung | Einfache und variable Produkte und Variationen, ein WooCommerce-Coupon je Stück, Code-Format (Präfix, Suffix, Länge, Zeichensatz, Mindeststärke), Ausstellung je Bestellstatus oder per Bestellaktion, idempotent; Wunschbetrag (Min/Max/Schritt/Vorschläge); Kaufregeln für Gutscheinprodukte; WooCommerce Subscriptions (Verlängerungen optional) |
| Ohne Bestellung | *Gutscheine → Neuer Gutschein* (Kulanz, Gewinn, Verkauf an der Kasse), *Karten importieren* (Bestandskarten mit Restwert), `wp vcap issue`/`wp vcap import`, PHP `issue_standalone()` |
| Personalisierung | Empfänger, Nachricht, Wunsch-Zustelldatum, eigene Feldgruppen, Designauswahl durch den Kunden, Live-Vorschau und PDF-Vorschau auf der Produktseite |
| Zustellung | Sofort, verzögert, festes Datum oder Kundenwunsch (Action Scheduler), Käufer/Empfänger/beide, Sammel-E-Mail bei großen Mengen, Größenlimit für Anhänge, Empfänger-Korrektur, Rückläufer, Erinnerung vor Ablauf, Hinweis an den Schenkenden, Empfängersprache; physische Karten (Versand, Aktivierung bei Status) |
| PDF-Designer | Mehrere Vorlagen und Formate, Drag & Drop, Ebenen, QR-Code, Barcode, Bilder, Platzhalter (auch eigene), Vorschau, Vorlagen je Sprache (WPML/Polylang) |
| Downloads & Kundenkonto | Bestellbestätigung, *Mein Konto → Gutscheine*, E-Mails, signierte und ablaufende Gast-Links, erneuter Versand und Änderung durch den Kunden, Guthabenabfrage, „Zu meinem Guthaben hinzufügen“ |
| Verwaltung | Gutscheinliste mit Suche/Filtern/CSV-Export, Detailansicht, Restwertkorrektur (eigenes Recht zum Erhöhen), erneuter Versand, Stornieren/Reaktivieren, Code neu ausstellen, Verlauf mit Quelle und Benutzer, Bestell-Metabox, Rückbuchung auf den Gutschein, Hinweise auf Geldfälle, Einrichtungs-Checkliste |
| Guthaben & Kundenguthaben | Reservierung vor der Zahlung, Verbuchung, Freigabe und Rückbuchung; Kundenguthaben je Konto, Erstattung als Guthaben mit Zustimmung, manuelle Buchung im Profil; Mehrzweckgutscheine als Zahlung nach Steuern (Einstellung) |
| Aufladung | Aufladeprodukt bucht den Wert auf eine bestehende Geschenkkarte, Rückbuchung bei Erstattung |
| Scanner, Kasse & Filiale | QR-/Kamera-/USB-Scanner für Gutscheine, Leistungspässe und Pass-Captain-Tickets, Einlösung am Schalter mit Belegnummer, Kasse/Filiale je Gerät, Druck, Storno einer Schaltereinlösung, Rolle „Gutschein-Kasse“, Tageslimits je Mitarbeiter und je Kasse |
| Aktionen, Bonus & Freunde werben | Regelbasierte Warenkorbrabatte mit Nutzungsgrenzen, Bonusgutscheine nach bezahlter Bestellung, persönlicher Empfehlungscode mit Prämie und Rücknahme, `[vcap_referral]` |
| Coupon-Regeln | SKU-Regeln (SKUs / SKUs ausschließen) und „nur Erstbestellung“ für jeden WooCommerce-Coupon, Coupon-Links (`?gutschein=CODE`), eigenes Geschenkkarten-Feld, Rate-Limit für Code-Eingaben |
| Code-Import & Code-Kampagnen | CSV/XLS/XLSX/ODS mit Batches, Formatprüfung und Duplikaterkennung; Kampagnen aus Vorlage-Coupon oder Code-Pool mit CSV-Export; Hintergrundläufe für große Mengen |
| Berichte & Journal | Übersicht, Kampagnen und Codes (Partner, Aktionen, Bonus), offener Gutscheinwert zum Stichtag, Einlösejournal (CSV, DATEV-Buchungsstapel), Kassen und Personal, Pass-Captain-Abgleich |
| Einlöse-API & Connectoren | `vcap()->api_v1`, `vcap()->redemptions` (Reservieren, Buchen, Freigeben, Erstatten mit Idempotenzschlüssel), REST `vcap/v1`, Einlösewege (Kanäle), Provider-Vertrag `VCAP\Contracts\RedemptionProvider`, Beispiel-Connector |
| WP Pass Captain | Bezahlen von Tickets mit Gutscheinen in der Pass-Captain-Kasse, Ticketgutscheine (fester Termin oder Wahltermin) mit Tausch auf der Einlöseseite, gemeinsamer Scanner, Rücknahme bei Storno, Abgleichbericht |
| WP Affiliate Captain | Code-Kampagnen einem Partner zuordnen (Provision über `wpac_coupon_affiliate`), Partnerspalte in den Kampagnen |
| Steuer & Recht | Steuerbehandlung je Gutscheinart (Einzweck/Mehrzweck/Aktion), Einzweckgutscheine nur für passende Steuersätze, gesetzliche Gültigkeit je Land (EU-Marktdaten), Bedingungen je Land, Steuerprüfung der Produkte |
| Kompatibilität | HPOS und klassische Bestellspeicherung, Block-Warenkorb/-Kasse, WPML/WCML, Polylang, Mehrwährung (WCML, Aelia, WOOCS, CURCY, WooPayments), Flexible Product/Checkout Fields, Cache-Plugins, Multisite (Netzwerkaktivierung, Deinstallation je Site) |
| Shortcodes & Blöcke | `[vcap_balance]`/`[vcap_voucher_status]`, `[vcap_redeem]`, `[vcap_my_vouchers]`, `[vcap_referral]` und die passenden Blöcke |
| Betrieb | Website-Zustand (geplante Aufgaben, Mails, privater Ordner, Einlöseseite, Absenderdomain), Diagnose-Infos, WP-CLI `wp vcap …`, Nachholen nach Reaktivierung, Protokoll im WooCommerce-Log |
| Sicherheit & Datenschutz | Capabilities, Nonces, signierte Links, persönliche Gutscheine nur mit bestätigter Konto-Adresse, Datenexport/-löschung, Anonymisierung, Rate-Limits, maskierte Codes in Notizen und Logs |

## Premium-Add-on (nur zur Information)

Das Add-on `voucher-captain-pro` (Repository `hastla007/captain-pro`, Plan: dort `docs/VOUCHER-PRO.md`) soll neue
Funktionen bringen, die es im Kern nicht gibt – geplant sind Wallet-Pässe (Apple/Google, Link „Zum Wallet hinzufügen“
über `vcap_voucher_links`), SMS-Zustellung und Firmenbestellungen mit Empfängerliste (über `issue_standalone()`). Der
Funktionsumfang des Add-ons entscheidet der Inhaber; für den Kern ändert sich dadurch nichts.

## Öffentliche Erweiterungspunkte für das Add-on

Ein Add-on registriert sich in der Action `vcap_loaded` (Argument: das laufende `VCAP\Plugin`) und nutzt nur die
öffentliche API: `vcap()->api_v1`, `vcap()->redemptions`, den REST-Namespace `vcap/v1`, die Schnittstellen
`VCAP\Contracts\*`, die dokumentierte Order-/Coupon-Meta und die Hooks mit Präfix `vcap_` (API.md, CONNECTORS.md).

**Versionierung** (CONNECTORS.md → 6. API versioning policy): Innerhalb von v1 nur additive Änderungen (neue Methoden,
optionale Argumente, Array-Schlüssel, Hooks, Fehlercodes); bestehende Schlüssel, Fehlercodes und Hook-Argumente
behalten ihre Bedeutung. Brechende Änderungen bekommen eine neue Version daneben (`api_v2`, `vcap/v2`); die alte
bleibt mindestens zwei Minor-Releases und sechs Monate mit Deprecation-Hinweis erhalten. Alles andere (Klassen ohne
dokumentierten Vertrag, Tabellen, private Meta) ist intern – ein Add-on fragt nie die Tabellen von Voucher Captain
direkt ab.

Die verbindliche Liste der Andockpunkte mit Argumenten und Version steht in **API.md → „Extension points for add-ons
(public API since 1.2.0)“**; `tests/run.php` prüft, dass jeder dort genannte Hook im Code aufgerufen wird.

Besonders nützlich für ein Add-on: `vcap_settings_sections` (eigener Abschnitt, seit 1.2.0) und `vcap_settings_fields`
(eigene Einstellungen im Tab *Gutscheine*),
`vcap_product_settings_fields`/`vcap_product_settings_save` (Produktoptionen), `vcap_voucher_types` und
`vcap_redemption_channels` (eigene Gutscheinarten und Kanäle), `vcap_redemption_allowed` (eigene Einlöseregeln),
`vcap_placeholder_definitions`/`vcap_placeholders` (PDF-Platzhalter), `vcap_template_design` (Vorlagen),
`vcap_report_tabs`/`vcap_report_page` (eigener Berichts-Tab, seit 1.2.0), `vcap_report_group` (Berichte),
`vcap_voucher_admin_actions`/`vcap_voucher_admin_action` (Aktionen in der Gutscheinansicht) und die Ereignisse
`vcap_vouchers_issued`, `vcap_voucher_redeemed`, `vcap_voucher_event`.

**Vertragsversion:** Die Konstante `VCAP_CONTRACT_VERSION` (seit Plugin 1.2.0) nennt die Version dieser öffentlichen
Schnittstelle, unabhängig von der Plugin-Version: 1.0.0 = Plugin 1.1.0, 1.1.0 = Plugin 1.2.0, 1.2.0 = Plugin 1.3.0 mit den
Lesefunktionen `vcap_read_*()` / `vcap()->read` (Zustand eines Gutscheins über die ID, ohne Code und personenbezogene
Daten; API.md → „Read functions“), 1.3.0 = Plugin 1.3.0 mit den Gutschein-Befehlen `vcap_command_*()` /
`vcap()->commands` (ausstellen, sperren, reaktivieren, einlösen über die ID; ab Werk aus, feste Grenzen, Protokoll;
API.md → „Voucher commands“). Ein Add-on prüft sie
einmal, statt einzelne Hooks abzufragen (CONNECTORS.md → 6.); die erste Version jedes Hooks steht in der Spalte
„Since“ von docs/HOOKS.md.

Seit Plugin 1.2.0 (Vertragsversion 1.1.0, Schema 1.8.0, Paket R6) zusätzlich für das Add-on:

- `vcap_admin_menu` (Action, `$parent_slug` = `vcap-vouchers`): nach dem Menü des Kerns, für eigene Unterseiten
  (`add_submenu_page( $parent_slug, … )`, erscheinen vor „Einstellungen“).
- `vcap_voucher_event`: zusätzlich `$source` (`Source::current()`) und die ID des handelnden Benutzers als 7. und
  8. Argument (rückwärtskompatibel; mit `add_action( …, 10, 8 )` registrieren).
- `vcap()->issuer->issue_standalone( array $args )`: Gutschein ohne Bestellung ausstellen (Firmenbestellung mit
  Empfängerliste, Kulanz, Gewinnspiel) – Produkt oder Wert/Art/Ablauf, Empfänger, Versand sofort, Quelle/Referenz
  (idempotent); Rechte prüft der Aufrufer (API.md → „Issuing vouchers without an order“). Danach
  `vcap_voucher_issued_standalone` (`Voucher`, `$args`).
- `vcap_voucher_links` (Filter, `array $links`, `Voucher`, `$context` = `email`, `email_plain`, `account`): zusätzliche
  Links wie „Add to Wallet“ in der Gutschein-E-Mail (HTML und Text) und in der Gutscheinliste von „Mein Konto“,
  escaped ausgegeben.
- `vcap_counter_limits` / `vcap_counter_limit_applies` (Filter): Tageslimits am Schalter je Mitarbeiter/Kasse ändern
  (z. B. je Filiale) bzw. festlegen, für wen sie gelten.

Vollständige Liste aller Hooks mit Fundstelle und Beschreibung: **[docs/HOOKS.md](HOOKS.md)** – aus dem Code erzeugt
(`php bin/hooks.php`), CI prüft mit `php bin/hooks.php --check`, dass sie aktuell ist. Diese Datei führt bewusst keine
eigene Tabelle mehr (sie veraltete, M12-102); `tests/run.php` prüft, dass jeder hier genannte `vcap_`-Hook in
docs/HOOKS.md steht.
