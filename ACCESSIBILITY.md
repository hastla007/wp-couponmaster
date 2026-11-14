# Barrierefreiheit – Prüfprotokoll mit Screenreader

Automatisch geprüft (CI-Job `browser-e2e`): axe-core (keine schweren Verstöße), Tastaturbedienung und – als
Voraussetzung für Screenreader – der Accessibility-Baum (`tests/e2e/06-screen-reader.spec.js`): jedes Formularfeld
hat einen zugänglichen Namen, Pflichtfelder sind als erforderlich ausgezeichnet, Fehler kommen als `alert`, Ergebnisse
als `status`/Live-Region, Tabellen haben Spaltenköpfe.

Die **Hörprüfung mit einem echten Screenreader** bleibt eine manuelle Release-Abnahme. Vorgehen je Release:
NVDA (aktuelle Version) mit Firefox oder Chrome unter Windows **und** VoiceOver mit Safari unter macOS; nur
Tastatur, Bildschirm nicht ansehen; Sprache der Seite Deutsch.

| Nr. | Ablauf | Erwartung beim Vorlesen | NVDA | VoiceOver | Befund |
|---|---|---|---|---|---|
| 1 | Produktseite Gutschein: Felder Empfänger, E-Mail, Nachricht der Reihe nach mit Tab | Feldname, „Pflichtfeld“/„erforderlich“, Hinweistexte werden gelesen | ☐ | ☐ | |
| 2 | Pflichtfeld leer lassen, „In den Warenkorb“ | Fehlermeldung wird sofort angesagt (Warnung/Alert) | ☐ | ☐ | |
| 3 | „Gutschein-Vorschau“ auslösen | Schaltfläche mit Namen; PDF öffnet sich, Hinweis bei Fehler | ☐ | ☐ | |
| 4 | Wunschbetrag wählen (Vorschläge als Optionsfelder) und Nachricht eingeben | Vorschläge als Gruppe mit gewählter Option; Zeichenlimit beim Fokussieren als Beschreibung, Restzeichen erst ab 20 und nach Tipppause angesagt | ☐ | ☐ | |
| 5 | Seite mit `[vcap_balance]`: unbekannten, dann gültigen Code prüfen | Nach dem Neuladen steht der Fokus auf dem Ergebnis, es wird gelesen; bei Fehler ist das Feld „ungültig“ und nennt den Fehler | ☐ | ☐ | |
| 6 | Seite mit `[vcap_redeem]`: Code einlösen | Feld- und Schaltflächenname; Hinweis nach dem Absenden fokussiert und gelesen, Feld bei Fehler „ungültig“ | ☐ | ☐ | |
| 7 | Ticketgutschein einlösen: Datum wählen, Teilnehmerdaten | Auswahl als Liste mit Namen, Schritte nachvollziehbar, Fehler angesagt | ☐ | ☐ | |
| 8 | Mein Konto → Gutscheine | Tabelle mit Spaltenköpfen; „Erneut senden“ und Download-Links eindeutig benannt | ☐ | ☐ | |
| 9 | Admin → Gutscheine → Scanner: Code scannen/eingeben + Enter | Ergebnis (gültig/ungültig/eingelöst) wird sofort angesagt (Live-Region), Fokus bleibt im Eingabefeld | ☐ | ☐ | |
| 10 | Admin → Aktionen → neue Aktion: Regeln, „Testwarenkorb“ | Felder benannt, Ergebnis des Tests wird angesagt | ☐ | ☐ | |
| 10a | Admin → Feldgruppe: Feld mit „Nach oben“/„Nach unten“ verschieben, Feld entfernen | Schaltflächen nennen das Feld; neue Position angesagt; Fokus bleibt beim Feld bzw. springt zum Nachbarfeld | ☐ | ☐ | |
| 11 | Admin → Gutschein-Designer (PDF-Vorlage) | Werkzeuge benannt, Zoomstufe angesagt; Elemente per Tastatur auswählbar | ☐ | ☐ | |
| 12 | Kasse (klassisch und Blöcke) mit Gutscheincode | Fehlermeldungen von Voucher Captain (z. B. „bereits eingelöst“) werden angesagt | ☐ | ☐ | |

Befunde mit Schweregrad (blockierend / erschwerend / kosmetisch) eintragen; blockierende vor dem Release beheben und
in `tests/e2e/06-screen-reader.spec.js` als Regressionstest ergänzen.

| Release | Geprüft von | Datum | NVDA-Version / Browser | VoiceOver / macOS | Ergebnis |
|---|---|---|---|---|---|
| | | | | | ☐ freigegeben |
