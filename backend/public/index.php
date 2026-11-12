<?php

declare(strict_types=1);

const DEPOSIT_FEE_PERCENT = 1.0;
const WIN_MULTIPLIER = 6;

session_start();

function envValue(string $key): string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $file = dirname(__DIR__) . '/.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$name, $value] = explode('=', $line, 2);
                $env[trim($name)] = trim($value, " \t\"'");
            }
        }
    }
    return $env[$key] ?? '';
}

function treasuryWallet(): string
{
    $keygen = trim((string)shell_exec('command -v solana-keygen'));
    $keypair = envValue('TREASURY_KEYPAIR_PATH') ?: '/root/.config/web3-test/treasury-keypair.json';
    if ($keygen === '' || !is_readable($keypair)) return '';
    return trim((string)shell_exec(escapeshellarg($keygen) . ' pubkey ' . escapeshellarg($keypair) . ' 2>/dev/null'));
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function requestJson(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($data) ? $data : [];
}

function rpc(string $method, array $params = []): array|string
{
    $url = envValue('SOLANA_RPC_URL');
    if ($url === '') jsonResponse(['ok' => false, 'error' => 'SOLANA_RPC_URL fehlt in backend/.env.'], 500);
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 25]]);
    $decoded = json_decode(@file_get_contents($url, false, $context) ?: '', true);
    if (!is_array($decoded)) jsonResponse(['ok' => false, 'error' => 'RPC antwortet nicht mit JSON.'], 502);
    if (isset($decoded['error'])) jsonResponse(['ok' => false, 'error' => 'Solana RPC: ' . ($decoded['error']['message'] ?? 'unbekannter Fehler')], 502);
    return $decoded['result'] ?? [];
}

function gameOrFail(string $id): array
{
    $game = $_SESSION['games'][$id] ?? null;
    if (!is_array($game)) jsonResponse(['ok' => false, 'error' => 'Spiel nicht gefunden.'], 404);
    return $game;
}

function saveGame(array $game): void { $_SESSION['games'][$game['id']] = $game; }
function validWallet(string $wallet): bool { return preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet) === 1; }

function verifyTransfer(array $transaction, string $source, string $destination, int $lamports): bool
{
    if (($transaction['meta']['err'] ?? null) !== null) return false;
    foreach (($transaction['transaction']['message']['instructions'] ?? []) as $instruction) {
        $parsed = $instruction['parsed'] ?? null;
        $info = is_array($parsed) ? ($parsed['info'] ?? []) : [];
        if (($instruction['program'] ?? '') === 'system' && ($parsed['type'] ?? '') === 'transfer' && ($info['source'] ?? '') === $source && ($info['destination'] ?? '') === $destination && (int)($info['lamports'] ?? 0) === $lamports) return true;
    }
    return false;
}

function sendRawTransaction(string $raw): string
{
    $result = rpc('sendTransaction', [$raw, ['encoding' => 'base64', 'skipPreflight' => false, 'preflightCommitment' => 'confirmed']]);
    if (!is_string($result) || $result === '') jsonResponse(['ok' => false, 'error' => 'Solana hat keine Transaktionssignatur geliefert.'], 502);
    return $result;
}

function treasuryKeypairPath(): string
{
    return envValue('TREASURY_KEYPAIR_PATH') ?: '/root/.config/web3-test/treasury-keypair.json';
}

