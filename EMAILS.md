# E-Mail-Texte – Freigabeliste

Erzeugt mit `php bin/email-texts.php` aus `languages/` (nicht von Hand bearbeiten; CI prüft, dass die Liste aktuell ist).
Alle Texte der Kunden-E-Mails von Voucher Captain mit Betreff, Überschrift, Inhalt und der Beschreibung, die Shop-Betreiber unter
WooCommerce → Einstellungen → E-Mails sehen. Platzhalter in `{…}` und `%s` werden beim Versand ersetzt. Shops können Betreff,
Überschrift und Zusatztext je E-Mail in WooCommerce überschreiben; diese Liste zeigt die Standardtexte.

Freigabe für Release: ____ · Datum: ____ · Geprüft von: ____

## `src/Emails/BonusEmail.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Promotion | Aktion | Aktion |
| Bonus voucher | Bonusgutschein | Bonusgutschein |
| Sent when an order earns a bonus voucher from a promotion (Vouchers → Promotions, discount "Bonus voucher after the order"). | Wird gesendet, wenn eine Bestellung einen Bonusgutschein aus einer Aktion erhält (Gutscheine → Aktionen, Rabatt „Bonusgutschein nach der Bestellung“). | Wird gesendet, wenn eine Bestellung einen Bonusgutschein aus einer Aktion erhält (Gutscheine → Aktionen, Rabatt „Bonusgutschein nach der Bestellung“). |
| A bonus voucher from {site_title} for you | Ein Bonusgutschein von {site_title} für Sie | Ein Bonusgutschein von {site_title} für Sie |
| Thank you – here is your bonus | Danke – hier ist Ihr Bonus | Danke – hier ist Ihr Bonus |
| Thank you for your order {order_number}. As a thank-you you receive {coupon_amount} off your next order with the code below. Valid until {expiry_date}. | Vielen Dank für Ihre Bestellung {order_number}. Als Dankeschön erhalten Sie mit dem folgenden Code {coupon_amount} Rabatt auf Ihre nächste Bestellung. Gültig bis {expiry_date}. | Vielen Dank für Ihre Bestellung {order_number}. Als Dankeschön erhalten Sie mit dem folgenden Code {coupon_amount} Rabatt auf Ihre nächste Bestellung. Gültig bis {expiry_date}. |
| no expiry | unbegrenzt | unbegrenzt |
| Available placeholders: %s | Verfügbare Platzhalter: %s | Verfügbare Platzhalter: %s |
| Email body | E-Mail-Text | E-Mail-Text |

## `src/Emails/BuyerEmail.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Voucher (buyer) | Gutschein (Käufer) | Gutschein (Käufer) |
| Sent to the customer with their purchased voucher, gift card or ticket. | Wird mit dem gekauften Gutschein, der Geschenkkarte oder dem Ticket an den Kunden gesendet. | Wird mit dem gekauften Gutschein, der Geschenkkarte oder dem Ticket an den Kunden gesendet. |
| Your gift for {recipient_name} is on its way | Ihr Geschenk für {recipient_name} ist unterwegs | Ihr Geschenk für {recipient_name} ist unterwegs |
| Your gift from {site_title} is on its way | Ihr Geschenk von {site_title} ist unterwegs | Ihr Geschenk von {site_title} ist unterwegs |
| Your voucher from {site_title} | Ihr Gutschein von {site_title} | Ihr Gutschein von {site_title} |
| Your voucher {coupon_code} | Ihr Gutschein {coupon_code} | Ihr Gutschein {coupon_code} |
| Hi {buyer_name}, thank you for your order #{order_number}. Your voucher for {product_name} is ready. | Hallo {buyer_name}, vielen Dank für Ihre Bestellung #{order_number}. Ihr Gutschein für {product_name} ist bereit. | Hallo {buyer_name}, vielen Dank für Ihre Bestellung #{order_number}. Ihr Gutschein für {product_name} ist bereit. |
| Hi {buyer_name}, your gift for {recipient_name} is on its way to {recipient_email}. This copy is for your records. | Hallo {buyer_name}, Ihr Geschenk für {recipient_name} ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. | Hallo {buyer_name}, Ihr Geschenk für {recipient_name} ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. |
| Hi {buyer_name}, your gift is on its way to {recipient_email}. This copy is for your records. | Hallo {buyer_name}, Ihr Geschenk ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. | Hallo {buyer_name}, Ihr Geschenk ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. |
| Hello, your gift for {recipient_name} is on its way to {recipient_email}. This copy is for your records. | Hallo, Ihr Geschenk für {recipient_name} ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. | Hallo, Ihr Geschenk für {recipient_name} ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. |
| Hello, your gift is on its way to {recipient_email}. This copy is for your records. | Hallo, Ihr Geschenk ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. | Hallo, Ihr Geschenk ist unterwegs an {recipient_email}. Diese Kopie ist für Ihre Unterlagen. |
| Hello, thank you for your order #{order_number}. Your voucher for {product_name} is ready. | Hallo, vielen Dank für Ihre Bestellung #{order_number}. Ihr Gutschein für {product_name} ist bereit. | Hallo, vielen Dank für Ihre Bestellung #{order_number}. Ihr Gutschein für {product_name} ist bereit. |
| Hi {buyer_name}, your voucher for {product_name} is ready. | Hallo {buyer_name}, Ihr Gutschein für {product_name} ist bereit. | Hallo {buyer_name}, Ihr Gutschein für {product_name} ist bereit. |
| Hello, your voucher for {product_name} is ready. | Hallo, Ihr Gutschein für {product_name} ist bereit. | Hallo, Ihr Gutschein für {product_name} ist bereit. |
| Hi {buyer_name}, your voucher is ready. | Hallo {buyer_name}, Ihr Gutschein ist bereit. | Hallo {buyer_name}, Ihr Gutschein ist bereit. |
| Hello, your voucher is ready. | Hallo, Ihr Gutschein ist bereit. | Hallo, Ihr Gutschein ist bereit. |

