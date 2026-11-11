# TODO – Voucher Captain

Ziel: Voucher Captain bleibt ein allgemein einsetzbares WooCommerce-Gutschein-Plugin. Anbindungen an ein bestimmtes Lern-, Kurs- oder Mitgliedersystem kommen in separate Connector-Plugins.

## Status und Repository-Hinweis

Diese Liste ist eine Arbeitsliste, kein verifizierter Fortschrittsbericht. Der Entwicklungsbranch enthält bereits umfangreiche Voucher-Captain-Funktionen; bestehende Funktionen gelten erst dann als erledigt, wenn sie gegen Scope und Abnahmekriterien geprüft und hier nachvollziehbar markiert wurden. Die Liste darf nicht so gelesen werden, dass alle angeführten Grundfunktionen noch fehlen.

Der ausführbare Plugin-Code liegt aktuell auf `claude/youthful-bardeen-7ejd7a` (enthält auch `codex/fix-voucher-audit-findings`). Der Standardbranch `main` enthält noch nicht diesen Code. Der Entwicklungsbranch muss über einen geprüften Pull Request in `main` überführt werden, bevor `main` als Release-Quelle gilt.

### Abgleich vom 11.11.2026 (Version 1.1.0)

Abgehakt sind nur Punkte, die im Code umgesetzt **und** automatisch geprüft sind. Nachweise: `tests/run.php` (ohne WordPress), sowie die CI-Suiten `tests/woocommerce-integration.php`, `tests/compatibility-integration.php`, `tests/security-integration.php`, `tests/redemption-integration.php` und `tests/order-lifecycle-integration.php`, die gegen WordPress 7.1.4, WooCommerce 11.2.2 und PHP 8.4 laufen (MySQL 8.4 und MariaDB 11.4, jeweils mit HPOS und mit klassischem Bestellspeicher), plus Installation, Update und Deinstallation des Release-ZIPs. Teilweise erledigte Punkte bleiben offen und tragen einen Vermerk *(teilweise: …)*.

## P0 – Repository, Status und Produktgrenze

- [ ] Pull Request des bestehenden Entwicklungsbranchs nach `main` vorbereiten, bestehende Änderungen prüfen und nach den Release-Gates mergen.
- [x] SCOPE, Quellcode und TODO gegeneinander abgleichen; vorhandene Funktionen mit dokumentierten Abnahmekriterien verifizieren und erst dann erledigt markieren. — *Nachweis: dieser Abgleich*
- [ ] Voucher-Captain-Grenze gegenüber Ticket Captain festschreiben: Gutscheine/Geschenkkarten/Discounts und Gutschein-Einlösung bleiben bei VCAP; Events, Sitzplätze, Kapazitäten, Eventbestellungen und Event-Check-in gehören TCAP.
- [ ] Bestehende Voucher-Scanner- und Ticket-ähnliche Funktionen inventarisieren; klare Migrations-/Koexistenzregel definieren, damit Event-Einlass nur den TCAP-Ticketstatus verändert.
- [ ] Release-Prüfung für VCAP allein, TCAP allein und beide Plugins gemeinsam als Pflichtkriterium dokumentieren.

## P0 – Öffentliche Einlöse-Schnittstelle

