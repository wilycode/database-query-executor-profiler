<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$candidates = [
    $packageRoot . '/vendor/autoload.php',
    dirname($packageRoot, 2) . '/autoload.php',
];

$loaded = false;
foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    throw new RuntimeException('Composer autoload file was not found.');
}

$composer = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$prefixes = $composer['autoload-dev']['psr-4'] ?? [];

spl_autoload_register(static function (string $class) use ($packageRoot, $prefixes): void {
    foreach ($prefixes as $prefix => $directories) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        foreach ((array) $directories as $directory) {
            $relative = substr($class, strlen($prefix));
            $file = $packageRoot . '/' . rtrim($directory, '/') . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
});
