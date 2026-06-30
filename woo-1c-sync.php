<?php
/*
Plugin Name: Синхронизация WooCommerce и 1С
Version: 0.9.20
Description: Синхронизирует WooCommerce с 1С:Предприятие 8 (Управление торговлей) — каталог, цены, остатки и заказы по протоколу CommerceML.
Author: knyazevetc
Author URI:
Plugin URI: https://github.com/knyazevetc/Woo-1C-Sync https://github.com/knyazevetc/Woo-1C-Sync
Text Domain: woo-1c-sync
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Plugin.php';

Woo1cSync\Plugin::boot(__FILE__);