- [x] Einen versionierten, dokumentierten öffentlichen PHP-Service für Gutscheinabfragen und -einlösungen bereitstellen. Keine Connector-Abhängigkeit von internen Klassen oder Tabellen. — *Nachweis: `vcap()->redemptions` (ohne Bestellung) und `vcap()->api_v1` (bestellbezogen), API.md*
- [x] Ein generisches Redemption-Provider-Konzept festlegen, über das Add-ons eigene Einlösearten registrieren können. — *Nachweis: `VCAP\\Contracts\\RedemptionProvider` + Filter `vcap_redemption_providers` (bestellbezogen); Einlöseziele über `vcap_redemption_allowed` / `vcap_redemption_target_available`*
- [x] Den Einlöseablauf als Zustandsübergang mit Reservierung, erfolgreichem Abschluss und wiederholbarem Fehlerfall umsetzen. — *Nachweis: reserve → commit/release, automatische Freigabe nach Ablauf; tests/redemption-integration.php*
- [x] Einlöseversuche mit Idempotency-Key absichern, damit parallele Requests oder Wiederholungen keinen doppelten Verbrauch und keine doppelte Leistung erzeugen. — *Nachweis: eindeutiger Schlüssel pro Einlösung, Konflikt bei abweichender Anfrage; tests/redemption-integration.php*
- [x] WooCommerce-Warenkorb und externe Einlösung auf denselben Gutscheinstatus prüfen lassen. Ein Code darf nicht über beide Wege doppelt eingelöst werden. — *Nachweis: Status `reserved`/`redeemed` wird im Warenkorb abgelehnt; tests/redemption-integration.php*
- [x] Ein Ereignis nach erfolgreichem Abschluss dokumentieren, z. B. `vcap_voucher_redeemed`, mit Gutschein-ID, Code, Nutzer-ID, Ziel-ID, Einlöse-ID und Zeitstempel. — *Nachweis: `vcap_voucher_redeemed` (API.md), feuert genau einmal*
- [x] Stabile Fehlercodes und API-Antworten definieren: ungültig, abgelaufen, bereits eingelöst, nicht berechtigt, Ziel nicht verfügbar, vorübergehender Fehler. — *Nachweis: Fehlercode-Tabelle in API.md*
- [x] Für browserbasierte REST-Aufrufe WordPress-Login-Cookies und Nonce unterstützen; bei externen Servern eine eng begrenzte Authentifizierung vorsehen. — *Nachweis: REST `/wp-json/vcap/v1/redemptions`, Server über WordPress-Anwendungspasswörter*
- [x] Nutzeridentität ausschließlich aus dem authentifizierten Request ableiten; keine frei übergebene `user_id` vertrauen. — *Nachweis: REST ignoriert `user_id`; getestet*
- [x] Rate-Limit für fehlgeschlagene Codeversuche implementieren und konfigurierbar machen. — *Nachweis: Einstellung „Fehlversuche mit ungültigen Codes“; getestet*

## P1 – Allgemeine Gutschein-Grundlage

- [x] Gutscheintyp und Einlöseweg generisch erweiterbar machen, ohne Kurs- oder LMS-Felder in den Plugin-Kern aufzunehmen. — *Nachweis: Filter `vcap_voucher_types`, Einlösewege `shop`/`external` pro Produkt („Einlösbar über“, am Verkauf festgeschrieben) mit Filtern `vcap_redemption_channels`, `vcap_redemption_channel_for_target`, `vcap_redemption_channel_allowed`; API.md; tests/connector-integration.php*
- [x] Transferierbarkeit als klare Produkteinstellung abbilden und für Vorlagen/E-Mails als Platzhalter bereitstellen. — *Nachweis: Produktoption „Übertragbar“, Platzhalter `{transferable}`; nicht übertragbare Gutscheine sind auf Käufer/Empfänger-E-Mail beschränkt; tests/account-integration.php*
- [x] Ablaufdatum, Restwert, Übertragbarkeit und Einlösebedingungen in Admin, Kundenkonto und PDF-Vorlagen ausgeben. — *Nachweis: Admin-Detail, Kundenkonto-Tab, E-Mail, Produktseite, Platzhalter `{redemption_terms}`/`{transferable}`; Bedingungen werden beim Kauf am Gutschein festgeschrieben*
- [x] Erneuten Versand für berechtigte Käufer im Kundenkonto ergänzen. — *Nachweis: Button „E-Mail erneut senden“ für zugestellte eigene Gutscheine, 3×/Stunde; tests/account-integration.php*
- [x] Code-Standard auf mindestens 12 zufällige Zeichen setzen; verwechslungsanfällige Zeichen weiter ausschließen. — *Nachweis: Standard 12, Minimum 8, ohne 0/O/1/I*
- [x] Rabattierte Gutscheinbestellungen anhand des tatsächlich bezahlten Positionsbetrags berechnen; Steueranteile und Mehrwährung gesondert berücksichtigen. — *Entscheidung 11.11.2026: Ein Gutschein ist immer seinen Nennwert wert (z. B. 50-€-Gutschein mit 10 % Rabatt gekauft = 50 €). Steuer und Mehrwährung werden berücksichtigt; tests/zero-total-integration.php*
- [x] Auftrags- und Erstattungsdaten positionsbezogen für angeschlossene Plugins verfügbar machen. — *Nachweis: `vcap()->api_v1->order_lines()` mit Mengen, Summen, Steuern, Erstattungen, Gutscheinen und Positions-Snapshot je Zeile; tests/connector-integration.php*
- [x] Verhalten für vollständig rabattierte 0-Euro-Bestellungen festlegen und mit Bestellstatus-/Zugangs-Integrationen abstimmen. — *WooCommerce-Standard: gilt als bezahlt, Gutscheine werden sofort ausgestellt und Guthaben abgebucht; tests/zero-total-integration.php*
- [x] WooCommerce-CRUD-APIs für Bestellungen und Gutscheine verwenden; HPOS-Unterstützung verifizieren. — *Nachweis: CI-Matrix HPOS und klassischer Bestellspeicher*
- [x] Kompatibilität der Produktfelder mit Produkt-, Warenkorb- und Kassenblöcken herstellen und testen. — *Nachweis: Produktformular-Block nutzt die klassischen Hooks; Store API nimmt `vcap_fields` beim Hinzufügen an, prüft Pflicht-/Formatregeln (RouteException), Felder erscheinen im Warenkorb-Block und landen in den Bestellpositionen; Produkte mit Pflichtfeldern verlinken in Produktlisten auf die Produktseite; tests/blocks-integration.php. Browser-Abnahme bleibt Release-Prüfung.*
- [x] Datenschutzauskunft, Löschung und konfigurierbare Aufbewahrungsfristen für Käufer- und Empfängerdaten abschließen. — *Nachweis: Exporter/Eraser inkl. Einlösungen, Aufbewahrungsfrist; tests/security-integration.php*

