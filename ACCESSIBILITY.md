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
| 9 | Admin → Gutscheine → Scanner: Code scannen/eingeben + Enter | Während der Abfrage „Wird geprüft …“ (aria-busy); Ergebnis (gültig/ungültig/eingelöst) wird sofort angesagt (Live-Region), Fokus bleibt im Eingabefeld (am Smartphone mit laufender Kamera nicht – keine Bildschirmtastatur über Kamera und Ergebnis) | ☐ | ☐ | |
| 10 | Admin → Aktionen → neue Aktion: Regeln, „Testwarenkorb“ | Felder benannt, Ergebnis des Tests wird angesagt | ☐ | ☐ | |
| 10a | Admin → Feldgruppe: Feld mit „Nach oben“/„Nach unten“ verschieben, Feld entfernen | Schaltflächen nennen das Feld; neue Position angesagt; Fokus bleibt beim Feld bzw. springt zum Nachbarfeld | ☐ | ☐ | |
| 11 | Admin → Gutschein-Designer (PDF-Vorlage) | Werkzeuge benannt, Zoomstufe angesagt; Elemente per Tastatur auswählbar | ☐ | ☐ | |
| 12 | Kasse (klassisch und Blöcke) mit Gutscheincode | Fehlermeldungen von Voucher Captain (z. B. „bereits eingelöst“) werden angesagt | ☐ | ☐ | |

Befunde mit Schweregrad (blockierend / erschwerend / kosmetisch) eintragen; blockierende vor dem Release beheben und
in `tests/e2e/06-screen-reader.spec.js` als Regressionstest ergänzen.

### Einstellungen: Hilfetexte und Fehlermeldungen

*WooCommerce → Einstellungen → Gutscheine* (SET-VC-23):

- **Jedes Feld hat einen Hilfetext** (Test „SET-VC-23: every register field has a help text“ in `tests/run.php`
  prüft alle Felder des Einstellungs-Registers, auch die der Integrationen).
- **Rechtlich relevante Hinweise stehen sichtbar unter dem Feld**, nicht nur im Tooltip: bei Einstellungen mit
  `legal` im Register (z. B. Einlösebedingungen, Gültigkeit, Anonymisierung, Belegnummer an der Kasse, DATEV-Konten)
  macht `SettingsRegistry::field()` aus dem Tooltip eine Beschreibung. Tooltips (Fragezeichen-Symbol) sind per
  Tastatur und Screenreader schlecht erreichbar; die Beschreibung liest der Screenreader mit dem Feld vor.
- **Fehlermeldungen nennen Feld und erlaubten Bereich.** Eine Zahl außerhalb von Mindest-/Höchstwert wird beim
  Speichern auf die Grenze gesetzt, eine Kommazahl in einem Ganzzahlfeld gerundet, Text oder ein leeres Pflichtfeld
  nicht gespeichert – jeweils mit einer Meldung oben auf der Seite, z. B. „Zustellstunde: 30 ist nicht erlaubt –
  erlaubt sind ganze Zahlen von 0 bis 23. Gespeichert wurde 23.“ Die Zahlenfelder tragen `min`/`max` auch im HTML,
  sodass Browser und Screenreader den Bereich schon bei der Eingabe kennen.
- Die Tabelle *Rechte je Rolle* (Datenschutz & Erweitert) hat Spaltenköpfe, Zeilenköpfe je Rolle und für jedes
  Kästchen einen Namen „Recht für Rolle“; feste Kästchen (Administrator, Rollen mit „WooCommerce verwalten“) sind
  deaktiviert und angehakt.
- *Gesetzliche Gültigkeit je Land* und *Einlösebedingungen je Land* (Allgemein, SET-VC-04) sind echte Tabellen
  statt Freitext: Beschriftung (`caption`), Spaltenköpfe, und jedes Feld hat einen eigenen Namen mit Zeilennummer
  („Land, Zeile 2“, „Jahre, Zeile 2“). „Zeile hinzufügen“ und „Entfernen“ sind Schaltflächen; nach dem Hinzufügen
  steht der Fokus im Länderfeld der neuen Zeile, nach dem Entfernen auf „Entfernen“ der nächsten (sonst vorigen)
  Zeile oder auf „Zeile hinzufügen“; beides wird über `wp.a11y.speak` angesagt (`assets/js/country-table.js`).
  Ohne JavaScript gibt es immer eine leere Zeile für einen neuen Eintrag, und eine geleerte Zeile wird beim Speichern
  entfernt. Eine ungültige Zeile wird nicht gespeichert: die Meldung oben nennt Einstellung und Zeile („Zeile 2 (FR):
  …“), die Zeile erscheint danach mit ihrem Fehlertext (`aria-invalid`, `aria-describedby`) erneut, damit nichts
  Eingegebenes verloren geht.
