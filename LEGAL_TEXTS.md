# Rechtlich relevante Kundentexte – Prüfvorlage je Land

Erzeugt mit `php bin/legal-texts.php` aus `languages/`, `config/markets.php`, `config/country-profiles.php` und
`config/settings.php` (nicht von Hand bearbeiten; CI prüft, dass die Vorlage aktuell ist und jeder Text noch existiert).
Prüfung durch die Rechtsberatung je Verkaufsland. Kein Wert dieser Vorlage ist rechtlich geprüft, solange die
Spalte „Rechtlich geprüft“ nicht abgehakt ist.

## 1. Texte, die der Shop selbst festlegt (Einstellungen, je Shop prüfen)

| Was | Wo | Wirkung |
|---|---|---|
| Einlösebedingungen (allgemein) | WooCommerce → Einstellungen → Gutscheine → „Default redemption conditions“ | stehen auf Gutschein/PDF (`{redemption_terms}`), wenn das Produkt keine eigenen hat |
| Einlösebedingungen pro Land | ebenda → „Redemption conditions per country“: Tabelle mit einer Zeile je Land und Sprache (Spalten **Land**, **Sprache** – „All languages“ oder ein Sprachcode wie `de_AT` –, **Text**) | Rechnungsland entscheidet, innerhalb des Landes die Sprache des Kunden (sonst die Zeile „All languages“); Text wird am Gutschein festgeschrieben |
| Gesetzliche Gültigkeit pro Land | ebenda → „Statutory validity per country“: Tabelle mit einer Zeile je Land (Spalten **Land**, **Jahre** – 0 = unbegrenzt –, **zum Jahresende**, **Quelle/Notiz**) | Ablaufdatum neuer Gutscheine mit gesetzlicher Gültigkeit; überschreibt die Standardwerte aus Abschnitt 2 |
| Bedingungen je Produkt | Produkt → Gutschein → „Redemption conditions“ | ersetzt die allgemeinen Bedingungen |
| Restwert gekaufter Einmal-Wertgutscheine | Produkt → Gutschein → „Keep the rest“ (Standard aus) | aus: der nicht genutzte Rest verfällt, der Kunde wird im Warenkorb gewarnt (Text unten „Restwert“; Verfallklausel in AGB nach § 307 BGB bzw. ABGB prüfen); an: der Rest wird Kundenguthaben bzw. eine neue Geschenkkarte (TAX.md 4.3) |

## 2. Gesetzliche Gültigkeit (Standardwerte, `config/markets.php`)

| Land | Regel | Quelle | Rechtlich geprüft |
|---|---|---|---|
| AT | 30 Jahre | OGH-Rechtsprechung (30 Jahre) | ☐ |
| BE | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| BG | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| CY | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| CZ | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| DE | 3 Jahre zum Jahresende | §§ 195, 199 BGB (3 Jahre zum Jahresende) | ☐ |
| DK | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| EE | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| ES | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| FI | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| FR | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| GR | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| HR | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| HU | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| IE | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| IT | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| LT | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| LU | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| LV | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| MT | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| NL | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| PL | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| PT | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| RO | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| SE | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| SI | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| SK | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| CH | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| GB | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| LI | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |
| NO | – (Standard: 3 Jahre zum Jahresende) | nicht geprüft | ☐ |

## 3. Vorschläge der Länderprofile (`config/country-profiles.php`)

Ein Länderprofil (Einstellungen → Gutscheine → Allgemein → „Country profile“) ändert nichts von selbst: Es zeigt
„aktuell → vorgeschlagen“, übernommen wird nur, was der Admin abhakt. Werte mit rechtlichem Gewicht tragen den
Hinweis „Rechtsprüfung“, bis die Prüfung im Profil vermerkt ist (`checked`). EU und OTHER sind neutral.

| Profil | Gesetzliche Gültigkeit (Zeile) | Gültigkeit ohne eigene Angabe | Belegnummer an der Kasse | Empfängerdaten anonymisieren nach | Rechtlich geprüft |
|---|---|---|---|---|---|
| DE | 3 Jahre zum Jahresende – Quelle: §§ 195, 199 BGB (3 Jahre zum Jahresende) | gesetzlich (Zeile links) | Pflicht (Länderdaten `fiscal_receipt`) | 1095 Tage nach Ablauf/Einlösung | ☐ nicht geprüft |
| AT | 30 Jahre – Quelle: OGH-Rechtsprechung (30 Jahre) | gesetzlich (Zeile links) | Pflicht (Länderdaten `fiscal_receipt`) | 1095 Tage nach Ablauf/Einlösung | ☐ nicht geprüft |
| CH | 10 Jahre – Notiz: Art. 127 OR (10 Jahre) | gesetzlich (Zeile links) | – (kein Vorschlag) | 1095 Tage nach Ablauf/Einlösung | ☐ nicht geprüft |
| EU | – (kein Vorschlag) | – (bleibt) | – (bleibt) | – (bleibt) | ☐ nicht geprüft |
| OTHER | – (kein Vorschlag) | – (bleibt) | – (bleibt) | – (bleibt) | ☐ nicht geprüft |