## `src/Emails/ExpiryReminderEmail.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Voucher expiry reminder | Erinnerung vor Gutschein-Ablauf | Erinnerung vor Gutschein-Ablauf |
| Sent once, a configurable number of days before a voucher with remaining value expires (WooCommerce → Settings → Vouchers → Delivery & downloads). | Wird einmalig eine einstellbare Anzahl Tage vor dem Ablauf eines Gutscheins mit Restwert versendet (WooCommerce → Einstellungen → Gutscheine → Zustellung & Downloads). | Wird einmalig eine einstellbare Anzahl Tage vor dem Ablauf eines Gutscheins mit Restwert versendet (WooCommerce → Einstellungen → Gutscheine → Zustellung & Downloads). |
| Your voucher from {site_title} expires on {expiry_date} | Ihr Gutschein von {site_title} läuft am {expiry_date} ab | Ihr Gutschein von {site_title} läuft am {expiry_date} ab |
| Don't forget your voucher | Vergessen Sie Ihren Gutschein nicht | Vergessen Sie Ihren Gutschein nicht |
| Your voucher {coupon_code} is valid until {expiry_date}. Remaining value: {coupon_balance}. | Ihr Gutschein {coupon_code} ist bis {expiry_date} gültig. Restwert: {coupon_balance}. | Ihr Gutschein {coupon_code} ist bis {expiry_date} gültig. Restwert: {coupon_balance}. |

## `src/Emails/RecipientEmail.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Voucher (gift recipient) | Gutschein (Geschenkempfänger) | Gutschein (Geschenkempfänger) |
| Sent to the gift recipient entered on the product page, at the scheduled delivery time. | Wird zum geplanten Zustellzeitpunkt an den auf der Produktseite eingegebenen Geschenkempfänger gesendet. | Wird zum geplanten Zustellzeitpunkt an den auf der Produktseite eingegebenen Geschenkempfänger gesendet. |
| You received a gift from {site_title} | Sie haben ein Geschenk von {site_title} erhalten | Sie haben ein Geschenk von {site_title} erhalten |
| {buyer_name} sent you a gift from {site_title} | {buyer_name} hat Ihnen ein Geschenk von {site_title} gesendet | {buyer_name} hat Ihnen ein Geschenk von {site_title} gesendet |
| You received a gift! | Sie haben ein Geschenk erhalten! | Sie haben ein Geschenk erhalten! |
| Hi {recipient_name}, {buyer_name} sent you a voucher worth {coupon_value}. | Hallo {recipient_name}, {buyer_name} hat Ihnen einen Gutschein im Wert von {coupon_value} gesendet. | Hallo {recipient_name}, {buyer_name} hat Ihnen einen Gutschein im Wert von {coupon_value} gesendet. |
| Hi {recipient_name}, you received a voucher worth {coupon_value} as a gift. | Hallo {recipient_name}, Sie haben einen Gutschein im Wert von {coupon_value} geschenkt bekommen. | Hallo {recipient_name}, Sie haben einen Gutschein im Wert von {coupon_value} geschenkt bekommen. |
| Hello, {buyer_name} sent you a voucher worth {coupon_value}. | Hallo, {buyer_name} hat Ihnen einen Gutschein im Wert von {coupon_value} geschenkt. | Hallo, {buyer_name} hat Ihnen einen Gutschein im Wert von {coupon_value} geschenkt. |
| Hello, you received a voucher worth {coupon_value} as a gift. | Hallo, Sie haben einen Gutschein im Wert von {coupon_value} geschenkt bekommen. | Hallo, Sie haben einen Gutschein im Wert von {coupon_value} geschenkt bekommen. |
| Hi {recipient_name}, {buyer_name} sent you a voucher for {coupon_value} off. | Hallo {recipient_name}, {buyer_name} hat Ihnen einen Gutschein über {coupon_value} Rabatt geschenkt. | Hallo {recipient_name}, {buyer_name} hat Ihnen einen Gutschein über {coupon_value} Rabatt geschenkt. |
| Hi {recipient_name}, you received a voucher for {coupon_value} off as a gift. | Hallo {recipient_name}, Sie haben einen Gutschein über {coupon_value} Rabatt geschenkt bekommen. | Hallo {recipient_name}, Sie haben einen Gutschein über {coupon_value} Rabatt geschenkt bekommen. |
| Hello, {buyer_name} sent you a voucher for {coupon_value} off. | Hallo, {buyer_name} hat Ihnen einen Gutschein über {coupon_value} Rabatt geschenkt. | Hallo, {buyer_name} hat Ihnen einen Gutschein über {coupon_value} Rabatt geschenkt. |
| Hello, you received a voucher for {coupon_value} off as a gift. | Hallo, Sie haben einen Gutschein über {coupon_value} Rabatt geschenkt bekommen. | Hallo, Sie haben einen Gutschein über {coupon_value} Rabatt geschenkt bekommen. |
| Hi {recipient_name}, {buyer_name} sent you {product_name}. | Hallo {recipient_name}, {buyer_name} hat Ihnen {product_name} geschenkt. | Hallo {recipient_name}, {buyer_name} hat Ihnen {product_name} geschenkt. |
| Hi {recipient_name}, you received {product_name} as a gift. | Hallo {recipient_name}, Sie haben {product_name} geschenkt bekommen. | Hallo {recipient_name}, Sie haben {product_name} geschenkt bekommen. |
| Hello, {buyer_name} sent you {product_name}. | Hallo, {buyer_name} hat Ihnen {product_name} geschenkt. | Hallo, {buyer_name} hat Ihnen {product_name} geschenkt. |
| Hello, you received {product_name} as a gift. | Hallo, Sie haben {product_name} geschenkt bekommen. | Hallo, Sie haben {product_name} geschenkt bekommen. |