- **Ein abgelehnter oder korrigierter Wert ist am Feld markiert** (PV-P5-4): Nach dem Speichern trägt das Feld
  `aria-invalid="true"`, und die Meldung von oben steht noch einmal direkt unter dem Feld (der Screenreader liest
  sie mit dem Feld vor). Das gilt für Zahlen, Akzentfarbe, Logo-Adresse und Zusätzliches CSS (PV-P5-3: eine Farbe
  ohne „#“ wird mit „#“ übernommen, eine ungültige Farbe oder eine Logo-Adresse ohne http(s) wird nicht gespeichert,
  gekürztes oder bereinigtes CSS wird gemeldet; das CSS-Feld hat `maxlength`).
- **Enter speichert** (PV-P5-2): Jeder Abschnitt beginnt mit einer unsichtbaren Schaltfläche „Änderungen speichern“
  (nicht per Tab erreichbar, für Screenreader verborgen). Enter in einem Feld speichert deshalb die Einstellungen und
  löst nicht die erste Aktionsschaltfläche der Seite aus (Länderprofil-Vorschau, „Datei prüfen“).
- **Länderprofil** (Allgemein, SET-VC-02): Auswahl mit Beschriftung „Profil“; die Vorschau ist eine Region mit
  Überschrift („Vorgeschlagene Änderungen: …“) und eine Tabelle mit Spaltenköpfen, jedes Kästchen ist über
  `label for` mit dem Namen der Einstellung verbunden. Hinweise „Rechtsprüfung ausstehend“ stehen als Text in der
  Tabelle. Auswahl und Kästchen gelten nicht als ungespeicherte Änderung (`wc-settings-prevent-change-event`), die
  Schaltflächen lösen keine Rückfrage „Seite verlassen?“ aus. Die Meldung nach „Übernehmen“/„Abbrechen“ wird
  angesagt (Fehler `role="alert"`, Erfolg `role="status"`).
- **Kassentabelle** (Kasse & Standorte, SET-VC-22): Tabelle mit unsichtbarer Beschriftung „Kassen und Filialen“,
  Spaltenköpfen und für jedes Feld einem Namen mit Zeilennummer („Name, Zeile 2“); leere Zeilen für neue Kassen.
- **Export, Import und Zurücksetzen** (Datenschutz & Erweitert, SET-VC-14/16): Dateifeld mit Beschriftung,
  Bereichsauswahl mit Beschreibung (`aria-describedby`), Bestätigungskästchen mit Beschriftung. Die Import-Vorschau
  ist eine Region mit Überschrift und Tabelle (Spaltenköpfe; Einstellung mit ihrem Feldnamen, Werte als Ja/Nein bzw.
  „(leer)“, PV-P5-6). Meldungen werden angesagt; fehlt beim Zurücksetzen die Bestätigung, ist das Kästchen markiert
  (`aria-invalid`, `aria-describedby` auf die Meldung).
- **Änderungsprotokoll** (Datenschutz & Erweitert, SET-VC-15): Tabelle mit Spaltenköpfen (Datum, Benutzer,
  Einstellung, Von, Nach, Quelle), Einstellungen mit Feldnamen, Werte lesbar (PV-P5-6). Breite Tabellen (Vorschau,
  Protokoll, Länderprofil) scrollen auf dem Telefon in ihrem eigenen Kasten, nicht die ganze Seite (PV-P5-7).

### Gutschein-PDF

Das PDF ist ein Druckdokument und **nicht getaggt** (dompdf erzeugt kein Tagged PDF/PDF/UA): Screenreader lesen den
Text in der Reihenfolge der Ebenen, ohne Überschriften- oder Lesereihenfolge-Struktur; QR-Code und Barcode haben keinen
Alternativtext. Gesetzt sind der Dokumenttitel „Produkt – Shop“ (nie der Gutscheincode) und die Sprache (`lang` aus der
Website- bzw. Bestellsprache). Barrierefreie Alternative: dieselben Angaben (Code, Wert, Gültigkeit, Einlösehinweise) stehen
als HTML in der Gutschein-E-Mail und unter *Mein Konto → Gutscheine*.

| Release | Geprüft von | Datum | NVDA-Version / Browser | VoiceOver / macOS | Ergebnis |
|---|---|---|---|---|---|
| | | | | | ☐ freigegeben |