## P1 – Steuer, Rabattregeln und Gutscheincodes
- [x] Rabattcodes und Gutscheine/Geschenkkarten dürfen Gutscheinprodukte standardmäßig nicht verbilligen bzw. bezahlen (Entscheidung 11.11.2026, branchenüblich); beides pro Shop freigebbar — *Nachweis: Einstellungen „Rabattcodes auf Gutscheine“ und „Gutscheine mit Gutscheinen bezahlen“, andere Produkte im Warenkorb bleiben rabattierbar; tests/purchase-rules-integration.php*

- [ ] Einzweck- und Mehrzweckgutschein als konfigurierbare Steuerbehandlung modellieren; steuerliche Umsetzung und Produkttexte mit Steuerberatung freigeben lassen.
- [ ] Steuerfälle für OSS, unterschiedliche Leistungsorte/-steuersätze, Kleinunternehmer und Preise mit gleichem Bruttopreis spezifizieren und verifizieren.
- [x] Produkt-, Kategorie- und SKU-Regeln samt Ausschlüssen für Rabattcodes unterstützen. — *Nachweis: Produkte/Kategorien und Ausschlüsse sind WooCommerce-Standard; neue Felder „Artikelnummern“ und „Artikelnummern ausschließen“ mit Präfix-Platzhalter *, auch für Warenkorb-Festbeträge; tests/sku-rules-integration.php*
- [x] Gesamtlimit, Limit pro Kunde, Mindestbestellwert, Ablauf und Erstkaufbedingung konfigurierbar machen. — *Nachweis: Gesamtlimit, Limit pro Kunde, Mindestbestellwert und Ablauf sind WooCommerce-Standard; neue Gutschein-Option „Nur für die erste Bestellung“ (Konto oder Rechnungs-E-Mail); tests/first-order-integration.php*
- [x] Erweiterungspunkte für „Kunde besitzt Kurs/Produkt X“ und Konflikte mit externen Mitgliederrabatten/Kombipreisen dokumentieren. — *Nachweis: CONNECTORS.md Abschnitt 5*
- [x] Coupon-Link mit sicherer automatischer Anwendung ergänzen, z. B. `?gutschein=CODE`. — *Nachweis: Einstellung „Gutschein-Links“ (standardmäßig aus), einmalige Anwendung über WooCommerce-Prüfung, Parameter wird per Redirect entfernt; tests/coupon-link-integration.php*
- [x] Generierung mehrerer Codes nach Präfix/Länge und CSV-Export für Partner- und Kampagnenpakete ergänzen. — *Nachweis: Gutscheine → Codes generieren (bis 1000 je Lauf, aus Vorlage-Gutschein oder in Code-Pool-Stapel), CSV-Export je Kampagne/Stapel; tests/campaign-integration.php*
- [x] Sicherstellen, dass Coupons keine Katalog-Streichpreise erzeugen und der Rabatt erst im Warenkorb erscheint. — *Nachweis: Preis, Preisanzeige und Angebotsstatus bleiben unverändert, Rabatt nur im Warenkorb; tests/first-order-integration.php*

## P2 – Integrations- und Betriebsverhalten

