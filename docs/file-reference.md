# Technische Dateidokumentation

Dieses Dokument beschreibt jede Datei, die in der öffentlichen Repository-Version von `simple-solana-dice` enthalten ist. Laufzeitdaten und Geheimnisse werden absichtlich nicht eingecheckt.

## Root-Dateien

### `.gitignore`

Schließt Node-Abhängigkeiten, Build-Ausgaben, `.env`-Dateien, Laufzeitverzeichnisse, SQLite-Datenbanken, Logs und Betriebssystemdateien aus. Die lokal gebündelte Datei `backend/public/vendor/solana-web3.min.js` bleibt absichtlich versioniert, weil der aktuelle PHP-Einstieg ohne Node-Build auf diese Datei zugreift.

### `README.md`

Öffentliche Einstiegspunkt-Dokumentation. Sie erklärt Zweck, Devnet-Grenzen, Voraussetzungen, Installation, Konfiguration, Spielablauf, API, Sicherheitsmodell und Verifikation.

## Backend

### `backend/public/index.php`

Die zentrale ausführbare Anwendung und der einzige öffentliche PHP-Einstiegspunkt.

Technische Verantwortlichkeiten:

- lädt serverseitige `.env`-Werte
- leitet die Treasury-Adresse mit `solana-keygen pubkey` aus dem geschützten Keypair ab
- kommuniziert mit dem konfigurierten Solana-RPC
- stellt JSON-Endpunkte für Health, Konfiguration, Status, Spiele, Einzahlung und Auszahlung bereit
- speichert den aktuellen POC-Spielstatus in der PHP-Session
- validiert Wallet-Adressen und Eingaben
- prüft bestätigte System-Transfer-Instructionen gegen Spieler, Treasury und Lamport-Betrag
- verhindert den Spielstart bei unzureichender Treasury-Liquidität
- führt die Gewinnberechnung aus dem Einsatz nach Gebühren durch
- startet serverseitige Treasury-Auszahlungen mit der Solana CLI
- liefert zugleich HTML, CSS und JavaScript für den aktuellen No-Build-Client

Die Frontend-Logik in dieser Datei verbindet Solflare, erstellt die Einzahlungstransaktion, verarbeitet API-Antworten, aktualisiert Kontostand/Treasury/RPC und animiert die sechs SVG-Würfelflächen. Der Treasury-Private-Key wird nicht in den HTML- oder JavaScript-Teil geschrieben.

### `backend/.env.example`

Nicht-geheime Konfigurationsvorlage. Sie dokumentiert die erwarteten Variablennamen:

- `APP_ENV` — Betriebsmodus
- `SOLANA_RPC_URL` — serverseitige JSON-RPC-URL
- `SOLANA_CLUSTER` — erwarteter Cluster, hier `devnet`
- `TREASURY_KEYPAIR_PATH` — Pfad zum außerhalb liegenden Treasury-Keypair
- `DATABASE_PATH` — reservierter Pfad für eine spätere persistente Datenbank

Die echte `backend/.env` wird durch `.gitignore` ausgeschlossen.

### `backend/README.md`

Backend-spezifische Sicherheits- und Betriebsnotizen. Die Datei erklärt, dass `public/index.php` der öffentliche Einstieg ist, RPC-Zugangsdaten serverseitig bleiben und das Treasury-Keypair nicht im Webroot liegt.

### `backend/public/vendor/solana-web3.min.js`

Lokal gebündelte Solana-Web3-Laufzeit für den Browser. Der PHP-Einstieg lädt sie über `/vendor/solana-web3.min.js`, damit der No-Build-POC ohne npm oder externe CDN-Abhängigkeit gestartet werden kann. Sie enthält keine Projekt- oder Wallet-Geheimnisse.

## Frontend

### `frontend/package.json`

Beschreibt die geplante TypeScript-/React-Variante. Enthalten sind React, Vite, TypeScript, Solana Web3.js, SPL-Token-Unterstützung sowie Solflare- und Wallet-Adapter-Pakete. Der aktuelle lokale Einstieg benötigt diese Variante noch nicht, weil die nutzbare Oberfläche in `backend/public/index.php` eingebettet ist.

### `frontend/src/main.ts`

Kompakte Source-Skizze für eine spätere getrennte TypeScript-Frontend-Anwendung. Sie definiert Wallet- und Game-Typen und zeigt den vorgesehenen API-Aufruf für ein Demo-Spiel. Die Datei ist bewusst ein Outline und wird aktuell nicht durch Vite ausgeliefert.

## Dokumentation und On-chain-Kontrakte

### `docs/architecture.md`

Architekturentwurf mit Komponenten, empfohlenem Zahlungsablauf, API-Idee und Produktionsanforderungen. Besonders wichtig ist die Trennung zwischen dem aktuellen PHP-POC und einem später benötigten Anchor/Rust-Escrow-Programm mit verifizierbarer Zufallsquelle.

### `docs/file-reference.md`

Dieses Dokument. Es beschreibt die Funktion jeder versionierten Datei und die bewusst ausgeschlossenen Laufzeitdateien.

### `contracts/README.md`

Dokumentiert, dass im Repository noch kein Anchor-/Rust-Programm enthalten ist. Für einen produktionsnahen Einsatz müsste die derzeit vertrauensbasierte PHP-Auszahlung durch ein geprüftes On-chain-Programm ersetzt werden.

## Bewusst nicht versionierte Dateien

Folgende Dateien oder Daten gehören nicht in ein öffentliches Repository:

- `backend/.env` mit RPC-URL oder Zugangstoken
- Treasury-Keypair und Seed-Phrase
- `var/`, Sessiondaten und SQLite-Datenbanken
- Logs und temporäre Testausgaben
- `node_modules/` und Build-Ausgaben
- persönliche Browser-Wallet-Daten

Das aktuelle Treasury-Keypair liegt außerhalb des Projekts unter einem geschützten Konfigurationspfad. Der Dateiwert wird weder dokumentiert noch ausgegeben.

## Datenfluss und Vertrauensgrenzen

Der Browser darf die Einzahlung mit Solflare signieren, aber nicht die Treasury-Auszahlung. Das Backend nimmt die signierte Einzahlung entgegen, sendet sie an den RPC und prüft die bestätigte Chain-Transaktion. Erst danach wird das Ergebnis erzeugt. Die Auszahlung wird bei einem Treffer durch den Server mit dem Keypair signiert.

Damit bleibt der Private Key außerhalb des Browsers. Der Ansatz ist trotzdem für Echtgeld ungeeignet, weil das Backend im POC eine zentrale Vertrauensinstanz für Ergebnis und Auszahlung darstellt. Ein echtes Produkt benötigt ein auditiertes On-chain-Programm, verifizierbare Zufallslogik, Replay-Schutz, persistente Buchführung und rechtliche Prüfung.
