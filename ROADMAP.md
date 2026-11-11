# WP Voucher Captain – 50-Punkte-Roadmap

Abgehakte Punkte sind im aktuellen PR implementiert und/oder durch die aufgeführten CI-Läufe abgedeckt. Offene Punkte bleiben die nächsten Umsetzungsschritte.

## Zielplattform

- Primäre Testbasis: WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.4.27 und MySQL 8.4 LTS.
- Zusätzliche Datenbankprüfung: MariaDB 11.4 LTS.
- PHP 8.1 bleibt als deklarierte Mindestversion in der CI.
- Yoast SEO 28.8 wird optional auf Konflikte geprüft; es wird keine Plugin-Abhängigkeit.

## P0 – Finanz- und Zustellkorrektheit

1. [x] Reservieren, Bestätigen, Freigeben und Erstatten als klaren Zustandsautomaten dokumentieren. (STATE_MACHINE.md)
2. [x] Wiederaufnahme unvollständiger Mehrgutschein-Reservierungen bei Checkout-Retry absichern.
3. [x] Wiederholte Zahlungsversuche vor Gateway-Aufruf erneut reservieren.
4. [x] Store-API-Reservierungsfehler als verständliche Checkout-Fehler ausgeben.
5. [x] Ledger-Schreibfehler in allen Guthaben-Transaktionen zum Rollback führen.
6. [x] Abbuchungs-Idempotenz gegen die jeweils letzte Ereigniszeile absichern.
7. [x] Erstattungen unter Zeilensperre idempotent verbuchen.
8. [x] Bestellmetadaten bei Teilfehlern erst nach vollständig erfolgreicher Wiederherstellung löschen.
9. [x] E-Mail-Zustellungen mit auslaufender Sperre gegen Worker-Abbrüche absichern.
10. [x] Zustellungen bei fehlender Adresse, deaktivierter E-Mail oder Versandfehler wiederholbar halten.

## P0 – Automatisierte Qualitätssicherung

11. [x] Smoke-Tests und PHP-Lint für PHP 8.1 und 8.4 in GitHub Actions ausführen.
12. [x] WordPress-Integrationstests mit WooCommerce 11.2.2 ergänzen.
13. [x] CI-Datenbankmatrix für MySQL 8.4 und MariaDB 11.4 einrichten.
14. [x] Parallel-Checkout-Test für denselben Guthabengutschein ergänzen. (tests/parallel-checkout.php, zwei Prozesse)
15. [x] Fehler-Injektion für fehlgeschlagene Ledger-INSERTs und Voucher-UPDATEs ergänzen. (tests/fault-injection-integration.php)
16. [x] Statusfolgen pending → processing → failed → payment retry → processing abdecken.
17. [x] Mehrgutschein-Checkout bei Abbruch nach erster Reservierung testen.
18. [x] Erstattung parallel zu neuer Einlösung testen.
19. [x] Zustelltests für Prozessabbruch, leere Empfängerliste und Mailer-Fehler ergänzen.
20. [x] CI-Ergebnisse und bekannte Grenzen im PR- und Release-Checklistenprozess dokumentieren.

## P1 – WordPress- und WooCommerce-Kompatibilität

21. [x] Plugin auf WordPress 7.1.4 und WooCommerce 11.2.2 mit HPOS installieren und aktualisieren.
22. [x] Klassische Kasse und Checkout-Blöcke separat mit bezahlten und unbezahlten Bestellungen prüfen.
23. [ ] Payment-Retry für bestehende Bestellungen ist hook-level abgedeckt; Gateway-Redirects und Webhook-Wiederholungen mit realen Gateways als E2E testen.
24. [x] Kompatibilität mit PHP 8.1 und 8.4 durch vollständige Lint- und Smoke-Läufe bestätigen.
25. [x] MySQL- und MariaDB-Tabellenengine nach Aktivierung und Upgrade verifizieren.
26. [x] Datenbankmigration von vorhandenen MyISAM-Tabellen unter realistischen Datenmengen testen.
27. [x] HPOS- und Legacy-Order-Storage in einer Integrationstestmatrix prüfen.
28. [x] Yoast SEO 28.8 ist in CI parallel aktiviert; Admin-, Checkout- und Frontend-Konflikte per Browser-E2E geprüft (`browser-e2e`).
29. [x] WooCommerce-Produkt-, Warenkorb- und Checkout-Blöcke mit Gutscheinfeldern testen.
30. [x] WooCommerce-Subscriptions-, Mehrwährungs- und Produktfeld-Verträge dokumentieren und mit optionalen Provider-/Renewal-Tests absichern (externe Premium-Plugins bleiben außerhalb der CI).