- [x] Gruppenpaket-Verhalten dokumentieren: Gutschein/Rabatt je Platz oder je Paket. — *Nachweis: CONNECTORS.md Abschnitt 4 (empfohlen: je Platz)*
- [x] Einlöse-, Guthaben- und Rückbuchungshistorie mit Nutzer, Zeitpunkt, Auftrag und externem Referenzschlüssel führen. — *Nachweis: Ereignisprotokoll je Gutschein (Nutzer, Zeit, Bestellung) und Tabelle `vcap_redemptions` mit Einlöseschlüssel*
- [x] Regeln für Rückerstattung/Storno vor und nach einer externen Einlösung definieren. — *Nachweis: CONNECTORS.md Abschnitt 3; stornierte Gutscheine können während einer Reservierung nicht mehr bestätigt werden (`vcap_cancelled`), tests/connector-integration.php*
- [x] Fehlgeschlagene externe Freischaltungen wiederholbar machen, ohne Gutscheinverbrauch oder Zugriff zu duplizieren. — *Nachweis: Muster in CONNECTORS.md Abschnitt 2 und Beispiel-Connector mit Wiederholungsjob und Freigabe nach Limit; tests/example-connector-integration.php*
- [x] Integrationsdokumentation, Beispiel-Connector und öffentliche API-Versionspolitik veröffentlichen. — *Nachweis: CONNECTORS.md (inkl. Versionspolitik), ADAPTERS.md, examples/vcap-example-connector*

## P2 – Qualitätssicherung und Veröffentlichung

- [ ] Automatisierte Integrationsprüfungen für VCAP allein und gemeinsam mit TCAP einrichten; WooCommerce-Statuswiederholungen, parallele Einlösungen, Timeout, Teil-/Vollerstat­tung und 0-Euro-Checkout abdecken. *(teilweise: VCAP allein inkl. Statuswiederholungen, Teil-/Vollerstattung und Abbruch; TCAP fehlt; 0-Euro-Checkout in tests/zero-total-integration.php)*
- [ ] Release-Prüfung mit HPOS, klassischer Kasse, Checkout-Blöcken, unterstützten Mehrwährungs-/Subscription-Integrationen und minimaler WordPress-/PHP-Version dokumentieren. *(automatisiert: HPOS/Legacy-Matrix, klassische Kasse und Checkout-Blöcke im Browser, Mindestversionen; offen: echte Mehrwährungs-/Subscription-Plugins und Zahlungsanbieter)*
- [x] Build-ZIP in einer sauberen WordPress-Installation installieren, aktivieren, aktualisieren und deinstallieren; bei gemeinsamer Installation Klassen-/Vendor-Konflikte prüfen. — *Nachweis: CI-Schritt „Build, install, and update the release ZIP“ + Deinstallationsprüfung; Konflikte mit TCAP noch offen, siehe Native Integration*
- [ ] Übersetzungen, Datenschutzauskunft/-löschung, Berechtigungen, Nonces, Rate-Limits und Admin-/Kunden-E-Mail-Vorlagen für Release freigeben.

## Optional nach dem ersten Kunden-Connector

- [x] E-Mail-Erinnerung vor Ablauf eines Gutscheins. — *Nachweis: Einstellung „Erinnerung vor Ablauf“ (aus, bis Tage gesetzt sind), WooCommerce-E-Mail „Erinnerung vor Gutschein-Ablauf“, einmal je Gutschein; tests/reminder-integration.php*
- [x] Statistiken nach Kampagne und Partner. — *Nachweis: Gutscheine → Auswertung: Zeitraum, je Code-Kampagne/Partner, Aktion, Bonus, Gutscheine und Einzelcode ausgegebene Codes, Nutzungen, Bestellungen, Rabatt und Umsatz abzüglich Erstattungen, Fremdwährungen getrennt, CSV-Export; tests/reports-integration.php*
- [x] Empfehlungs-/Freunde-werben-Codes. — *Nachweis: Einstellung „Freunde werben“ (standardmäßig aus), persönlicher Erstbestellungs-Code im Kundenkonto mit Teilen-Link, eigene Codes gesperrt, Prämie als Gutschein per E-Mail oder Guthaben nach abgeschlossener Bestellung, einmal pro Bestellung, Auswertung je Werbendem; tests/referral-integration.php*
- [x] Allgemeine Einlöseseite oder Block/Shortcode, den Connector-Plugins erweitern können. — *Nachweis: `[vcap_redeem]` / Block „Gutschein einlösen“; Connectoren nutzen die Einlöse-API (API.md)*

## Native Integration: Voucher Captain + Ticket Captain

Voucher Captain und Ticket Captain sind eigenständige Plugins. Wenn beide aktiv sind, integrieren sie sich direkt; ein separates Connector-Plugin zwischen ihnen ist nicht erforderlich. Beide Plugins müssen auch allein funktionsfähig bleiben.