## 4. Aufbewahrungsfristen (Einstellungen → Gutscheine → „Privacy & advanced“ → „Retention“)

Standardwerte aus `config/settings.php`; ein Filter gleichen Namens gewinnt. Ob eine Frist zu Datenschutz- und
Aufbewahrungspflichten des Verkaufslandes passt, ist je Land zu prüfen (der Vorschlag der Länderprofile steht in
Abschnitt 3).

| Option | Was | Standard | Bereich | Rechtlich geprüft |
|---|---|---|---|---|
| `vcap_retention_days` | Empfängerdaten (Name, E-Mail, Nachricht, eigene Felder) anonymisieren nach | 0 (Tage nach Ablauf oder vollständiger Einlösung; 0 = behalten) | 0 – offen | ☐ |
| `vcap_voucher_requests_open_months` | Offene Gutscheinanfragen (Form Captain) löschen nach | 6 (Monate ab Eingang bzw. letzter Bestätigung) | 1 – 120 | ☐ |
| `vcap_voucher_requests_closed_months` | Erledigte Gutscheinanfragen löschen nach | 24 (Monate ab Ausgabe bzw. Übernahme) | 1 – 120 | ☐ |
| `vcap_review_retention_months` | Notizen zu Bewertungseinladungen löschen nach | 24 (Monate ab Einlösung) | 1 – 120 | ☐ |
| `vcap_review_revoked_retention_days` | Zurückgezogene Bewertungsnotizen löschen nach | 90 (Tage) | 30 – 3650 | ☐ |
| `vcap_command_log_days` | Protokoll der Gutscheinbefehle (ohne Codes) behalten | 90 (Tage) | 30 – 3650 | ☐ |
| `vcap_scanner_failed_lookup_days` | Fehlgeschlagene Kassenabfragen je Mitarbeiter behalten | 92 (Tage) | 31 – 3650 | ☐ |

## 5. Feste Texte des Plugins

### Gültigkeit und Ablauf

| Englisch (Quelltext) | Deutsch (DE, du) | Deutsch (DE, Sie) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|---|
| Valid until %s | Gültig bis %s | Gültig bis %s | Gültig bis %s | src/Placeholders.php:107 templates/emails/plain/voucher-bundle.php:46 | ☐ |
| Valid until: %s | Gültig bis: %s | Gültig bis: %s | Gültig bis: %s | src/Downloads.php:1025 | ☐ |
| unlimited | unbegrenzt | unbegrenzt | unbegrenzt | src/Downloads.php:1025 src/Placeholders.php:105 | ☐ |
| no expiry | unbegrenzt | unbegrenzt | unbegrenzt | src/Emails/BonusEmail.php:109 | ☐ |
| Your voucher from {site_title} expires on {expiry_date} | Dein Gutschein von {site_title} läuft am {expiry_date} ab | Ihr Gutschein von {site_title} läuft am {expiry_date} ab | Ihr Gutschein von {site_title} läuft am {expiry_date} ab | src/Emails/ExpiryReminderEmail.php:24 | ☐ |
| Your voucher {coupon_code} is valid until {expiry_date}. Remaining value: {coupon_balance}. | Dein Gutschein {coupon_code} ist bis {expiry_date} gültig. Restwert: {coupon_balance}. | Ihr Gutschein {coupon_code} ist bis {expiry_date} gültig. Restwert: {coupon_balance}. | Ihr Gutschein {coupon_code} ist bis {expiry_date} gültig. Restwert: {coupon_balance}. | src/Emails/ExpiryReminderEmail.php:32 | ☐ |
| This voucher has expired. | Dieser Gutschein ist abgelaufen. | Dieser Gutschein ist abgelaufen. | Dieser Gutschein ist abgelaufen. | src/API/V1/RedemptionService.php:812 src/Coupons/CartCoupons.php:374 | ☐ |

### Übertragbarkeit

