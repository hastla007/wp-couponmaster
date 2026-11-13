# Rechtlich relevante Kundentexte – Prüfvorlage je Land

Erzeugt mit `php bin/legal-texts.php` aus `languages/` und `config/markets.php` (nicht von Hand bearbeiten; CI prüft,
dass die Vorlage aktuell ist und jeder Text noch existiert). Prüfung durch die Rechtsberatung je Verkaufsland.

## 1. Texte, die der Shop selbst festlegt (Einstellungen, je Shop prüfen)

| Was | Wo | Wirkung |
|---|---|---|
| Einlösebedingungen (allgemein) | WooCommerce → Einstellungen → Gutscheine → „Default redemption conditions“ | stehen auf Gutschein/PDF (`{redemption_terms}`), wenn das Produkt keine eigenen hat |
| Einlösebedingungen pro Land | ebenda → „Redemption conditions per country“ (`AT: …`) | Rechnungsland entscheidet; Text wird am Gutschein festgeschrieben |
| Gesetzliche Gültigkeit pro Land | ebenda → „Statutory validity per country“ (`DE: 3 end`) | Ablaufdatum neuer Gutscheine mit gesetzlicher Gültigkeit |
| Bedingungen je Produkt | Produkt → Gutschein → „Redemption conditions“ | ersetzt die allgemeinen Bedingungen |

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

## 3. Feste Texte des Plugins

### Gültigkeit und Ablauf

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|
| Valid until %s | Gültig bis %s | Gültig bis %s | templates/emails/plain/voucher.php:29 templates/emails/voucher.php:36 | ☐ |
| Valid until: %s | Gültig bis: %s | Gültig bis: %s | src/Downloads.php:336 | ☐ |
| unlimited | unbegrenzt | unbegrenzt | src/Downloads.php:336 src/Placeholders.php:99 | ☐ |
| no expiry | unbegrenzt | unbegrenzt | src/Emails/BonusEmail.php:78 | ☐ |
| Your voucher from {site_title} expires on {expiry_date} | Ihr Gutschein von {site_title} läuft am {expiry_date} ab | Ihr Gutschein von {site_title} läuft am {expiry_date} ab | src/Emails/ExpiryReminderEmail.php:24 | ☐ |
| Your voucher {coupon_code} is valid until {expiry_date}. Remaining value: {coupon_balance}. | Ihr Gutschein {coupon_code} ist bis {expiry_date} gültig. Restwert: {coupon_balance}. | Ihr Gutschein {coupon_code} ist bis {expiry_date} gültig. Restwert: {coupon_balance}. | src/Emails/ExpiryReminderEmail.php:32 | ☐ |
| This voucher has expired. | Dieser Gutschein ist abgelaufen. | Dieser Gutschein ist abgelaufen. | src/API/V1/RedemptionService.php:642 src/CouponManager.php:1007 | ☐ |

### Übertragbarkeit

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|
| Transferable – anyone with the code can redeem it. | Übertragbar – jede Person mit dem Code kann ihn einlösen. | Übertragbar – jede Person mit dem Code kann ihn einlösen. | src/ProductConfig.php:162 src/Voucher.php:198 | ☐ |
| Personal – only the buyer or the named recipient can redeem it. | Persönlich – nur Käufer oder genannte Empfänger können ihn einlösen. | Persönlich – nur Käufer oder genannte Empfänger können ihn einlösen. | src/ProductConfig.php:160 src/Voucher.php:199 | ☐ |
| Personal, not transferable | Persönlich, nicht übertragbar | Persönlich, nicht übertragbar | templates/myaccount/vouchers-table.php:67 | ☐ |

### Restwert

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|
| Balance: %s | Restwert: %s | Restwert: %s | src/Downloads.php:330 templates/myaccount/vouchers-table.php:45 | ☐ |
| Remaining balance | Restwert | Restwert | src/Placeholders.php:20 | ☐ |

### Erstattung und Widerruf

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|
| Vouchers from this order were already used (%1$s, value used: %2$s). At most %3$s can be refunded; the unused vouchers are voided. | Gutscheine aus dieser Bestellung wurden bereits verwendet (%1$s, verbrauchter Wert: %2$s). Erstattet werden können höchstens %3$s; die unverbrauchten Gutscheine werden entwertet. | Gutscheine aus dieser Bestellung wurden bereits verwendet (%1$s, verbrauchter Wert: %2$s). Erstattet werden können höchstens %3$s; die unverbrauchten Gutscheine werden entwertet. | src/Issuer.php:679 | ☐ |
| %s refunded as store credit. | %s als Kundenguthaben erstattet. | %s als Kundenguthaben erstattet. | src/StoreCredit.php:276 | ☐ |
| This voucher has been cancelled. | Dieser Gutschein wurde storniert. | Dieser Gutschein wurde storniert. | src/API/V1/RedemptionService.php:538 src/API/V1/RedemptionService.php:629 | ☐ |

### Einlösung

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) | Fundstelle | Freigabe |
|---|---|---|---|---|
| Enter the code in the cart or at checkout on %s to redeem it. | Geben Sie den Code im Warenkorb oder an der Kasse auf %s ein, um ihn einzulösen. | Geben Sie den Code im Warenkorb oder an der Kasse auf %s ein, um ihn einzulösen. | templates/emails/voucher.php:94 | ☐ |
| This voucher has already been fully redeemed. | Dieser Gutschein wurde bereits vollständig eingelöst. | Dieser Gutschein wurde bereits vollständig eingelöst. | src/CouponManager.php:1004 | ☐ |

## 4. Freigabe

| Land | Geprüft von | Datum | Ergebnis |
|---|---|---|---|
| DE | | | ☐ freigegeben ☐ mit Änderungen |
| AT | | | ☐ freigegeben ☐ mit Änderungen |
| | | | |
