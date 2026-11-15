# Steuerliche Behandlung von Gutscheinen – Freigabe-Dossier

**Status:** Entwurf zur Prüfung durch die Steuerberatung – **nicht freigegeben**. Stand: 15.11.2026 (Audit-Gremium,
TODO.md M12-03 … M12-69); Abschnitte und Fragen neu nummeriert (M12-32).
**Bezug:** TODO.md, P1 „Steuerliche Zuordnung, Produkttexte und Erstattungsfolgen je Gutscheinart mit Steuerberatung
freigeben lassen“. Folgepunkte, die von den Antworten abhängen: TODO.md Abschnitt „Entscheidungen Steuer“
(Steuerstatus prüfen, Mehrzweck nach Steuer verrechnen; Einzweck-Satz/Land an Pass Captain: Abschnitt 4.2) und
„Audit-Gremium (12 Experten) 15.11.2026“ (M12-03, -10, -12, -27 … -32, -42, -66, -68, -69).
**Sichere Voreinstellung bei offenen Rechtsfragen:** Wo eine Frage (Abschnitt 11) noch offen ist, wählt der Code die
Variante, bei der keine USt verloren geht und kein Kunde Geld verliert; die Frage ist jeweils genannt.
**Rechtsgrundlage, auf die sich der Code bezieht:** Richtlinie (EU) 2016/1065 (Gutschein-Richtlinie), umgesetzt in
DE § 3 Abs. 13–15 UStG, AT § 4 Abs. 3a UStG 1994.

Dieses Dokument beschreibt, **was das Plugin heute tatsächlich tut** – je Gutscheinart beim Verkauf, bei der Einlösung
und bei Erstattungen –, welche Texte der Shop sieht und welche Fragen die Steuerberatung beantworten muss. Jede
Aussage ist durch einen automatisierten Test belegt (Spalte „Nachweis“); ändert sich das Verhalten, schlägt der Test
fehl und dieses Dokument muss angepasst werden.

---

## 1. Begriffe und Einstellungen im Plugin

| Plugin-Wert | Bezeichnung im Produkt (Feld „VAT treatment“) | Gemeinte steuerliche Einordnung |
|---|---|---|
| `payment` | *Multi-purpose voucher (means of payment, VAT on redemption)* | Mehrzweckgutschein: Verkauf nicht steuerbar, USt entsteht bei Einlösung auf die gelieferte Leistung |
| `single` | *Single-purpose voucher (VAT already charged at sale)* | Einzweckgutschein: Leistungsort und geschuldete Steuer stehen beim Verkauf fest, USt beim Verkauf |
| `discount` | *Promotion (price reduction)* | Kein Gutschein im Sinne der Richtlinie: Preisnachlass, mindert die Bemessungsgrundlage |
| `auto` (Standard) | *Automatic (value vouchers: multi-purpose, percentage: discount)* | Ableitung nach Abschnitt 2 |

Code: `src/ProductConfig.php` → `ProductConfig::tax_treatment()`; Produktfeld in `src/ProductSettings.php`.
Shops und Erweiterungen können die Zuordnung je Gutschein mit dem Filter `vcap_voucher_tax_treatment` ändern.

**Einstellung „Mehrzweckgutscheine als Zahlung“** (`vcap_mpv_after_tax`, Abschnitt 4.1): **Neuinstallationen
starten mit „an“** (M12-03, `Install::activate_site()` legt die Option nur an, wenn noch keine Schema-Version
gespeichert ist); bestehende Shops behalten ihre Einstellung (fehlende Option = aus). Nachweis:
`tests/tax-accounting-integration.php` „M12-03: a new installation starts with …“, „M12-03: an existing shop …“.
Die Umstellung bestehender Shops ist Frage F17.

**Steuerstatus des Gutscheinprodukts:** Steuerstatus und Steuerklasse des *Gutscheinprodukts* (WooCommerce-Felder
„Steuerstatus“, „Steuerklasse“) bestimmen, ob beim Verkauf USt berechnet wird. Das Plugin prüft sie gegen die
Einordnung **und** die Einstellung oben (`ProductConfig::tax_status_conflict()`; Warnung im Produkt, im Website-
Zustand und als Hinweis im Produkt-Editor):

| Einordnung | Einstellung | Erwarteter Steuerstatus | Warnung bei Abweichung |
|---|---|---|---|
| Mehrzweck (`payment`) | an | „Keiner“ (Verkauf nicht steuerbar, USt bei Einlösung auf die volle Leistung) | „Steuerstatus auf ‚Keiner‘ setzen – oder Einzweck wählen“; Website-Zustand „empfohlen“ |
| Mehrzweck (`payment`) | aus | „Steuerpflichtig“ (USt beim Verkauf; die Einlösung ist ein Preisnachlass) | bei „Keiner“: **„Einstellung einschalten oder Produkt steuerpflichtig lassen“**, Website-Zustand **kritisch** – der Wert würde weder beim Verkauf noch bei der Einlösung versteuert |
| Einzweck (`single`) | egal | „Steuerpflichtig“ mit der Steuerklasse der späteren Leistung | „Steuerstatus auf ‚Steuerpflichtig‘ setzen“; zusätzlich bei Verkauf in mehrere Länder (WooCommerce „Verkaufsorte“): „Land und USt der Einlösung stehen beim Verkauf nicht fest“ (M12-28, F3/F14) |

Nachweis: `tests/tax-accounting-integration.php` „M12-03: setting off + multi-purpose product "None" …“,
„M12-03: Site Health is critical …“, „M12-03: setting off + multi-purpose product kept taxable: no warning …“,
„M12-03: setting on: a taxable multi-purpose product …“, „M12-28: a single-purpose product in a shop that sells to
several countries is warned about“. Die Ersteinrichtung (Checkliste) enthält einen Hinweis zu dieser Kopplung
(„M12-03: the onboarding checklist has a VAT hint …“).

## 2. Zuordnung je Gutscheinart (Einstellung „Automatisch“)

| Nr. | Gutscheinart | Wie entsteht er | Automatische Einordnung | Nachweis (`tests/tax-integration.php`) |
|---|---|---|---|---|
| 2.1 | **Wertgutschein** (Typ „Gutschein“, Festbetrag, einmalig) | verkauft als Produkt | `payment` (Mehrzweck) | „auto: a sold value voucher …“ |
| 2.2 | **Geschenkkarte** (Typ „Geschenkkarte“, Restguthaben) | verkauft als Produkt, auch Aufladung | `payment` (Mehrzweck) | „auto: a gift card with balance …“ |
| 2.3 | **Prozentgutschein** | verkauft als Produkt; nur mit Wertquelle „Fester Wert“ über 0 bis höchstens 100 (sonst nicht speicherbar, nicht kaufbar und nicht ausgestellt – `ProductConfig::discount_errors()`); „Fester Produktrabatt“ ist für verkaufte Gutscheine gesperrt | `discount` (Preisnachlass) | „auto: a sold percentage voucher …“; `tests/product-setup-integration.php` Abschnitt 14 |
| 2.4 | **Ticketgutschein mit Geldwert** (Typ „Ticket“, Wertquelle „Fester Wert“ > 0) | verkauft als Produkt | `payment` (wie Wertgutschein); seit M12-10 auch so verrechnet: Zahlung nach Steuer, Zahlungsmittel neben „Nur einzeln“, in Journal und Verbindlichkeit (`Voucher::has_cash_value()`) – vorher Rabatt und in keinem Bericht. Frage F9 | „auto: a sold ticket voucher …“; `tests/tax-accounting-integration.php` „M12-10: …“ |
| 2.5 | **Ticketgutschein ohne Geldwert** (Eintritt für bestimmte Veranstaltung/Termin) | verkauft als Produkt; wird auf der Einlöseseite in Tickets getauscht, bezahlt keine Bestellung | im Warenkorb und bei Pass Captain als Zahlung gesperrt (`vcap_ticket_voucher`); Tausch erzeugt eine 0‑€‑Bestellung in Pass Captain | `tests/passcaptain-integration.php` „a ticket voucher cannot pay for a Pass Captain checkout …“ |
| 2.6 | **Kundenguthaben** (Typ „Guthaben“) | Erstattung als Guthaben, Empfehlungsprämie, manuelle Gutschrift, Rest eines Einmalgutscheins („Keep the rest“, 4.3) | `payment` (Empfehlungsprämien: Frage F4) | „auto: store credit …“ |
| 2.7 | **Aktions-/Kampagnencode** (Festbetrag oder Prozent, ohne Verkauf) | im Admin erzeugt (Kampagnen, Bonusgutschein, Freunde werben, Erstbestellung) | `discount` | „auto: a value code without a sale …“, „auto: a percentage code …“ |
| 2.8 | **Gutschein ohne Verkauf** (Kulanz, Preis, Gewinnspiel) | `issue_standalone()` ohne `paid` (API.md) | `discount` – auch bei einem Gutschein- oder Geschenkkartenprodukt, vor jeder Produkteinstellung (M12-42; Filter `vcap_voucher_tax_treatment` bleibt). Im Verbindlichkeitenbericht als „unentgeltlich“ getrennt. Frage F4 | `tests/tax-accounting-integration.php` „M12-42: a voucher issued without a sale …“ |
| 2.9 | **Gutschein ohne Bestellung, aber bezahlt** (Firmenbestellung auf Rechnung) | `issue_standalone()` mit `paid => true` | wie verkauft: Produkteinstellung, sonst `payment` | „M12-42: a voucher issued with "paid" => true …“ |