function sendTreasuryPayout(array $game): string
{
    $keypair = treasuryKeypairPath();
    if (!is_readable($keypair)) jsonResponse(['ok' => false, 'error' => 'Treasury-Keypair fehlt serverseitig. Erwartet wird: ' . $keypair], 503);
    $solana = trim((string)shell_exec('command -v solana'));
    if ($solana === '') jsonResponse(['ok' => false, 'error' => 'Solana CLI ist serverseitig nicht verfügbar.'], 503);
    $keygen = trim((string)shell_exec('command -v solana-keygen'));
    if ($keygen === '') jsonResponse(['ok' => false, 'error' => 'Solana-Keygen ist serverseitig nicht verfügbar.'], 503);
    $pubkeyCommand = escapeshellarg($keygen) . ' pubkey ' . escapeshellarg($keypair) . ' 2>/dev/null';
    $actualTreasury = trim((string)shell_exec($pubkeyCommand));
    if ($actualTreasury !== $game['treasury']) jsonResponse(['ok' => false, 'error' => 'Treasury-Keypair passt nicht zum konfigurierten Treasury-Public-Key.'], 503);
    $amount = number_format(((int)$game['payout_lamports']) / 1000000000, 9, '.', '');
    $command = escapeshellarg($solana) . ' transfer ' . escapeshellarg($game['wallet']) . ' ' . escapeshellarg($amount)
        . ' --keypair ' . escapeshellarg($keypair) . ' --from ' . escapeshellarg($keypair) . ' --url ' . escapeshellarg(envValue('SOLANA_RPC_URL'))
        . ' --output json-compact --no-wait 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) jsonResponse(['ok' => false, 'error' => 'Treasury-Auszahlung konnte nicht signiert/gesendet werden.'], 502);
    $response = json_decode(implode("\n", $output), true);
    $signature = is_array($response) ? (string)($response['signature'] ?? '') : '';
    if ($signature === '') jsonResponse(['ok' => false, 'error' => 'Solana CLI lieferte keine Auszahlungssignatur.'], 502);
    return $signature;
}

function findDepositSignature(array $game): string
{
    $signatures = rpc('getSignaturesForAddress', [$game['treasury'], ['limit' => 20]]);
    if (!is_array($signatures)) return '';
    foreach ($signatures as $entry) {
        $signature = (string)($entry['signature'] ?? '');
        if ($signature === '' || ($entry['err'] ?? null) !== null) continue;
        $transaction = rpc('getTransaction', [$signature, ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0]]);
        if (is_array($transaction) && verifyTransfer($transaction, $game['wallet'], $game['treasury'], (int)$game['lamports'])) return $signature;
    }
    return '';
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$treasury = treasuryWallet();

if ($path === '/api/health') jsonResponse(['ok' => true, 'mode' => 'devnet', 'real_money' => false, 'treasury_configured' => validWallet($treasury)]);
if ($path === '/api/config' && $method === 'GET') jsonResponse(['ok' => true, 'cluster' => envValue('SOLANA_CLUSTER') ?: 'devnet', 'treasury' => $treasury, 'max_amount_sol' => 1, 'deposit_fee_percent' => DEPOSIT_FEE_PERCENT, 'win_multiplier' => WIN_MULTIPLIER]);
if ($path === '/api/status' && $method === 'GET') {
    $balanceResult = rpc('getBalance', [$treasury, ['commitment' => 'confirmed']]);
    $healthResult = rpc('getHealth');
    $balanceLamports = is_array($balanceResult) ? (int)($balanceResult['value'] ?? 0) : 0;
    $rpcHealthy = $healthResult === 'ok';
    jsonResponse(['ok' => true, 'rpc_status' => $rpcHealthy ? 'ok' : 'error', 'treasury_balance_lamports' => $balanceLamports, 'treasury_balance_sol' => $balanceLamports / 1000000000]);
}

if ($path === '/api/rpc' && $method === 'POST') {
    $data = requestJson();
    $allowed = ['getLatestBlockhash', 'getBalance'];
    $rpcMethod = (string)($data['method'] ?? '');
    if (!in_array($rpcMethod, $allowed, true)) jsonResponse(['ok' => false, 'error' => 'RPC-Methode nicht erlaubt.'], 403);
    jsonResponse(['ok' => true, 'result' => rpc($rpcMethod, is_array($data['params'] ?? null) ? $data['params'] : [])]);
}

