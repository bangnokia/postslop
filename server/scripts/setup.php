<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\PrivateFiles;
use Crab\Contracts\Database\Migrator;

$storage = dirname(__DIR__) . '/storage';
PrivateFiles::directory($storage);
PrivateFiles::directory($storage . '/media');
PrivateFiles::directory($storage . '/browser');
PrivateFiles::directory($storage . '/browser/x');
// The access token lives in .env, so only you may read that file.
$env = dirname(__DIR__) . '/.env';
$settings = is_file($env) ? (string) file_get_contents($env) : '';
if (preg_match('/^POSTSLOP_TOKEN=\S/m', $settings) !== 1) {
    $token = 'POSTSLOP_TOKEN=' . bin2hex(random_bytes(32));
    $settings = preg_match('/^POSTSLOP_TOKEN=.*$/m', $settings) === 1
        ? (string) preg_replace('/^POSTSLOP_TOKEN=.*$/m', $token, $settings)
        : rtrim($settings) . ($settings === '' ? '' : "\n") . $token . "\n";
    file_put_contents($env, $settings);
}
chmod($env, 0o600);
$app = require dirname(__DIR__) . '/app.php';
$app->container->get(Migrator::class)->run();
foreach (glob($storage . '/database.sqlite*') ?: [] as $database) {
    chmod($database, 0o600);
}
echo "Postslop local storage is ready.\n";
