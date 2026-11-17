# Voucher Captain

WooCommerce-Plugin für personalisierte **PDF-Gutscheine, Geschenkkarten, Guthaben und Rabattcodes**. Voucher Captain verwaltet Gutscheine und Gutschein-Einlösungen. Die Eventverwaltung, Ticketkontingente, Sitzplätze, Eventbestellungen und der Einlass gehören zu [WP Pass Captain](https://github.com/hastla007/wp-passmaster). Beide Plugins bleiben eigenständig; sind beide aktiv, verbindet sie ein versionierter Vertrag: Gutscheine und Geschenkkarten bezahlen Tickets in der Pass-Captain-Kasse, Ticketgutscheine werden gegen Tickets getauscht, der gemeinsame Scanner prüft beides ([API.md](API.md), [CONNECTORS.md](CONNECTORS.md)). Code, Namen und Gestaltung sind eigenständig.

**Kern frei.** Voucher Captain ist vollständig frei (GPL-2.0-or-later) und enthält keinen Lizenz- oder Update-Code: Alle Funktionen – auch Aktionen und Bonusgutscheine, Freunde werben, Aufladung von Geschenkkarten, Auswertung nach Kampagnen und Partnern sowie Ticketgutscheine und gemeinsamer Scanner mit WP Pass Captain – sind ohne Einschränkung verfügbar. Ausgelieferte Funktionen werden nie nachträglich gesperrt oder verschoben. Premiumfunktionen entstehen nur als getrenntes Add-on (`voucher-captain-pro`, eigenes Repository) über die öffentlichen Erweiterungspunkte ([API.md](API.md) → „Extension points for add-ons“, [docs/FREE-PREMIUM.md](docs/FREE-PREMIUM.md)); der Kern ruft keinen Lizenz- oder Update-Server auf.

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

`{coupon_code}` `{coupon_value}` `{coupon_balance}` `{expiry_date}` `{issue_date}` `{product_name}` `{product_price}` `{buyer_name}` `{buyer_email}` `{recipient_name}` (only the recipient's own name) `{holder_name}` (recipient, else buyer) `{recipient_email}` `{message}` `{delivery_date}` `{order_number}` `{order_date}` `{event_date}` `{event_location}` `{ticket_admissions}` `{site_name}` `{site_url}` `{redeem_url}` (redeem page with the code filled in) and, for Pass Captain ticket vouchers, `{ticket_event}` `{ticket_quantity}`

Dynamische Platzhalter:

- `{field:schluessel}` für eigene Felder
- `{item_meta:Name}` für Positions-Meta, z. B. aus Flexible Product Fields
- `{order_meta:_feld}` für Bestell-Meta, z. B. aus Flexible Checkout Fields
- `{billing_company}` und `{shipping_…}` für Adressfelder

Ein PDF-Textelement, dessen Platzhalter alle leer sind (z. B. „Für: {recipient_name}“ ohne Empfängernamen), wird nicht gedruckt.

Eigene Platzhalter lassen sich über die Filter `vcap_placeholder_definitions` und `vcap_placeholders` ergänzen.

## Voraussetzungen

WordPress 7.0+, WooCommerce 11.2.2+ (wird beim Laden geprüft), PHP 8.5+ (mit GD, mbstring, zip); Datenbank MySQL 9.7+ oder MariaDB 12.3+ (ältere Server: nur Hinweis im Website-Zustand). Getestet bis WordPress 7.1 und WooCommerce 11.3.

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

**Missbrauchsschutz (Standard an, unter WooCommerce → Einstellungen → Gutscheine änderbar; 0 schaltet jede Grenze ab).**
- *Wunschbetrag:* Produkte ohne eigenen Höchstbetrag sind auf *„Standard-Höchstbetrag für Wunschbeträge“* begrenzt (`vcap_custom_max_default`, Standard 500 in Shopwährung, nie unter dem Mindestbetrag des Produkts); ein eigener Höchstbetrag bleibt.
- *Gutscheinwert je Kunde und 24 Stunden:* höchstens 1.000 (`vcap_customer_daily_limit`) – Gutscheine im Warenkorb plus in den letzten 24 Stunden bestellte (wartend, in Bearbeitung, abgeschlossen) desselben Kontos oder derselben normalisierten Rechnungs-E-Mail; die Kasse lehnt darüber ab.
- *Haltefrist für Neukunden:* Gutscheine einer Erstbestellung über 250 (`vcap_hold_new_customer_above`) werden ausgestellt, aber erst nach 24 Stunden ab Zahlung (`vcap_hold_hours`) zugestellt und einlösbar (geplante Zustellung, Coupon bis dahin Entwurf). Neukunde = keine andere wartende/bezahlte Bestellung desselben Kontos bzw. derselben E-Mail, die älter als die Haltefrist ist. Danke-Seite, Bestellmails und Konto nennen den Termin; Code und PDF erscheinen erst danach. „Jetzt zustellen“ am Gutschein gibt früher frei.
- *Freunde werben:* Prämie erst 14 Tage nach Zahlung der abgeschlossenen Freundesbestellung (`vcap_referral_wait_days`, geplante Aktion; Storno/Erstattung in der Frist verwirft sie), höchstens 10 Prämien je Werber in 30 Tagen (`vcap_referral_max_rewards`), keine Prämie bei Bestellsumme 0, unbezahlter Nachnahme/Vorkasse oder gleicher Rechnungs-/Lieferadresse wie der Werber (`vcap_referral_address_check`). Mindestbestellwert des Freundescodes: leer = doppelter Festrabatt; ein Festrabatt verlangt immer mehr als den Rabatt (mindestens Rabatt + 1).
- *Bonusgutscheine:* gültig und versendet erst 14 Tage nach Zahlung (`vcap_bonus_wait_days`; bis dahin Entwurf, Gültigkeit ab diesem Tag). Eine Teilerstattung unter die Aktionsschwelle zieht einen ungenutzten Bonus zurück; ist er schon eingelöst, wird die Erstattung um den genutzten Bonus gekürzt (Filter `vcap_allow_refund_of_used_bonus`).

## Integrationen und Sicherheit

- [Versionierte PHP-API und Providervertrag](API.md)
- [Sicherheits- und Datenschutzkontrollen](SECURITY.md)
- [Release-Abnahmecheckliste](RELEASE_CHECKLIST.md)
- [Connector-Plugins: Einlösung, Wiederholung, Storno, Versionierung](CONNECTORS.md)
- [Adapter für Mehrsprachigkeit, Mehrwährung, Subscriptions und Produktfelder](ADAPTERS.md)
- [Zustandsautomat für Reservierung, Buchung, Freigabe und Erstattung](STATE_MACHINE.md)
- [Freier Kern und getrenntes Premium-Add-on](docs/FREE-PREMIUM.md)
- [Steuerbehandlung je Gutscheinart](TAX.md)
- [Release-Prozess, statische Analyse, Drittlizenzen](RELEASE.md)
- [50-Punkte-Roadmap](ROADMAP.md)


### Captain-Suite-Verbund: Form, Review, Affiliate, Pass und Workflow

Voucher Captain bleibt alleiniger Eigentümer von Gutscheincode, Wert, Ausgabe, Einlösung, Erstattung und Gutschein-PII. Die bestehende Anbindung an WP Pass Captain bleibt die verbindliche Integration für Gutschein → Ticket; Voucher Captain ruft dafür den dokumentierten Pass-Service auf. Form Captain kann später für Firmenanfragen oder Gutschein-Personalisierung verwendet werden. Eine Formulareinsendung erzeugt weder Gutschein noch Zahlung; Ausstellung erfolgt erst nach expliziter Prüfung/Bestätigung durch einen Menschen und über den Voucher-Service. Automatisierungen wie Workflows dürfen nach den Entscheidungen D11/D11b des Inhabers (siehe [TODO.md](TODO.md)) Gutscheine ausstellen, entwerten, reaktivieren und einlösen – aber nur über die vier Gutschein-Befehle des Kerns (`vcap_command_issue()`, `vcap_command_void()`, `vcap_command_reactivate()`, `vcap_command_redeem()`, API.md → „Voucher commands“) mit festen Grenzen, Protokoll und Missbrauchsschutz, ab Werk ausgeschaltet. Erstattungen und andere Geldbewegungen bleiben Menschen vorbehalten.

Review Captain ist ein separater optionaler Adapter. Ein Kauf oder die bloße Ausstellung eines Gutscheins ist kein abgeschlossenes Kundenerlebnis und löst keine Review-Einladung aus. Für Service-/Erlebnisgutscheine kann erst eine bestätigte, idempotente Einlösung als geeigneter Trigger dienen, sofern der Anbieter/Betreiber sie als tatsächliche Leistungserbringung klassifiziert. Refund/Storno, Opt-out, Frequenzlimit und Empfängerauflösung müssen berücksichtigt werden. Allgemeine Rabattcodes, Guthabenaufladungen und Shopbestellungen lösen standardmäßig keine Einladung aus. Review Captain besitzt Einladung, Versand und Review. Ein Adapter darf aus dem Einlöseereignis nur die stabile Referenz (`voucher_id`, `redemption_key`, Zeitpunkt, Kanal) übernehmen: Das PHP-Ereignis `vcap_voucher_redeemed` übergibt heute zusätzlich den Gutscheincode und die Benutzer-ID (siehe Tabelle unten); beides darf den Adapter nicht verlassen.

Affiliate Captain kann Verkauf/Erstattung über seinen bestehenden Verkaufsquellenvertrag verarbeiten. Ein Voucher-Ereignis darf nicht parallel eine zweite Provision neben dem WooCommerce-Auftrag erzeugen; die Quelle und Idempotenz müssen vor Aktivierung des Connectors festgelegt werden. Aggregierte Review-Auswertung ist ein späterer, read-only Ausbau und darf weder Kunden-/Review-PII noch provisionswirksame Änderungen enthalten. Alle Erweiterungen bleiben optional und vermeiden direkte Tabellenzugriffe.

**Stand im Kern (1.2.0, Details in [API.md → Captain Suite](API.md#captain-suite-since-120)):**

- *Review-Quelle v1* (`vcap_review_source_v1`): Nur Gutscheine, deren Produkt „Einlösung bestätigt eine Leistung (für Bewertungseinladungen)“ hat (Standard aus), und nur Einlösungen an der Kasse (Scanner, „Als eingelöst markieren (Laden)“) – externe Einlösungen nur für ausdrücklich registrierte Ziel-Präfixe – melden `vcap_review_redemption_eligible`; Storno der Kasseneinlösung, Erstattung der externen Einlösung oder Annullierung des Gutscheins melden `vcap_review_redemption_revoked`. Stabile Referenz je Einlösung, Wiederholungen ohne Duplikat, Payload ohne Code, Namen, E-Mail und Betrag. Die Adresse gibt nur `recipient()` an Gutschein-/Shopverwalter oder Hintergrundläufe heraus (Opt-out-Filter `vcap_review_opted_out`). Ohne lauschendes Review-Plugin wird nichts gespeichert.
- *Gutschein-Anfragen v1* (`vcap_voucher_request_service_v1`): Ein Formular-Plugin übergibt Firmenbestellung oder Personalisierungswunsch mit `submit()`; daraus entsteht nur eine Anfrage unter *Gutscheine → Gutschein-Anfragen*. Gutscheine entstehen erst, wenn ein Verwalter sie dort ausdrücklich ausstellt (Verkauf oder kostenlos ist bewusst zu wählen) – über den vorhandenen Ausstellweg, idempotent je Gutschein. Keine Zahlung, kein Angebot, kein Lesen fremder Einträge.
- *Affiliate-Deduplizierung* (heutige Regel, [ADAPTERS.md 5](ADAPTERS.md#5-wp-affiliate-captain-partner-programme)): Provision entsteht nur an WooCommerce-Bestellungen, die Affiliate Captain selbst erfasst. Voucher Captain meldet keine eigenen Verkaufs-, Einlösungs-, Review- oder Anfrageereignisse an Affiliate Captain.


- Der Kern kennt weder Lizenzen noch ein „Pro“ eines anderen Plugins. Er prüft höchstens, ob ein Plugin vorhanden und kompatibel ist ([docs/FREE-PREMIUM.md](docs/FREE-PREMIUM.md)).
- Gutscheinzustand, Guthaben und Journal ändern sich nur über die Services von Voucher Captain ([API.md](API.md)), nie durch direkte Tabellenzugriffe.
- Ein künftiger Ereignisumschlag für andere Plugins oder Systeme lässt weg, was die heutigen PHP-Hooks für Code im selben WordPress mitgeben: Gutscheincode, Token und Download-Links, PDF, E-Mail-Adressen, Namen, Nachricht, eigene Feldwerte und – solange die Spezifikation nichts anderes festlegt – die Benutzer-ID. Übrig bleiben stabile IDs (`voucher_id`, `redemption_key`, `order_id`), Status, Betrag, Währung, Kanal, Quelle und Zeitpunkt.
- Die PHP-Hooks selbst bleiben unverändert (Vertrag v1, nur additive Änderungen, [CONNECTORS.md](CONNECTORS.md) → 6.).

**Was der Kern heute ausgibt** (PHP-Actions im selben WordPress; vollständige Liste mit Fundstellen: [docs/HOOKS.md](docs/HOOKS.md); Zeitpunkt nach COMMIT: [CONNECTORS.md](CONNECTORS.md) → „Event timing“). Ein `Voucher`-Objekt enthält immer Code, Token, Käufer- und Empfängerdaten.

| Hook | Seit | Wann | Argumente |
|---|---|---|---|
| `vcap_vouchers_issued` | 1.0.0 | Gutscheine einer Bestellung wurden ausgestellt | `Voucher[]`, `WC_Order` |
| `vcap_voucher_issued_standalone` | 1.2.0 | Gutschein ohne Bestellung ausgestellt (`issue_standalone()`) | `Voucher`, Argumente des Aufrufs |
| `vcap_voucher_created` | 1.0.0 | Gutscheinzeile angelegt | `Voucher` |
| `vcap_voucher_updated` | 1.0.0 | Gutschein gespeichert (jede Änderung) | `Voucher` |
| `vcap_voucher_deleted` | 1.2.0 | Gutschein wieder entfernt, weil die Ausstellung scheiterte | `Voucher` |
| `vcap_voucher_delivered` | 1.0.0 | E-Mail an alle Empfänger zugestellt | `Voucher`, E-Mail-Adressen |
| `vcap_voucher_delivery_given_up` | 1.2.0 | Zustellung nach wiederholten Fehlern aufgegeben | `Voucher`, Zahl der Fehlversuche |
| `vcap_voucher_bounced` | 1.2.0 | Gutschein-E-Mail kam zurück | `Voucher`, Rolle, Grund |
| `vcap_voucher_redeemed` | 1.1.0 | Einlösung abgeschlossen (Shop, Schalter oder Einlöse-API) | Array: `voucher_id`, `code`, `user_id`, `amount`, `currency`, `order_id`, `channel` (`shop`, `counter`, `external`), `committed_at`, `source`, `redemption_key`, `target`; am Schalter zusätzlich `till`, `receipt`; über die Einlöse-API zusätzlich `state`, `balance_after`, `expires_at`, `created_at`, `meta` |
| `vcap_redemption_reserved` | 1.1.0 | Gutschein für eine externe Einlösung reserviert | Array wie bei der Einlöse-API (mit `code`, `user_id`) |
| `vcap_redemption_released` | 1.1.0 | Reservierung freigegeben oder abgelaufen | dasselbe Array, `bool $expired` |
| `vcap_redemption_refunded` | 1.1.0 | Abgeschlossene externe Einlösung ganz oder teilweise erstattet | dasselbe Array, zurückgebuchter Betrag |
| `vcap_redemption_reversed` | 1.2.0 | Schaltereinlösung storniert | Gutschein-ID, Journal-ID, Betrag, Grund |
| `vcap_voucher_event` | 1.0.0 | Journaleintrag geschrieben, z. B. `issued`, `redeemed`, `voided`, `reactivated`, `expired`, `delivery_failed` | Gutschein-ID, Ereignis, Betrag, Restwert, Notiz als Text, Bestell-ID, Quelle, Benutzer-ID (die letzten beiden seit 1.2.0) |
| `vcap_voucher_expired` | 1.2.0 | Tägliche Wartung hat abgelaufene Gutscheine markiert | `Voucher[]` (je Stapel) |
| `vcap_voucher_reissued` | 1.2.0 | Gutschein mit neuem Code neu ausgestellt | `Voucher`, alter Code |
| `vcap_store_credit_changed` | 1.1.0 | Kundenguthaben geändert | Benutzer-ID, Betrag, Saldo, Ereignis, Grund, Bestell-ID |
| `vcap_gift_card_topped_up` | 1.2.0 | Geschenkkarte durch eine Bestellung aufgeladen | `Voucher`, Betrag, `WC_Order`, Bestellposition |

Für Widerruf und Reaktivierung gibt es kein eigenes Ereignis; sie erscheinen als `voided` bzw. `reactivated` in `vcap_voucher_event` und als `vcap_voucher_updated`. Eine Ereignis-ID, ein Ausgangspuffer (Outbox) und eine Zustellung an andere Systeme (Webhooks) gibt es im Kern nicht.

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
