# Steuerliche Behandlung von Gutscheinen – Freigabe-Dossier

**Status:** Entwurf zur Prüfung durch die Steuerberatung – **nicht freigegeben**.
**Bezug:** TODO.md, P1 „Steuerliche Zuordnung, Produkttexte und Erstattungsfolgen je Gutscheinart mit Steuerberatung
freigeben lassen“. Folgepunkte, die von den Antworten abhängen: TODO.md Abschnitt „Entscheidungen Steuer“
(Steuerstatus prüfen, Mehrzweck nach Steuer verrechnen; Einzweck-Satz/Land an Pass Captain: Abschnitt 4.2).
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

**Wichtig:** Der Steuerstatus und die Steuerklasse des *Gutscheinprodukts* (WooCommerce-Felder „Steuerstatus“,
„Steuerklasse“) bestimmen, ob beim Verkauf USt berechnet wird. Das Plugin setzt oder prüft diese Felder heute
**nicht** gegen die Einordnung oben (offener Punkt, Abschnitt 7).

## 2. Zuordnung je Gutscheinart (Einstellung „Automatisch“)

| Nr. | Gutscheinart | Wie entsteht er | Automatische Einordnung | Nachweis (`tests/tax-integration.php`) |
|---|---|---|---|---|
| 2.1 | **Wertgutschein** (Typ „Gutschein“, Festbetrag, einmalig) | verkauft als Produkt | `payment` (Mehrzweck) | „auto: a sold value voucher …“ |
| 2.2 | **Geschenkkarte** (Typ „Geschenkkarte“, Restguthaben) | verkauft als Produkt, auch Aufladung | `payment` (Mehrzweck) | „auto: a gift card with balance …“ |
| 2.3 | **Prozentgutschein** | verkauft als Produkt | `discount` (Preisnachlass) | „auto: a sold percentage voucher …“ |
| 2.4 | **Ticketgutschein** (Typ „Ticket“, mit Geldwert) | verkauft als Produkt | `payment` (wie Wertgutschein) | „auto: a sold ticket voucher …“ |
| 2.5 | **Ticketgutschein ohne Geldwert** (Eintritt für bestimmte Veranstaltung/Termin) | verkauft als Produkt; wird auf der Einlöseseite in Tickets getauscht, bezahlt keine Bestellung | im Warenkorb und bei Pass Captain als Zahlung gesperrt (`vcap_ticket_voucher`); Tausch erzeugt eine 0‑€‑Bestellung in Pass Captain | `tests/passcaptain-integration.php` „a ticket voucher cannot pay for a Pass Captain checkout …“ |
| 2.6 | **Kundenguthaben** (Typ „Guthaben“) | nicht verkauft: Erstattung als Guthaben, Empfehlungsprämie, manuelle Gutschrift | `payment` | „auto: store credit …“ |
| 2.7 | **Aktions-/Kampagnencode** (Festbetrag oder Prozent, ohne Verkauf) | im Admin erzeugt (Kampagnen, Bonusgutschein, Freunde werben, Erstbestellung) | `discount` | „auto: a value code without a sale …“, „auto: a percentage code …“ |

Eine ausdrückliche Einstellung am Produkt (`payment`, `single`, `discount`) gilt immer vor der Automatik
(Nachweis: „setting … wins …“).

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
- Nennwert: in Shops mit Bruttopreisen der Bruttopreis (Nachweis: „the voucher value is the gross price (120)“), in
  Shops mit Nettopreisen der Nettopreis.
- Rechnung/Beleg: erstellt WooCommerce bzw. das Rechnungs-Plugin des Shops; Voucher Captain gibt keine eigene
  Rechnung aus.

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
| `single` | USt wurde beim Verkauf berechnet; Einlösung ist keine neue steuerbare Leistung | USt nur auf den Rest | passt, **wenn** Satz und Land des Verkaufs mit der Bestellung übereinstimmen (Frage F3) |
| `payment` | Gutschein ist Zahlungsmittel; USt auf die volle Leistung (20 € im Beispiel) | USt nur auf den Rest (10 €) | **passt nicht** – zu wenig USt ausgewiesen; Umsetzung „nach Steuer verrechnen“ offen (TODO.md, Entscheidungen Steuer) |