if ($path === '/api/game/create' && $method === 'POST') {
    $data = requestJson();
    $wallet = trim((string)($data['wallet'] ?? ''));
    $guess = filter_var($data['guess'] ?? null, FILTER_VALIDATE_INT);
    $amount = filter_var($data['amount_sol'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!validWallet($wallet)) jsonResponse(['ok' => false, 'error' => 'Ungültige Wallet-Adresse.'], 422);
    if (!validWallet($treasury)) jsonResponse(['ok' => false, 'error' => 'Treasury-Public-Key fehlt oder ist ungültig.'], 500);
    if ($guess === false || $guess < 1 || $guess > 6) jsonResponse(['ok' => false, 'error' => 'Tipp muss 1 bis 6 sein.'], 422);
    if ($amount === false || $amount <= 0 || $amount > 1) jsonResponse(['ok' => false, 'error' => 'Einsatz muss größer als 0 und höchstens 1 SOL sein.'], 422);
    $lamports = (int)round((float)$amount * 1000000000);
    $feeLamports = (int)round($lamports * (DEPOSIT_FEE_PERCENT / 100));
    $playLamports = $lamports - $feeLamports;
    if ($lamports < 1 || $playLamports < 1) jsonResponse(['ok' => false, 'error' => 'Einsatz ist nach Gebühren zu klein.'], 422);
    $treasuryBalanceResult = rpc('getBalance', [$treasury, ['commitment' => 'confirmed']]);
    $treasuryBalanceLamports = is_array($treasuryBalanceResult) ? (int)($treasuryBalanceResult['value'] ?? 0) : 0;
    $potentialPayoutLamports = $playLamports * WIN_MULTIPLIER;
    if ($potentialPayoutLamports >= $treasuryBalanceLamports) jsonResponse(['ok' => false, 'error' => 'Der mögliche Gewinn ist höher oder gleich dem aktuell verfügbaren Treasury-Guthaben. Bitte einen kleineren Einsatz wählen.'], 422);
    $id = 'game_' . bin2hex(random_bytes(10));
    $game = ['id' => $id, 'wallet' => $wallet, 'guess' => $guess, 'amount_sol' => $lamports / 1000000000, 'lamports' => $lamports, 'fee_percent' => DEPOSIT_FEE_PERCENT, 'fee_lamports' => $feeLamports, 'play_lamports' => $playLamports, 'potential_payout_lamports' => $potentialPayoutLamports, 'treasury_balance_at_create_lamports' => $treasuryBalanceLamports, 'treasury' => $treasury, 'status' => 'awaiting_payment', 'created_at' => gmdate('c')];
    saveGame($game);
    jsonResponse(['ok' => true, 'game' => $game]);
}

if ($path === '/api/game/submit' && $method === 'POST') {
    $data = requestJson(); $game = gameOrFail((string)($data['game_id'] ?? '')); $raw = (string)($data['raw_transaction'] ?? '');
    if ($game['status'] !== 'awaiting_payment' || $raw === '' || base64_decode($raw, true) === false) jsonResponse(['ok' => false, 'error' => 'Ungültiger Spiel- oder Transaktionsstatus.'], 422);
    $signature = sendRawTransaction($raw); $game['deposit_signature'] = $signature; $game['status'] = 'payment_pending'; saveGame($game);
    jsonResponse(['ok' => true, 'signature' => $signature]);
}

if ($path === '/api/game/recover' && $method === 'POST') {
    $game = gameOrFail((string)(requestJson()['game_id'] ?? ''));
    if (!in_array($game['status'], ['awaiting_payment', 'payment_pending'], true)) jsonResponse(['ok' => false, 'error' => 'Dieses Spiel ist nicht wiederherstellbar.'], 422);
    if ($game['treasury'] !== treasuryWallet()) jsonResponse(['ok' => false, 'error' => 'Dieses Spiel gehört zu einer alten Treasury und kann nicht wiederhergestellt werden.'], 409);
    $signature = findDepositSignature($game);
    if ($signature === '') jsonResponse(['ok' => false, 'error' => 'Keine passende bestätigte Einzahlung gefunden.'], 404);
    $game['deposit_signature'] = $signature; $game['status'] = 'payment_pending'; saveGame($game);
    jsonResponse(['ok' => true, 'signature' => $signature, 'recovered' => true]);
}

if ($path === '/api/game/confirm' && $method === 'POST') {
    $data = requestJson(); $game = gameOrFail((string)($data['game_id'] ?? '')); $signature = trim((string)($data['signature'] ?? $game['deposit_signature'] ?? ''));
    if ($game['status'] !== 'payment_pending' || $game['deposit_signature'] === '' || $signature === '' || $signature !== $game['deposit_signature']) jsonResponse(['ok' => false, 'error' => 'Die Einzahlung wurde noch nicht von Solflare signiert und an Solana gesendet.'], 422);
    $tx = rpc('getTransaction', [$signature, ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0]]);
    if ($tx === []) jsonResponse(['ok' => true, 'status' => 'pending']);
    if (!verifyTransfer($tx, $game['wallet'], $game['treasury'], (int)$game['lamports'])) jsonResponse(['ok' => false, 'error' => 'Die Einzahlung passt nicht zu Wallet, Treasury oder Betrag.'], 422);
    $result = random_int(1, 6); $game['result'] = $result; $game['won'] = $result === (int)$game['guess']; $game['payout_lamports'] = $game['won'] ? (int)$game['play_lamports'] * WIN_MULTIPLIER : 0; $game['status'] = 'resolved'; saveGame($game);
    jsonResponse(['ok' => true, 'status' => $game['status'], 'game' => $game]);
}

