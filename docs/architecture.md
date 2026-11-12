# Architekturentscheidung

## Ist das technisch möglich?

Ja. Eine TypeScript-Frontend-Anwendung kann Solflare direkt über den injizierten Provider oder über den Solana Wallet Adapter verbinden und eine native SOL-Transaktion signieren lassen. PHP kann als API-Backend dienen. PHP sollte jedoch nicht als alleinige Vertrauensinstanz für den Zufall oder die Auszahlung dienen.

## Empfohlener Ablauf

1. Frontend verbindet Wallet und zeigt die tatsächlich verbundene Public Key an.
2. Backend erstellt eine eindeutige `game_id`, legt SOL-Einsatz, Cluster und Treasury fest und liefert eine kurzlebige Spiel-Challenge.
3. Frontend erstellt eine exakt beschriebene native SOL-Transfer-Transaktion und lässt sie durch Solflare signieren.
4. Backend prüft die bestätigte Solana-Transaktion gegen alle erwarteten Werte; nur eine bestätigte, noch nicht verwendete Zahlung zählt.
5. Das Ergebnis wird durch ein geprüftes on-chain-Programm bzw. eine verifizierbare Zufallsquelle bestimmt. Browser und PHP dürfen das Ergebnis nicht heimlich beeinflussen können.
6. Ein Gewinn wird nicht durch ein beliebiges PHP-Update gutgeschrieben, sondern durch eine on-chain-Regel mit Replay-Schutz, Limits und ausreichend Treasury-Liquidität ausgezahlt.
7. Frontend liest den finalen Status aus der Chain/API und zeigt Explorer-Link, Spiel-ID und Ergebnis.

## Komponenten

### Frontend / TypeScript

- Wallet-Auswahl und Verbindungsstatus
- Solana RPC/Connection
- SOL-Lamports und Dezimalstellen
- Transaktionsaufbau ohne Private Keys
- Statusanzeige: `created`, `awaiting_payment`, `confirmed`, `resolved`, `paid`, `failed`
- Schutz gegen doppelte Klicks und falsche Cluster

### PHP-Backend

- JSON-API mit CORS-Allowlist und CSRF-/Replay-Schutz
- Challenge-Erstellung und Statusverwaltung
- Transaktionsprüfung über Solana RPC
- Rate-Limits, Idempotency Keys, Audit-Logs
- Keine Verwahrung von User-Private-Keys
- Datenbank: PostgreSQL oder SQLite für POC; PostgreSQL für produktiven Betrieb

### Solana-Programm

Für einen echten Einsatz: Anchor/Rust-Programm mit:

- Escrow/Treasury-Regeln
- eindeutiger Spiel-ID und Nonce
- Einsatz- und Auszahlungsgrenzen
- Replay-Schutz
- pausierbarem Notfallmodus
- definierter Zufallsquelle/VRF-Integration
- Events für Einzahlungen, Ergebnis und Auszahlung

## Beispiel-API

```text
POST /api/games
  request: { wallet, guess, amount }
  response: { game_id, deposit_instruction, expires_at }

POST /api/games/{id}/confirm
  request: { signature }
  response: { status, slot, explorer_url }

GET /api/games/{id}
  response: { status, guess, result, payout_signature }
```

Die API darf niemals ein vom Client geliefertes `result=win` oder `payout=6x` akzeptieren.

## Wichtige offene Entscheidungen

- Devnet oder Mainnet?
- Welches Treasury und welche Limits?
- Ist es ein reines Testspiel ohne Wert oder Echtgeldbetrieb?
- Wer bestimmt den Zufall und wie wird er verifiziert?
- Welche Jurisdiktionen und Alters-/KYC-Anforderungen gelten?
- Soll der Gewinn exakt `6x Einsatz` inklusive oder zuzüglich Einsatz sein?
