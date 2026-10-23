<?php

declare(strict_types=1);

/**
 * Autoloader for projects that do not use Composer.
 * With Composer, the PSR-4 autoloading from composer.json is used instead.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'DBHandler\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/src/aliases.php';