- [x] Gemeinsamen, versionierten PHP-Vertrag für Gutschein-Einlösung und Ticketausstellung festlegen. Keine direkten Abhängigkeiten auf interne Klassen oder Datenbanktabellen des jeweils anderen Plugins. *(Gutschein-Einlösung im Pass-Captain-Checkout: `wp_pass_captain_voucher_provider_v1`; Ticketausstellung aus einem Gutschein: `wp_pass_captain_ticket_service_v1`)*
- [x] Bei gemeinsamer Installation Ticket Captain als Einlöseziel/Aktion für passende Voucher-Captain-Gutscheine anbieten. *(Einlöseseite, Gutscheinverwaltung „In Tickets umwandeln“, `vcap()->passcaptain_tickets->exchange()`)*
- [x] Für ein Gutscheinprodukt konfigurierbar machen, welches Pass-Captain-Ticket bei erfolgreicher Einlösung ausgestellt wird. Die Ticketausstellung erst nach bestätigter Gutschein-Einlösung auslösen. *(reservieren → Tickets ausstellen → bestätigen)*
- [ ] WooCommerce-Ticketprodukte weiterhin direkt nach erfolgreichem Kauf ausstellen können; dafür ist kein Gutschein erforderlich.
- [x] Ereignisvertrag mit eindeutiger Bestellpositions- bzw. Einlöse-ID, Nutzer/Empfänger, Zielprodukt und Zeitstempel definieren. Ticket-Ausstellung muss idempotent sein.
- [x] Gutscheineinlösung und Ticketausstellung so koordinieren, dass Wiederholungen/Timeouts keinen Doppelverbrauch und keine doppelten Tickets erzeugen; fehlgeschlagene Zustellung sicher wiederholbar machen.
- [x] Rückerstattung, Storno und Gutscheinwiderruf zwischen den Plugins abstimmen; Ticketstatus und Gutscheinstatus nachvollziehbar protokollieren. *(storniert → Tickets widerrufen; Gutscheinverlauf und Pass-Captain-Audit-Log)*
- [ ] In Admin-Oberflächen den jeweils anderen Plugin-Status und fehlende Voraussetzungen verständlich anzeigen, ohne das jeweils andere Plugin zur Pflichtabhängigkeit zu machen.
- [ ] Voucher Captain und Ticket Captain als getrennte Plugin-Einträge und eigene Admin-Menüs beibehalten; gegenseitige Links und optional gemeinsame Scannerfunktion ergänzen, ohne Menüs oder Pluginverwaltung zusammenzulegen.

## Connector für Learnboating (kundenspezifisches Add-on)

Befund im Kursplugin: `/wp-json/boatingschool/v1/coupons/redeem` ist derzeit für Gruppen-Beitrittscodes bestimmt. Der `CouponController` lehnt WooCommerce-Coupons ausdrücklich ab. WooCommerce-Kursprodukte werden über „Lernzugang“ in `bp_product_access` Kursen/Features und Laufzeiten zugeordnet; `OrderSync` und `EntitlementService` erzeugen daraus idempotent Zugänge für Bestellpositionen. Es gibt noch keinen öffentlichen, idempotenten Dienst zum Freischalten eines Kurszugangs aus einer externen Gutscheineinlösung. `EntitlementService::grantManual()` ist dafür kein sicherer Ersatz: es erzeugt bei jedem Aufruf neue manuelle Zugänge mit neuer Referenz.

