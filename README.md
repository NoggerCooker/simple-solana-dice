# Simple Solana Dice

`Simple Solana Dice` ist ein Solana-Devnet-Proof-of-Concept für ein Wallet-basiertes Würfelspiel mit nativen SOL-Einsätzen. Der Spieler verbindet Solflare, wählt eine von sechs Würfelflächen und einen Einsatz zwischen `0,01` und `1 SOL`. Nach einer serverseitig bestätigten Einzahlung wird das Ergebnis erzeugt. Bei einem Treffer wird die Auszahlung serverseitig mit dem geschützten Treasury-Keypair signiert.

> **Wichtig:** Dieses Projekt ist ausschließlich für Solana Devnet gedacht. Es ist kein geprüftes Echtgeld-, Glücksspiel- oder Verwahrungssystem. Der serverseitige Raw-Keypair-Auszahlungsweg ist nur ein Entwicklungs-POC.

## Funktionsumfang

- Solflare-Browser-Wallet über den injizierten Provider
- native SOL-Einzahlungen auf die Treasury
- sechs anklickbare SVG-Würfelflächen ohne Emoji-Abhängigkeit
- animierter Würfelwurf nach bestätigter Einzahlung
- Kontostand, Treasury-Guthaben und RPC-Status
- serverseitige Prüfung von Sender, Empfänger, Betrag und bestätigter Transaktion
- konfigurierbare Einzahlungsgebühr, aktuell `1 %`
- Gewinnberechnung aus dem Einsatz nach Gebühren
- serverseitig signierte Treasury-Auszahlung ohne Treasury-Wallet im Browser
- lokaler PHP-Start ohne Node.js-Build für den funktionsfähigen Demo-/POC-Einstieg

## Technische Eckdaten

- Cluster: Solana Devnet
- Server: PHP 8.x, eingebauter PHP-Webserver oder Apache
- Frontend-Einstieg: HTML/CSS/JavaScript in `backend/public/index.php`
- Solana-RPC: ausschließlich serverseitig über `SOLANA_RPC_URL`
- Wallet: Solflare über `window.solflare`
- Spielstatus: PHP-Session im aktuellen POC
- Treasury-Keypair: außerhalb des Projektordners, niemals im Webroot oder Git
- Gebühr: `DEPOSIT_FEE_PERCENT` in `backend/public/index.php`
- Multiplikator: `WIN_MULTIPLIER` in `backend/public/index.php`

## Projektstruktur

```text
simple-solana-dice/
├── README.md
├── .gitignore
├── backend/
│   ├── .env.example
│   ├── README.md
│   └── public/
│       ├── index.php
│       └── vendor/
│           └── solana-web3.min.js
├── contracts/
│   └── README.md
├── docs/
│   ├── architecture.md
│   └── file-reference.md
└── frontend/
    ├── package.json
    └── src/
        └── main.ts
```

Eine vollständige technische Beschreibung jeder Datei steht in [`docs/file-reference.md`](docs/file-reference.md).

## Voraussetzungen

Für den lokalen PHP-POC:

- PHP 8.x mit aktivierter JSON- und Session-Unterstützung
- Solana CLI und `solana-keygen` auf dem Server, wenn echte Devnet-Auszahlungen getestet werden
- ein außerhalb des Projekts gespeichertes Treasury-Keypair
- ein serverseitig erreichbarer Solana-RPC-Endpunkt
- Solflare im Browser für echte Wallet-Signaturen

Node.js ist für den aktuellen eingebetteten PHP-Einstieg nicht erforderlich. `frontend/` enthält die geplante TypeScript-/React-Struktur und Abhängigkeiten, ist aber nicht der aktuell ausgelieferte Build-Einstieg.

## Konfiguration

Die lokale geheime Datei wird nicht versioniert:

```text
backend/.env
```

Vorlage:

```dotenv
APP_ENV=devnet
SOLANA_RPC_URL=https://api.devnet.solana.com
SOLANA_CLUSTER=devnet
TREASURY_KEYPAIR_PATH=/root/.config/web3-test/treasury-keypair.json
DATABASE_PATH=var/simple-solana-dice.sqlite3
```

`SOLANA_RPC_URL` kann auf einen privaten Devnet-RPC wie QuickNode zeigen. Der Wert wird nur vom PHP-Backend gelesen und niemals an den Browser ausgeliefert. Das Keypair sollte außerhalb des Projektordners liegen und mindestens mit `chmod 600` geschützt werden.

Die Wirtschaftlichkeit wird zentral in `backend/public/index.php` festgelegt:

```php
const DEPOSIT_FEE_PERCENT = 1.0;
const WIN_MULTIPLIER = 6;
```

