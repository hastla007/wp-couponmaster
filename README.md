# Voucher Captain

WooCommerce-Plugin für personalisierte **PDF-Gutscheine, Geschenkkarten und Event-Tickets**. Der Funktionsumfang orientiert sich an „Flexible PDF Coupons PRO“. Code, Namen und Gestaltung sind eigenständig.

## Funktionen

| Bereich | Umfang |
|---|---|
| **Verkauf & Ausstellung** | Einfache und variable Produkte (inkl. Variationen) als Gutschein. Pro gekauftem Stück wird ein WooCommerce-Coupon erzeugt, auch mehrere PDFs pro Stück möglich. Code mit Präfix/Suffix/Länge/Zeichensatz und optionaler Bestellnummer. Ausstellung automatisch bei frei wählbaren Bestellstatus oder manuell über die Bestellaktion. Idempotent (keine Duplikate). |
| **Wert & Einlösung** | Produktpreis, fixer Wert oder **Wunschbetrag des Kunden** (Min/Max/Schrittweite/Vorschlagsbeträge). Prozent- oder Fixrabatt. Einmalig, mehrfach mit **Guthaben** (Geschenkkarte) oder wiederverwendbar. Ablauf in Tagen, zu einem festen Datum oder nie. Gratisversand, Produkt-/Kategorie-Einschränkungen, Mindest-/Höchstbestellwert, Einschränkung auf E-Mail-Adressen. Guthaben wird bei Storno zurückgebucht. |
| **Personalisierung** | Empfängername, Empfänger-E-Mail, Nachricht und Wunsch-Zustelldatum. Eigene **Feldgruppen** (Text, Textbereich, E-Mail, Zahl, Datum, Auswahl, Checkbox) für alle, ausgewählte Produkte, Kategorien oder Tags. Pflichtfeld, Zeichenlimit, Hilfetext und Position einstellbar. |
| **Zustellung** | Sofort, X Tage nach Kauf, festes Datum oder Kundenwunschdatum, über Action Scheduler. Versand an Käufer, Empfänger oder beide. Eigene E-Mails mit konfigurierbarem Betreff/Überschrift/Text, zusätzlichen Empfängern und PDF-Anhang. |
| **PDF-Designer** | Mehrere Vorlagen, A4/A5/A6/DL/Letter oder eigenes Format, Hoch-/Querformat, Hintergrundfarbe/-bild. Drag & Drop mit Hilfslinien/Snapping, Ebenen (sperren/ausblenden/sortieren), Rotation, Deckkraft, Undo/Redo. Elemente: Text, Überschrift, Bild, QR-Code, Barcode, Form, Linie. Schrift, Größe, Farbe, Ausrichtung, fett/kursiv/unterstrichen. Platzhalter. PDF-Vorschau im Admin und optional für Kunden (mit Wasserzeichen). |
| **Downloads** | Auf der Bestellbestätigung, in Mein Konto (Bestellung + eigener Tab „Gutscheine“) und in E-Mails. Gastkäufe über HMAC-signierte, optional ablaufende Links. |
| **Verwaltung** | Gutscheinliste mit Suche, Filtern und CSV-Export. Detailansicht mit Bearbeiten, Guthabenkorrektur, erneutem Versand, Stornieren und vollständigem Verlauf (Audit-Log). Metabox in der Bestellung (HPOS-kompatibel). Shortcode `[wcvs_balance]` zur Guthabenabfrage. |
| **Tickets & Scanner** | QR-Check-in-Link auf Tickets. Scanner-Seite für Handy/Desktop (Kamera, USB-Scanner oder manuelle Eingabe) mit mehreren Einlässen pro Ticket, Rückgängig und Einlösen im Laden. |
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

Eigene Platzhalter lassen sich über die Filter `wcvs_placeholder_definitions` und `wcvs_placeholders` ergänzen.

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

Nach der Aktivierung werden zwei Startvorlagen angelegt (Geschenkkarte A5, Ticket A6). Danach im Produkt die Option **„Gutschein“** anhaken und den Tab **„Gutschein“** konfigurieren.

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