- [ ] Learnboating-Vertrag ergänzen: stabile PHP-Schnittstelle oder authentifizierte REST-Route zum Prüfen und Einlösen eines Kursgutscheins. Nicht die Gruppen-Code-Route `/coupons/redeem` umwidmen.
- [ ] Vertragseingaben festlegen: Voucher Captain Einlöse-ID als Idempotency-Key, authentifizierter Learnboating-Nutzer, zugeordnetes WooCommerce-Kursprodukt oder Variation, Kurs-Feature(s), gewünschter Startzeitpunkt und Voucher Captain Herkunftsreferenz.
- [ ] Learnboating soll Kurs und Feature(s), Laufzeit, Zugangskonflikte und Kursstatus anhand seiner bestehenden Produktzuordnung „Lernzugang“ (`ProductAccessRepository`) validieren. Keine zweite, abweichende Kurs-/Laufzeit-Konfiguration im allgemeinen Voucher Captain anlegen.
- [ ] In Learnboating einen idempotenten Grant-Service ergänzen. Er muss denselben Einlöse-Key wiedererkennen, eine eindeutige Referenz am Zugang speichern und für Audit/Support Quelle, Voucher-ID, Kursprodukt, Nutzer und Einlösezeitpunkt protokollieren.
- [ ] Start der Kurslaufzeit auf bestätigten Einlösezeitpunkt setzen und die konfigurierte Dauer aus der Kursprodukt-Zuordnung verwenden. Verhalten bei vorhandenem Zugang oder Verlängerung festlegen.
- [ ] Voucher Captain muss den Gutschein zunächst reservieren, dann den Grant-Service aufrufen und erst bei bestätigter Freischaltung die Einlösung endgültig verbuchen. Fehler und Timeouts müssen wiederholbar sein; keine dauerhafte Reservierung oder doppelte Freischaltung.
- [ ] Für „Mein Lernen“ die bestehende authentifizierte Learnboating-App-Route erweitern oder eine neue Route einführen. Der Nutzer wird aus dem Login-Token abgeleitet, nicht aus einem Request-Feld.
- [ ] WooCommerce-Warenkorb/Kasse und die direkte Kurseinlösung müssen denselben Voucher-Status verwenden. Die Einlöseroute soll nur für passende Gutscheintypen/Kursziele verfügbar sein.
- [ ] Admin-Verknüpfung des Gutschein-Ziels mit einem Learnboating-Kursprodukt/Variation bereitstellen und gegen gelöschte, deaktivierte oder nicht mehr zugeordnete Produkte absichern.
- [ ] Regeln für Erstattung, Widerruf, bereits bestehenden Kurszugang, abgelaufenen Kurs und Ablauf des Gutscheins gemeinsam festlegen.
- [ ] Fehlercodes und Nutzertexte für ungültig, reserviert, eingelöst, abgelaufen, falscher Kurs, kein Zugang möglich sowie vorübergehenden Fehler abstimmen.

## Abnahmekriterien für den Connector

- [ ] Ein gültiger Kursgutschein schaltet über die konfigurierte Learnboating-Produktzuordnung exakt Kurs und Feature(s) frei.
- [ ] Derselbe Idempotency-Key liefert denselben Grant zurück; parallele oder wiederholte Requests erzeugen keinen zweiten Zugang und verbrauchen den Gutschein nicht mehrfach.
- [ ] Die Laufzeit beginnt am bestätigten Einlösezeitpunkt und folgt der in Learnboating konfigurierten Produktlaufzeit.
- [ ] Derselbe Code kann nicht gleichzeitig im WooCommerce-Warenkorb und über „Mein Lernen“ eingelöst werden.
- [ ] Bei Fehler/Timeout bleibt die Einlösung sicher wiederholbar; die Voucher-Reservierung kann erneut verarbeitet oder freigegeben werden.
- [ ] Verlauf und Supportansicht können Nutzer, Voucher-ID, Kursprodukt, Einlöse-ID und Zeitpunkt zusammenführen.

## P2 – Rabattkampagnen und Store Credit

### Regelbasierte Aktionen

- [x] Rabattkampagnen mit Bedingungen für Erstkauf, nächste Bestellung, Mindestwarenkorb, Produkt/Kategorie, Kundengruppe, Zeitraum und gekaufte Menge modellieren. — *Nachweis: Gutscheine → Aktionen (CPT `vcap_discount`); Bedingungen Erstkauf/Stammkunde, Mindestwarenwert, Produkte/Kategorien, Benutzerrollen, Von/Bis, Mindestmenge; src/Discounts/Engine.php*
- [x] Prozent-, Festbetrag-, Staffel- und „Kaufe X, erhalte Y“-Aktionen unterstützen, soweit sie sich zuverlässig auf WooCommerce-Bestellpositionen abbilden lassen. — *Nachweis: alle vier Typen als WooCommerce-Coupon-Typ `vcap_campaign` mit Rabatt pro Bestellposition; tests/run.php + tests/promotion-integration.php*
- [x] Priorität, Ausschlüsse, Stapelbarkeit und Verhalten bei mehreren passenden Kampagnen konfigurierbar machen. — *Nachweis: Priorität = Reihenfolge, Ausschluss-Produkte/-Kategorien, „mit anderen Aktionen kombinierbar“, „gilt auch mit Gutscheincode“; Engine::select()*
- [x] Vor Veröffentlichung eine Vorschau/Testmöglichkeit für Regeln und berechnete Rabatte bereitstellen. — *Nachweis: Metabox „Testwarenkorb“ berechnet ungespeicherte Regeln per AJAX mit Rabatt je Zeile und Ablehnungsgrund*
- [x] Rabatte ausschließlich nachvollziehbar im Warenkorb/Checkout berechnen; keine künstlichen Streichpreise erzeugen. — *Nachweis: Rabatt als eigene Coupon-Zeile mit Aktionsname in Warenkorb und Bestellung (Meta `_vcap_campaign_id`); Katalogpreise unverändert*
- [x] Automatisches Anwenden nur nach ausdrücklicher Aktivierung durch den Händler anbieten; URL-Coupon sicher validieren und gegen wiederholte/unerwünschte Anwendung absichern. — *Nachweis: Einstellung „Automatische Aktionen“ standardmäßig aus; Aktionscodes sind nur gültig, solange die Aktion veröffentlicht ist und ihre Regeln erfüllt sind; URL-Coupons über CouponLink*