Eine ausdrückliche Einstellung am Produkt (`payment`, `single`, `discount`) gilt vor der Automatik (Nachweis:
„setting … wins …“) – außer bei Gutscheinen ohne Verkauf (2.8). Das Merkmal „verkauft“ steht im Journal-Eintrag
„issued“ (`ProductConfig::is_sold()`, `parse_snapshot()`); vor M12-42 ohne Bestellung ausgestellte Gutscheine haben
es nicht und behalten ihre bisherige Einordnung (Automatik wie oben, im Bericht als verkauft).

## 3. Verkauf eines Gutscheins

- Der Gutschein ist ein normales WooCommerce-Produkt. **Ob USt anfällt, entscheidet allein dessen Steuerstatus/
  Steuerklasse** (WooCommerce-Standard).
  - Mehrzweck richtig eingerichtet = Steuerstatus „Keiner“ → Verkauf ohne USt (Nachweis: „a multi-purpose gift card
    sold as "not taxable" …“).
  - Einzweck = steuerpflichtig mit dem Satz der späteren Leistung → USt beim Verkauf (Nachweis: „a single-purpose
    voucher sold as taxable …“).
- Je verkaufter Gutscheinposition wird beim Ausstellen ein unveränderlicher Schnappschuss gespeichert
  (`_vcap_line`, `Issuer::line_data()`): Gutscheinart, Nennwert, Währung, Netto, Steuer, Steuerklasse, Steuerstatus
  und ob Preise inkl. Steuer waren. Spätere Produkt- oder Steueränderungen verändern ihn nicht.
- Je Gutschein wird beim Ausstellen die **Einordnung** (`payment`/`single`/`discount`) und das Merkmal „verkauft“
  im Journal-Eintrag „issued“ festgehalten (Notiz `issued_tax` bzw. `issued_standalone`, M12-29/M12-42); das Journal
  zeigt diese Einordnung auch nach einer späteren Produktänderung (Nachweis „M12-29: the VAT treatment at issue is
  kept …“, „M12-29: the journal line shows the treatment at issue …“).
- Nennwert: in Shops mit Bruttopreisen der Bruttopreis (Nachweis: „the voucher value is the gross price (120)“), in
  Shops mit Nettopreisen der Nettopreis.
  - **Ausnahme Guthabenkarte mit USt verkauft (Shops mit Nettopreisen, TODO T7):** Eine Karte mit Restguthaben,
    deren Verkauf besteuert wurde und die *nicht* als Mehrzweckgutschein eingeordnet ist (Einordnung `single`, auch
    `discount` oder per Filter), ist den **bezahlten Bruttobetrag** wert: Verkauf 50 € + 20 % USt = 60 € → Wert und
    Guthaben 60 €; bei der Einlösung bezahlt sie 60 € des Bruttobetrags (4.1). Vorher war sie nur 50 € (netto) wert,
    deckte bei der Einlösung 50 € brutto, und der Kunde musste die beim Kauf bezahlte USt nachzahlen.
    Code: `Issuer::balance_sold_with_vat()`. Nachweis: `tests/tax-integration.php` 9.1 („net prices (T7): …“).
  - Dieselbe Regel gilt für **Aufladungen** (M12-12, gemeinsame Funktion `Issuer::sold_with_vat_value()` für
    `Issuer::voucher_amount()` und `TopUp::line_value()`): eine mit USt verkaufte Einzweck-Aufladung bringt den
    bezahlten Bruttobetrag auf die Karte (vorher nur netto, die Kundin verlor die USt). Nachweis: `tests/run.php`
    „M12-12: net-price shop, single-purpose top-up …“, `tests/tax-accounting-integration.php` „M12-12: …“.
  - Mehrzweckgutscheine behalten ihr Verhalten (Wert = Nettopreis; richtig eingerichtet werden sie ohne USt verkauft,
    dann ist netto = bezahlt). Auch eine versehentlich steuerpflichtig verkaufte Mehrzweck-Karte bleibt beim Nettowert
    (die Produktwarnung, Abschnitt 1, meldet sie).
  - **Einmal-Wertgutscheine** (ohne Restguthaben): Wert = Nettopreis. Bei der Einlösung zahlt ein
    **Mehrzweck**-Einmalgutschein seit M12-27 genau seinen Wert **brutto** (100 € mindern netto 83,33 + USt 16,67;
    `CartCoupons::prepare_gross_balance()`, wie Guthabenkarten, 4.1). Vorher minderte sein Festbetrag den Nettopreis
    und die USt darauf zusätzlich – ein 100‑€‑Gutschein deckte 120 €. Einzweck-Einmalgutscheine und Aktionen mindern
    weiterhin netto + USt. Nachweis: `tests/tax-accounting-integration.php` „M12-27: net prices, setting off …“.
  - **Die Einordnung als Einzweckgutschein (und damit dieser Bruttowert) muss die Steuerberatung bestätigen (F13).**
- Rechnung/Beleg: erstellt WooCommerce bzw. das Rechnungs-Plugin des Shops; Voucher Captain gibt keine eigene
  Rechnung aus.

### 3.1 Ohne Bestellung ausgegebene und importierte Gutscheine

Gutscheine aus „Gutscheine → Neuer Gutschein“ und `wp vcap issue --amount=…` (Kulanz, Gewinnspiel) werden ohne
Zahlung ausgegeben und als „nicht verkauft“ übergeben (`paid => false` an `Issuer::issue_standalone()`); importierte
Bestandskarten („Karten importieren“, `wp vcap import`) gelten als bereits verkauft (`paid => true`). Wie das bei der
Einlösung steuerlich zu behandeln ist (z. B. als Preisnachlass), entscheidet die Steuerlogik, die dieses Kennzeichen
auswertet. WooCommerce Analytics zeigt diese Gutscheine nicht (COMPATIBILITY.md „WooCommerce Analytics“); maßgeblich
sind die Berichte unter „Gutscheine → Berichte“.

## 4. Einlösung

### 4.1 Im WooCommerce-Warenkorb (alle Gutscheinarten)

Jeder Gutschein wird technisch als WooCommerce-Coupon verrechnet (Wertgutschein/Geschenkkarte/Guthaben: Warenkorb-
Festbetrag; Prozent: Prozent-Coupon). WooCommerce verteilt den Betrag anteilig auf die Positionen und **berechnet die
USt vom reduzierten Preis**.

