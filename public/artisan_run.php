<?php
// Deploy runner — protected by token.
// Reads DEPLOY_TOKEN DIRECTLY from the .env file on disk, so it works even
// when config is cached (config:cache makes env() return null outside config
// files) and regardless of whether the host exposes getenv().

$laravelRoot = __DIR__ . '/../laravel';

// --- Read DEPLOY_TOKEN straight from .env ---
$validToken = '';
$envPath = $laravelRoot . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*DEPLOY_TOKEN\s*=\s*(.*)$/', $line, $m)) {
            $validToken = trim($m[1]);
            // strip surrounding quotes if present
            if (strlen($validToken) >= 2
                && (($validToken[0] === '"' && substr($validToken, -1) === '"')
                 || ($validToken[0] === "'" && substr($validToken, -1) === "'"))) {
                $validToken = substr($validToken, 1, -1);
            }
            break;
        }
    }
}

$token = $_GET['token'] ?? '';
if (! $validToken || ! hash_equals($validToken, (string) $token)) {
    http_response_code(403);
    exit('Forbidden');
}

// --- Bootstrap Laravel & run deploy tasks ---
define('LARAVEL_START', microtime(true));
require $laravelRoot . '/vendor/autoload.php';
$app    = require_once $laravelRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

echo '<pre>';
echo "=== CK-MEMS Deploy Runner (v2) ===\n\n";

// Clear caches first (so migrate/route use fresh .env & files)
foreach (['config:clear', 'cache:clear', 'route:clear', 'view:clear'] as $cmd) {
    echo "▶ php artisan $cmd\n";
    try { $kernel->call($cmd); echo $kernel->output(); } catch (\Throwable $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }
    echo "\n";
}

// Migrate
echo "▶ php artisan migrate --force\n";
try {
    $status = $kernel->call('migrate', ['--force' => true]);
    echo $kernel->output();
    echo "Exit code: $status\n\n";
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n\n";
}

// Re-cache for performance.
// NOTE: route:cache is intentionally skipped — web.php uses closure routes
// (/qr/{idCode} + SPA catch-all) which cannot be serialized, so routes stay
// dynamic (fine for this app, and the /qr/ fix takes effect immediately).
foreach (['config:cache', 'view:cache'] as $cmd) {
    echo "▶ php artisan $cmd\n";
    try { $kernel->call($cmd); echo $kernel->output(); } catch (\Throwable $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }
    echo "\n";
}

echo '=== DONE ' . date('Y-m-d H:i:s') . " ===\n";
echo '</pre>';