if ($path === '/api/payout/prepare' && $method === 'POST') {
    $game = gameOrFail((string)(requestJson()['game_id'] ?? ''));
    if ($game['status'] !== 'resolved' || empty($game['won'])) jsonResponse(['ok' => false, 'error' => 'Für dieses Spiel gibt es keine Auszahlung.'], 422);
    jsonResponse(['ok' => true, 'game_id' => $game['id'], 'from' => $game['treasury'], 'to' => $game['wallet'], 'lamports' => $game['payout_lamports']]);
}

if ($path === '/api/payout/submit' && $method === 'POST') {
    $data = requestJson(); $game = gameOrFail((string)($data['game_id'] ?? '')); $raw = (string)($data['raw_transaction'] ?? '');
    if ($game['status'] !== 'resolved' || empty($game['won'])) jsonResponse(['ok' => false, 'error' => 'Ungültige Auszahlung.'], 422);
    if ($raw !== '') {
        if (base64_decode($raw, true) === false) jsonResponse(['ok' => false, 'error' => 'Ungültige Auszahlung.'], 422);
        $game['payout_signature'] = sendRawTransaction($raw);
    } else {
        $game['payout_signature'] = sendTreasuryPayout($game);
    }
    $game['status'] = 'payout_pending'; saveGame($game); jsonResponse(['ok' => true, 'signature' => $game['payout_signature'], 'server_signed' => $raw === '']);
}

if ($path === '/api/payout/confirm' && $method === 'POST') {
    $game = gameOrFail((string)(requestJson()['game_id'] ?? '')); $signature = $game['payout_signature'] ?? '';
    if ($signature === '') jsonResponse(['ok' => false, 'error' => 'Auszahlungssignatur fehlt.'], 422);
    $tx = rpc('getTransaction', [$signature, ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0]]);
    if ($tx === []) jsonResponse(['ok' => true, 'status' => 'pending']);
    if (!verifyTransfer($tx, $game['treasury'], $game['wallet'], (int)$game['payout_lamports'])) jsonResponse(['ok' => false, 'error' => 'Auszahlung konnte nicht verifiziert werden.'], 422);
    $game['status'] = 'paid'; saveGame($game); jsonResponse(['ok' => true, 'status' => 'paid', 'game' => $game]);
}

