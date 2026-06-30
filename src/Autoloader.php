<?php

declare(strict_types=1);

namespace Woo1cSync;

/**
 * PSR-4 autoloader for plugin classes under src/.
 */
final class Autoloader
{
    /**
     * Register the Woo1cSync namespace autoloader.
     */
    public static function register(string $pluginDir): void
    {
        $srcDir = rtrim($pluginDir, '/') . '/src/';

        spl_autoload_register(static function (string $class) use ($srcDir): void {
            $prefix = 'Woo1cSync\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = $srcDir . str_replace('\\', '/', $relative) . '.php';
            if (is_readable($path)) {
                require_once $path;
            }
        });
    }
}
