# Paymos für PrestaShop

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles Paymos-Zahlungsmodul für PrestaShop. Es bietet Stablecoin-Zahlungen im
Checkout an, leitet die Kundschaft zur gehosteten Paymos-Zahlungsseite weiter und setzt
die Bestellung nach On-Chain-Bestätigung auf **Payment accepted** — ausgelöst durch einen
signierten Callback, niemals durch die Rückkehr des Kundenbrowsers.

Bei der Installation legt das Modul drei eigene Bestellstatus an, sodass Krypto-Zahlungen
keinen bestehenden Status mit anderer Bedeutung im Backoffice zweckentfremden müssen. Die
Rechnung lautet auf die Warenkorbwährung, und der Kurs wird im Moment der Zahlung fixiert.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- PrestaShop 1.7.8 bis einschließlich 9.x;
- PHP 7.4 oder neuer mit den Erweiterungen `curl`, `hash`, `json` und `openssl`;
- eine HTTPS-Storefront — `PS_SSL_ENABLED` aktiviert und Shop-URL über TLS erreichbar;
- ein Paymos-Konto, in dessen Dashboard das Projekt für die Bestellungen dieses Shops ausgewählt ist.

Das Paymos PHP SDK ist direkt im Paket enthalten, sodass im Shop kein Composer-Schritt
erforderlich ist. Das Modul bringt eigene Übersetzungen für Englisch, Russisch, Deutsch,
Spanisch, Türkisch und vereinfachtes Chinesisch mit.

## Installieren und verbinden

