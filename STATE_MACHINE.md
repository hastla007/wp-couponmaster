# Zustandsautomat: Reservieren → Bestätigen → Freigeben → Erstatten

Voucher Captain verbucht jeden Wertfluss eines Gutscheins atomar: Zeile sperren (`SELECT … FOR UPDATE`), Saldo ändern, Journalzeile schreiben (`vcap_events`), `COMMIT`. Schlägt einer der Schritte fehl, wird alles zurückgerollt (geprüft in `tests/fault-injection-integration.php`). Welcher Schritt erlaubt ist, entscheidet immer die **letzte Journalzeile für dieses Paar aus Gutschein und Bestellung** (bzw. der Einlöse-Schlüssel bei externen Einlösungen). Wiederholte Aufrufe sind deshalb idempotent.

## 1. Gutscheinstatus

| Status | Bedeutung | Übergänge |
|---|---|---|
| `scheduled` | ausgestellt, Zustellung geplant (Geschenk mit Datum) | → `active` bei Zustellung bzw. am Stichtag |
| `active` | einlösbar | → `reserved` (externe Reservierung), → `redeemed` (Saldo 0 / Einmalgutschein eingelöst), → `expired`, → `void` |
| `reserved` | gerade extern reserviert (Einlöse-API) | → `active` (freigegeben/abgelaufen), → `redeemed` (bestätigt) |
| `redeemed` | vollständig eingelöst | → `active` bei Rückbuchung (Storno/Erstattung, Freigabe) |
| `expired` | abgelaufen | nur durch Bearbeitung im Admin änderbar |
| `void` | storniert (z. B. Kaufbestellung erstattet) | → `active` nur durch Admin („Reaktivieren“) |

## 2. Wertgutschein/Guthaben im Shop (pro Bestellung)

```
              Checkout / Zahlungsversuch
 (kein Eintrag) ───────────────────────────▶ reserved ──── Zahlung (processing/completed) ───▶ committed
       ▲                                        │                                               │
       │      fehlgeschlagen / storniert /      │                                               │ Storno / vollständige
       └──── Bestellung gelöscht / zu lange ────┘                                               │ Erstattung
              unbezahlt  (reservation_released)                                                 ▼
                                                                                             restored
```

| Ereignis (`vcap_events.event`) | Auslöser | Saldo | Wiederholung |
|---|---|---|---|
| `reserved` | Checkout (klassisch und Store API), erneuter Zahlungsversuch | − Betrag | gleicher Betrag ⇒ bestehende Reservierung; anderer Betrag ⇒ Konflikt |
| `committed` | Status `processing`/`completed` (auch 0-€-Bestellungen) | unverändert | erneute Zahlung/Webhooks ⇒ keine zweite Buchung |
| `redeemed` | Abbuchung ohne vorherige Reservierung (z. B. im Admin angelegte Bestellung) | − Betrag | per Bestell-Merker `_vcap_redemptions` nur einmal |
| `reservation_released` | `failed`/`cancelled` vor Zahlung, Bestellung in den Papierkorb/gelöscht, unbezahlt älter als die Haltefrist | + Betrag | nur wenn letzte Zeile `reserved` |
| `restored` | `cancelled`/`refunded`/`failed` nach Zahlung (Einstellung „Guthaben bei Stornierung wiederherstellen“) | + Betrag | nur wenn letzte Zeile `committed`/`redeemed` mit gleichem Betrag |

Kann bei einer bezahlten Bestellung nichts abgebucht werden (Saldo inzwischen verbraucht, Gutschein storniert), wird die Bestellung auf `on-hold` gesetzt und mit einer Notiz versehen – sie wird nie stillschweigend ausgeliefert.

**Teilerstattungen** ändern keinen Saldo (Geld geht über die Zahlungsart zurück). Gutschrift als Guthaben nur ausdrücklich über „Als Guthaben erstatten“ (`refund_credit`).

## 3. Externe Einlösung (Einlöse-API, Connectoren)

```
 reserve(key) ──▶ reserved ──commit(key)──▶ committed
                     │
                     ├──release(key)──▶ released
                     └──TTL abgelaufen──▶ released (automatisch)
```

| Zustand (Tabelle `vcap_redemptions`) | Journal | Gutschein |
|---|---|---|
| `reserved` | `api_reserved` | Einmalgutschein `reserved`, Wertgutschein: Saldo − Betrag |
| `committed` | `api_committed` | Einmalgutschein `redeemed`; Saldo bleibt abgebucht |
| `released` | `api_released` | zurück auf `active` bzw. Saldo + Betrag |

Gleicher Schlüssel + gleiche Anfrage ⇒ gleiche Antwort; gleicher Schlüssel + andere Anfrage ⇒ `vcap_idempotency_conflict`. Einzelheiten: [API.md](API.md).

## 4. Kundenguthaben

Guthaben ist ein Wertgutschein vom Typ `credit` und folgt im Shop exakt Abschnitt 2. Zusätzlich: `credit`/`debit` (manuelle Buchung mit Pflicht-Grund) und `refund_credit` (Erstattung als Guthaben) – jeweils atomar mit Journalzeile.

## 5. Nachweise in der CI

- Statusfolgen, Zahlungswiederholung, Abbruch, Erstattung: `tests/order-lifecycle-integration.php`, `tests/acceptance-integration.php`
- Zwei gleichzeitige Bestellungen mit denselben Geschenkkarten (zwei Prozesse): `tests/parallel-checkout.php`
- Fehlgeschlagene Journal-INSERTs und Gutschein-UPDATEs: `tests/fault-injection-integration.php`
- Externe Einlösung: `tests/redemption-integration.php`