### Kundenkonto-Guthaben

- [x] Optionales Store-Credit-Konto mit unveränderlichem Buchungsjournal für Gutschriften, Belastungen, Korrekturen, Bestellbezug und Erstattungen umsetzen. — *Nachweis: Einstellung „Guthaben“ (standardmäßig aus); Journal mit Gutschrift, Belastung, Erstattung, Reservierung/Verbuchung, Bestellbezug; tests/store-credit-integration.php*
- [x] Manuelle Gutschrift/Korrektur im Admin mit Berechtigung, Begründung und Audit-Protokoll ermöglichen. — *Nachweis: Benutzerprofil → Guthaben: Betrag ± mit Pflicht-Grund, Berechtigung und Nonce, atomar; Journal mit Benutzer*
- [x] Guthaben im Kundenkonto anzeigen und teilweise Einlösung an der Kasse unterstützen. — *Nachweis: Mein Konto → Gutscheine zeigt Saldo, Code und Journal; Hinweis mit „Guthaben verwenden“ in Warenkorb/Kasse; Teil-Einlösung über die Guthaben-Logik*
- [x] Kombination mit Coupons, Steuerbehandlung und Erstattungsreihenfolge eindeutig konfigurieren und dokumentieren. — *Nachweis: Einstellungen „Guthaben mit Rabattcodes“ und „Reihenfolge von Geschenkkarten und Guthaben“; Rabatte immer vor Geschenkkarten/Guthaben (Filter `woocommerce_coupon_sort`); Verhalten zu Steuer und Erstattung in README.md beschrieben; tests/store-credit-integration.php. Offen bleibt eine Verrechnung „nach Steuer“ (hängt an der steuerlichen Entscheidung zu Einzweck/Mehrzweck).*
- [x] Rückerstattung als Guthaben nur als ausdrücklich gewählte Erstattungsart anbieten; niemals eine Geldrückzahlung stillschweigend ersetzen. — *Nachweis: eigener Button „Als Guthaben erstatten“ in der Bestellung (WooCommerce-Erstattung ohne Rückzahlung + Gutschrift), Gäste ausgeschlossen*
- [x] Guthaben standardmäßig währungsgebunden führen; Mehrwährungsumrechnung nur über unterstützte Integration mit dokumentierter Wechselkursregel zulassen. — *Nachweis: Guthaben in Shop-Basiswährung; Fremdwährungs-Erstattungen über `vcap_amount_to_base_currency` (unterstützte Währungs-Plugins)*

## P2 – Kundenbindung und Shop-Erweiterungen