Beispiel (Nachweis „current behaviour: WooCommerce charges VAT only on the remaining 60 …“): Ware 120 € brutto
(20 %), Geschenkkarte 60 € → Kunde zahlt 60 €, die Bestellung weist **10 € USt** aus statt 20 €.

| Einordnung | Was steuerlich gemeint ist | Was heute im Warenkorb passiert | Bewertung |
|---|---|---|---|
| `discount` | Preisnachlass mindert Bemessungsgrundlage | USt vom reduzierten Preis | passt |
| `single` | USt wurde beim Verkauf berechnet; Einlösung ist keine neue steuerbare Leistung | USt nur auf den Rest; nur auf Positionen mit **Satz und Land des Verkaufs** (M12-28) | passt (Frage F3: Sperre statt Mehrzweck) |
| `payment` | Gutschein ist Zahlungsmittel; USt auf die volle Leistung (20 € im Beispiel) | Einstellung „Mehrzweckgutscheine als Zahlung“ an (Neuinstallationen): volle USt (20 €), Gutschein als Zahlungszeile. Aus (bestehende Shops): USt nur auf den Rest (10 €) | an: passend; aus: **passt nur**, wenn das Produkt steuerpflichtig verkauft wurde (Abschnitt 1) – Umstellung F17 |

**Einstellung „Mehrzweckgutscheine als Zahlung“** (`vcap_mpv_after_tax`; Neuinstallationen an, bestehende Shops
unverändert, Abschnitt 1): Gutscheine mit Einordnung `payment` (Geschenkkarten, Wertgutscheine, Guthabenkonto) bleiben
als Code im Warenkorb (Prüfung, Nutzungsgrenzen), mindern aber keinen Preis. Sie zahlen stattdessen mit einer
eigenen, nicht steuerbaren Zeile „Gutschein CODE“ **nach** allen Steuern, Versand und Gebühren – höchstens den noch
offenen Betrag. Die Bestellung weist die volle USt aus; Reservierung, Abbuchung, Storno und Erstattung lesen den
gezahlten Betrag aus dieser Zeile (`PaymentVouchers::paid_amount()`), eine Neuberechnung der Bestellung macht daraus
keinen zusätzlichen Rabatt. Einzweckgutscheine und Rabattaktionen bleiben Preisnachlässe. Filter
`vcap_voucher_pays_after_tax( $applies, $coupon, $voucher )`. Nachweis: `tests/tax-integration.php` Abschnitt 8
(Warenkorb 120 € inkl. 20 % USt, Karte 60 € → Zahlbetrag 60 €, USt 20 €; Zahlungszeile −60 € ohne Steuer; Bestellung,
Abbuchung genau 60 €, Storno gibt 60 € zurück; 200‑€‑Karte zahlt höchstens 120 €; Einzweckgutschein bleibt Rabatt).
Warenkorb-/Kassenblöcke: Die Store API liefert Zahlungen, Restguthaben und den Guthaben-Hinweis unter
`extensions["voucher-captain"]` (`CartBlocks`); `assets/js/cart-blocks.js` zeigt je Gutschein die Zeile „Gutschein CODE
−x“ und das Restguthaben unter den Rabatten (Slot `ExperimentalDiscountsMeta`), ergänzt den Coupon um „zahlt als
Gutscheinzahlung (siehe unten)“ (Filter `coupons`) und zeigt „Guthaben verwenden“ (Slot `ExperimentalOrderMeta`).
Die Rabattangabe des Blocks bleibt bei 0 bzw. „–“ (je nach WooCommerce-Version als Summe oder je Coupon): Sie stammt
aus `totals.total_discount` bzw. `coupons[].totals.total_discount` der Store API, für die WooCommerce keinen Filter
bietet (der Filter `coupons` ändert nur die Beschriftung, die Coupon-Darstellung hat keine Klasse je Coupon, die sich
gezielt ausblenden ließe). Der Hinweis „zahlt als Gutscheinzahlung“ erklärt die 0 und steht nur an einem zahlenden
Coupon mit eigenem Rabatt 0; Summe und Zahlbetrag stimmen, in der Bestellung ist die Zahlung eine Gebührenzeile. Ein Festbetragsgutschein zur
einmaligen Einlösung, der mehr wert ist als der Warenkorb, zeigt wie im klassischen Warenkorb den Hinweis, dass der
Restwert verfällt (`extensions["voucher-captain"]["forfeits"]`, gleicher Text aus
`ProductChecks::residual_notice()`, als Statusmeldung unter den Rabatten); ein zahlender Gutschein verliert dabei nur,
was er nicht bezahlen kann. Nachweis: `tests/blocks-integration.php`.

**Was der Gutschein bezahlen darf** (`PaymentVouchers::payments_for()`): nur den Bruttobetrag (Zeilensumme nach
anderen Rabatten + deren USt) der Positionen, auf die WooCommerce den Coupon anwenden würde, und davon nur die, die
die Beschränkungen des Coupons erfüllen:

- Filter `woocommerce_coupon_get_items_to_apply` – damit gilt „Gutscheine kaufen keine Gutscheine“ (auch keine
  Aufladungen, `VoucherPurchaseRules`, Einstellung `vcap_vouchers_buy_vouchers`) und die SKU-Regeln;
- Produkt-, Kategorie-, Ausschluss- und Sale-Beschränkungen des Coupons wie bei einem Produkt-Coupon
  (`WC_Coupon::is_valid_for_product()`; WooCommerce selbst prüft sie bei Warenkorb-Festbeträgen nur als
  „muss im Warenkorb sein“).

**Versand und Gebühren** bezahlt ein Gutschein nur, wenn er nicht auf bestimmte Produkte oder Kategorien beschränkt
ist (ein freies Zahlungsmittel zahlt die ganze Bestellung, ein Kategoriegutschein nur seine Waren; ein
WooCommerce-Festbetrag-Coupon würde Versand nie mindern). Filter
`vcap_voucher_pays_shipping_and_fees( $pays, $coupon, $cart )`. Mehrere Gutscheine zahlen denselben Betrag nie
doppelt; jeder zahlt höchstens seinen Wert und den noch offenen Betrag.

**Nur der Hauptwarenkorb zahlt** (`WC()->cart`): weitere Warenkorb-Instanzen – WooCommerce Subscriptions berechnet
seine Abo-Warenkörbe als Klone des Hauptwarenkorbs – behalten ihre Summe und überschreiben die Zahlungen des
Hauptwarenkorbs nicht. Ein Gutschein bezahlt die Bestellung, auf die er angewendet wird, nie eine Verlängerung. Bei der
Bestellanlage (klassisch `woocommerce_checkout_create_order`, Store API
`woocommerce_store_api_checkout_update_order_meta`) werden die Zahlungen aus dem Hauptwarenkorb neu berechnet (nicht
aus der Session), die Zahlungszeilen angehängt und die Summe gesetzt, ohne die Bestellung vorzeitig zu speichern.
Nachweis: `tests/tax-integration.php` 8.1 (Ware 120 + Geschenkkarten-Produkt 50, Karte 200 → zahlt 120, offen 50;
Kategoriegutschein zahlt nur die 60 der Kategorie, keine fremde Ware, keine Gebühr; freier Gutschein zahlt auch die
Gebühr) und 8.2 (zweite Warenkorb-Berechnung als Klon ohne Gutschein ändert nichts; Bestellung aus dem
Hauptwarenkorb trotz falscher Session-Daten richtig; keine vorzeitige Speicherung).

Für Shops mit Nettopreisen zieht das Plugin Geschenkkarten, Guthaben und (seit M12-27) Mehrzweck-Einmalgutscheine
brutto ab (60 € Guthaben bezahlen 60 € des Bruttobetrags, nicht 60 € netto + Steuer; Filter
`vcap_balance_covers_tax`, Nachweis `tests/acceptance-integration.php`, `tests/tax-accounting-integration.php`
„M12-27: …“). An der Minderung der USt-Bemessungsgrundlage ändert das nichts (Einstellung aus).