Für Shops mit Nettopreisen zieht das Plugin Geschenkkarten und Guthaben brutto ab (60 € Guthaben bezahlen 60 € des
Bruttobetrags, nicht 60 € netto + Steuer; Filter `vcap_balance_covers_tax`, Nachweis
`tests/acceptance-integration.php`). An der Minderung der USt-Bemessungsgrundlage ändert das nichts.

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

## 5. Erstattungsfolgen

### 5.1 Erstattung des Gutscheinkaufs

| Fall | Verhalten | Einstellung / Nachweis |
|---|---|---|
| Kaufbestellung vollständig erstattet oder storniert | ausgestellte Gutscheine werden gesperrt | „Gutscheine bei Erstattung stornieren“ (`vcap_void_on_refund`, Standard an); `tests/tax-integration.php` „a fully refunded gift card purchase voids …“ |
| Teilerstattung (Menge) | so viele Gutscheine wie erstattet werden gesperrt, ungenutzte zuerst | `Issuer::on_partial_refund()`; `tests/acceptance-integration.php` „refunding one of two gift cards …“, `tests/connector-integration.php` |
| Gutschein schon (teilweise) eingelöst | Erstattung höchstens Bestellbetrag minus verbrauchter Wert; mehr lehnt WooCommerce mit Hinweis ab (Filter `vcap_allow_refund_of_spent_vouchers`) | `Issuer::guard_refund()`; `tests/acceptance-integration.php` „refunding value that was already spent …“ |
| Ticketgutschein bereits in Tickets getauscht | Kauferstattung sperrt den Gutschein und storniert die Tickets | `tests/passcaptain-integration.php` „voiding the voucher (purchase refunded) revokes its tickets“ |

Steuerliche Folge (vom Plugin nicht selbst gebucht): bei Einzweck Korrektur der beim Verkauf berechneten USt durch
die Erstattung in WooCommerce; bei Mehrzweck keine USt-Folge, da beim Verkauf keine berechnet wurde (Frage F5).

### 5.2 Erstattung einer mit Gutschein bezahlten Bestellung

| Fall | Verhalten | Einstellung / Nachweis |
|---|---|---|
| Storniert, fehlgeschlagen oder vollständig erstattet | eingesetzter Gutschein-/Guthabenbetrag wird zurückgebucht; einmalige Gutscheine wieder gültig | „Guthaben bei Stornierung wiederherstellen“ (`vcap_restore_on_cancel`, Standard an); `tests/tax-integration.php` „cancelling the redemption order …“, `tests/acceptance-integration.php` „a full refund restores the used balance …“ |
| Teilerstattung | WooCommerce erstattet über die Zahlungsart; Gutscheinsalden bleiben unverändert | README.md „Erstattung“ |
| „Als Guthaben erstatten“ | nur ausdrücklich durch das Personal, nicht für Gastbestellungen; erzeugt Kundenguthaben (2.6) | `tests/store-credit-integration.php` (`refund_as_credit`) |
| Geschenkkarten-Aufladung storniert/erstattet | Aufladung wird zurückgenommen, soweit das Guthaben noch vorhanden ist | `src/TopUp.php::revoke()`; `tests/acceptance-integration.php` (Aufladung) |
| Pass Captain: Tickets einer Gutscheinzahlung erstattet | Pass Captain bucht den Gutscheinanteil genau einmal zurück; Abgleich unter Gutscheine → Auswertung → Abgleich | `tests/passcaptain-integration.php` „the voucher share of the refunded ticket returns …“ |

Barauszahlung von Restguthaben ist im Plugin nicht vorgesehen (Frage F6).

## 6. Texte, die Shop und Kunden sehen