if ($path === '/api/games' && $method === 'GET') jsonResponse(['ok' => true, 'games' => array_values($_SESSION['games'] ?? [])]);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Simple Solana Dice</title>
<style>:root{color-scheme:dark;--bg:#0b1020;--panel:#141b31;--line:#2c385d;--text:#eef2ff;--muted:#aab5d6;--accent:#8b7cff;--good:#54d38a;--bad:#ff7185}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#1b2450,#0b1020 55%);font:16px system-ui,sans-serif;color:var(--text);min-height:100vh}.wrap{max-width:960px;margin:auto;padding:28px 18px 56px}header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}h1{margin:0 0 6px;font-size:clamp(28px,5vw,46px)}p{color:var(--muted);line-height:1.5}.badge{border:1px solid #5dd6ff;color:#8be2ff;padding:8px 12px;border-radius:999px;font-size:13px}.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;margin-top:20px}.card{background:#141b31ee;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 16px 40px #05081780}label{display:block;color:var(--muted);font-size:14px;margin:16px 0 7px}input,select,button{width:100%;border-radius:10px;border:1px solid var(--line);background:#0d1429;color:var(--text);padding:12px;font:inherit}button{cursor:pointer;background:var(--accent);border:0;font-weight:700;margin-top:16px}button.secondary{background:#28365f}button:disabled{opacity:.5;cursor:not-allowed}.wallet{display:flex;gap:10px;align-items:center}.wallet button{width:auto;margin:0;white-space:nowrap}.status{padding:10px 12px;border-radius:10px;background:#0d1429;color:var(--muted);margin-top:14px;font-size:14px;line-height:1.4}.status.success{background:#103d2b;border:1px solid #32c47b;color:#a5f2c6;font-weight:700}.status.pending{background:#2f2a10;border:1px solid #c2a43d;color:#ffe9a1}.status.error{background:#421b27;border:1px solid #e05c76;color:#ffb3c1}.balance-panel{margin-top:14px;padding:16px 18px;border:1px solid #425487;border-radius:14px;background:linear-gradient(135deg,#18254b,#10182f)}.balance-label{display:block;color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:.08em}.balance-value{display:block;margin-top:4px;font-size:clamp(26px,5vw,38px);font-weight:800;color:#fff}.bet-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.metric{padding:14px;border:1px solid var(--line);border-radius:14px;background:#0d1429}.metric-label{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.05em}.metric-value{display:block;margin-top:5px;font-size:clamp(20px,4vw,28px);font-weight:800;color:#fff}.metric-value.highlight{color:#b9adff}.metric-note{display:block;margin-top:4px;color:var(--muted);font-size:12px}.system-panel{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.system-metric{padding:13px 14px;border:1px solid var(--line);border-radius:14px;background:#0d1429}.system-metric span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.05em}.system-metric strong{display:block;margin-top:5px;font-size:18px;color:#fff}.rpc-ok{color:var(--good)!important}.rpc-error{color:var(--bad)!important}.header-system{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.header-system .system-metric{min-width:150px}.notice{border-left:3px solid #5dd6ff;padding:11px 13px;background:#102c3a;color:#bcecff;line-height:1.45;margin-top:20px}.dice{font-size:100px;text-align:center;padding:18px 0;min-height:155px;display:grid;place-items:center;transform-style:preserve-3d}.dice.rolling{animation:dice-bounce .22s infinite alternate,dice-spin 1.1s infinite linear;text-shadow:0 0 18px #8b7cffaa}.dice.final{animation:dice-pop .55s ease-out}.face-options{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-top:8px}.face-option{position:relative;cursor:pointer}.face-option input{position:absolute;opacity:0;pointer-events:none}.face-option span{display:grid;place-items:center;min-height:64px;border:1px solid var(--line);border-radius:12px;background:#0d1429;font-size:34px;transition:.18s}.face-option input:checked+span{border-color:#9d91ff;background:#29235b;box-shadow:0 0 0 2px #8b7cff55,0 0 20px #8b7cff44;transform:translateY(-2px)}.face-option span:hover{border-color:#8b7cff}.die-choice svg{width:42px;height:42px;display:block}.dice svg{width:min(150px,40vw);height:auto;display:block;filter:drop-shadow(0 10px 12px #05081788)}@keyframes dice-spin{to{transform:rotate(360deg) scale(1.08)}}@keyframes dice-bounce{from{translate:0 -3px}to{translate:0 5px}}@keyframes dice-pop{0%{transform:scale(.65) rotate(-18deg);filter:brightness(1.8)}70%{transform:scale(1.15) rotate(8deg)}100%{transform:scale(1) rotate(0)}}.result{text-align:center;font-size:20px;font-weight:700;min-height:32px}.good{color:var(--good)}.bad{color:var(--bad)}.hidden{display:none}.small{font-size:13px;color:var(--muted)}.tx{word-break:break-all}@media(max-width:700px){header,.grid{display:block}.badge{display:inline-block;margin-top:12px}.card{margin-bottom:18px}.wallet{display:block}.wallet button{width:100%;margin-top:10px}}</style></head>
<body><main class="wrap"><header><div><h1>Simple Solana Dice</h1></div><div><div class="header-system"><div class="system-metric"><span>Verfügbare Treasury</span><strong id="treasuryBalance">— SOL</strong></div><div class="system-metric"><span>RPC Status</span><strong id="rpcStatus">Prüfe…</strong></div></div></div></header>
<section class="grid"><div class="card"><h2>Spieler-Wallet</h2><div class="wallet"><input id="wallet" readonly value="Nicht verbunden"><button id="connect">Solflare verbinden</button></div><div id="walletStatus" class="status">Nur der Public Key wird gelesen.</div><div class="balance-panel"><span class="balance-label">Kontostand</span><strong id="balance" class="balance-value">— SOL</strong></div><h2 style="margin-top:28px">Dein Tipp</h2><div class="small">Wähle eine Würfelfläche:</div><div class="face-options" role="radiogroup" aria-label="Würfel-Tipp"><label class="face-option"><input type="radio" name="guess" value="1" checked><span class="die-choice" data-face="1"></span></label><label class="face-option"><input type="radio" name="guess" value="2"><span class="die-choice" data-face="2"></span></label><label class="face-option"><input type="radio" name="guess" value="3"><span class="die-choice" data-face="3"></span></label><label class="face-option"><input type="radio" name="guess" value="4"><span class="die-choice" data-face="4"></span></label><label class="face-option"><input type="radio" name="guess" value="5"><span class="die-choice" data-face="5"></span></label><label class="face-option"><input type="radio" name="guess" value="6"><span class="die-choice" data-face="6"></span></label></div><div class="small" style="margin-top:10px">Gebühr pro Einzahlung: <?= number_format(DEPOSIT_FEE_PERCENT, 2, ',', '.') ?> % · wird unabhängig vom Würfelergebnis einbehalten.</div><label for="amount">SOL-Einsatz: <output id="amountValue">0.10 SOL</output></label><input id="amount" type="range" min="0.01" max="1" step="0.01" value="0.10"><div class="bet-summary"><div class="metric"><span class="metric-label">Einsatz</span><strong id="stakeDisplay" class="metric-value">0,100 SOL</strong><span class="metric-note">inkl. 1 % Gebühr</span></div><div class="metric"><span class="metric-label">Möglicher Gewinn</span><strong id="winDisplay" class="metric-value highlight">0,594 SOL</strong><span class="metric-note">Trefferchance: 16,67 %</span></div></div><button id="play" disabled>Einsatz signieren und würfeln</button><div id="playStatus" class="status">Verbinde zuerst Solflare.</div></div>
<div class="card"><h2>Ergebnis</h2><div id="dice" class="dice"><svg viewBox="0 0 100 100" role="img" aria-label="Würfel"><rect x="7" y="7" width="86" height="86" rx="18" fill="#8b7cff" stroke="#c5beff" stroke-width="3"/><circle cx="50" cy="50" r="8" fill="#fff"/></svg></div><div id="result" class="result">Noch kein Spiel</div><div id="payoutBox" class="hidden"><div class="status">Auszahlungen werden sicher serverseitig mit dem Treasury-Key signiert.</div><div id="payoutStatus" class="status"></div></div><h3 style="margin-top:30px">Transaktionen</h3><div id="tx" class="small tx"></div></div></section></main>
<script src="/vendor/solana-web3.min.js"></script><script>const $=id=>document.getElementById(id),W=window.solanaWeb3;let provider=null,player='',treasury='',current=null,treasuryBalanceLamports=0;const faces=[1,2,3,4,5,6],feePercent=<?= json_encode(DEPOSIT_FEE_PERCENT) ?>,winMultiplier=<?= json_encode(WIN_MULTIPLIER) ?>;const short=v=>v.length>18?v.slice(0,8)+'…'+v.slice(-6):v;const wait=ms=>new Promise(r=>setTimeout(r,ms));const formatSol=v=>v.toFixed(3).replace('.',',')+' SOL';const pipPositions={1:[[50,50]],2:[[30,30],[70,70]],3:[[30,30],[50,50],[70,70]],4:[[30,30],[70,30],[30,70],[70,70]],5:[[30,30],[70,30],[50,50],[30,70],[70,70]],6:[[30,25],[30,50],[30,75],[70,25],[70,50],[70,75]]};function dieSvg(face){const p=pipPositions[face]||pipPositions[1];return '<svg viewBox="0 0 100 100" role="img" aria-label="Würfelfläche '+face+'"><rect x="7" y="7" width="86" height="86" rx="18" fill="#8b7cff" stroke="#c5beff" stroke-width="3"/>'+p.map(([x,y])=>'<circle cx="'+x+'" cy="'+y+'" r="8" fill="#fff"/>').join('')+'</svg>'}document.querySelectorAll('.die-choice').forEach(el=>{el.innerHTML=dieSvg(Number(el.dataset.face))});function updateBetSummary(){const gross=Number($('amount').value),net=gross*(1-feePercent/100);$('amountValue').textContent=formatSol(gross);$('stakeDisplay').textContent=formatSol(gross);$('winDisplay').textContent=formatSol(net*winMultiplier);$('play').disabled=!player||treasuryBalanceLamports<=Math.round(net*winMultiplier*1000000000)}$('amount').oninput=updateBetSummary;updateBetSummary();const selectedGuess=()=>Number(document.querySelector('input[name="guess"]:checked').value);async function animateRoll(finalFace){const el=$('dice');el.classList.remove('final');el.classList.add('rolling');for(let i=0;i<18;i++){el.innerHTML=dieSvg(faces[i%faces.length]);await wait(70+i*8)}el.innerHTML=dieSvg(finalFace);el.classList.remove('rolling');void el.offsetWidth;el.classList.add('final');await wait(550)}async function api(path,body){const r=await fetch(path,{method:body?'POST':'GET',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined});const text=await r.text();let j;try{j=JSON.parse(text)}catch{throw Error('Backend antwortet nicht mit JSON (HTTP '+r.status+').')}if(!r.ok||j.ok===false)throw Error(j.error||'Serverfehler');return j}async function rpc(method,params=[]){return(await api('/api/rpc',{method,params})).result}async function blockhash(){return(await rpc('getLatestBlockhash',[{commitment:'confirmed'}])).value.blockhash}function b64(bytes){let s='';for(const b of bytes)s+=String.fromCharCode(b);return btoa(s)}function transfer(from,to,lamports,hash){const tx=new W.Transaction();tx.feePayer=new W.PublicKey(from);tx.recentBlockhash=hash;tx.add(W.SystemProgram.transfer({fromPubkey:new W.PublicKey(from),toPubkey:new W.PublicKey(to),lamports}));return tx}async function refreshBalance(){if(!player)return;const r=await rpc('getBalance',[player,{commitment:'confirmed'}]);$('balance').textContent=(r.value/1e9).toFixed(6)+' SOL'}async function refreshSystemStatus(){try{const s=await api('/api/status');treasuryBalanceLamports=Number(s.treasury_balance_lamports||0);$('treasuryBalance').textContent=Number(s.treasury_balance_sol||0).toFixed(6)+' SOL';$('rpcStatus').textContent=s.rpc_status==='ok'?'Online':'Fehler';$('rpcStatus').className=s.rpc_status==='ok'?'rpc-ok':'rpc-error';updateBetSummary()}catch(e){treasuryBalanceLamports=0;$('treasuryBalance').textContent='Nicht verfügbar';$('rpcStatus').textContent='Fehler';$('rpcStatus').className='rpc-error';updateBetSummary()}}async function signSend(tx,submitPath,gameId){const signed=await provider.signTransaction(tx);const raw=b64(signed.serialize());const out=await api(submitPath,{game_id:gameId,raw_transaction:raw});return out.signature}async function waitConfirm(path,gameId){for(let i=0;i<15;i++){const r=await api(path,{game_id:gameId});if(r.status!=='pending')return r;await wait(1000)}throw Error('Bestätigung dauert länger als erwartet. Transaktion später erneut prüfen.')}$('connect').onclick=async()=>{try{provider=window.solflare;if(!provider)throw Error('Solflare ist in diesem Browser nicht verfügbar.');const r=await provider.connect();player=(provider.publicKey||r?.publicKey).toString();const c=await api('/api/config');treasury=c.treasury;$('wallet').value=short(player);$('walletStatus').textContent='Solflare verbunden: '+short(player);await Promise.all([refreshBalance(),refreshSystemStatus()]);updateBetSummary();current=null;}catch(e){$('walletStatus').textContent=e.message}};$('play').onclick=async()=>{try{$('play').disabled=true;$('playStatus').textContent='Spiel wird vorbereitet…';const amount=Number($('amount').value),guess=selectedGuess();let sig='';const created=await api('/api/game/create',{wallet:player,guess,amount_sol:amount});current=created.game;const hash=await blockhash();const tx=transfer(player,treasury,current.lamports,hash);$('playStatus').textContent='Bitte Einzahlung in Solflare signieren…';sig=await signSend(tx,'/api/game/submit',current.id);current.deposit_signature=sig;$('tx').textContent='Einzahlung: '+sig;$('playStatus').textContent='Warte auf Devnet-Bestätigung…';const result=await waitConfirm('/api/game/confirm',current.id);current=result.game;await animateRoll(current.result);$('result').className='result '+(current.won?'good':'bad');$('result').textContent=current.won?'Gewonnen · Auszahlung: '+(current.payout_lamports/1e9).toFixed(9)+' SOL':'Verloren · Ergebnis war '+current.result;if(current.won){$('payoutBox').classList.remove('hidden');$('payoutStatus').className='status pending';$('payoutStatus').textContent='Gewinn erkannt. Auszahlung wird sofort serverseitig signiert…';const submitted=await api('/api/payout/submit',{game_id:current.id});$('tx').textContent+='\nAuszahlung: '+submitted.signature;$('payoutStatus').textContent='Auszahlung gesendet. Warte auf Bestätigung…';const done=await waitConfirm('/api/payout/confirm',current.id);current=done.game;$('payoutStatus').className='status success';$('payoutStatus').textContent='✓ Auszahlung erfolgreich bestätigt.';$('result').textContent='Gewonnen · Auszahlung erfolgreich bestätigt: '+(current.payout_lamports/1e9).toFixed(9)+' SOL';await Promise.all([refreshBalance(),refreshSystemStatus()])}else{$('payoutBox').classList.add('hidden')}await Promise.all([refreshBalance(),refreshSystemStatus()])}catch(e){$('playStatus').textContent=e.message;if($('payoutBox').classList.contains('hidden')===false){$('payoutStatus').className='status error';$('payoutStatus').textContent='Auszahlung nicht bestätigt: '+e.message}}finally{updateBetSummary()}};refreshSystemStatus();</script></body></html>