**Einzweckgutschein: Satz und Land** (M12-28, `TaxAccounting::single_purpose_items()`/`single_purpose_valid()`): Im
Warenkorb, an der Kasse (klassisch und Blöcke/Store API) und bei einem im Admin hinzugefügten Gutschein gilt ein
Einzweckgutschein nur für Positionen mit dem USt-Satz (Toleranz 0,01 Prozentpunkte für gerundete Bruttopreise) und
dem Steuerland seines Verkaufs (`ProductConfig::sale_tax()`). Andere Positionen bleiben unberührt; passt keine
Position, wird der Gutschein mit dem Hinweis „This single-purpose voucher was sold with 20 % VAT for AT and can only
be redeemed for goods with the same VAT rate and country …“ abgelehnt. **Sichere Voreinstellung:** ablehnen statt
als Mehrzweck behandeln (Frage F3; Pass Captain behandelt eine Abweichung als Mehrzweck, 4.2). Ohne Verkaufsposition
(Gutschein ohne Bestellung) und bei ausgeschalteter Steuer gibt es keine Prüfung. Nachweis:
`tests/tax-accounting-integration.php` „M12-28: refused for goods with another VAT rate …“, „M12-28: in a mixed cart
it discounts only the goods …“.
Umrechnung (TODO T8, T15; `CouponManager::prepare_gross_balance()`, `GrossBalanceSplit`): Das Plugin spielt die
Verteilung von WooCommerce nach (Festbetrag gleichmäßig je Stück auf die Preise **nach** vorher angewandten Rabatten,
USt je Position mit ihrem Satz, gerundet wie Warenkorb bzw. Bestellung) und wählt den größten Nettobetrag, bei dem
Netto + USt **genau** dem Guthaben entspricht. Damit zieht eine 100‑€‑Karte auch bei gemischten Sätzen und einem
vorher angewandten Rabattcode nie mehr als 100 € brutto ab und wird ausgeschöpft (vorher z. B. 103,15 € –
der Checkout brach ab), und es bleiben keine Cent-Reste durch Abrunden (50‑€‑Karte: netto 41,67 + USt 8,33).
Lässt die Steuerrundung keinen exakten Betrag zu (1 Cent netto mehr ergibt 2 Cent brutto mehr), bezahlt die Karte
einen Cent weniger; hat der Kunde den Rest der Bestellung selbst bezahlt, wird dieser Rest (höchstens eine
Preis-Nachkommastelle, auch ältere Sub-Cent-Reste) als eigenes Journal-Ereignis `rounding_written_off` „Rundungsrest
ausgebucht“ auf 0 gebucht; Storno/Erstattung der Bestellung gibt ihn mit der Abbuchung zurück. Eine Karte, die
größer als die Bestellung ist, behält ihren Rest. Nachweis: `tests/tax-integration.php` 9.2 („net prices, mixed
rates, code + card (T8 …)“, klassischer Warenkorb und Bestellung) und 9.3 („net prices (T15): …“).

### 4.2 In WP Pass Captain (Ticketkasse)

Pass Captain fragt den Gutschein über den Provider-Vertrag an und erhält `treatment` und `purpose`
(`src/Compat/PassCaptainProvider.php`, Nachweis Abschnitt 3 des Tests):

| `treatment` | `purpose` an Pass Captain | Verhalten in Pass Captain |
|---|---|---|
| `payment` | `multi` | Gutschein bezahlt den Bruttobetrag; USt auf die volle Ticketleistung |
| `single` | `single` + `sale_tax` | Gutschein mindert Preis und USt – nur wenn Satz und Land der Tickets dem Verkauf entsprechen, sonst wie `multi` |
| `discount` | `discount` | Preisnachlass (mindert Preis und USt); Rechnung: „Eingelöster Rabattcode …“, **keine** Aussage „USt bereits beim Verkauf abgerechnet“ |

`sale_tax` = Umsatzsteuer des Gutscheinverkaufs: `rate_bp` (Basispunkte, 2000 = 20 %) und `country` (Land des
angewandten Steuersatzes, sonst Steuerort der Bestellung nach „Steuer berechnen auf Basis von“). Festgehalten im
Zeilen-Snapshot (`tax_rate_bp`, `tax_country`), für ältere Gutscheine aus der Verkaufsposition berechnet
(`ProductConfig::sale_tax()`). Pass Captain vergleicht mit dem Satz jeder Ticketposition und seinem Steuerland
(`seller_country`); bei Abweichung gilt der Gutschein als Mehrzweckgutschein, die Bestellung vermerkt
`purpose_changed` (`single_rate`/`single_country`). Gleiches gilt beim Tausch von Ticketgutscheinen.
Nachweis: `tests/tax-integration.php` („the line snapshot records the VAT of the sale …“, „Pass Captain gets the rate
and country of the sale …“, „purpose "discount" for a promotion code“) und in WP Pass Captain
`tests/integration/modules/zz_voucher_purpose.php` (Abweichung Satz/Land, Rabattcode-Rechnung, Tausch).

### 4.3 Restwert von Einmalgutscheinen

Ein Einmalgutschein, der mehr wert ist als die Bestellung, wird einmal eingelöst; der Kunde wird im Warenkorb gewarnt
(`ProductChecks::residual_notice()`, LEGAL_TEXTS.md „Restwert“). Seit M12-29 bucht das Journal getrennt:

- „redeemed“ (Eingelöst) mit dem **in der Bestellung genutzten Betrag** (Coupon-Rabatt + entfernte USt bzw.
  Zahlungszeile, `TaxAccounting::used_by_order()`), mit Bestell-ID;
- „forfeited“ (Restwert verfallen) mit dem **Rest**, ebenfalls mit Bestell-ID;
- wird die Einlösung zurückgegeben (Storno/Vollerstattung), bucht „restored“ den vollen Wert zurück.

**Produkteinstellung „Keep the rest“** (Rest behalten, M12-68, Standard aus): Bei *verkauften* Einmal-
Wertgutscheinen wird der Rest statt „forfeited“ als „rest_converted“ gebucht und dem Kunden gutgeschrieben – als
Kundenguthaben seines Kontos (Kundenguthaben eingeschaltet), sonst als neue Geschenkkarte an die Rechnungsadresse
(gleiches Produkt, also gleiche Einordnung, „verkauft“). Wird die Einlösung zurückgegeben, nimmt das Plugin das
Guthaben bzw. die Karte zurück, soweit noch vorhanden („rest_reverted“). Der Warenkorbhinweis sagt dann, dass der
Rest nicht verfällt. Ob ein Verfall des Restes bei gekauften Gutscheinen wirksam vereinbart werden kann (DE § 307
BGB, AT ABGB), prüft die Rechtsberatung (LEGAL_TEXTS.md Abschnitt 1). Steuerfolge des Restes: Frage F20.
Nachweis: `tests/tax-accounting-integration.php` „M12-29: a single-use voucher of 100 used for 60 …“, „M12-68: …“.

## 5. Erstattungsfolgen

### 5.1 Erstattung des Gutscheinkaufs

