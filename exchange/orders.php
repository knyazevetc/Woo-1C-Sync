<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Woo1cSync\Exchange\ExchangeState;
use Woo1cSync\Exchange\ExchangeSupport;
use Woo1cSync\Exchange\Handlers\OrdersHandler;

$wc1c_orders_state = new ExchangeState();
/** @var OrdersHandler $wc1c_orders_handler */
$wc1c_orders_handler = new OrdersHandler($wc1c_orders_state);
ExchangeSupport::setOrdersHandler($wc1c_orders_handler);

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 * @param array<string, string> $attrs
 */
function wc1c_orders_start_element_handler($is_full, $names, $depth, $name, $attrs): void
{
    global $wc1c_orders_handler;

    $wc1c_orders_handler->startElementHandler($is_full, $names, (int) $depth, $name, $attrs);
}

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 */
function wc1c_orders_character_data_handler($is_full, $names, $depth, $name, $data): void
{
    global $wc1c_orders_handler;

    $wc1c_orders_handler->characterDataHandler($is_full, $names, (int) $depth, $name, $data);
}

/**
 * @param mixed $is_full
 * @param array<int, string> $names
 */
function wc1c_orders_end_element_handler($is_full, $names, $depth, $name): void
{
    global $wc1c_orders_handler;

    $wc1c_orders_handler->endElementHandler($is_full, $names, (int) $depth, $name);
}
