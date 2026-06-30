<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN') && !defined('WP_CLI')) {
    exit;
}

$pluginDir = __DIR__ . '/';

require_once $pluginDir . 'src/Autoloader.php';
Woo1cSync\Autoloader::register($pluginDir);

if (!defined('WC1C_PLUGIN_DIR')) {
    define('WC1C_PLUGIN_DIR', $pluginDir);
}
if (!defined('WC1C_DATA_DIR')) {
    $uploadDir = wp_upload_dir();
    define('WC1C_DATA_DIR', "{$uploadDir['basedir']}/woo-1c-sync/");
}

use Woo1cSync\Exchange\ExchangeService;
use Woo1cSync\Services\AttributeService;

ExchangeService::instance()->disableTimeLimit();

if (is_dir(WC1C_DATA_DIR)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(WC1C_DATA_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $path => $item) {
        $item->isDir() ? rmdir($path) : unlink($path);
    }
    rmdir(WC1C_DATA_DIR);
}

(new AttributeService())->dropMetaIndexes();
