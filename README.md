# Voucher Captain

WooCommerce-Plugin für personalisierte **PDF-Gutscheine, Geschenkkarten, Guthaben und Rabattcodes**. Voucher Captain verwaltet Gutscheine und Gutschein-Einlösungen. Die Eventverwaltung, Ticketkontingente, Sitzplätze, Eventbestellungen und der Einlass gehören zu [Ticket Captain](https://github.com/hastla007/wp-passmaster). Beide Plugins bleiben eigenständig; die direkte Bridge wird separat umgesetzt. Code, Namen und Gestaltung sind eigenständig.

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
| **Gutschein- und Leistungspässe** | QR-Prüfung und Einlösung von Voucher-Captain-Gutscheinen bzw. serviceartigen Pässen. Der aktuelle Scanner bleibt für diese VCAP-Datensätze zuständig; Eventtickets und Einlassstatus verwaltet Ticket Captain. Die gemeinsame Scanner-Bridge ist geplant. |
| **Code-Import** | CSV, XLS, XLSX, ODS (eigener schlanker Leser), Batches, Formatprüfung per Regex, Duplikate in Datei und Bestand werden erkannt. |
| **Kompatibilität** | WooCommerce Subscriptions, WPML/WCML (`wpml-config.xml`, Vorlage je Sprache), Multi-Currency (WCML, Aelia, WOOCS, CURCY), Flexible Product Fields / Flexible Checkout Fields über `{item_meta:…}` / `{order_meta:…}`. HPOS und Block-Warenkorb/-Kasse. |
| **Sicherheit & Datenschutz** | Capabilities, Nonces, Escaping, signierte Links, keine Remote-Bilder im PDF (SSRF-Schutz). WordPress-Datenexport/-löschung, automatische Anonymisierung nach X Tagen. Übersetzungsfertig, Deutsch (de_DE, de_AT) enthalten. |

### Platzhalter

`{coupon_code}` `{coupon_value}` `{coupon_balance}` `{expiry_date}` `{issue_date}` `{product_name}` `{product_price}` `{buyer_name}` `{buyer_email}` `{recipient_name}` `{recipient_email}` `{message}` `{delivery_date}` `{order_number}` `{order_date}` `{event_date}` `{event_location}` `{ticket_admissions}` `{site_name}` `{site_url}`

Dynamische Platzhalter:

- `{field:schluessel}` für eigene Felder
- `{item_meta:Name}` für Positions-Meta, z. B. aus Flexible Product Fields
- `{order_meta:_feld}` für Bestell-Meta, z. B. aus Flexible Checkout Fields
- `{billing_company}` und `{shipping_…}` für Adressfelder

Eigene Platzhalter lassen sich über die Filter `vcap_placeholder_definitions` und `vcap_placeholders` ergänzen.

## Voraussetzungen

WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+ (mit GD, mbstring, zip).

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

**Steuer.** Geschenkkarten und Guthaben werden technisch als WooCommerce-Warenkorb-Coupon (Festbetrag) verrechnet. WooCommerce verteilt den Betrag anteilig auf die Positionen und berechnet die Steuer vom reduzierten Preis. Das entspricht einer Behandlung wie ein Preisnachlass. Ob das für Ihre Gutscheinart (Einzweck-/Mehrzweckgutschein) steuerlich passt, muss mit der Steuerberatung geklärt werden; eine Verrechnung „nach Steuer“ als Zahlungsmittel ist derzeit nicht eingebaut (offener Punkt in TODO.md).

**Erstattung.**
- *Storniert, fehlgeschlagen oder vollständig erstattet:* der in der Bestellung verwendete Gutschein-/Guthabenbetrag wird zurückgebucht (Einstellung *„Guthaben bei Stornierung wiederherstellen“*, Standard an). Ungenutzte Bonusgutscheine der Bestellung werden zurückgezogen.
- *Teilerstattung:* WooCommerce erstattet den Betrag über die Zahlungsart; Gutschein- und Guthabensalden bleiben unverändert.
- *Erstattung als Guthaben:* nur ausdrücklich über „Als Guthaben erstatten“ in der Bestellung (nie automatisch, nicht für Gastbestellungen).
- Gekaufte Gutscheine werden bei vollständiger Erstattung/Stornierung der Kaufbestellung gesperrt (*„Gutscheine bei Erstattung stornieren“*).

## Integrationen und Sicherheit

- [Versionierte PHP-API und Providervertrag](API.md)
- [Sicherheits- und Datenschutzkontrollen](SECURITY.md)
- [Release-Abnahmecheckliste](RELEASE_CHECKLIST.md)
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

GPL-2.0-or-later. Mitgelieferte Bibliotheken: dompdf (LGPL-2.1), chillerlan/php-qrcode (MIT), picqer/php-barcode-generator (LGPL-3.0), jsQR (Apache-2.0).