| Ort | Text (englischer Quelltext / deutsche Übersetzung) |
|---|---|
| Produkt → Gutschein → „VAT treatment“ | *Automatic (value vouchers: multi-purpose, percentage: discount)* / *Automatisch (Wertgutscheine: Mehrzweck, Prozent: Rabatt)* |
| | *Multi-purpose voucher (means of payment, VAT on redemption)* / *Mehrzweckgutschein (Zahlungsmittel, USt bei Einlösung)* |
| | *Single-purpose voucher (VAT already charged at sale)* / *Einzweckgutschein (USt bereits beim Verkauf abgerechnet)* |
| | *Promotion (price reduction)* / *Rabattaktion (Preisnachlass)* |
| Hilfetext | *How connected checkouts (e.g. WP Pass Captain) treat the voucher for VAT. Have the classification checked by your tax adviser.* |
| Kunde | Gutscheintexte enthalten keine Steueraussage; Einlösebedingungen kommen aus den Ländertexten (Einstellungen → Bedingungen je Land) |

Bekannte Textprobleme: „USt bereits beim Verkauf abgerechnet“ darf nicht für Rabattaktionen erscheinen (TODO.md
„Einzweckgutschein …“); der Hilfetext sagt nicht, dass der WooCommerce-Warenkorb heute alle Arten als Preisnachlass
rechnet (Abschnitt 4.1) – wird nach Freigabe angepasst.

## 7. Bekannte Lücken (Umsetzung nach Freigabe)

1. **Mehrzweck im WooCommerce-Warenkorb** wird als Preisnachlass verrechnet statt als Zahlungsmittel nach Steuer
   (Abschnitt 4.1). Folge: zu wenig ausgewiesene USt auf Einlösebestellungen.
2. **Steuerstatus des Gutscheinprodukts** wird nicht gegen die Einordnung geprüft (Mehrzweck mit Steuer verkauft =
   doppelte Besteuerung; Einzweck ohne Steuer verkauft = keine Besteuerung).
3. **Einzweck: Satz/Land** des Verkaufs werden nicht an Pass Captain übergeben und nicht mit der Einlösung verglichen.
4. **Aktionscodes ohne Verkauf**, die als Festbetrag wirken (Bonus, Freunde werben), werden als Preisnachlass
   behandelt. Ob Prämien steuerlich anders einzuordnen sind, ist offen (F4).

## 8. Fragen an die Steuerberatung

Bitte je Frage ankreuzen bzw. ergänzen.

| Nr. | Frage | Antwort |
|---|---|---|
| F1 | Ist die automatische Zuordnung in Abschnitt 2 (2.1–2.7) für unsere Shops (DE/AT) richtig? Abweichungen je Zeile. | ☐ ja ☐ nein, Abweichung: |
| F2 | Wertgutschein/Geschenkkarte für ein **gemischtes Sortiment** (unterschiedliche Steuersätze): Mehrzweck – bestätigt? | ☐ ja ☐ nein |
| F3 | Einzweckgutschein, bei Einlösung **anderer Satz oder anderes Land** als beim Verkauf: als Mehrzweck behandeln? Oder Einlösung sperren? | ☐ Mehrzweck ☐ sperren ☐ anders: |
| F4 | Bonus-, Empfehlungs- und Erstbestellungscodes (ohne Verkauf, Festbetrag): Preisnachlass – bestätigt? Gilt das auch für Kundenguthaben aus Empfehlungsprämien? | ☐ ja ☐ nein |
| F5 | Kauferstattung (5.1): reicht die WooCommerce-Erstattung (USt-Korrektur bei Einzweck, keine bei Mehrzweck) oder ist ein eigener Beleg nötig? | |
| F6 | Barauszahlung von Restguthaben (z. B. AT bei geringem Rest, Ablauf): verpflichtend/zulässig? Steuerfolge? | |
| F7 | Verfall eines **Mehrzweck**-Gutscheins ohne Einlösung: Steuerfolge (keine USt?) und Ausweis in der Buchhaltung (Verbindlichkeitenbericht Abschnitt „Abgelaufen“)? | |
| F8 | Verfall eines **Einzweck**-Gutscheins: bleibt die beim Verkauf abgeführte USt? | |
| F9 | Ticketgutschein ohne Geldwert (2.5) für eine bestimmte Veranstaltung: Einzweck (Ort und Satz stehen fest)? Gilt das auch, wenn der Termin frei wählbar ist? | |
| F10 | Prozentgutschein, der **verkauft** wird (2.3): Preisnachlass oder Gutschein? | |
| F11 | Geschenkkarten-Aufladung: eigener Mehrzweckgutschein-Verkauf, nicht steuerbar – bestätigt? | ☐ ja ☐ nein |
| F12 | Texte Abschnitt 6: fachlich korrekt? Gewünschte Formulierung für Kunden (z. B. auf Rechnung/Gutschein: „Mehrzweckgutschein, USt wird bei Einlösung berechnet“)? | |