1. Laden Sie das neueste Paket unter [GitHub Releases](https://github.com/paymos-labs/prestashop/releases/latest) herunter.
2. Laden Sie es im **Module Manager** (Modulmanager) hoch und aktivieren Sie es.
3. Öffnen Sie im Paymos Dashboard das gewünschte Projekt; dieses aktuelle Projekt wird automatisch verwendet.
4. Öffnen Sie **Module Manager → Paymos → Configure** und klicken Sie auf **Connect Paymos**.
5. Bestätigen Sie in Paymos die angezeigte Shop-URL und das aktuelle Projekt.

Das Paket ist für alle Händler identisch und enthält keinerlei Zugangsdaten — keinen
API-Schlüssel, kein Secret, keine Projekt-ID, kein Webhook-Geheimnis, kein Token und keinen
Gerätecode. Bis Schritt 5 bestätigt ist, bleibt das Modul installiert, aber inaktiv.

Eine einzige Bestätigung deckt beide Umgebungen ab. Pro Umgebung nutzt Paymos Ihren einen
aktiven Payment-Schlüssel weiter — oder legt einen an, falls keiner existiert — und
registriert einen Invoice-Webhook für die Callback-URL dieses Shops. Ein bereits vorhandener
Webhook wird nur dann wiederverwendet, wenn URL, Kategorie und Projekt exakt übereinstimmen;
ein abweichender Webhook unter derselben Adresse wird nicht überschrieben. Das kurzlebige
Geräte-Token wird anschließend verworfen; der laufende Verkehr mit der Merchant API ist
HMAC-signiert.

Die Konfigurationsseite enthält danach genau eine Einstellung — **Mode** (Sandbox oder Live) —
neben einer schreibgeschützten Übersicht mit maskiertem Schlüssel, gebundenem Projekt,
Callback-URL und Abgleich-URL. Zwei Dinge liegen außerhalb dieser Seite: die
Währungsbeschränkungen des Moduls in den allgemeinen PrestaShop-Zahlungseinstellungen sowie
die Shop-URL, an die die Verbindung gebunden ist.

## Ablage der Geheimnisse

Zugangsdaten und vorübergehender Gerätezustand werden in der PrestaShop-Konfiguration als
AES-256-GCM-Umschlag gespeichert, verschlüsselt mit einem aus `_COOKIE_KEY_` abgeleiteten
Schlüssel. Gespeicherte Geheimnisse werden nicht wieder in die Admin-Seite ausgegeben und
lassen sich nicht manuell eintragen. Erneutes Verbinden ist der einzige Weg, sie zu ändern,
und der einzige erforderliche Schritt nach der Rotation eines Schlüssels oder Webhook-Geheimnisses.

## Bestellstatus

Bei der Installation werden drei Status angelegt — keiner davon ist als bezahlt oder
protokollierbar (`logable`) markiert, und keiner versendet E-Mails an die Kundschaft:

- **Awaiting Paymos payment** — die Bestellung existiert, die Rechnung ist offen, es ist noch nichts eingegangen;
- **Paymos payment confirming** — die Transaktion ist auf der Chain und sammelt Bestätigungen;
- **Paymos payment — manual review** — Geld ist eingegangen, aber ein Betrag stimmt nicht mehr
  überein, oder die Bestellung wurde geändert, während ihre alte Rechnung bereits bezahlt, noch
  zahlbar oder nicht lesbar war, sodass keine neue Rechnung ausgestellt wurde.

Endgültige Ergebnisse landen in den PrestaShop-eigenen Status, damit das übrige Backoffice
wie gewohnt arbeitet:

| Eingehendes Ereignis | Bestellstatus |
|---|---|
| `invoice.confirming` | Paymos payment confirming |
| `invoice.underpaid_waiting`, `invoice.awaiting_payment` | Awaiting Paymos payment |
| `invoice.paid`, `invoice.paid_over` | Payment accepted |
| `invoice.underpaid` | Payment error |
| `invoice.expired`, `invoice.cancelled` | Canceled |

Eine bezahlte Bestellung wird niemals zurückgesetzt: Ein verspätetes `cancelled`, `expired`
oder `confirming` wird in den privaten Bestellnotizen vermerkt und verworfen. Stimmt der
Betrag eines bezahlten Callbacks nicht mehr mit der Bestellung überein, wechselt die Bestellung
in **manual review** statt in Payment accepted — samt einer Notiz mit allen verglichenen Zahlen.
Sobald die Zahlung On-Chain finalisiert ist, werden Transaktions-Hash und Explorer-Link als
Notiz ergänzt.

Beim Deinstallieren des Moduls bleiben die drei Status erhalten. PrestaShop verweigert das
Löschen von Status, auf die historische Bestellungen verweisen, und verwaiste Altbestellungen
wären das größere Übel.

## Ablauf für Kundinnen und Kunden

Paymos erscheint als Zahlungsart mit eigener Schaltfläche. Bei Auswahl übergibt der Shop den
Warenkorb an den `validation`-Controller des Moduls, der die Bestellung im Status **Awaiting
Paymos payment** anlegt und zur gehosteten Zahlungsseite weiterleitet; der gezahlte Betrag wird
erst später aus dem verifizierten Callback geschrieben, niemals an dieser Stelle aus dem Warenkorb.

Ein doppeltes Absenden oder Neuladen im Checkout erzeugt weder eine zweite Bestellung noch eine
zweite Rechnung — stattdessen wird die bestehende Bestellung für diesen Warenkorb gefunden und
die Kundschaft auf deren Statusseite geleitet.

Lässt sich die Rechnung gar nicht erstellen, wechselt die Bestellung auf Payment error und die
Kundschaft erhält eine klare Meldung statt einer leeren Weiterleitung. Derselbe Controller deckt
auch den Normalfall neutral ab: Wer die Zahlungsseite zu früh geschlossen hat, sieht, dass die
Zahlung noch bestätigt wird, samt einem Link **Continue payment** zurück zur offenen Rechnung.
Konnte der Checkout eine alte Rechnung nicht ersetzen, weist die Seite darauf hin, dass die
Bestellung geprüft werden muss, und bittet um Kontaktaufnahme mit dem Shop — ohne Zahlungslink.
Die Seite ist in jedem Fall schreibgeschützt und löst niemals einen Statuswechsel aus.

## In der Sandbox testen

1. Lassen Sie **Mode** auf Sandbox und geben Sie über die Storefront eine Bestellung auf.
2. Öffnen Sie diese Rechnung im Paymos Dashboard und lösen Sie das gewünschte Ergebnis manuell aus. In der Sandbox bestätigt sich nichts per Timer.
3. Prüfen Sie Statusverlauf und Notizen der Bestellung im Backoffice.
4. Stellen Sie **Mode** auf Live um. Die einmalige Verbindung hat beide Umgebungen eingerichtet, es muss nichts doppelt konfiguriert werden.

Hinter einer Sandbox-Zahlung liegt keine echte Blockchain, daher werden dort weder
Transaktions-Hash noch Explorer-Link erfasst.

## Callbacks und Abgleich

Der Callback-Controller liest den unveränderten Request-Body und den Header `X-Webhook-Signature`
(`t={timestamp},v1={hmac_hex}`, HMAC-SHA256, zeitkonstanter Vergleich, während einer
Schlüsselrotation für beide Geheimnisse tolerant) und antwortet mit einem echten HTTP-Statuscode.
Ereignis-IDs werden in der eigenen Tabelle des Moduls gespeichert, sodass Mehrfachzustellungen
quittiert, aber nicht zweimal auf die Bestellung angewendet werden. Vor jedem Endstatus wird die
Rechnung erneut aus der Merchant API abgerufen und gegen den gespeicherten Snapshot geprüft.

```text
https://your-store.example/index.php?fc=module&module=paymos&controller=callback
```

Der Abgleich (Reconciliation) ist das Sicherheitsnetz für Zustellungen, die nie angekommen sind.
Er verarbeitet bis zu 50 offene Rechnungen der letzten 24 Stunden über dieselbe Zuordnungslogik —
inklusive Rückprüfung über die API, Betragsprüfung und Rollback-Schutz. Es gibt zwei Wege, ihn
auszulösen:

- per HTTP über die mit Token versehene URL auf der Konfigurationsseite — diese Adresse gehört in
  Ihren Scheduler, da PrestaShop 9 den direkten Zugriff auf `.php`-Dateien unter `modules/`
  blockiert und ein Cronjob direkt auf das Skript einen 403-Fehler erhalten würde;
- über die Shell mit `php modules/paymos/cron/reconcile.php`, wofür kein Token erforderlich ist.

Jeder Abgleichlauf prüft neben offenen Rechnungen auch ausstehende Paymos-Erstattungen.

## Erstattungen

Das Modul unterstützt native Erstattungen über die Paymos Merchant API (`/v1/refunds`) in zwei
serverseitig berechneten Modi: `full` (vollständige Rechnungserstattung) und `overpayment`
(Erstattung des Überzahlungsbetrags einer `paid_over`-Rechnung). Die Mittel gehen als neue
ausgehende Blockchain-Transaktion ausschließlich an die verifizierte `first_sender`-Adresse, die
Paymos bei der bezahlten Rechnung gespeichert hat — eine manuelle Eingabe der Empfänger-Wallet
ist nicht vorgesehen, und beliebige Teilbeträge werden nicht unterstützt.

Im PrestaShop-Backoffice können Mitarbeitende mit Bearbeitungsrecht für **Orders** Erstattungen
über zwei Wege anstoßen:

- das Panel **Paymos refunds** auf der Bestelldetailseite (`displayAdminOrderMain`, auch per
  Bestell-ID über **Module Manager → Paymos → Configure** erreichbar) mit den Aktionen
  **Refund in full** und **Refund overpayment**, Anzeige der verifizierten `first_sender`-Adresse
  und dem Operationsverlauf;
- den nativen PrestaShop-Ablauf für vollständige Bestellerstattungen (`paymosNativeRefund`).

Gutschriften (Order Slips) und Erstattungs-E-Mails an Kundinnen und Kunden werden zurückgehalten,
bis die Paymos-Erstattung `completed` erreicht und über den Callback-Controller oder den
`reconcile`-Endpunkt bzw. das CLI-Skript gegenüber der Merchant API verifiziert wurde. Im
Sandbox-Modus lassen sich die Endzustände (`completed`, `failed`, `cancelled`) für End-to-End-Tests
simulieren.

## Fehlerbehebung

**Verbindung lehnt eine HTTP-Shop-URL ab.** Aktivieren Sie `PS_SSL_ENABLED` und stellen Sie sicher,
dass der Shop tatsächlich über TLS ausgeliefert wird; das Modul nennt diese Einstellung in der
Fehlermeldung statt stillschweigend fehlzuschlagen.

**Die Bestellung bleibt in Awaiting Paymos payment hängen.** Rufen Sie die oben genannte
Callback-URL von außerhalb Ihres Netzwerks ab — sie muss ohne HTTP-Authentifizierung und ohne
Weiterleitung antworten, da Weiterleitungen nicht verfolgt werden. Prüfen Sie anschließend das
PrestaShop-Log auf Einträge mit `PaymosPrestaShop`. War der Shop zeitweise nicht erreichbar,
rufen Sie die Abgleich-URL einmal auf, um den Rückstand aufzuholen.

**Eine Bestellung steht auf manual review.** Dafür gibt es zwei Ursachen, die in der Bestellnotiz
klar unterschieden werden. Entweder hat sich die Bestellsumme zwischen Rechnungserstellung und
Zahlungseingang geändert — dann nennt die Notiz Snapshot, aktuellen Gesamtbetrag und den Betrag
aus dem Ereignis. Oder Bestellsumme, Modus bzw. Projekt wurden geändert, während die alte
Paymos-Rechnung bereits bezahlt, noch zahlbar oder nicht lesbar war — dann beginnt die Notiz mit
`"Paymos payment needs manual review."`. Im zweiten Fall wird keine neue Rechnung erzeugt und die
Kundschaft gebeten, sich bei Ihnen zu melden; prüfen Sie zuerst die alte Rechnung im Paymos
Dashboard. Klären Sie beide Fälle manuell.

**Paymos wird im Checkout nicht angeboten.** Das Modul blendet sich aus, wenn die Warenkorbwährung
nicht zu den freigegebenen Währungen des Moduls gehört oder die aktive Umgebung nicht vollständig
verbunden ist. Prüfen Sie zuerst die Währungsbeschränkungen und danach das Verbindungspanel.

- [Dokumentation](https://paymos.io/de/docs/cms-prestashop)
- [Quellcode](https://github.com/paymos-labs/prestashop)
- [Änderungsprotokoll](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