| Fall | Verhalten | Einstellung / Nachweis |
|---|---|---|
| Kaufbestellung vollständig erstattet oder storniert | ausgestellte Gutscheine werden gesperrt; eine aufgeladene Geschenkkarte behält ihr Aufladeguthaben: nur der unverbrauchte Kaufwert wird abgebucht (Journal „purchase_refunded“), die Karte bleibt nutzbar | „Gutscheine bei Erstattung stornieren“ (`vcap_void_on_refund`, Standard an); `tests/tax-integration.php` „a fully refunded gift card purchase voids …“, `tests/topup-integration.php` Abschnitt 4b |
| Kaufbestellung von Hand „Storniert“ (keine Erstattung erfasst) | nur ungenutzte Gutscheine werden gesperrt; bereits genutzte (eingelöst, teilweise verbraucht, reserviert) bleiben gültig, Bestellnotiz nennt sie | `Issuer::on_purchase_cancelled()`; `tests/order-lifecycle-integration.php` „P9 …“ |
| Teilerstattung (Menge) | so viele Gutscheine wie erstattet werden gesperrt, ungenutzte zuerst, dann teilweise verbrauchte Geschenkkarten; vollständig verbrauchte (eingelöster Einmalgutschein, Karte ohne Guthaben) nie – Bestellnotiz. Schlägt das Sperren fehl: Bestellnotiz, Admin-Hinweis und automatische Wiederholung | `Issuer::on_partial_refund()`; `tests/acceptance-integration.php` „refunding one of two gift cards …“, `tests/connector-integration.php` |
| Gutschein schon (teilweise) eingelöst | Erstattung höchstens Bestellbetrag minus verbrauchter Wert; mehr lehnt WooCommerce mit Hinweis ab (Filter `vcap_allow_refund_of_spent_vouchers`). Geschenkkarte: verbraucht = Kaufwert + Aufladungen − Rücknahmen − Guthaben, verbraucht wird zuerst der Kaufwert; Kauf- und Aufladungserstattungen zusammen nie mehr als das Guthaben. Einmalgutschein in einer unbezahlten Bestellung (ausstehend, fehlgeschlagen, wartend) gilt als eingelöst | `Issuer::guard_refund()`, `TopUp::shares()`; `tests/acceptance-integration.php` „refunding value that was already spent …“, „… sitting in an unpaid order …“, `tests/topup-integration.php` Abschnitt 4b |
| Aufladung einer Geschenkkarte erstattet (ganz oder teilweise) | Teilerstattung nimmt den erstatteten Anteil der Aufladeposition von der Karte zurück (Positionserstattung über `_refunded_item_id`; ein Betrag ohne Positionen erstattet zuerst die übrigen Waren, erst der Rest die Aufladung), Storno/Vollerstattung den Rest; bereits ausgegebenes Aufladeguthaben ist nicht erstattbar – mehr als das Kartenguthaben hergibt, lehnt WooCommerce mit Höchstbetrag ab (Filter `vcap_allow_refund_of_spent_top_ups`; bestellweit zählt es in `Issuer::guard_refund()` mit, auch ohne `vcap_void_on_refund`) | `TopUp::reverse_refunded()`, `TopUp::guard_refund()`; `tests/topup-integration.php` Abschnitte 1–4 |
| Ticketgutschein bereits in Tickets getauscht | Kauferstattung sperrt den Gutschein und storniert die Tickets | `tests/passcaptain-integration.php` „voiding the voucher (purchase refunded) revokes its tickets“ |

Steuerliche Folge (vom Plugin nicht selbst gebucht): bei Einzweck Korrektur der beim Verkauf berechneten USt durch
die Erstattung in WooCommerce; bei Mehrzweck keine USt-Folge, da beim Verkauf keine berechnet wurde (Frage F5).

### 5.2 Erstattung einer mit Gutschein bezahlten Bestellung

| Fall | Verhalten | Einstellung / Nachweis |
|---|---|---|
| Storniert, fehlgeschlagen oder vollständig erstattet | eingesetzter Gutschein-/Guthabenbetrag wird zurückgebucht; einmalige Gutscheine wieder gültig (bei „Vollständig erstattet“ senkt Voucher Captain den Nutzungszähler selbst, WooCommerce tut es nur bei „Storniert“; ist die Einstellung aus, bleiben auch Einmalgutscheine eingelöst). Ist die Karte inzwischen abgelaufen oder gesperrt: Bestellnotiz + Admin-Hinweis (Filter `vcap_restore_extension_days` verlängert abgelaufene Karten) | „Guthaben bei Stornierung wiederherstellen“ (`vcap_restore_on_cancel`, Standard an); `tests/tax-integration.php` „cancelling the redemption order …“, `tests/acceptance-integration.php` „a full refund restores the used balance …“ |
| Teilerstattung | WooCommerce erstattet über die Zahlungsart; Gutscheinsalden bleiben unverändert | README.md „Erstattung“ |
| Teilrückgabe einer mit Geschenkkarte bezahlten Bestellung (auch Gast, Gesamt 0 €) | „Auf Gutschein zurückbuchen“ in der Bestellung: Betrag zurück auf die Karte, höchstens der von ihr gezahlte und noch nicht zurückgebuchte Anteil; Journal `returned` mit Position und Grund; eine spätere Stornierung bucht nur den Rest zurück | `src/OrderReturn.php`; `tests/order-lifecycle-integration.php` „a returned item is booked back …“ |
| „Als Guthaben erstatten“ | nur ausdrücklich durch das Personal, nicht für Gastbestellungen; erzeugt Kundenguthaben (2.6); Bestellung in Fremdwährung ohne bekannten Kurs wird abgelehnt (nie 1:1). **Offen (M12-11):** die WooCommerce-Erstattung hat keine Positionen und keine USt (keine Korrektur nach § 17 UStG) – Frage F18 | `tests/store-credit-integration.php` (`refund_as_credit`) |
| Bestellung mit Einmalgutschein bezahlt, nachdem der Gutschein gesperrt oder anderweitig eingelöst wurde | Fehlbetrags-Kennzeichen, Bestellnotiz, Status „Wartend“ (wie ein nicht abbuchbares Guthaben); „Bezahlen“ im Kundenkonto wird mit Hinweis abgelehnt | `CouponManager::check_single_use_conflicts()`; `tests/acceptance-integration.php` „paying the order after the voucher was voided …“ |
| Geschenkkarten-Aufladung storniert/erstattet | Aufladung wird zurückgenommen, soweit das Guthaben noch vorhanden ist | `src/TopUp.php::revoke()`; `tests/acceptance-integration.php` (Aufladung) |
| Geschenkkarten-Aufladung gebucht | Wert nach derselben Regel wie eine neue Karte (Nennwert: fester Betrag, eingegebener Betrag oder Positionspreis vor Rabatten, brutto bei Bruttopreisen, sonst netto; im Netto-Shop brutto bei einer mit USt verkauften Einzweck-Aufladung, Abschnitt 3) – auch wenn die Aufladung mit einer anderen Karte bezahlt wurde; Kundin erhält eine Bestellnotiz (E-Mail) mit neuem Restwert und Gültigkeit. **Aufladeprodukt und Karte müssen dieselbe Einordnung haben** (M12-12): sonst lehnt der Warenkorb mit „the card and the top-up are treated differently for VAT …“ ab, eine trotzdem bezahlte Position wird gesperrt (nie gebucht). Karte bei Zahlung gesperrt/abgelaufen oder Wert 0: Position gesperrt, Bestellung „Wartend“, Kundeninfo, Admin-Hinweis. Aufladung nur bei mindestens 30 Tagen Restlaufzeit (Filter `vcap_topup_min_remaining_days`); Verlängerung der Gültigkeit per Filter `vcap_topup_expires_at` (Standard: unverändert) | `TopUp::top_up_instead()`, `TopUp::line_value()`, `TopUp::treatment_mismatch()`; `tests/topup-integration.php` (T11/E5), `tests/tax-accounting-integration.php` „M12-12: …“ |
| Pass Captain: Tickets einer Gutscheinzahlung erstattet | Pass Captain bucht den Gutscheinanteil genau einmal zurück; Abgleich unter Gutscheine → Auswertung → Abgleich | `tests/passcaptain-integration.php` „the voucher share of the refunded ticket returns …“ |

Barauszahlung von Restguthaben ist im Plugin nicht vorgesehen (Frage F6).

## 6. Buchhaltung: Journal, Verbindlichkeit, Kontierung

### 6.1 Journal (Gutscheine → Auswertung → Einlösejournal, CSV)

Jede Buchung, die Wert bewegt, mit Vorzeichen (Wert vom Gutschein = negativ), Bestellnummer, Kasse/Beleg und den
Spalten **„VAT treatment“** (Einordnung beim Ausstellen, 3; ältere Gutscheine nach heutigen Einstellungen) und
**„Order amount“** (Gesamtbetrag der Bestellung in ihrer Währung) – M12-29, `Ledger::journal_line()`. Seit M12-29:

