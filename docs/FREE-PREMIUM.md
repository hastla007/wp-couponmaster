# Freier Kern und getrenntes Premium-Add-on

Entscheidung des Betreibers vom 13.11.2026 (verbindlich). Dieses Dokument ersetzt das frühere LICENSING.md.

## Regeln

1. **Der Kern ist frei.** Voucher Captain steht unter GPL-2.0-or-later und wird über WordPress.org verteilt. Jede
   Funktion, die heute im Code steht, gehört zum Kern und bleibt dauerhaft frei – auch Aktionen, Freunde werben,
   Aufladung, Auswertungen und die WP-Pass-Captain-Integration. Im Kern ist nichts gesperrt, abgestuft oder „in
   Premium enthalten“.
2. **Kein Lizenz- oder Update-Code im Kern.** Kein Lizenz-Client, keine Aufrufe von `wplc/v1`, kein `Update URI`-Header,
   keine eigenen Filter auf `pre_set_site_transient_update_plugins` oder `plugins_api`, keine Berechtigungsprüfungen
   (`allows_entitlement` o. Ä.), keine Lizenzseite und kein Lizenzmenü, keine Upgrade-Hinweise („Upgrade to Premium“),
   keine Konstanten `VCAP_EDITION`/`VCAP_LICENSE_*`, keine Aufteilung in Free- und Premium-Build. Es gibt **einen** Build
   (`bin/build.sh` → `build/voucher-captain.zip`); Updates kommen von WordPress.org.
3. **WordPress.org-Richtlinie 11:** keine gesperrten Bedienelemente. Erlaubt wäre höchstens ein ausblendbarer Hinweis
   auf den eigenen Admin-Seiten; der Kern verzichtet ganz darauf.
4. **Bestandsdaten.** Ein Update räumt einmal je Site auf, was Vorabversionen mit Lizenzcode hinterlassen haben
   (`VCAP\Install::remove_license_data()`, Merker `vcap_license_data_removed`): die Optionen `vcap_wplc_*` (auch als
   Netzwerk-Optionen), das WP-Cron-Ereignis und die Action-Scheduler-Aktion `vcap_license_refresh` sowie den
   Update-Cache `vcap_update_check`. `uninstall.php` entfernt dasselbe bei jeder Deinstallation, auch wenn die Daten
   behalten werden. Gutscheindaten werden dabei nicht angefasst.
5. **Premium ist ein getrenntes Add-on** (eigenes Plugin, eigenes Repository, eigener Slug). Es nutzt ausschließlich
   die öffentlichen Erweiterungspunkte unten und bringt seinen Lizenz- und Update-Code selbst mit. Der frühere
   Lizenzcode des Kerns liegt dafür im Branch `addon-source/licensing-from-youthful-bardeen`. Ein Add-on darf keine
   Kernfunktion nachträglich sperren.
6. **Absicherung:** `tests/license-guard.php` (CI-Job `smoke`) durchsucht die ausgelieferten Dateien
   (`voucher-captain.php`, `uninstall.php`, `readme.txt`, `src/`, `templates/`, `assets/` ohne `assets/vendor/`,
   `examples/`, `config/`) nach Lizenz- und Update-Code und schlägt mit Datei:Zeile fehl. `bin/build.sh` verweigert
   einen Plugin-Kopf mit `Update URI` oder Editions-/Lizenzkonstanten.

## Funktionsumfang „Kern, dauerhaft frei“

