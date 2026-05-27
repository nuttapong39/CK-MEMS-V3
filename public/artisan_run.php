<?php
// Deploy runner — protected by token, safe to keep on server
$token = $_GET['token'] ?? '';
$validToken = getenv('DEPLOY_TOKEN') ?: '';

if (!$validToken || $token !== $validToken) {
    http_response_code(403);
    exit('Forbidden');
}

define('LARAVEL_START', microtime(true));
$laravelRoot = __DIR__ . '/../laravel';
require $laravelRoot . '/vendor/autoload.php';

$app    = require_once $laravelRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

echo '<pre>';
echo "=== CK-MEMS Deploy Runner ===\n\n";

// Migrate
echo "▶ php artisan migrate --force\n";
try {
    $status = $kernel->call('migrate', ['--force' => true]);
    echo $kernel->output();
    echo "Exit code: $status\n\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

// Clear & re-cache
foreach (['config:clear', 'config:cache', 'route:cache', 'view:cache', 'optimize'] as $cmd) {
    echo "▶ php artisan $cmd\n";
    try {
        $status = $kernel->call($cmd);
        echo $kernel->output();
        echo "Exit code: $status\n\n";
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n\n";
    }
}

echo "=== DONE " . date('Y-m-d H:i:s') . " ===\n";
echo '</pre>';