- [x] Gutscheine mit konfigurierbarem Vorablauf-Hinweis und Zustellstatus versenden; Versandwiederholung und Unterdrückung von Erinnerungen unterstützen. — *Nachweis: Vorablauf-Erinnerung, Zustellstatus/-fehler im Verlauf, erneuter Versand (Admin und Kundenkonto), Unterdrückung je Gutschein; tests/reminder-integration.php*
- [x] Bonusgutschein bei qualifizierender Bestellung oder Geschenkkartenkauf als optionale Kampagnenaktion ausgeben. — *Nachweis: Aktion „Bonusgutschein nach der Bestellung“ (Auslöser Bestellung oder Gutscheinkauf), Einmal-Code mit E-Mail-Bindung, Ablauf und Mindestwert, eigene WooCommerce-E-Mail; Rücknahme bei Storno/Erstattung; tests/bonus-integration.php*
- [x] Gutscheinsaldo, Gutscheinstatus, Einlöseseite und Versandaktion über wiederverwendbare Blöcke/Shortcodes bereitstellen. — *Nachweis: Shortcodes `[vcap_balance]`/`[vcap_voucher_status]`, `[vcap_redeem]`, `[vcap_my_vouchers]` (mit „erneut senden“), `[vcap_referral]` und gleichnamige serverseitige Blöcke; tests/shortcodes-integration.php*
- [x] Kundenvorschau personalisierter Gutscheine vor dem Kauf anbieten. — *Nachweis: Vorschau-Button auf der Produktseite, PDF mit Wasserzeichen*
- [x] Feste Wertstufen und Wunschbetrag für Gutscheinprodukte unterstützen, wenn Produkt-/Preis-Integrationen dies erlauben; Werte, Währung und Steuerdaten positionsbezogen speichern. — *Nachweis: Wunschbetrag mit Min/Max/Schrittweite/Vorschlägen, Variationen als Wertstufen; Positions-Snapshot `_vcap_line` mit Nennwert, Gutschein- und Bestellwährung, Netto/Steuer nach Rabatt, Steuerklasse/-status; tests/connector-integration.php*
- [x] Mehrsprachige, mehrwährungsfähige, Subscription- und Produktfeld-Erweiterungen über dokumentierte Adapter statt interne APIs anbinden. — *Nachweis: ADAPTERS.md; eingebaute Adapter WPML/WCML, Aelia, WOOCS, CURCY, Subscriptions; wpml-config.xml um neue Daten ergänzt*
- [x] Akzeptanztests für Kampagnenpriorität, Warenkorbgrenzen, teilweise Einlösung, Erstattung, Mehrwährung, Wiederholungs-Webhook und Datenschutzfristen ergänzen. — *Nachweis: tests/acceptance-integration.php (Warenkorbgrenze, Teil-Einlösung, Erstattung, wiederholte Webhooks), promotion-/bonus-integration (Priorität, Limits), order-lifecycle-integration (Mehrwährung), security-integration (Aufbewahrungsfristen), parallel-checkout und fault-injection; Zustandsautomat in STATE_MACHINE.md*

## P1 – EU-Markt und Plattform-Kompatibilität

- [ ] EU als Zielmarkt umsetzen; Länder-, Währungs-, Sprach- und Steuerkonfiguration getrennt von fest codierter Geschäftslogik halten.
- [x] Release-CI gegen WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.4 (am Snapshot 8.4.27), MySQL 8.4 LTS und MariaDB 11.4 LTS einrichten. — *Nachweis: .github/workflows/plugin-smoke.yml*
- [x] Unterstützte Mindestversionen separat von der aktuellen Test-Baseline festlegen und pro Release aktualisieren. — *Nachweis: COMPATIBILITY.md (Minimum WP 6.4 / WC 8.9 / PHP 8.1 vs. Baseline WP 7.1.4 / WC 11.2.2 / PHP 8.4); Hinweis und Abschaltung bei WC < 8.9; CI-Job `minimum-versions` führt die komplette Integrationssuite auf den Mindestversionen aus*
- [x] Yoast SEO 28.8 als optionale Kompatibilitätskonfiguration prüfen; Yoast darf keine Laufzeitabhängigkeit sein. — *Nachweis: kein Yoast-Bezug im Code; Integrationssuite und Browser-Tests mit Yoast 28.8, Job `minimum-versions` ohne Yoast*
- [x] Mehrwährungs-, Mehrsprachigkeits- und WooCommerce-Produktfeld-Integrationen über dokumentierte Adapter spezifizieren. — *Nachweis: ADAPTERS.md*
- [ ] EU-weite Steuerfälle, OSS, Bruttopreisberechnung, Gutscheinbesteuerung und positionsbezogene Erstattungen mit Steuerberatung für die unterstützten Länder festlegen.
- [ ] Verbraucherinformationen und Gutscheinbedingungen pro Land konfigurierbar machen und rechtlich prüfen lassen. *(technisch erledigt: Einstellung „Einlösebedingungen pro Land“, Rechnungsland entscheidet, am Gutschein festgeschrieben; offen: rechtliche Prüfung der Texte)*
- [ ] Barrierefreiheit von Produktfeldern, Checkout-/Kundenkonto-Flows, PDF-Vorschau und Admin-Oberfläche prüfen. *(automatisiert erledigt: axe-Scans und Tastaturbedienung für Produktfelder, PDF-Vorschau, Shortcodes, Kundenkonto, Scanner und Aktions-Editor in `browser-e2e`; gefundene Mängel behoben. Offen: Prüfung mit echtem Screenreader)*
