# WP Voucher Captain – 50-Punkte-Roadmap

Abgehakte Punkte sind implementiert und durch die aufgeführten CI-Jobs (`.github/workflows/plugin-smoke.yml`) abgedeckt. Offene Punkte bleiben die nächsten Umsetzungsschritte. Detailbefunde und ihr Stand stehen in TODO.md. Stand: 13.11.2026.

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
23. [ ] Payment-Retry für bestehende Bestellungen ist hook-level abgedeckt; Stripe und PayPal Payments laufen auf Plugin-Ebene in CI (`ecosystem`). Offen: Gateway-Redirects und Webhook-Wiederholungen mit echten Sandbox-Konten (manuelle Release-Prüfung).
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
38. [x] PHPCS mit WordPress- und WooCommerce-Regeln in CI ergänzen. (Streng für `src/API/V1` und `src/Contracts` im Job `quality`; ganzes Plugin mit WordPress-Extra, I18n und Security report-only im Job `quality-wide`, Altlasten-Abbau siehe RELEASE.md → Static analysis.)
39. [x] Statische Analyse für PHP 8.1-Typen, Nullwerte und Datenbank-Rückgaben einführen. (PHPStan Level 0 streng für API/Verträge; Level 1 für das ganze Plugin mit Baseline, report-only, `quality-wide`; WordPress Plugin Check report-only, `plugin-check`.)
40. [x] Geheimnisse, Debug-Ausgaben und sensible Bestell-/Empfängerdaten in Logs prüfen.

## Stand

Punkte 1–22, 24–44, 46 und 49–50 sind umgesetzt und in CI abgedeckt (darunter Yoast-Browser-E2E, 28, und der WP-Pass-Captain-Vertrag mit gemeinsamem Plugin-Test, 44). Der `Release`-Workflow baut ein Tag nur, wenn `Plugin smoke tests` für genau diesen Commit grün ist (RELEASE.md).

Offen bleiben reale Gateway-Sandboxen (23), der Learnboating-Connector im Kursplugin (45), die steuerliche Freigabe je Gutscheinart (47) und der echte Screenreader-Test (48).

Außerhalb der 50 Punkte, Stand Release und Lizenz:

- [x] Lizenzierung über WP License Captain (Premium-Edition, fail-closed, signierter Offline-Cache) und Premium-Updates mit SHA-256-Prüfung; Abnahme gegen einen echten Lizenzserver (`license-acceptance`).
- [ ] Aufteilung Free/Premium (`config/premium-features.php`) und Update-Kanal der Free-Edition – Entscheidung des Betreibers.
- [x] Release-ZIP mit Lizenzdateien aller Bibliotheken und `THIRD-PARTY-NOTICES.txt` (geprüft im Job `quality`).
- [ ] wordpress.org-Tauglichkeit: Befunde von Plugin Check und der breiten PHPCS/PHPStan-Prüfung abarbeiten, danach die Jobs blockierend schalten.

## P2 – Produktreife und Betrieb

41. [x] Öffentlichen, versionierten PHP-Service für Gutscheinprüfung und -einlösung definieren.
42. [x] Einlöse-Provider-Schnittstelle ohne Abhängigkeit auf interne Tabellen spezifizieren.
43. [x] Idempotency-Key und stabile Fehlercodes für externe Connectoren festlegen.
44. [x] Voucher Captain und WP Pass Captain (früher „Ticket Captain“) über dokumentierten, optionalen Vertrag integrieren. (Pass-Captain-Checkout, Gutschein → Ticket, gemeinsamer Scanner; Job `pass-captain`)
45. [ ] Learnboating-Connector als separates Add-on mit wiederholbarer Grant-Verarbeitung planen. (Vertrag und Grant-Service in Learnboating sind fertig, das Add-on fehlt; TODO.md → Connector für Learnboating.)
46. [x] Ablauf-, Übertragbarkeits-, Restwert- und Einlösebedingungen im Kundenkonto erklären. (Kundenkonto-Tab, E-Mail, Produktseite; Bedingungen pro Land)
47. [ ] Steuerbehandlung und Erstattungsreihenfolge je Gutscheinart mit Fachberatung spezifizieren. (Technisch umgesetzt und in TAX.md beschrieben: Steuerbehandlung je Produkt, Einstellung „Mehrzweckgutscheine als Zahlung“; offen: Freigabe durch die Steuerberatung.)
48. [ ] Übersetzungen, Tastaturbedienung und Screenreader-Flows für Kauf und Einlösung prüfen. (Tastatur und axe automatisiert in `browser-e2e`; Übersetzung de_DE/de_AT vollständig geprüft in `smoke`, de_DE_formal/de_CH fallen auf de_DE zurück; offen: echter Screenreader)
49. [x] Release-ZIP in sauberer WordPress-Installation installieren, aktualisieren und deinstallieren.
50. [x] Abnahme von VCAP allein, WP Pass Captain allein und beiden Plugins gemeinsam als Release-Gate einführen.
