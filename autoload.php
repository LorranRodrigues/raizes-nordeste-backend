<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 mínimo (sem Composer), para manter o projeto rodando
 * apenas com PHP puro do XAMPP. Mapeia o prefixo "App\" para o diretório src/.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
