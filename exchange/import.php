<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

if (!defined('WC1C_PRODUCT_DESCRIPTION_TO_CONTENT')) {
    define('WC1C_PRODUCT_DESCRIPTION_TO_CONTENT', false);
}
if (!defined('WC1C_PREVENT_CLEAN')) {
    define('WC1C_PREVENT_CLEAN', false);
}
if (!defined('WC1C_UPDATE_POST_NAME')) {
    define('WC1C_UPDATE_POST_NAME', false);
}
if (!defined('WC1C_MATCH_BY_SKU')) {
    define('WC1C_MATCH_BY_SKU', false);
}
if (!defined('WC1C_MATCH_CATEGORIES_BY_TITLE')) {
    define('WC1C_MATCH_CATEGORIES_BY_TITLE', false);
}
if (!defined('WC1C_MATCH_PROPERTIES_BY_TITLE')) {
    define('WC1C_MATCH_PROPERTIES_BY_TITLE', false);
}
if (!defined('WC1C_MATCH_PROPERTY_OPTIONS_BY_TITLE')) {
    define('WC1C_MATCH_PROPERTY_OPTIONS_BY_TITLE', false);
}
if (!defined('WC1C_USE_GUID_AS_PROPERTY_OPTION_SLUG')) {
    define('WC1C_USE_GUID_AS_PROPERTY_OPTION_SLUG', true);
}

use Woo1cSync\Exchange\ExchangeState;
use Woo1cSync\Exchange\ExchangeSupport;
use Woo1cSync\Exchange\Handlers\ImportHandler;

$wc1c_import_state = new ExchangeState();
$wc1c_import_state->isMoysklad = !empty($GLOBALS['wc1c_is_moysklad']);

/** @var ImportHandler $wc1c_import_handler */
$wc1c_import_handler = new ImportHandler($wc1c_import_state);
ExchangeSupport::setImportHandler($wc1c_import_handler);
$wc1c_import_handler->registerHooks();

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 * @param array<string, string> $attrs
 */
function wc1c_import_start_element_handler($is_full, $names, $depth, $name, $attrs): void
{
    global $wc1c_import_handler;

    $wc1c_import_handler->startElementHandler($is_full, $names, $depth, $name, $attrs);
}

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 */
function wc1c_import_character_data_handler($is_full, $names, $depth, $name, $data): void
{
    global $wc1c_import_handler;

    $wc1c_import_handler->characterDataHandler($is_full, $names, $depth, $name, $data);
}

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 */
function wc1c_import_end_element_handler($is_full, $names, $depth, $name): void
{
    global $wc1c_import_handler;

    $wc1c_import_handler->endElementHandler($is_full, $names, $depth, $name);
}

/**
 * @param mixed $is_full
 * @param array<string, mixed> $product
 *
 * @return int|false|null
 */
function wc1c_replace_product($is_full, $guid, $product)
{
    global $wc1c_import_handler;

    return $wc1c_import_handler->replaceProduct($is_full, $guid, $product);
}