## `src/Emails/ReferralEmail.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Referral reward | Empfehlungsprämie | Empfehlungsprämie |
| Sent to a customer when a friend they referred completed an order (Refer a friend, reward as coupon). | Wird an Kundinnen und Kunden gesendet, wenn eine von ihnen geworbene Person eine Bestellung abgeschlossen hat (Freunde werben, Prämie als Gutschein). | Wird an Kundinnen und Kunden gesendet, wenn eine von ihnen geworbene Person eine Bestellung abgeschlossen hat (Freunde werben, Prämie als Gutschein). |
| Thank you for recommending {site_title} | Danke, dass Sie {site_title} empfehlen | Danke, dass Sie {site_title} empfehlen |
| Your friend ordered – here is your reward | Ihre Empfehlung hat sich gelohnt – hier ist Ihre Prämie | Ihre Empfehlung hat sich gelohnt – hier ist Ihre Prämie |
| A friend you referred has placed an order. As a thank-you you receive {coupon_amount} off your next order with the code below. Valid until {expiry_date}. | Eine von Ihnen geworbene Person hat bestellt. Als Dankeschön erhalten Sie mit dem folgenden Code {coupon_amount} Rabatt auf Ihre nächste Bestellung. Gültig bis {expiry_date}. | Eine von Ihnen geworbene Person hat bestellt. Als Dankeschön erhalten Sie mit dem folgenden Code {coupon_amount} Rabatt auf Ihre nächste Bestellung. Gültig bis {expiry_date}. |

## `src/Emails/VoucherEmail.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Additional recipients | Zusätzliche Empfänger | Zusätzliche Empfänger |
| Comma separated addresses that each receive their own copy of this email (e.g. for your records). Whether the voucher counts as delivered depends only on the customer's address. | Kommagetrennte Adressen, die jeweils eine eigene Kopie dieser E-Mail erhalten (z. B. für Ihre Unterlagen). Ob der Gutschein als zugestellt gilt, hängt nur von der Adresse des Kunden ab. | Kommagetrennte Adressen, die jeweils eine eigene Kopie dieser E-Mail erhalten (z. B. für Ihre Unterlagen). Ob der Gutschein als zugestellt gilt, hängt nur von der Adresse des Kunden ab. |
| Attach PDF | PDF anhängen | PDF anhängen |
| Attach the voucher PDF | Gutschein-PDF anhängen | Gutschein-PDF anhängen |

## `templates/emails/plain/voucher.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Value: %s | Wert: %s | Wert: %s |
| What happens next | So geht es weiter | So geht es weiter |
| Code: %s | Code: %s | Code: %s |
| Valid until %s | Gültig bis %s | Gültig bis %s |
| Download: | Download: | Download: |
| Redeem now: | Jetzt einlösen: | Jetzt einlösen: |

## `templates/emails/voucher.php`

| Englisch (Quelltext) | Deutsch (DE) | Deutsch (AT) |
|---|---|---|
| Your code | Ihr Code | Ihr Code |
| Download ticket (PDF) | Ticket herunterladen (PDF) | Ticket herunterladen (PDF) |
| Download voucher (PDF) | Gutschein herunterladen (PDF) | Gutschein herunterladen (PDF) |
| Redeem now | Jetzt einlösen | Jetzt einlösen |
| Enter the code in the cart or at checkout on %s to redeem it. | Geben Sie den Code im Warenkorb oder an der Kasse auf %s ein, um ihn einzulösen. | Geben Sie den Code im Warenkorb oder an der Kasse auf %s ein, um ihn einzulösen. |

67 Texte.