| Ereignis | Betrag | Bedeutung |
|---|---|---|
| `issued` | + Wert | Gutschein ausgestellt (Notiz: Einordnung, verkauft/unentgeltlich) |
| `redeemed` | − genutzter Betrag | Einlösung; bei Einmalgutscheinen der in der Bestellung genutzte Betrag (4.3) |
| `forfeited` / `rest_converted` | − Rest | Rest eines Einmalgutscheins verfallen bzw. als Guthaben/neue Karte behalten (4.3) |
| `expired` | − Restwert | Ablauf (tägliche Wartung): Guthaben bzw. Wert des nicht eingelösten Einmalgutscheins (vorher ohne Betrag) |
| `expiry_reversed` | + Restwert | Gutschein wieder gültig (Gültigkeit verlängert, reaktiviert): Ablauf einmal zurückgebucht |
| `rounding_written_off` | − Rest | Rundungsrest der Netto-Umrechnung (4.1), jetzt mit Bestell-ID |

Die Summe der Spalte entspricht der Änderung der offenen Gutscheinwerte. Nachweis: `tests/tax-accounting-integration.php`
„M12-29: …“, `tests/tax-integration.php` „M12-29: the booked-off rounding rest carries the order id …“.

### 6.2 Verbindlichkeit (Stichtag)

Offene Werte je Währung und Art; abgelaufene Werte getrennt; **„Of which free of charge“**: der Teil, der ohne
Verkauf ausgestellt wurde (2.8, M12-42) – keine Gegenleistung erhalten. Die CSV je Gutschein hat die Spalte „Sold“.
Tickets mit Geldwert sind enthalten (M12-10). Nachweis: „M12-42: the liability report shows the free vouchers …“.

### 6.3 Kontierung (Vorschlag, Frage F21)

| Vorgang | Buchung | USt |
|---|---|---|
| Verkauf Mehrzweckgutschein (Steuerstatus „Keiner“) | Geld/Forderung an Gutscheinverbindlichkeit | keine |
| Verkauf Einzweckgutschein | Geld an Erlös (mit USt) bzw. nach Vorgabe der Steuerberatung | beim Verkauf |
| Einlösung Mehrzweck, Einstellung an | Erlös der Bestellung mit voller USt; die Zeile „Gutschein …“ (negative Gebühr) ist eine **Zahlung**: Gutscheinverbindlichkeit an Forderung/Verrechnungskonto | auf die volle Leistung |
| Einlösung Mehrzweck, Einstellung aus / Einzweck / Aktion | Coupon mindert den Erlös (WooCommerce-Standard) | vom reduzierten Preis |
| Verfall, verfallener Rest | Gutscheinverbindlichkeit an sonstige Erträge | Mehrzweck: keine (F7, F20) |

**Gutscheinzahlungen für Exporte** (M12-30): WooCommerce Analytics, Rechnungs- und DATEV-Plugins sehen die
Zahlungszeile als negative Gebühr und buchen sie sonst als 0‑%-Erlösminderung. Deshalb hält jede Bestellung ihre
Gutscheinzahlungen zusätzlich als Bestell-Meta **`_vcap_voucher_payments`** (Code => Betrag in Bestellwährung,
`PaymentVouchers::order_payments()`, Filter **`vcap_order_voucher_payments( $payments, $order )`**); die WooCommerce
REST-API liefert sie als `vcap_voucher_payments` (maskierter Code, Betrag). Exporte sollen diese Beträge als
Zahlung buchen und die Gebührenzeile auslassen. Nachweis: „M12-30: …“.

### 6.4 DATEV-Export (optional, M12-69)

Gutscheine → Auswertung → Einlösejournal → „Export DATEV (EXTF)“: das Journal des Zeitraums als DATEV-Buchungsstapel
(EXTF, Kategorie 21, Windows-1252, „;“). Einstellungen unter WooCommerce → Einstellungen → Gutscheine → Integrationen:
Berater- und Mandantennummer, Wirtschaftsjahresbeginn, Sachkontenlänge, **Gutscheinverbindlichkeit**,
**Verrechnungskonto**, **Ertragskonto für verfallene Werte**. Jede Buchung eines Wertgutscheins: Wertzugang
Verrechnungskonto an Verbindlichkeit, Wertabgang Verbindlichkeit an Verrechnungskonto; Verfall, verfallener Rest und
Rundungsrest gegen das Ertragskonto. Aktionen (`discount`) bleiben draußen; kein BU-Schlüssel. Belegfeld 1 =
Bestellnummer bzw. Code, Buchungstext = Ereignis + maskierter Code. Der Zeitraum muss in einem Wirtschaftsjahr
liegen. **Testimport durch die Steuerberatung vor dem Einsatz.** Nachweis: „M12-69: …“.

### 6.5 DSGVO-Löschung und Aufbewahrung (M12-66)

Die Löschung auf Anfrage ersetzt nur **Freitext**-Notizen im Verlauf (können Namen/Adressen enthalten); codierte
Notizen (Korrekturgründe, Zustellergebnis, Einordnung) sowie Beträge und Daten bleiben (GoBD). Die Löschung selbst
wird als Ereignis „privacy_erased“ mit der Zahl der ersetzten Notizen protokolliert
(`VoucherRepository::anonymise_event_notes()`). Nachweis: `tests/security-integration.php` „M12-66: the erasure is
logged …“, `tests/tax-accounting-integration.php` „M12-66: …“.

## 7. Steuerfälle: Sätze, Leistungsort (OSS), gleicher Bruttopreis, Kleinunternehmer

Voucher Captain rechnet **keine Steuersätze selbst**: Sätze, Länder und Klassen kommen aus WooCommerce
(WooCommerce → Einstellungen → Steuer). Das Plugin legt fest, *welcher Wert* ein Gutschein hat und *wie viel* von
ihm abgebucht wird. Spezifikation und Nachweis (`tests/tax-integration.php`, Abschnitt 7):

| Nr. | Fall | Festgelegtes Verhalten | Nachweis |
|---|---|---|---|
| 7.1 | **Mehrere Steuersätze in einer Bestellung** (z. B. 20 % und 10 %), Bruttopreise | WooCommerce verteilt den Gutscheinbetrag anteilig auf die Positionen; jede Position behält ihren Satz; abgebucht wird genau der bezahlte Betrag. Beispiel: 120 € (20 %) + 110 € (10 %), Karte 115 € → Rest 115 € (netto + USt beider Positionen), Karte 0 €. Die Aufteilung der USt auf die Sätze folgt der WooCommerce-Verteilung. | „mixed rates, gross prices …“ (3 Prüfungen) |
| 7.2 | Mehrere Steuersätze, **Nettopreise** | Karte bezahlt brutto (Nettoanteil + dessen USt) über beide Sätze; abgebucht wird genau der Kartenwert – auch wenn vorher ein Rabattcode angewandt wurde (Umrechnung aus den rabattierten Positionspreisen je Satz, 4.1). | „mixed rates, net prices …“, „net prices, mixed rates, code + card (T8 …)“ |
| 7.3 | **Leistungsort im anderen EU-Land (OSS)**, Steuer nach Rechnungsland | Die Bestellung trägt nur den Satz des Käuferlands (z. B. DE 19 %); die Karte bezahlt brutto und wird genau um den bezahlten Betrag belastet. Der Gutscheinwert bleibt in der Shop-Basiswährung; das Land der Einlösung spielt für den Wert keine Rolle. | „OSS: …“ (3 Prüfungen) |
| 7.4 | **Gleicher Bruttopreis in allen Ländern** (WooCommerce-Filter `woocommerce_adjust_non_base_location_prices` = false) | Ein steuerpflichtiger Gutschein für „50 €“ kostet überall 50 € brutto und ist 50 € wert. | „same gross price …“ |
| 7.5 | **WooCommerce-Standard** (Netto bleibt, Satz des Käuferlands kommt dazu) | Bei steuerpflichtigem Gutscheinprodukt zahlt ein DE-Käufer 49,58 € statt 50 € (Basis AT 20 %). Der Gutschein ist **genau das wert, was bezahlt wurde** (nie mehr). Für Gutscheine mit festem Nennwert daher Mehrzweck mit Steuerstatus „Keiner“ (7.6) oder gleichen Bruttopreis (7.4) verwenden. | „WooCommerce default …“ |
| 7.6 | Mehrzweckgutschein **nicht steuerbar** verkauft | Kostet und ist in jedem Land gleich viel wert (keine Länderanpassung ohne Steuer). | „a multi-purpose card sold as not taxable …“ |
| 7.7 | **Kleinunternehmer** (Steuer im Shop aus) | Verkauf und Einlösung ohne USt; Gutschein ist den bezahlten Betrag wert und bezahlt genau diesen. Die Einordnung Einzweck/Mehrzweck hat keine Steuerwirkung. Pass Captain übernimmt seinen Kleinunternehmer-Hinweis selbst. | „small business: …“ (2 Prüfungen) |
| 7.8 | **Fremdwährung** (Mehrwährungs-Plugin) | Gutscheinwerte und Salden in der Basiswährung, Umrechnung über `vcap_amount_to_base_currency` (WCML, Aelia, WOOCS, CURCY, WooPayments Multi-Currency); der Kurs wird beim Checkout an der Bestellung gespeichert (`_vcap_exchange_rate`). **Nie 1:1:** ohne bekannten Kurs wird kein Gutschein ausgestellt (Bestellung „Wartend“ mit Notiz und Admin-Hinweis), keine Guthaben-Erstattung gebucht und kein Festbetragsgutschein im Fremdwährungs-Warenkorb angenommen; Website-Zustand meldet es. Freigabe je Plugin unter TODO.md „Reale Zahlungsanbieter, Mehrwährungs- …“ | `tests/acceptance-integration.php`, `tests/store-credit-integration.php`, `tests/ecosystem-integration.php` |

