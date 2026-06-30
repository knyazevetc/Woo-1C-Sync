<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange;

use Woo1cSync\Exchange\Handlers\ImportHandler;
use Woo1cSync\Exchange\Handlers\OffersHandler;
use Woo1cSync\Exchange\Handlers\OrdersHandler;

/**
 * Bridge between handler classes and legacy exchange helpers registered on ExchangeService.
 */
final class ExchangeSupport
{
    private static ?ImportHandler $importHandler = null;

    private static ?OffersHandler $offersHandler = null;

    private static ?OrdersHandler $ordersHandler = null;

    public static function setImportHandler(ImportHandler $handler): void
    {
        self::$importHandler = $handler;
    }

    public static function getImportHandler(): ?ImportHandler
    {
        return self::$importHandler;
    }

    public static function setOffersHandler(OffersHandler $handler): void
    {
        self::$offersHandler = $handler;
    }

    public static function getOffersHandler(): ?OffersHandler
    {
        return self::$offersHandler;
    }

    public static function setOrdersHandler(OrdersHandler $handler): void
    {
        self::$ordersHandler = $handler;
    }

    public static function getOrdersHandler(): ?OrdersHandler
    {
        return self::$ordersHandler;
    }

    /**
     * @param mixed $noExit
     */
    public static function error(string $message, string $type = 'Error', $noExit = false): void
    {
        wc1c_error($message, $type, $noExit);
    }

    /**
     * @param array<int, string> $names
     */
    public static function xmlParentName(array $names, int $depth, int $ancestor = 1): ?string
    {
        return wc1c_xml_parent_name($names, $depth, $ancestor);
    }

    /**
     * @param array<string|int, mixed> $array
     * @param string|int $key
     */
    public static function xmlAppend(array &$array, $key, string $data): void
    {
        wc1c_xml_append($array, $key, $data);
    }

    /**
     * @param array<int, array<string, mixed>> $array
     */
    public static function xmlAppendNested(array &$array, int $index, string $key, string $data): void
    {
        wc1c_xml_append_nested($array, $index, $key, $data);
    }

    public static function checkWpdbError(): void
    {
        wc1c_check_wpdb_error();
    }

    /**
     * @param mixed $wpError
     */
    public static function checkWpError($wpError): void
    {
        wc1c_check_wp_error($wpError);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    public static function postIdByMeta(string $key, $value)
    {
        return wc1c_post_id_by_meta($key, $value);
    }

    public static function parseDecimal(string $number): float
    {
        return wc1c_parse_decimal($number);
    }

    /**
     * @param mixed $isFull
     * @param array<int, array<string, mixed>> $suboffers
     */
    public static function replaceSuboffers($isFull, array $suboffers, bool $areProducts = false): void
    {
        if (self::$offersHandler !== null) {
            self::$offersHandler->replaceSuboffers((bool) $isFull, $suboffers, $areProducts);

            return;
        }

        if (function_exists('wc1c_replace_suboffers')) {
            wc1c_replace_suboffers($isFull, $suboffers, $areProducts);
        }
    }

    /**
     * @param mixed $isFull
     * @param array<string, mixed> $product
     *
     * @return int|false|null
     */
    public static function replaceProduct($isFull, string $guid, array $product)
    {
        if (self::$importHandler !== null) {
            return self::$importHandler->replaceProduct($isFull, $guid, $product);
        }

        if (function_exists('wc1c_replace_product')) {
            return wc1c_replace_product($isFull, $guid, $product);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|false|null
     */
    public static function woocommerceAttributeById(int $attributeId)
    {
        return wc1c_woocommerce_attribute_by_id($attributeId);
    }

    public static function deleteWoocommerceAttribute(int $attributeId): void
    {
        wc1c_delete_woocommerce_attribute($attributeId);
    }

    public static function cleanupTransients(): void
    {
        wc1c_cleanup_transients();
    }
}