## 10. Steuerfälle: Sätze, Leistungsort (OSS), gleicher Bruttopreis, Kleinunternehmer

Voucher Captain rechnet **keine Steuersätze selbst**: Sätze, Länder und Klassen kommen aus WooCommerce
(WooCommerce → Einstellungen → Steuer). Das Plugin legt fest, *welcher Wert* ein Gutschein hat und *wie viel* von
ihm abgebucht wird. Spezifikation und Nachweis (`tests/tax-integration.php`, Abschnitt 7):

| Nr. | Fall | Festgelegtes Verhalten | Nachweis |
|---|---|---|---|
| 10.1 | **Mehrere Steuersätze in einer Bestellung** (z. B. 20 % und 10 %), Bruttopreise | WooCommerce verteilt den Gutscheinbetrag anteilig auf die Positionen; jede Position behält ihren Satz; abgebucht wird genau der bezahlte Betrag. Beispiel: 120 € (20 %) + 110 € (10 %), Karte 115 € → Rest 115 € (netto + USt beider Positionen), Karte 0 €. Die Aufteilung der USt auf die Sätze folgt der WooCommerce-Verteilung. | „mixed rates, gross prices …“ (3 Prüfungen) |
| 10.2 | Mehrere Steuersätze, **Nettopreise** | Karte bezahlt brutto (Nettoanteil + dessen USt) über beide Sätze; abgebucht wird genau der Kartenwert. | „mixed rates, net prices …“ |
| 10.3 | **Leistungsort im anderen EU-Land (OSS)**, Steuer nach Rechnungsland | Die Bestellung trägt nur den Satz des Käuferlands (z. B. DE 19 %); die Karte bezahlt brutto und wird genau um den bezahlten Betrag belastet. Der Gutscheinwert bleibt in der Shop-Basiswährung; das Land der Einlösung spielt für den Wert keine Rolle. | „OSS: …“ (3 Prüfungen) |
| 10.4 | **Gleicher Bruttopreis in allen Ländern** (WooCommerce-Filter `woocommerce_adjust_non_base_location_prices` = false) | Ein steuerpflichtiger Gutschein für „50 €“ kostet überall 50 € brutto und ist 50 € wert. | „same gross price …“ |
| 10.5 | **WooCommerce-Standard** (Netto bleibt, Satz des Käuferlands kommt dazu) | Bei steuerpflichtigem Gutscheinprodukt zahlt ein DE-Käufer 49,58 € statt 50 € (Basis AT 20 %). Der Gutschein ist **genau das wert, was bezahlt wurde** (nie mehr). Für Gutscheine mit festem Nennwert daher Mehrzweck mit Steuerstatus „Keiner“ (10.6) oder gleichen Bruttopreis (10.4) verwenden. | „WooCommerce default …“ |
| 10.6 | Mehrzweckgutschein **nicht steuerbar** verkauft | Kostet und ist in jedem Land gleich viel wert (keine Länderanpassung ohne Steuer). | „a multi-purpose card sold as not taxable …“ |
| 10.7 | **Kleinunternehmer** (Steuer im Shop aus) | Verkauf und Einlösung ohne USt; Gutschein ist den bezahlten Betrag wert und bezahlt genau diesen. Die Einordnung Einzweck/Mehrzweck hat keine Steuerwirkung. Pass Captain übernimmt seinen Kleinunternehmer-Hinweis selbst. | „small business: …“ (2 Prüfungen) |
| 10.8 | **Fremdwährung** (Mehrwährungs-Plugin) | Gutscheinwerte und Salden in der Basiswährung, Umrechnung über `vcap_amount_to_base_currency`; Freigabe je Plugin unter TODO.md „Reale Zahlungsanbieter, Mehrwährungs- …“ | `tests/acceptance-integration.php` |