Für einen Bruttoeinsatz `G` gilt:

```text
Gebühr = round(G × DEPOSIT_FEE_PERCENT / 100)
Spieleinsatz = G − Gebühr
Gewinnauszahlung = Spieleinsatz × WIN_MULTIPLIER
```

Die Treasury-Prüfung verlangt, dass die mögliche Auszahlung kleiner als das aktuell verfügbare Treasury-Guthaben ist.

## Lokaler Start

```bash
cd /root/simple-solana-dice
php -S 0.0.0.0:8765 -t backend/public
```

Danach im Browser öffnen:

```text
http://127.0.0.1:8765
```

Für einen Server mit installierter Solana CLI muss der CLI-Pfad verfügbar sein:

```bash
export PATH="/root/.local/share/solana/install/active_release/bin:$PATH"
```

## Spielablauf

1. Der Browser ruft `/api/config` und `/api/status` auf.
2. Der Spieler verbindet Solflare.
3. Der Spieler wählt Tipp und Einsatz.
4. `/api/game/create` erzeugt eine neue Spiel-ID und prüft die Treasury-Liquidität.
5. Das Frontend erstellt eine native SOL-Transfertransaktion zur vom Server gelieferten Treasury-Adresse.
6. Solflare signiert die Transaktion.
7. `/api/game/submit` übermittelt die signierte Transaktion an den RPC.
8. `/api/game/confirm` wartet auf eine bestätigte Transaktion und prüft die Transferdaten serverseitig.
9. Erst danach wird das Würfelergebnis erzeugt.
10. Bei einem Treffer bereitet der Server die Auszahlung vor; der Server signiert die Treasury-Auszahlung selbst.
11. `/api/payout/confirm` bestätigt die Auszahlung und aktualisiert den Spielstatus.

Ein abgebrochener Signaturdialog darf nicht als Einzahlung gelten. Alte Spiele werden nicht automatisch als neues Spiel fortgesetzt.

## API-Übersicht

- `GET /api/health` — Betriebsmodus und Treasury-Konfiguration
- `GET /api/config` — Cluster, Treasury-Adresse, Einsatzlimit, Gebühr und Multiplikator
- `GET /api/status` — RPC-Status und verfügbares Treasury-Guthaben
- `POST /api/rpc` — allowlist-basierter serverseitiger RPC-Proxy
- `POST /api/game/create` — Spiel anlegen und Liquidität prüfen
- `POST /api/game/submit` — signierte Einzahlung an den RPC senden
- `POST /api/game/recover` — explizite Recovery einer bereits bestätigten Einzahlung
- `POST /api/game/confirm` — Einzahlung verifizieren und Spiel auflösen
- `POST /api/payout/prepare` — serverseitige Auszahlung vorbereiten
- `POST /api/payout/submit` — Auszahlung senden
- `POST /api/payout/confirm` — Auszahlung bestätigen
- `GET /api/games` — Spiele der aktuellen PHP-Session anzeigen

Clientdaten wie `result=win` oder `payout=6x` werden nicht als vertrauenswürdige Entscheidungsgrundlage akzeptiert.

## Sicherheitsgrenzen

- Niemals `.env`, Keypairs, Sessiondaten, SQLite-Dateien oder Logs veröffentlichen.
- Niemals Private Keys an Browser, API-Responses, Logs oder Git übergeben.
- Der QuickNode-/RPC-Endpunkt bleibt serverseitig.
- Die Treasury-Auszahlung verwendet im POC die Solana CLI und einen serverseitigen Raw-Keypair. Das ist nicht dieselbe Sicherheitsgarantie wie ein auditiertes On-chain-Escrow-Programm.
- Für einen produktionsnahen Betrieb wären ein Anchor/Rust-Programm, deterministische Spiel-IDs, Replay-Schutz, pausierbare Notfalllogik, verifizierbare Zufallsquelle, Rate-Limits, persistente Datenbank, Monitoring und ein Sicherheits-/Rechtsaudit erforderlich.

## Verifikation des aktuellen Arbeitsstands

Der lokale Server wurde nach der Umbenennung aus `/root/simple-solana-dice` gestartet. Die folgenden Endpunkte antworten erfolgreich:

```text
GET /api/health  -> ok=true, mode=devnet, real_money=false
GET /api/config  -> cluster=devnet, deposit_fee_percent=1, win_multiplier=6
GET /api/status  -> rpc_status=ok
```

Die Würfelgrafik wird als SVG ausgeliefert; die sechs Würfelflächen und die Animation verwenden keine Unicode-Würfel- oder Emoji-Zeichen.
