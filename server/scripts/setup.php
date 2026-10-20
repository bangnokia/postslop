<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\PrivateFiles;
use Crab\Contracts\Database\Migrator;

$storage = dirname(__DIR__) . '/storage';
PrivateFiles::directory($storage);
PrivateFiles::directory($storage . '/posts');
PrivateFiles::directory($storage . '/media');
PrivateFiles::directory($storage . '/browser');
PrivateFiles::directory($storage . '/browser/x');
$path = $storage . '/client-token';
if (!is_file($path)) {
    $previous = umask(0o077);
    try {
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Could not create the access token.');
        }
        fwrite($file, bin2hex(random_bytes(32)) . "\n");
        fclose($file);
    } finally {
        umask($previous);
    }
}
chmod($path, 0o600);
$app = require dirname(__DIR__) . '/app.php';
$app->container->get(Migrator::class)->run();
foreach (glob($storage . '/database.sqlite*') ?: [] as $database) {
    chmod($database, 0o600);
}
echo "Postslop local storage is ready.\n";
