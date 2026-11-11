# TODO – Voucher Captain

Ziel: Voucher Captain bleibt ein allgemein einsetzbares WooCommerce-Gutschein-Plugin. Anbindungen an ein bestimmtes Lern-, Kurs- oder Mitgliedersystem kommen in separate Connector-Plugins.

## P0 – Öffentliche Einlöse-Schnittstelle

- [ ] Einen versionierten, dokumentierten öffentlichen PHP-Service für Gutscheinabfragen und -einlösungen bereitstellen. Keine Connector-Abhängigkeit von internen Klassen oder Tabellen.
- [ ] Ein generisches Redemption-Provider-Konzept festlegen, über das Add-ons eigene Einlösearten registrieren können.
- [ ] Den Einlöseablauf als Zustandsübergang mit Reservierung, erfolgreichem Abschluss und wiederholbarem Fehlerfall umsetzen.
- [ ] Einlöseversuche mit Idempotency-Key absichern, damit parallele Requests oder Wiederholungen keinen doppelten Verbrauch und keine doppelte Leistung erzeugen.
- [ ] WooCommerce-Warenkorb und externe Einlösung auf denselben Gutscheinstatus prüfen lassen. Ein Code darf nicht über beide Wege doppelt eingelöst werden.
- [ ] Ein Ereignis nach erfolgreichem Abschluss dokumentieren, z. B. `vcap_voucher_redeemed`, mit Gutschein-ID, Code, Nutzer-ID, Ziel-ID, Einlöse-ID und Zeitstempel.
- [ ] Stabile Fehlercodes und API-Antworten definieren: ungültig, abgelaufen, bereits eingelöst, nicht berechtigt, Ziel nicht verfügbar, vorübergehender Fehler.
- [ ] Für browserbasierte REST-Aufrufe WordPress-Login-Cookies und Nonce unterstützen; bei externen Servern eine eng begrenzte Authentifizierung vorsehen.
- [ ] Nutzeridentität ausschließlich aus dem authentifizierten Request ableiten; keine frei übergebene `user_id` vertrauen.
- [ ] Rate-Limit für fehlgeschlagene Codeversuche implementieren und konfigurierbar machen.

## P1 – Allgemeine Gutschein-Grundlage

- [ ] Gutscheintyp und Einlöseweg generisch erweiterbar machen, ohne Kurs- oder LMS-Felder in den Plugin-Kern aufzunehmen.
- [ ] Transferierbarkeit als klare Produkteinstellung abbilden und für Vorlagen/E-Mails als Platzhalter bereitstellen.
- [ ] Ablaufdatum, Restwert, Übertragbarkeit und Einlösebedingungen in Admin, Kundenkonto und PDF-Vorlagen ausgeben.
- [ ] Erneuten Versand für berechtigte Käufer im Kundenkonto ergänzen.
- [ ] Code-Standard auf mindestens 12 zufällige Zeichen setzen; verwechslungsanfällige Zeichen weiter ausschließen.
- [ ] Rabattierte Gutscheinbestellungen anhand des tatsächlich bezahlten Positionsbetrags berechnen; Steueranteile und Mehrwährung gesondert berücksichtigen.
- [ ] Auftrags- und Erstattungsdaten positionsbezogen für angeschlossene Plugins verfügbar machen.
- [ ] Verhalten für vollständig rabattierte 0-Euro-Bestellungen festlegen und mit Bestellstatus-/Zugangs-Integrationen abstimmen.
- [ ] WooCommerce-CRUD-APIs für Bestellungen und Gutscheine verwenden; HPOS-Unterstützung verifizieren.
- [ ] Kompatibilität der Produktfelder mit Produkt-, Warenkorb- und Kassenblöcken herstellen und testen.
- [ ] Datenschutzauskunft, Löschung und konfigurierbare Aufbewahrungsfristen für Käufer- und Empfängerdaten abschließen.

## P1 – Steuer, Rabattregeln und Gutscheincodes

- [ ] Einzweck- und Mehrzweckgutschein als konfigurierbare Steuerbehandlung modellieren; steuerliche Umsetzung und Produkttexte mit Steuerberatung freigeben lassen.
- [ ] Steuerfälle für OSS, unterschiedliche Leistungsorte/-steuersätze, Kleinunternehmer und Preise mit gleichem Bruttopreis spezifizieren und verifizieren.
- [ ] Produkt-, Kategorie- und SKU-Regeln samt Ausschlüssen für Rabattcodes unterstützen.
- [ ] Gesamtlimit, Limit pro Kunde, Mindestbestellwert, Ablauf und Erstkaufbedingung konfigurierbar machen.
- [ ] Erweiterungspunkte für „Kunde besitzt Kurs/Produkt X“ und Konflikte mit externen Mitgliederrabatten/Kombipreisen dokumentieren.
- [ ] Coupon-Link mit sicherer automatischer Anwendung ergänzen, z. B. `?gutschein=CODE`.
- [ ] Generierung mehrerer Codes nach Präfix/Länge und CSV-Export für Partner- und Kampagnenpakete ergänzen.
- [ ] Sicherstellen, dass Coupons keine Katalog-Streichpreise erzeugen und der Rabatt erst im Warenkorb erscheint.

## P2 – Integrations- und Betriebsverhalten

- [ ] Gruppenpaket-Verhalten dokumentieren: Gutschein/Rabatt je Platz oder je Paket.
- [ ] Einlöse-, Guthaben- und Rückbuchungshistorie mit Nutzer, Zeitpunkt, Auftrag und externem Referenzschlüssel führen.
- [ ] Regeln für Rückerstattung/Storno vor und nach einer externen Einlösung definieren.
- [ ] Fehlgeschlagene externe Freischaltungen wiederholbar machen, ohne Gutscheinverbrauch oder Zugriff zu duplizieren.
- [ ] Integrationsdokumentation, Beispiel-Connector und öffentliche API-Versionspolitik veröffentlichen.

## Optional nach dem ersten Kunden-Connector

- [ ] E-Mail-Erinnerung vor Ablauf eines Gutscheins.
- [ ] Statistiken nach Kampagne und Partner.
- [ ] Empfehlungs-/Freunde-werben-Codes.
- [ ] Allgemeine Einlöseseite oder Block/Shortcode, den Connector-Plugins erweitern können.

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

