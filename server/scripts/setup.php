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
// .env may hold the access token, so only you may read it.
$env = dirname(__DIR__) . '/.env';
if (is_file($env)) {
    chmod($env, 0o600);
}
$app = require dirname(__DIR__) . '/app.php';
$app->container->get(Migrator::class)->run();
foreach (glob($storage . '/database.sqlite*') ?: [] as $database) {
    chmod($database, 0o600);
}
echo "Postslop local storage is ready.\n";