## P1 – Sicherheit, Datenschutz und Datenintegrität

31. [x] Alle Admin-Aktionen auf Capability-, Nonce- und Eingabevalidierung prüfen.
32. [x] Store API und Scanner-Endpunkte mit unauthentifizierten und unberechtigten Requests testen.
33. [x] Gutschein-Codes gegen Rate-Limits und Enumeration absichern.
34. [x] Datenschutzexport und -löschung mit mehr als 500 Datensätzen testen.
35. [x] Aufbewahrungsfristen, Anonymisierung und Audit-Protokoll gemeinsam prüfen.
36. [x] PDF-Downloads und Gastlinks auf Zugriffsschutz und Ablauf testen.
37. [x] Abhängigkeiten und Composer-Lockdatei regelmäßig auf bekannte Schwachstellen prüfen.
38. [x] PHPCS mit WordPress- und WooCommerce-Regeln in CI ergänzen.
39. [x] Statische Analyse für PHP 8.1-Typen, Nullwerte und Datenbank-Rückgaben einführen.
40. [x] Geheimnisse, Debug-Ausgaben und sensible Bestell-/Empfängerdaten in Logs prüfen.

## Umsetzung in diesem PR

Punkte 31–43 und 49–50 sind durch Sicherheits-/Datenschutztests, den versionierten PHP-Providervertrag, Qualitätsprüfungen in CI und den Release-ZIP-Installations-/Deinstallationslauf umgesetzt. Die CI-Matrix muss für den aktuellen Commit grün sein, bevor dieser PR freigegeben wird.

Offen bleiben reale Gateway-Redirects/Webhooks (23), Yoast-Browser-E2E (28), der separate Ticket-Captain-Adapter und gemeinsame Plugin-Test (44), Learnboating-Spezifikation/Connector (45), vom Händler freizugebende Gutscheinbedingungen (46), steuerliche Fachentscheidung (47) und Accessibility-Browserprüfung (48).

## P2 – Produktreife und Betrieb

41. [x] Öffentlichen, versionierten PHP-Service für Gutscheinprüfung und -einlösung definieren.
42. [x] Einlöse-Provider-Schnittstelle ohne Abhängigkeit auf interne Tabellen spezifizieren.
43. [x] Idempotency-Key und stabile Fehlercodes für externe Connectoren festlegen.
44. [ ] Voucher Captain und Ticket Captain über dokumentierten, optionalen Vertrag integrieren.
45. [ ] Learnboating-Connector als separates Add-on mit wiederholbarer Grant-Verarbeitung planen.
46. [x] Ablauf-, Übertragbarkeits-, Restwert- und Einlösebedingungen im Kundenkonto erklären. (Kundenkonto-Tab, E-Mail, Produktseite; Bedingungen pro Land)
47. [ ] Steuerbehandlung und Erstattungsreihenfolge je Gutscheinart mit Fachberatung spezifizieren.
48. [ ] Übersetzungen, Tastaturbedienung und Screenreader-Flows für Kauf und Einlösung prüfen. (Tastatur und axe automatisiert in `browser-e2e`; offen: echter Screenreader)
49. [x] Release-ZIP in sauberer WordPress-Installation installieren, aktualisieren und deinstallieren.
50. [x] Abnahme von VCAP allein, Ticket Captain allein und beiden Plugins gemeinsam als Release-Gate einführen.