Offene Fragen dazu an die Steuerberatung:

| Nr. | Frage | Antwort |
|---|---|---|
| F13 | 10.3: Ist ein in AT verkaufter **Einzweck**gutschein bei Einlösung durch einen DE-Kunden (anderer Leistungsort) als Mehrzweck zu behandeln? (Folgepunkt TODO.md „Einzweckgutschein: Steuersatz und Land …“) | |
| F14 | 10.5: Soll der Shop bei steuerpflichtigen Gutscheinen „gleicher Bruttopreis“ (10.4) verwenden, oder ist der WooCommerce-Standard akzeptabel? | ☐ 10.4 ☐ Standard |
| F15 | 10.7: Wechsel vom Kleinunternehmer zur Regelbesteuerung – wie sind vorher verkaufte, später eingelöste Gutscheine zu behandeln? | |

## 11. EU-weite Steuerfälle (Zielmarkt EU‑27)

Gilt für jedes Land, an das der Shop verkauft (WooCommerce „Verkaufsorte“). Voucher Captain speichert **keine
Steuersätze**; Sätze, OSS-Länder und Leistungsort kommen aus den WooCommerce-Steuereinstellungen. Länderdaten
(Währung, Sprache, gesetzliche Gültigkeit) stehen in `config/markets.php` (COMPATIBILITY.md „EU target market“).

| Thema | Festgelegtes Verhalten (gilt in allen EU-Ländern gleich) | Nachweis |
|---|---|---|
| OSS / Leistungsort | WooCommerce berechnet die USt nach Käuferland (Abschnitt 10.3); Gutscheine verändern den Satz nicht. | `tests/tax-integration.php` 7.3 |
| Bruttopreise | Gutscheinwert = tatsächlich bezahlter Bruttobetrag; „gleicher Bruttopreis“ und WooCommerce-Standard beide unterstützt (10.4/10.5). | 7.4 |
| Gutscheinbesteuerung | Richtlinie (EU) 2016/1065 ist in allen Mitgliedstaaten umgesetzt; Einordnung je Produkt (Abschnitt 1–4). Länderspezifische Abweichungen nur über `vcap_voucher_tax_treatment`. | Abschnitt 2 |
| Positionsbezogene Erstattung | Je Bestellposition: erstattete Menge, Netto **und USt** (`order_lines()` → `refunded_quantity`, `refunded_total`, `refunded_tax`); Gutscheine der Position werden in dieser Menge gesperrt. | „line-based refund …“, `tests/acceptance-integration.php` |
| Kleinunternehmer (z. B. DE § 19, AT § 6 Abs. 1 Z 27) | Steuer im Shop aus → keine USt bei Verkauf und Einlösung (10.7). | 7.5 |

Prüfung je Land durch die Steuerberatung (je Zeile ankreuzen):

| Land | Satz/OSS in WooCommerce eingerichtet | Einordnung Mehrzweck/Einzweck bestätigt (F1) | Verfall/Erstattung bestätigt (F5–F8) | Besonderheiten |
|---|---|---|---|---|
| DE | ☐ | ☐ | ☐ | |
| AT | ☐ | ☐ | ☐ | |
| weitere Verkaufsländer: | ☐ | ☐ | ☐ | |

## 9. Freigabe

| | |
|---|---|
| Geprüft durch (Kanzlei, Name) | |
| Datum | |
| Geltungsbereich (Länder, Shops) | |
| Ergebnis | ☐ freigegeben ☐ freigegeben mit Änderungen (siehe Antworten) ☐ nicht freigegeben |

Nach der Freigabe: Antworten in die Umsetzung der Lücken (Abschnitt 7) übernehmen, Tests anpassen, TODO.md-Punkt
abhaken und auf dieses Dokument mit Datum verweisen.