| Englisch (Quelltext) | Deutsch (DE, du) | Deutsch (DE, Sie) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|---|
| Transferable – anyone with the code can redeem it. | Übertragbar – jede Person mit dem Code kann ihn einlösen. | Übertragbar – jede Person mit dem Code kann ihn einlösen. | Übertragbar – jede Person mit dem Code kann ihn einlösen. | src/ProductConfig.php:190 src/Voucher.php:240 | ☐ |
| Personal – only the buyer or the named recipient can redeem it. | Persönlich – nur Käufer oder genannte Empfänger können ihn einlösen. | Persönlich – nur Käufer oder genannte Empfänger können ihn einlösen. | Persönlich – nur Käufer oder genannte Empfänger können ihn einlösen. | src/ProductConfig.php:188 src/Voucher.php:241 | ☐ |
| Personal – only the named recipient can redeem it. | Persönlich – nur die genannte beschenkte Person kann ihn einlösen. | Persönlich – nur die genannte beschenkte Person kann ihn einlösen. | Persönlich – nur die genannte beschenkte Person kann ihn einlösen. | src/ProductConfig.php:186 src/Voucher.php:249 | ☐ |
| Personal – only the buyer can redeem it. | Persönlich – nur der Käufer kann ihn einlösen. | Persönlich – nur der Käufer kann ihn einlösen. | Persönlich – nur der Käufer kann ihn einlösen. | src/ProductConfig.php:184 src/Voucher.php:252 | ☐ |

### Restwert

| Englisch (Quelltext) | Deutsch (DE, du) | Deutsch (DE, Sie) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|---|
| Balance: %s | Restwert: %s | Restwert: %s | Restwert: %s | src/Downloads.php:1019 templates/myaccount/vouchers-table.php:50 | ☐ |
| Remaining balance | Restwert | Restwert | Restwert | src/Placeholders.php:20 | ☐ |
| Voucher %1$s: only %2$s of %3$s is used in this order. The voucher can be redeemed only once – the remaining %4$s will be lost. Add more items to use its full value. | Gutschein %1$s: In dieser Bestellung werden nur %2$s von %3$s angerechnet. Der Gutschein kann nur einmal eingelöst werden – der Restwert von %4$s verfällt. Lege weitere Artikel in den Warenkorb, um den vollen Wert zu nutzen. | Gutschein %1$s: In dieser Bestellung werden nur %2$s von %3$s angerechnet. Der Gutschein kann nur einmal eingelöst werden – der Restwert von %4$s verfällt. Legen Sie weitere Artikel in den Warenkorb, um den vollen Wert zu nutzen. | Gutschein %1$s: In dieser Bestellung werden nur %2$s von %3$s angerechnet. Der Gutschein kann nur einmal eingelöst werden – der Restwert von %4$s verfällt. Legen Sie weitere Artikel in den Warenkorb, um den vollen Wert zu nutzen. | src/ProductChecks.php:244 | ☐ |

### Erstattung und Widerruf

| Englisch (Quelltext) | Deutsch (DE, du) | Deutsch (DE, Sie) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|---|
| Vouchers from this order were already used (%1$s, value used: %2$s). At most %3$s can be refunded; the unused vouchers are voided. | Gutscheine aus dieser Bestellung wurden bereits verwendet (%1$s, verbrauchter Wert: %2$s). Erstattet werden können höchstens %3$s; die unverbrauchten Gutscheine werden entwertet. | Gutscheine aus dieser Bestellung wurden bereits verwendet (%1$s, verbrauchter Wert: %2$s). Erstattet werden können höchstens %3$s; die unverbrauchten Gutscheine werden entwertet. | Gutscheine aus dieser Bestellung wurden bereits verwendet (%1$s, verbrauchter Wert: %2$s). Erstattet werden können höchstens %3$s; die unverbrauchten Gutscheine werden entwertet. | src/Issuer.php:1400 | ☐ |
| %s refunded as store credit. | %s als Kundenguthaben erstattet. | %s als Kundenguthaben erstattet. | %s als Kundenguthaben erstattet. | src/StoreCredit.php:485 | ☐ |
| This voucher has been cancelled. | Dieser Gutschein wurde storniert. | Dieser Gutschein wurde storniert. | Dieser Gutschein wurde storniert. | src/API/V1/RedemptionService.php:696 src/API/V1/RedemptionService.php:799 | ☐ |

### Einlösung

| Englisch (Quelltext) | Deutsch (DE, du) | Deutsch (DE, Sie) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|---|
| Enter the code in the cart or at checkout on %s to redeem it. | Gib den Code im Warenkorb oder an der Kasse auf %s ein, um ihn einzulösen. | Geben Sie den Code im Warenkorb oder an der Kasse auf %s ein, um ihn einzulösen. | Geben Sie den Code im Warenkorb oder an der Kasse auf %s ein, um ihn einzulösen. | src/Channels.php:72 | ☐ |
| This voucher has already been fully redeemed. | Dieser Gutschein wurde bereits vollständig eingelöst. | Dieser Gutschein wurde bereits vollständig eingelöst. | Dieser Gutschein wurde bereits vollständig eingelöst. | src/Coupons/CartCoupons.php:82 | ☐ |

## 6. Freigabe

| Land | Geprüft von | Datum | Ergebnis |
|---|---|---|---|
| DE | | | ☐ freigegeben ☐ mit Änderungen |
| AT | | | ☐ freigegeben ☐ mit Änderungen |
| CH | | | ☐ freigegeben ☐ mit Änderungen |
| | | | |