Offene Fragen dazu: F14, F15, F16 (Abschnitt 11).

## 8. EU-weite Steuerfälle (Zielmarkt EU‑27)

Gilt für jedes Land, an das der Shop verkauft (WooCommerce „Verkaufsorte“). Voucher Captain speichert **keine
Steuersätze**; Sätze, OSS-Länder und Leistungsort kommen aus den WooCommerce-Steuereinstellungen. Länderdaten
(Währung, Sprache, gesetzliche Gültigkeit) stehen in `config/markets.php` (COMPATIBILITY.md „EU target market“).

| Thema | Festgelegtes Verhalten (gilt in allen EU-Ländern gleich) | Nachweis |
|---|---|---|
| OSS / Leistungsort | WooCommerce berechnet die USt nach Käuferland (Abschnitt 7.3); Gutscheine verändern den Satz nicht. | `tests/tax-integration.php` 7.3 |
| Bruttopreise | Gutscheinwert = tatsächlich bezahlter Bruttobetrag; „gleicher Bruttopreis“ und WooCommerce-Standard beide unterstützt (7.4/7.5). | 7.4 |
| Gutscheinbesteuerung | Richtlinie (EU) 2016/1065 ist in allen Mitgliedstaaten umgesetzt; Einordnung je Produkt (Abschnitt 1–4). Länderspezifische Abweichungen nur über `vcap_voucher_tax_treatment`. | Abschnitt 2 |
| Positionsbezogene Erstattung | Je Bestellposition: erstattete Menge, Netto **und USt** (`order_lines()` → `refunded_quantity`, `refunded_total`, `refunded_tax`); Gutscheine der Position werden in dieser Menge gesperrt. | „line-based refund …“, `tests/acceptance-integration.php` |
| Kleinunternehmer (z. B. DE § 19, AT § 6 Abs. 1 Z 27) | Steuer im Shop aus → keine USt bei Verkauf und Einlösung (7.7). | 7.5 |

Prüfung je Land durch die Steuerberatung (je Zeile ankreuzen):

| Land | Satz/OSS in WooCommerce eingerichtet | Einordnung Mehrzweck/Einzweck bestätigt (F1) | Verfall/Erstattung bestätigt (F5–F8, F18, F20) | Besonderheiten |
|---|---|---|---|---|
| DE | ☐ | ☐ | ☐ | |
| AT | ☐ | ☐ | ☐ | |
| weitere Verkaufsländer: | ☐ | ☐ | ☐ | |

## 9. Texte, die Shop und Kunden sehen

| Ort | Text (englischer Quelltext / deutsche Übersetzung) |
|---|---|
| Produkt → Gutschein → „VAT treatment“ | *Automatic (value vouchers: multi-purpose, percentage: discount)* / *Automatisch (Wertgutscheine: Mehrzweck, Prozent: Rabatt)* |
| | *Multi-purpose voucher (means of payment, VAT on redemption)* / *Mehrzweckgutschein (Zahlungsmittel, USt bei Einlösung)* |
| | *Single-purpose voucher (VAT already charged at sale)* / *Einzweckgutschein (USt bereits beim Verkauf abgerechnet)* |
| | *Promotion (price reduction)* / *Rabattaktion (Preisnachlass)* |
| Hilfetext | *How connected checkouts (e.g. WP Pass Captain) treat the voucher for VAT. Have the classification checked by your tax adviser.* |
| Kunde | Gutscheintexte enthalten keine Steueraussage; Einlösebedingungen kommen aus den Ländertexten (Einstellungen → Bedingungen je Land) |

| Kunde (Warenkorb) | Restwert eines Einmalgutscheins: „… the remaining %4$s will be lost …“ bzw. mit „Keep the rest“ „… is not lost: it is kept for you as store credit …“ (4.3, LEGAL_TEXTS.md) |
| Kunde (Warenkorb) | Einzweckgutschein mit anderem Satz/Land: „This single-purpose voucher was sold with … VAT for … and can only be redeemed for goods with the same VAT rate and country …“ (4.1) |
| Kunde (Aufladung) | „This gift card cannot be topped up with this product: the card and the top-up are treated differently for VAT …“ (5.2) |
| Admin | Produkt- und Website-Zustand-Warnungen zum Steuerstatus und zu Einzweck bei mehreren Ländern (Abschnitt 1) |

Bekannte Textprobleme: „USt bereits beim Verkauf abgerechnet“ erscheint nur bei Einzweckgutscheinen (Pass Captain
bekommt für Aktionen `discount`, 4.2); die Beschriftungen der Einordnung im Produkt sind nach Freigabe (F12) anzupassen.

## 10. Bekannte Lücken (Umsetzung nach Freigabe)

Erledigt seit dem letzten Stand: Mehrzweck als Zahlung (Neuinstallationen an, bestehende Shops mit kritischer
Warnung, M12-03), Prüfung des Steuerstatus (Abschnitt 1), Satz/Land für Einzweck an Pass Captain und im Warenkorb
(4.1/4.2, M12-28), Ticket mit Geldwert (M12-10), Aufladung mit Einordnung (M12-12), Gutscheine ohne Verkauf (M12-42),
Journal mit Einordnung, Verfall und Restwert (Abschnitt 6).

1. **Theke, Scanner und REST-Einlösung** (`RedemptionService`) liefern keine Einordnung (`purpose`, `sale_tax`) und
   prüfen bei Einzweckgutscheinen Satz/Land nicht (keine Positionen) – M12-31.
2. **„Als Guthaben erstatten“** legt eine WooCommerce-Erstattung ohne Positionen und ohne USt an (M12-11, F18).
3. **Gutscheinzahlung als negative Gebührenzeile:** Bestell-Meta, REST-Feld und Filter für Exporte gibt es
   (Abschnitt 6.3); eine echte Teilzahlung (eigene Zahlungsart) ist offen (M12-30).
4. **Kaufpreis unter Nennwert** (Art. 73a MwStSystRL, z. B. 100‑€‑Karte für 90 € verkauft): das Plugin bucht und
   verrechnet den Nennwert (F19).
