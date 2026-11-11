# WP Voucher Captain – 50-Punkte-Roadmap

Abgehakte Punkte sind im aktuellen PR implementiert und/oder durch die aufgeführten CI-Läufe abgedeckt. Offene Punkte bleiben die nächsten Umsetzungsschritte.

## Zielplattform

- Primäre Testbasis: WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.4.27 und MySQL 8.4 LTS.
- Zusätzliche Datenbankprüfung: MariaDB 11.4 LTS.
- PHP 8.1 bleibt als deklarierte Mindestversion in der CI.
- Yoast SEO 28.8 wird optional auf Konflikte geprüft; es wird keine Plugin-Abhängigkeit.

## P0 – Finanz- und Zustellkorrektheit

1. [ ] Reservieren, Bestätigen, Freigeben und Erstatten als klaren Zustandsautomaten dokumentieren.
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
14. [ ] Parallel-Checkout-Test für denselben Guthabengutschein ergänzen.
15. [ ] Fehler-Injektion für fehlgeschlagene Ledger-INSERTs und Voucher-UPDATEs ergänzen.
16. [x] Statusfolgen pending → processing → failed → payment retry → processing abdecken.
17. [x] Mehrgutschein-Checkout bei Abbruch nach erster Reservierung testen.
18. [ ] Erstattung parallel zu neuer Einlösung testen.
19. [ ] Zustelltests für Prozessabbruch, leere Empfängerliste und Mailer-Fehler ergänzen.
20. [x] CI-Ergebnisse und bekannte Grenzen im PR- und Release-Checklistenprozess dokumentieren.

## P1 – WordPress- und WooCommerce-Kompatibilität

21. [ ] Plugin auf WordPress 7.1.4 und WooCommerce 11.2.2 mit HPOS installieren und aktualisieren.
22. [ ] Klassische Kasse und Checkout-Blöcke separat mit bezahlten und unbezahlten Bestellungen prüfen.
23. [ ] Payment-Retry für bestehende Bestellungen sowie Gateway-Redirects und Webhook-Wiederholungen prüfen.
24. [ ] Kompatibilität mit PHP 8.1 und 8.4 durch vollständige Lint- und Smoke-Läufe bestätigen.
25. [ ] MySQL- und MariaDB-Tabellenengine nach Aktivierung und Upgrade verifizieren.
26. [ ] Datenbankmigration von vorhandenen MyISAM-Tabellen unter realistischen Datenmengen testen.
27. [ ] HPOS- und Legacy-Order-Storage in einer Integrationstestmatrix prüfen.
28. [ ] Yoast SEO 28.8 optional aktivieren und Admin-, Checkout- und Frontend-Konflikte prüfen.
29. [ ] WooCommerce-Produkt-, Warenkorb- und Checkout-Blöcke mit Gutscheinfeldern testen.
30. [ ] WooCommerce-Subscriptions-, Mehrwährungs- und Produktfeld-Integrationen gezielt spezifizieren und testen.

## P1 – Sicherheit, Datenschutz und Datenintegrität

31. [ ] Alle Admin-Aktionen auf Capability-, Nonce- und Eingabevalidierung prüfen.
32. [ ] Store API und Scanner-Endpunkte mit unauthentifizierten und unberechtigten Requests testen.
33. [ ] Gutschein-Codes gegen Rate-Limits und Enumeration absichern.
34. [ ] Datenschutzexport und -löschung mit mehr als 500 Datensätzen testen.
35. [ ] Aufbewahrungsfristen, Anonymisierung und Audit-Protokoll gemeinsam prüfen.
36. [ ] PDF-Downloads und Gastlinks auf Zugriffsschutz und Ablauf testen.
37. [ ] Abhängigkeiten und Composer-Lockdatei regelmäßig auf bekannte Schwachstellen prüfen.
38. [ ] PHPCS mit WordPress- und WooCommerce-Regeln in CI ergänzen.
39. [ ] Statische Analyse für PHP 8.1-Typen, Nullwerte und Datenbank-Rückgaben einführen.
40. [ ] Geheimnisse, Debug-Ausgaben und sensible Bestell-/Empfängerdaten in Logs prüfen.

## P2 – Produktreife und Betrieb

41. [ ] Öffentlichen, versionierten PHP-Service für Gutscheinprüfung und -einlösung definieren.
42. [ ] Einlöse-Provider-Schnittstelle ohne Abhängigkeit auf interne Tabellen spezifizieren.
43. [ ] Idempotency-Key und stabile Fehlercodes für externe Connectoren festlegen.
44. [ ] Voucher Captain und Ticket Captain über dokumentierten, optionalen Vertrag integrieren.
45. [ ] Learnboating-Connector als separates Add-on mit wiederholbarer Grant-Verarbeitung planen.
46. [ ] Ablauf-, Übertragbarkeits-, Restwert- und Einlösebedingungen im Kundenkonto erklären.
47. [ ] Steuerbehandlung und Erstattungsreihenfolge je Gutscheinart mit Fachberatung spezifizieren.
48. [ ] Übersetzungen, Tastaturbedienung und Screenreader-Flows für Kauf und Einlösung prüfen.
49. [ ] Release-ZIP in sauberer WordPress-Installation installieren, aktualisieren und deinstallieren.
50. [ ] Abnahme von VCAP allein, Ticket Captain allein und beiden Plugins gemeinsam als Release-Gate einführen.
