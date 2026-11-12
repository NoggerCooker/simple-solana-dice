// Source outline for the Solflare browser interaction used by the local PHP demo.
// The runnable no-build demo is embedded in backend/public/index.php because
// Node/npm are intentionally not required for this local test.

type WalletState = { address: string; isDemo: boolean };
type Game = { id: string; guess: number; result: number; amount: number; won: boolean; payout: number };

export async function startDemoGame(wallet: WalletState, guess: number, amount: number): Promise<Game> {
  const response = await fetch('/api/game', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ wallet: wallet.address, guess, amount }),
  });
  const payload = await response.json() as { ok: boolean; game?: Game; error?: string };
  if (!payload.ok || !payload.game) throw new Error(payload.error ?? 'Spiel konnte nicht gestartet werden.');
  return payload.game;
}