5. **Ablauf vor der Zustellung** (`delivery_expired`) wird ohne Betrag gebucht; nur der Ablauf eines zugestellten
   Gutscheins („expired“) trägt den Restwert.
6. Vor M12-42 ohne Bestellung ausgestellte Gutscheine haben kein Merkmal „verkauft“ (Abschnitt 2).

## 11. Fragen an die Steuerberatung

Bitte je Frage ankreuzen bzw. ergänzen. Neu nummeriert (M12-32): die frühere zweite F13 (Einzweck, anderes Land) ist
F14, die früheren F14/F15 sind F15/F16; F17–F21 sind neu. Wo eine Frage offen ist, steht die sichere Voreinstellung
des Codes dabei.

| Nr. | Frage | Voreinstellung im Code | Antwort |
|---|---|---|---|
| F1 | Ist die automatische Zuordnung in Abschnitt 2 (2.1–2.9) für unsere Shops (DE/AT) richtig? Abweichungen je Zeile. | wie Abschnitt 2 | ☐ ja ☐ nein, Abweichung: |
| F2 | Wertgutschein/Geschenkkarte für ein **gemischtes Sortiment** (unterschiedliche Steuersätze): Mehrzweck – bestätigt? | Mehrzweck | ☐ ja ☐ nein |
| F3 | Einzweckgutschein, bei Einlösung **anderer Satz oder anderes Land** als beim Verkauf: als Mehrzweck behandeln? Oder Einlösung sperren? | Warenkorb: nur passende Positionen, sonst gesperrt (4.1); Pass Captain: Mehrzweck (4.2) | ☐ Mehrzweck ☐ sperren ☐ anders: |
| F4 | Gutscheine **ohne Verkauf** (Kulanz, Preise, Bonus-, Empfehlungs- und Erstbestellungscodes): Preisnachlass – bestätigt? Gilt das auch für Kundenguthaben aus Empfehlungsprämien? | Preisnachlass (2.7, 2.8); Kundenguthaben `payment` | ☐ ja ☐ nein |
| F5 | Kauferstattung (5.1): reicht die WooCommerce-Erstattung (USt-Korrektur bei Einzweck, keine bei Mehrzweck) oder ist ein eigener Beleg nötig? | WooCommerce-Erstattung | |
| F6 | Barauszahlung von Restguthaben (z. B. AT bei geringem Rest, Ablauf): verpflichtend/zulässig? Steuerfolge? | keine Barauszahlung | |
| F7 | Verfall eines **Mehrzweck**-Gutscheins ohne Einlösung: Steuerfolge (keine USt?) und Ausweis in der Buchhaltung? | Journal „expired“ mit Restwert, DATEV gegen Ertragskonto ohne Steuerschlüssel (6.1, 6.4) | |
| F8 | Verfall eines **Einzweck**-Gutscheins: bleibt die beim Verkauf abgeführte USt? | keine Korrektur durch das Plugin | |
| F9 | **Ticketgutschein mit Geldwert** (2.4): Mehrzweck (so verrechnet und berichtet, M12-10) – oder Einzweck, wenn Veranstaltung und Satz feststehen? Ticketgutschein **ohne** Geldwert (2.5) für eine bestimmte Veranstaltung: Einzweck? Auch bei frei wählbarem Termin? | mit Geldwert: Mehrzweck (Automatik), per Produkt auf Einzweck umstellbar | |
| F10 | Prozentgutschein, der **verkauft** wird (2.3): Preisnachlass oder Gutschein? | Preisnachlass | |
| F11 | Geschenkkarten-Aufladung: eigener Mehrzweckgutschein-Verkauf, nicht steuerbar – bestätigt? Muss sie dieselbe Einordnung haben wie die Karte? | gleiche Einordnung Pflicht (5.2) | ☐ ja ☐ nein |
| F12 | Texte Abschnitt 9: fachlich korrekt? Gewünschte Formulierung für Kunden (z. B. auf Rechnung/Gutschein: „Mehrzweckgutschein, USt wird bei Einlösung berechnet“)? | | |
| F13 | Shops mit Nettopreisen: Guthabenkarte **mit USt verkauft** (Einzweck, Abschnitt 3 „Ausnahme“): Wert = bezahlter Bruttobetrag (50 € + USt = 60 €), bei Einlösung bezahlt sie 60 € brutto – bestätigt? Ist eine Karte mit Restguthaben überhaupt als Einzweckgutschein zulässig (Teileinlösungen, ggf. andere Sätze bei Einlösung, vgl. F3)? | Bruttowert (T7), auch für Aufladungen | ☐ ja ☐ nein, stattdessen: |
| F14 | 7.3: Ist ein in AT verkaufter **Einzweck**gutschein bei Einlösung durch einen DE-Kunden (anderer Leistungsort) als Mehrzweck zu behandeln? Ist Einzweck überhaupt zulässig, wenn der Shop in mehrere Länder verkauft (OSS)? | Warnung im Produkt/Website-Zustand; Einlösung nur bei gleichem Land (4.1) | |
| F15 | 7.5: Soll der Shop bei steuerpflichtigen Gutscheinen „gleicher Bruttopreis“ (7.4) verwenden, oder ist der WooCommerce-Standard akzeptabel? | WooCommerce-Standard | ☐ 7.4 ☐ Standard |
| F16 | 7.7: Wechsel vom Kleinunternehmer zur Regelbesteuerung – wie sind vorher verkaufte, später eingelöste Gutscheine zu behandeln? | keine Sonderbehandlung | |
| F17 | **Standard der Einstellung „Mehrzweckgutscheine als Zahlung“:** Neuinstallationen an – bestätigt? Sollen bestehende Shops umstellen, und wie sind dann die vor der Umstellung ohne USt verkauften und danach eingelösten Gutscheine (bzw. steuerpflichtig verkaufte, nach der Umstellung als Zahlung eingelöste) zu behandeln? | neu: an; bestehend: unverändert, kritische Warnung bei Mehrzweck + „Keiner“ (Abschnitt 1) | |
| F18 | **Erstattung als Guthaben** (5.2): Ist eine Korrektur nach § 17 UStG (Erstattung mit Positionen und USt) nötig, damit die spätere Einlösung des Guthabens (2.6, Mehrzweck) nicht doppelt versteuert wird? | heute ohne Positionen (offen, M12-11) | |
| F19 | **Kaufpreis unter Nennwert** (Art. 73a MwStSystRL, z. B. Rabattaktion „100‑€‑Karte für 90 €“, Wiederverkäufer): Bemessungsgrundlage bei Einlösung eines Mehrzweckgutscheins = gezahlter Betrag oder Nennwert? Muss das Plugin den Kaufpreis je Gutschein festhalten? | Nennwert (Kaufpreis steht im Zeilen-Schnappschuss) | |
| F20 | **Restwert eines Einmalgutscheins** (4.3): Verfall des Restes eines Mehrzweck-Einmalgutscheins ohne USt (Ertrag), beim Einzweck keine Korrektur – bestätigt? Ist die Umwandlung in Kundenguthaben/neue Karte („Keep the rest“) steuerlich neutral (Mehrzweck → Mehrzweck)? | „forfeited“ gegen Ertragskonto; Umwandlung neutral | |
| F21 | **Kontierung** (Abschnitt 6.3/6.4): Konten, keine Steuerschlüssel auf Gutscheinbuchungen, Gutscheinzahlung als Zahlung statt Erlösminderung – bestätigt? | wie 6.3 | |

## 12. Freigabe

| | |
|---|---|
| Geprüft durch (Kanzlei, Name) | |
| Datum | |
| Geltungsbereich (Länder, Shops) | |
| Ergebnis | ☐ freigegeben ☐ freigegeben mit Änderungen (siehe Antworten) ☐ nicht freigegeben |

Nach der Freigabe: Antworten in die Umsetzung der Lücken (Abschnitt 10) übernehmen, Tests anpassen, TODO.md-Punkt
abhaken und auf dieses Dokument mit Datum verweisen.
