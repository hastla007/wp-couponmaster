# Voucher Captain

WooCommerce-Plugin für personalisierte **PDF-Gutscheine, Geschenkkarten, Guthaben und Rabattcodes**. Voucher Captain verwaltet Gutscheine und Gutschein-Einlösungen. Die Eventverwaltung, Ticketkontingente, Sitzplätze, Eventbestellungen und der Einlass gehören zu [WP Pass Captain](https://github.com/hastla007/wp-passmaster). Beide Plugins bleiben eigenständig; sind beide aktiv, verbindet sie ein versionierter Vertrag: Gutscheine und Geschenkkarten bezahlen Tickets in der Pass-Captain-Kasse, Ticketgutscheine werden gegen Tickets getauscht, der gemeinsame Scanner prüft beides ([API.md](API.md), [CONNECTORS.md](CONNECTORS.md)). Code, Namen und Gestaltung sind eigenständig.

**Editionen.** Free- und Premium-Edition entstehen aus demselben Code (`bin/build.sh`, `VCAP_EDITION=premium`). In der Premium-Edition brauchen Aktionen/Bonusgutscheine, Freunde werben, Aufladung, Auswertungen und die WP-Pass-Captain-Anbindung eine aktive Lizenz über WP License Captain ([LICENSING.md](LICENSING.md)); Updates kommen dann vom Lizenzserver. Welche Funktionen endgültig Premium sind, entscheidet der Betreiber noch (`config/premium-features.php`).

## Funktionen

| Bereich | Umfang |
|---|---|
| **Verkauf & Ausstellung** | Einfache und variable Produkte (inkl. Variationen) als Gutschein. Pro gekauftem Stück wird ein WooCommerce-Coupon erzeugt, auch mehrere PDFs pro Stück möglich. Code mit Präfix/Suffix/Länge/Zeichensatz und optionaler Bestellnummer. Ausstellung automatisch bei frei wählbaren Bestellstatus oder manuell über die Bestellaktion. Idempotent (keine Duplikate). |
| **Wert & Einlösung** | Produktpreis, fixer Wert oder **Wunschbetrag des Kunden** (Min/Max/Schrittweite/Vorschlagsbeträge). Prozent- oder Fixrabatt. Einmalig, mehrfach mit **Guthaben** (Geschenkkarte) oder wiederverwendbar. Ablauf in Tagen, zu einem festen Datum oder nie. Gratisversand, Produkt-/Kategorie-Einschränkungen, Mindest-/Höchstbestellwert, Einschränkung auf E-Mail-Adressen. Guthaben wird vor dem Senden an das Zahlungsgateway pro Bestellung reserviert, nach erfolgreicher Zahlung verbucht und bei fehlgeschlagener oder abgebrochener Zahlung freigegeben; Stornos verbuchen die Rückbuchung gemäß Einstellung. |
| **Personalisierung** | Empfängername, Empfänger-E-Mail, Nachricht und Wunsch-Zustelldatum. Eigene **Feldgruppen** (Text, Textbereich, E-Mail, Zahl, Datum, Auswahl, Checkbox) für alle, ausgewählte Produkte, Kategorien oder Tags. Pflichtfeld, Zeichenlimit, Hilfetext und Position einstellbar. |
| **Zustellung** | Sofort, X Tage nach Kauf, festes Datum oder Kundenwunschdatum, über Action Scheduler. Versand an Käufer, Empfänger oder beide. Eigene E-Mails mit konfigurierbarem Betreff/Überschrift/Text, zusätzlichen Empfängern und PDF-Anhang. |
| **PDF-Designer** | Mehrere Vorlagen, A4/A5/A6/DL/Letter oder eigenes Format, Hoch-/Querformat, Hintergrundfarbe/-bild. Drag & Drop mit Hilfslinien/Snapping, Ebenen (sperren/ausblenden/sortieren), Rotation, Deckkraft, Undo/Redo. Elemente: Text, Überschrift, Bild, QR-Code, Barcode, Form, Linie. Schrift, Größe, Farbe, Ausrichtung, fett/kursiv/unterstrichen. Platzhalter. PDF-Vorschau im Admin und optional für Kunden (mit Wasserzeichen). |
| **Downloads** | Auf der Bestellbestätigung, in Mein Konto (Bestellung + eigener Tab „Gutscheine“) und in E-Mails. Gastkäufe über HMAC-signierte, optional ablaufende Links. |
| **Verwaltung** | Gutscheinliste mit Suche, Filtern und CSV-Export. Detailansicht mit Bearbeiten, Guthabenkorrektur, erneutem Versand, Stornieren und vollständigem Verlauf (Audit-Log). Metabox in der Bestellung (HPOS-kompatibel). Shortcodes und Blöcke: `[vcap_balance]`/`[vcap_voucher_status]` (Guthaben/Status), `[vcap_redeem]` (Einlöseseite), `[vcap_my_vouchers]` (Gutscheine mit erneutem Versand), `[vcap_referral]` (Freunde werben). |
| **Gutschein- und Leistungspässe** | QR-Prüfung und Einlösung von Voucher-Captain-Gutscheinen bzw. serviceartigen Pässen. Der gemeinsame Scanner (*Gutscheine → Scanner*) erkennt auch WP-Pass-Captain-Tickets und checkt sie bei Pass Captain ein (Berechtigung von Pass Captain nötig); Eventtickets und Einlassstatus verwaltet weiterhin Pass Captain. |
| **Code-Import** | CSV, XLS, XLSX, ODS (eigener schlanker Leser), Batches, Formatprüfung per Regex, Duplikate in Datei und Bestand werden erkannt. |
| **Kompatibilität** | WooCommerce Subscriptions, WPML/WCML (`wpml-config.xml`, Vorlage je Sprache), Multi-Currency (WCML, Aelia, WOOCS, CURCY), Flexible Product Fields / Flexible Checkout Fields über `{item_meta:…}` / `{order_meta:…}`. HPOS und Block-Warenkorb/-Kasse. |
| **Sicherheit & Datenschutz** | Capabilities, Nonces, Escaping, signierte Links, keine Remote-Bilder im PDF (SSRF-Schutz). WordPress-Datenexport/-löschung, automatische Anonymisierung nach X Tagen. Übersetzungsfertig, Deutsch (de_DE, de_AT) enthalten; „Deutsch (Sie)“ (de_DE_formal) und Schweizerdeutsch (de_CH) nutzen die deutsche Übersetzung, solange es keine eigene Datei gibt. |

### Platzhalter

`{coupon_code}` `{coupon_value}` `{coupon_balance}` `{expiry_date}` `{issue_date}` `{product_name}` `{product_price}` `{buyer_name}` `{buyer_email}` `{recipient_name}` `{recipient_email}` `{message}` `{delivery_date}` `{order_number}` `{order_date}` `{event_date}` `{event_location}` `{ticket_admissions}` `{site_name}` `{site_url}` `{redeem_url}` (redeem page with the code filled in) and, for Pass Captain ticket vouchers, `{ticket_event}` `{ticket_quantity}`

Dynamische Platzhalter:

- `{field:schluessel}` für eigene Felder
- `{item_meta:Name}` für Positions-Meta, z. B. aus Flexible Product Fields
- `{order_meta:_feld}` für Bestell-Meta, z. B. aus Flexible Checkout Fields
- `{billing_company}` und `{shipping_…}` für Adressfelder

Eigene Platzhalter lassen sich über die Filter `vcap_placeholder_definitions` und `vcap_placeholders` ergänzen.

## Voraussetzungen

WordPress 6.4+, WooCommerce 8.9+ (wird beim Laden geprüft), PHP 8.1+ (mit GD, mbstring, zip). Getestet bis WordPress 7.1 und WooCommerce 11.2.

## Installation

**Release-ZIP bauen:**
```bash
bin/build.sh
```
Danach `build/voucher-captain.zip` unter *Plugins → Installieren → Hochladen* einspielen.

**Aus dem Repository** (z. B. Entwicklung): Ordner nach `wp-content/plugins/` legen und die Abhängigkeiten installieren:
```bash
composer install --no-dev
```

Nach der Aktivierung werden zwei Startvorlagen angelegt (Geschenkkarte A5 und Gutschein-/Leistungspass A6). Danach im Produkt die Option **„Gutschein“** anhaken und den Tab **„Gutschein“** konfigurieren.

## Verrechnung, Steuer und Erstattung von Gutscheinen und Guthaben

**Reihenfolge an der Kasse.** WooCommerce rechnet zuerst die Rabatte (Aktionen, Produkt-, Prozent- und Warenkorb-Rabattcodes). Danach bezahlen Wertgutscheine/Geschenkkarten und Kundenguthaben den Restbetrag – unabhängig davon, in welcher Reihenfolge der Kunde die Codes eingegeben hat. Zwischen Geschenkkarten und Guthaben gilt die Einstellung *„Reihenfolge von Geschenkkarten und Guthaben“* (Standard: erst Geschenkkarten, dann Guthaben). So wird nie mehr Guthaben verbraucht als nötig.

**Kombination.** *„Guthaben mit Rabattcodes“* (Standard: erlaubt) legt fest, ob Guthaben in derselben Bestellung wie Rabattcodes genutzt werden darf. Rabattcodes und Geschenkkarten dürfen Gutscheinprodukte standardmäßig nicht verbilligen bzw. bezahlen (*„Rabattcodes auf Gutscheine“*, *„Gutscheine mit Gutscheinen bezahlen“*).

**Steuer.** Geschenkkarten und Guthaben werden technisch als WooCommerce-Warenkorb-Coupon (Festbetrag) verrechnet. WooCommerce verteilt den Betrag anteilig auf die Positionen und berechnet die Steuer vom reduzierten Preis. Das entspricht einer Behandlung wie ein Preisnachlass. Mit der Einstellung *„Mehrzweckgutscheine als Zahlung“* (`vcap_mpv_after_tax`, Standard: aus) zahlen Mehrzweckgutscheine, Geschenkkarten und Guthaben stattdessen nach allen Steuern, Versand und Gebühren als nicht steuerbare Zeile „Gutschein CODE“; die Bestellung behält die volle Umsatzsteuer (`src/PaymentVouchers.php`; im Block-Warenkorb und in der Block-Kasse erscheint sie ebenfalls, die Rabattangabe des Coupons bleibt dort bei 0, siehe TAX.md 4.1). Je Gutscheinprodukt lässt sich die Steuerbehandlung (automatisch, Mehrzweck, Einzweck, Aktion) festlegen; ein unpassender Steuerstatus wird gemeldet. Welche Variante für Ihre Gutscheinart steuerlich passt, muss mit der Steuerberatung geklärt werden. Die vollständige Beschreibung je Gutscheinart mit den Fragen an die Steuerberatung steht in [TAX.md](TAX.md).

**Erstattung.**
- *Storniert, fehlgeschlagen oder vollständig erstattet:* der in der Bestellung verwendete Gutschein-/Guthabenbetrag wird zurückgebucht (Einstellung *„Guthaben bei Stornierung wiederherstellen“*, Standard an). Ungenutzte Bonusgutscheine der Bestellung werden zurückgezogen.
- *Teilerstattung:* WooCommerce erstattet den Betrag über die Zahlungsart; Gutschein- und Guthabensalden der Bestellung bleiben unverändert. Gibt der Kunde Artikel einer mit Geschenkkarte bezahlten Bestellung zurück (auch Gast, auch Gesamtbetrag 0 €), bucht „Auf Gutschein zurückbuchen“ in der Bestellung den Betrag auf die Karte zurück (höchstens den von ihr gezahlten Anteil; spätere Stornierung bucht nur den Rest zurück). Bei Gutscheinkäufen werden so viele Gutscheine gesperrt, wie erstattet wurden (ungenutzte zuerst, vollständig verbrauchte nie); eine Erstattung, die bereits verbrauchten Gutscheinwert auszahlen würde, wird mit dem Höchstbetrag abgelehnt.
- *Von Hand „Storniert“ (ohne Erstattung):* nur ungenutzte gekaufte Gutscheine werden gesperrt; bereits genutzte bleiben gültig (Bestellnotiz).
- *Zurückbuchung auf abgelaufene oder gesperrte Karte:* Bestellnotiz und Admin-Hinweis (Dashboard, Bestellungen, Website-Zustand) – Gültigkeit verlängern oder als Geld/Guthaben erstatten; Filter `vcap_restore_extension_days` verlängert abgelaufene Karten automatisch.
- *Erstattung als Guthaben:* nur ausdrücklich über „Als Guthaben erstatten“ in der Bestellung (nie automatisch, nicht für Gastbestellungen) und nur mit protokollierter Zustimmung des Kunden.
- Gekaufte Gutscheine werden bei vollständiger Erstattung/Stornierung der Kaufbestellung gesperrt (*„Gutscheine bei Erstattung stornieren“*).

## Integrationen und Sicherheit

- [Versionierte PHP-API und Providervertrag](API.md)
- [Sicherheits- und Datenschutzkontrollen](SECURITY.md)
- [Release-Abnahmecheckliste](RELEASE_CHECKLIST.md)
- [Connector-Plugins: Einlösung, Wiederholung, Storno, Versionierung](CONNECTORS.md)
- [Adapter für Mehrsprachigkeit, Mehrwährung, Subscriptions und Produktfelder](ADAPTERS.md)
- [Zustandsautomat für Reservierung, Buchung, Freigabe und Erstattung](STATE_MACHINE.md)
- [Lizenzierung und Premium-Edition](LICENSING.md)
- [Steuerbehandlung je Gutscheinart](TAX.md)
- [Release-Prozess, statische Analyse, Drittlizenzen](RELEASE.md)
- [50-Punkte-Roadmap](ROADMAP.md)

## Entwicklung

```bash
composer install
```
Installiert die Dev-Abhängigkeiten (PhpSpreadsheet nur für Testdateien, WP/WC-Stubs).

```bash
php tests/run.php
```
Logik- und PDF-Tests ohne WordPress: Codes, Bereinigung, Platzhalter, Ablauf/Zustellung, PDF-Rendering, Import.

```bash
php bin/make-pot.php
```
Erzeugt die `.pot`-Datei.

```bash
php bin/make-mo.php
```
Kompiliert die `.po`-Dateien zu `.mo`.

Templates lassen sich im Theme unter `woocommerce/voucher-captain/` überschreiben.

## Lizenz

Voucher Captain: GPL-2.0-or-later ([LICENSE](LICENSE)). Das Release-ZIP enthält diese Bibliotheken und Schriften; jede behält ihre Lizenzdatei im `vendor/`-Ordner, die Übersicht steht in `THIRD-PARTY-NOTICES.txt` im ZIP (erzeugt von `bin/third-party-notices.php`, siehe [RELEASE.md](RELEASE.md#third-party-licences)):

| Komponente | Lizenz | Hinweis |
|---|---|---|
| dompdf/dompdf | LGPL-2.1 | PDF-Erzeugung |
| dompdf/php-font-lib | LGPL-2.1-or-later | von dompdf |
| dompdf/php-svg-lib | LGPL-3.0-or-later | von dompdf; GPL-3.0-Text in `licenses/GPL-3.0.txt` |
| masterminds/html5 | MIT | von dompdf |
| sabberworm/php-css-parser | MIT | von dompdf; beim Build in den Namensraum `VCAP\Vendor\` verschoben (`bin/scope-vendor.php`) |
| chillerlan/php-qrcode | MIT oder Apache-2.0 | QR-Codes |
| chillerlan/php-settings-container | MIT | von php-qrcode |
| picqer/php-barcode-generator | LGPL-3.0-or-later | Barcodes; GPL-3.0-Text in `licenses/GPL-3.0.txt` |
| DejaVu-Schriften (mit dompdf) | Bitstream-Vera- und Arev-Lizenz, DejaVu-Änderungen gemeinfrei | `licenses/DejaVu-Fonts-LICENSE.txt` |
| Adobe-Core-14-Fontmetriken (`*.afm`, mit dompdf) | Adobe-Hinweis in jeder Datei | |

Der Kamera-Scanner nutzt den nativen `BarcodeDetector` und fällt sonst (iOS/Safari, Firefox) auf das mitgelieferte jsQR 1.4.0 (Apache-2.0, `assets/vendor/jsqr/` mit LICENSE) zurück.
