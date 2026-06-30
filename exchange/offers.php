<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WC1C_PRICE_TYPE')) {
    define('WC1C_PRICE_TYPE', null);
}
if (!defined('WC1C_PRESERVE_PRODUCT_VARIATIONS')) {
    define('WC1C_PRESERVE_PRODUCT_VARIATIONS', false);
}

use Woo1cSync\Exchange\ExchangeState;
use Woo1cSync\Exchange\ExchangeSupport;
use Woo1cSync\Exchange\Handlers\OffersHandler;

$wc1c_offers_state = new ExchangeState();
/** @var OffersHandler $wc1c_offers_handler */
$wc1c_offers_handler = new OffersHandler($wc1c_offers_state);
ExchangeSupport::setOffersHandler($wc1c_offers_handler);

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 * @param array<string, string> $attrs
 */
function wc1c_offers_start_element_handler($is_full, $names, $depth, $name, $attrs): void
{
    global $wc1c_offers_handler;

    $wc1c_offers_handler->startElementHandler($is_full, $names, (int) $depth, $name, $attrs);
}

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 */
function wc1c_offers_character_data_handler($is_full, $names, $depth, $name, $data): void
{
    global $wc1c_offers_handler;

    $wc1c_offers_handler->characterDataHandler($is_full, $names, (int) $depth, $name, $data);
}

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 */
function wc1c_offers_end_element_handler($is_full, $names, $depth, $name): void
{
    global $wc1c_offers_handler;

    $wc1c_offers_handler->endElementHandler($is_full, $names, (int) $depth, $name);
}

/**
 * @param mixed $is_full
 * @param array<int, array<string, mixed>> $suboffers
 */
function wc1c_replace_suboffers($is_full, array $suboffers, bool $are_products = false): void
{
    global $wc1c_offers_handler;

    $wc1c_offers_handler->replaceSuboffers($is_full, $suboffers, $are_products);
}