| Bereich | Umfang |
|---|---|
| Verkauf & Ausstellung | Einfache und variable Produkte als Gutschein, ein WooCommerce-Coupon je Stück, Code-Format, Ausstellung je Bestellstatus oder manuell, idempotent |
| Wert & Einlösung | Produktpreis, fixer Wert oder Wunschbetrag; Prozent-/Fixrabatt; einmalig, Geschenkkarte mit Restwert oder wiederverwendbar; Ablauf, Einschränkungen; Guthaben-Reservierung, Verbuchung und Rückbuchung |
| Personalisierung | Empfänger, Nachricht, Wunsch-Zustelldatum, eigene Feldgruppen |
| Zustellung | Sofort, verzögert, festes Datum oder Kundenwunsch über Action Scheduler; eigene E-Mails mit PDF-Anhang |
| PDF-Designer | Mehrere Vorlagen und Formate, Drag & Drop, Ebenen, QR-Code, Barcode, Platzhalter, Vorschau |
| Downloads | Bestellbestätigung, Mein Konto, E-Mails, signierte Gast-Links |
| Verwaltung | Gutscheinliste, Detailansicht, Guthabenkorrektur, erneuter Versand, Stornieren, Verlauf; Shortcodes und Blöcke |
| Kundenguthaben | Guthabenkonto je Kunde, Erstattung als Guthaben mit Zustimmung |
| Scanner & Pässe | QR-Prüfung und Einlösung, Kassiererrolle, Storno einer Schaltereinlösung |
| **Standorte & Mitarbeiter** (Entscheidung 15.11.: frei) | Kasse/Filiale je Gerät und Beleg-Nr. als eigene Spalten im Einlösejournal (Seite + CSV), Bericht „Kassen und Personal“ (Schaltereinlösungen je Kasse/Filiale und/oder Mitarbeiter, Anzahl, Betrag, Stornos, je Währung, CSV), Tageslimits für Schaltereinlösungen je Mitarbeiter und je Kasse (Betrag/Anzahl, Standard aus, eigenes Limit im Benutzerprofil) |
| Code-Import & Code-Kampagnen | CSV/XLS/XLSX/ODS, Batches, Duplikaterkennung, Kampagnen mit Partnern |
| Einlöse-API & Connectoren | `vcap()->api_v1`, `vcap()->redemptions`, REST `vcap/v1`, Kanäle, eigene Gutscheintypen |
| Steuer & Recht | Steuerbehandlung je Gutscheinart, Mehrzweckgutscheine als Zahlung, gesetzliche Gültigkeit je Land |
| Berichte | Offener Gutscheinwert zum Stichtag, Einlösejournal (CSV), Pass-Captain-Abgleich, **Auswertung nach Kampagnen und Partnern** (früher Premium) |
| **Aktionen und Bonusgutscheine** (früher Premium) | Regelbasierte Rabatte im Warenkorb, Bonusgutscheine, Nutzungsgrenzen |
| **Freunde werben** (früher Premium) | Persönlicher Empfehlungscode, Prämien, Rücknahme bei Storno, `[vcap_referral]` |
| **Aufladung von Geschenkkarten** (früher Premium) | Aufladeprodukt bucht den Wert auf eine bestehende Karte, Rückbuchung bei Erstattung |
| **WP-Pass-Captain-Integration** (früher Premium) | Ticketgutscheine, gemeinsamer Scanner; dazu wie bisher Bezahlen mit Gutscheinen in der Pass-Captain-Kasse |
| Kompatibilität | WooCommerce Subscriptions, WPML/WCML, Multi-Currency (WCML, Aelia, WOOCS, CURCY), HPOS, Block-Warenkorb/-Kasse, Multisite |
| Sicherheit & Datenschutz | Capabilities, Nonces, signierte Links, Datenexport/-löschung, Anonymisierung, Rate-Limit |

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

Besonders nützlich für ein Add-on: `vcap_settings_fields` (eigene Einstellungen im Tab *Gutscheine*),
`vcap_product_settings_fields`/`vcap_product_settings_save` (Produktoptionen), `vcap_voucher_types` und
`vcap_redemption_channels` (eigene Gutscheinarten und Kanäle), `vcap_redemption_allowed` (eigene Einlöseregeln),
`vcap_placeholder_definitions`/`vcap_placeholders` (PDF-Platzhalter), `vcap_report_group` (Berichte),
`vcap_voucher_admin_actions`/`vcap_voucher_admin_action` (Aktionen in der Gutscheinansicht) und die Ereignisse
`vcap_vouchers_issued`, `vcap_voucher_redeemed`, `vcap_voucher_event`.

**Vertragsversion:** Die Konstante `VCAP_CONTRACT_VERSION` (seit Plugin 1.2.0) nennt die Version dieser öffentlichen
Schnittstelle, unabhängig von der Plugin-Version: 1.0.0 = Plugin 1.1.0, 1.1.0 = Plugin 1.2.0. Ein Add-on prüft sie
einmal, statt einzelne Hooks abzufragen (CONNECTORS.md → 6.). Der Kern weiß dabei nichts über das Add-on.

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
