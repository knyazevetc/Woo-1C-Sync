<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange\Handlers;

use WC_Order;
use WC_Shipping_Rate;
use Woo1cSync\Exchange\ExchangeState;
use Woo1cSync\Exchange\ExchangeSupport;

/**
 * Handles CommerceML orders XML parsing and synchronizes WooCommerce orders from 1C documents.
 */
final class OrdersHandler
{
    /** @var array<string, mixed>|null */
    private static ?array $shippingMethods = null;

    public function __construct(
        private readonly ExchangeState $state,
    ) {
        ExchangeSupport::setOrdersHandler($this);
        add_filter('woocommerce_new_order_data', [$this, 'woocommerceNewOrderData']);
    }

    /**
     * @param array<int, string> $names
     * @param array<string, string> $attrs
     */
    public function startElementHandler(
        $isFull,
        array $names,
        int $depth,
        string $name,
        array $attrs,
    ): void {
        if (ExchangeSupport::xmlParentName($names, $depth) == 'КоммерческаяИнформация' && $name == 'Документ') {
            $this->state->document = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Документ' && $name == 'Контрагенты') {
            $this->state->document['Контрагенты'] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Контрагенты' && $name == 'Контрагент') {
            $this->state->document['Контрагенты'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Документ' && $name == 'Товары') {
            $this->state->document['Товары'] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товары' && $name == 'Товар') {
            $this->state->document['Товары'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товар' && $name == 'ЗначенияРеквизитов') {
            $i = count($this->state->document['Товары']) - 1;
            $this->state->document['Товары'][$i]['ЗначенияРеквизитов'] = [];
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'Товар'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ЗначенияРеквизитов'
            && $name == 'ЗначениеРеквизита'
        ) {
            $i = count($this->state->document['Товары']) - 1;
            $this->state->document['Товары'][$i]['ЗначенияРеквизитов'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товар' && $name == 'ХарактеристикиТовара') {
            $i = count($this->state->document['Товары']) - 1;
            $this->state->document['Товары'][$i]['ХарактеристикиТовара'] = [];
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'Товар'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ХарактеристикиТовара'
            && $name == 'ХарактеристикаТовара'
        ) {
            $i = count($this->state->document['Товары']) - 1;
            $this->state->document['Товары'][$i]['ХарактеристикиТовара'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Документ' && $name == 'ЗначенияРеквизитов') {
            $this->state->document['ЗначенияРеквизитов'] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ЗначенияРеквизитов' && $name == 'ЗначениеРеквизита') {
            $this->state->document['ЗначенияРеквизитов'][] = [];
        }
    }

    /**
     * @param array<int, string> $names
     */
    public function characterDataHandler(
        $isFull,
        array $names,
        int $depth,
        string $name,
        string $data,
    ): void {
        if (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'КоммерческаяИнформация'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Документ'
            && !in_array($name, ['Контрагенты', 'Товары', 'ЗначенияРеквизитов'], true)
        ) {
            ExchangeSupport::xmlAppend($this->state->document, $name, $data);
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'Контрагенты'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Контрагент'
        ) {
            $i = count($this->state->document['Контрагенты']) - 1;
            ExchangeSupport::xmlAppendNested($this->state->document['Контрагенты'], $i, $name, $data);
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'Товары'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Товар'
            && !in_array($name, ['СтавкиНалогов', 'ЗначенияРеквизитов', 'ХарактеристикиТовара'], true)
        ) {
            $i = count($this->state->document['Товары']) - 1;
            ExchangeSupport::xmlAppendNested($this->state->document['Товары'], $i, $name, $data);
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 3) == 'Товар'
            && ExchangeSupport::xmlParentName($names, $depth, 2) == 'ЗначенияРеквизитов'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ЗначениеРеквизита'
        ) {
            $i = count($this->state->document['Товары']) - 1;
            $j = count($this->state->document['Товары'][$i]['ЗначенияРеквизитов']) - 1;
            ExchangeSupport::xmlAppendNested(
                $this->state->document['Товары'][$i]['ЗначенияРеквизитов'],
                $j,
                $name,
                $data,
            );
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 3) == 'Товар'
            && ExchangeSupport::xmlParentName($names, $depth, 2) == 'ХарактеристикиТовара'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ХарактеристикаТовара'
        ) {
            $i = count($this->state->document['Товары']) - 1;
            $j = count($this->state->document['Товары'][$i]['ХарактеристикиТовара']) - 1;
            ExchangeSupport::xmlAppendNested(
                $this->state->document['Товары'][$i]['ХарактеристикиТовара'],
                $j,
                $name,
                $data,
            );
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 3) == 'Документ'
            && ExchangeSupport::xmlParentName($names, $depth, 2) == 'ЗначенияРеквизитов'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ЗначениеРеквизита'
        ) {
            $i = count($this->state->document['ЗначенияРеквизитов']) - 1;
            ExchangeSupport::xmlAppendNested($this->state->document['ЗначенияРеквизитов'], $i, $name, $data);
        }
    }

    /**
     * @param array<int, string> $names
     */
    public function endElementHandler(
        $isFull,
        array $names,
        int $depth,
        string $name,
    ): void {
        if (ExchangeSupport::xmlParentName($names, $depth) == 'КоммерческаяИнформация' && $name == 'Документ') {
            $this->replaceDocument($this->state->document);
        } elseif (!$depth && $name == 'КоммерческаяИнформация') {
            ExchangeSupport::cleanupTransients();

            do_action('wc1c_post_orders', $isFull);
        }
    }

    /**
     * @param array<string, mixed> $orderData
     *
     * @return array<string, mixed>
     */
    public function woocommerceNewOrderData(array $orderData): array
    {
        $orderData['import_id'] = $this->state->document['Номер'];

        return $orderData;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function replaceDocument(array $document): void
    {
        global $wpdb;

        if ($document['ХозОперация'] != 'Заказ товара' || $document['Роль'] != 'Продавец') {
            return;
        }

        $order = wc_get_order($document['Номер']);

        if (!$order) {
            $args = [
                'status' => 'on-hold',
                'customer_note' => @$document['Комментарий'],
            ];

            $contragent_name = @$document['Контрагенты'][0]['Наименование'];
            if ($contragent_name == 'Гость') {
                $user_id = 0;
            } elseif (strpos($contragent_name, ' ') !== false) {
                list($first_name, $last_name) = explode(' ', $contragent_name, 2);
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT u1.user_id FROM $wpdb->usermeta u1 JOIN $wpdb->usermeta u2 ON u1.user_id = u2.user_id WHERE (u1.meta_key = 'billing_first_name' AND u1.meta_value = %s AND u2.meta_key = 'billing_last_name' AND u2.meta_value = %s) OR (u1.meta_key = 'shipping_first_name' AND u1.meta_value = %s AND u2.meta_key = 'shipping_last_name' AND u2.meta_value = %s)",
                    $first_name,
                    $last_name,
                    $first_name,
                    $last_name,
                ));
                ExchangeSupport::checkWpdbError();
                if ($result) {
                    $user_id = $result;
                }
            }
            if (isset($user_id)) {
                $args['customer_id'] = $user_id;
            }

            $order = wc_create_order($args);
            ExchangeSupport::checkWpError($order);

            if (!isset($user_id)) {
                update_post_meta($order->get_id(), 'wc1c_contragent', $contragent_name);
            }

            $args = [
                'ID' => $order->get_id(),
            ];

            $date = @$document['Дата'];
            if ($date && !empty($document['Время'])) {
                $date .= " {$document['Время']}";
            }
            $timestamp = strtotime($date);
            $args['post_date'] = date('Y-m-d H:i:s', $timestamp);

            $result = wp_update_post($args);
            ExchangeSupport::checkWpError($result);
            if (!$result) {
                ExchangeSupport::error('Failed to update order post');
            }

            update_post_meta($order->get_id(), '_wc1c_guid', $document['Ид']);
        } else {
            $args = [
                'order_id' => $order->get_id(),
            ];

            foreach ($document['ЗначенияРеквизитов'] as $requisite) {
                if (
                    $requisite['Наименование'] != 'Статуса заказа ИД'
                    || !in_array($requisite['Значение'], [
                        'pending',
                        'processing',
                        'on-hold',
                        'completed',
                        'cancelled',
                        'refunded',
                        'failed',
                    ], true)
                ) {
                    continue;
                }

                $args['status'] = $requisite['Значение'];
                break;
            }

            foreach ($document['ЗначенияРеквизитов'] as $requisite) {
                if ($requisite['Наименование'] != 'Отменен' || $requisite['Значение'] != 'true') {
                    continue;
                }

                $args['status'] = 'cancelled';
                break;
            }

            $order = wc_update_order($args);
            ExchangeSupport::checkWpError($order);
        }

        $is_deleted = false;
        foreach ($document['ЗначенияРеквизитов'] as $requisite) {
            if ($requisite['Наименование'] != 'ПометкаУдаления' || $requisite['Значение'] != 'true') {
                continue;
            }

            $is_deleted = true;
            break;
        }

        if ($is_deleted && $order->get_status() != 'trash') {
            wp_trash_post($order->get_id());
        } elseif (!$is_deleted && $order->get_status() == 'trash') {
            wp_untrash_post($order->get_id());
        }

        $post_meta = [];
        if (isset($document['Валюта'])) {
            $post_meta['_order_currency'] = $document['Валюта'];
        }
        if (isset($document['Сумма'])) {
            $post_meta['_order_total'] = ExchangeSupport::parseDecimal($document['Сумма']);
        }

        $document_products = [];
        $document_services = [];
        if (isset($document['Товары'])) {
            foreach ($document['Товары'] as $i => $document_product) {
                foreach ($document_product['ЗначенияРеквизитов'] as $document_product_requisite) {
                    if ($document_product_requisite['Наименование'] != 'ТипНоменклатуры') {
                        continue;
                    }

                    if ($document_product_requisite['Значение'] == 'Услуга') {
                        $document_services[] = $document_product;
                    } else {
                        $document_products[] = $document_product;
                    }
                    break;
                }
            }
        }

        $this->replaceDocumentProducts($order, $document_products);
        $post_meta['_order_shipping'] = $this->replaceDocumentServices($order, $document_services);

        $current_post_meta = get_post_meta($order->get_id());
        foreach ($current_post_meta as $meta_key => $meta_value) {
            $current_post_meta[$meta_key] = $meta_value[0];
        }

        foreach ($post_meta as $meta_key => $meta_value) {
            $current_meta_value = @$current_post_meta[$meta_key];
            if ($current_meta_value == $meta_value) {
                continue;
            }

            update_post_meta($order->get_id(), $meta_key, $meta_value);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $documentProducts
     */
    private function replaceDocumentProducts(WC_Order $order, array $documentProducts): void
    {
        $line_items = $order->get_items();
        $line_item_ids = [];
        foreach ($documentProducts as $i => $document_product) {
            $product_id = ExchangeSupport::postIdByMeta('_wc1c_guid', $document_product['Ид']);
            if (!$product_id) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                ExchangeSupport::error('Failed to get product');
            }

            $documentProducts[$i]['product'] = $product;

            $current_line_item_id = null;
            foreach ($line_items as $line_item_id => $line_item) {
                if (
                    $line_item['product_id'] != $product->get_id()
                    || (int) $line_item['variation_id'] != $product->get_id()
                ) {
                    continue;
                }

                $current_line_item_id = $line_item_id;
                break;
            }
            $documentProducts[$i]['line_item_id'] = $current_line_item_id;

            if ($current_line_item_id) {
                $line_item_ids[] = $current_line_item_id;
            }
        }

        $old_line_item_ids = array_diff(array_keys($line_items), $line_item_ids);
        if ($old_line_item_ids) {
            $order->remove_order_items('line_item');

            foreach ($documentProducts as $i => $document_product) {
                $documentProducts[$i]['line_item_id'] = null;
            }
        }

        foreach ($documentProducts as $document_product) {
            $quantity = isset($document_product['Количество'])
                ? ExchangeSupport::parseDecimal($document_product['Количество'])
                : 1;
            $coefficient = isset($document_product['Коэффициент'])
                ? ExchangeSupport::parseDecimal($document_product['Коэффициент'])
                : 1;
            $quantity *= $coefficient;

            if (!empty($document_product['Сумма'])) {
                $total = ExchangeSupport::parseDecimal($document_product['Сумма']);
            } else {
                $price = ExchangeSupport::parseDecimal(@$document_product['ЦенаЗаЕдиницу']);
                $total = $price * $quantity;
            }

            $args = [
                'totals' => [
                    'subtotal' => $total,
                    'total' => $total,
                ],
            ];

            if (!isset($document_product['product'])) {
                continue;
            }
            $product = $document_product['product'];

            if ($product->variation_id) {
                $attributes = $product->get_variation_attributes();
                $variation = [];
                foreach ($attributes as $attribute_key => $attribute_value) {
                    $variation[urldecode($attribute_key)] = urldecode($attribute_value);
                }
                $args['variation'] = $variation;
            }

            $line_item_id = $document_product['line_item_id'];
            if (!$line_item_id) {
                $line_item_id = $order->add_product($product, $quantity, $args);
                if (!$line_item_id) {
                    ExchangeSupport::error('Failed to add product to the order');
                }
            } else {
                $args['qty'] = $quantity;

                $result = $order->update_product($line_item_id, $product, $args);
                if (!$result) {
                    ExchangeSupport::error('Failed to update product in the order');
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $documentServices
     */
    private function replaceDocumentServices(WC_Order $order, array $documentServices): ?float
    {
        $shipping_items = $order->get_shipping_methods();

        if ($shipping_items && !$documentServices) {
            $order->remove_order_items('shipping');

            return null;
        }

        if (!self::$shippingMethods) {
            if ($shipping = WC()->shipping) {
                $shipping->load_shipping_methods();
                self::$shippingMethods = $shipping->get_shipping_methods();
            }
        }

        $shipping_cost_sum = 0;
        foreach ($documentServices as $document_service) {
            foreach (self::$shippingMethods as $shipping_method_id => $shipping_method) {
                if ($document_service['Наименование'] != $shipping_method->title) {
                    continue;
                }

                $shipping_cost = ExchangeSupport::parseDecimal($document_service['Сумма']);
                $shipping_cost_sum += $shipping_cost;

                $method_title = isset($shipping_method->method_title) ? $shipping_method->method_title : '';
                $args = [
                    'method_id' => $shipping_method->id,
                    'method_title' => $method_title,
                    'cost' => $shipping_cost,
                ];

                if (!$shipping_items) {
                    $shipping_rate = new WC_Shipping_Rate(
                        $args['method_id'],
                        $args['method_title'],
                        $args['method_title'],
                        null,
                        $args['method_id'],
                    );

                    $shipping_item_id = $order->add_shipping($shipping_rate);
                    if (!$shipping_item_id) {
                        ExchangeSupport::error('Failed to add shippin to the order');
                    }
                } else {
                    $shipping_item_id = key($shipping_items);
                    $result = $order->update_shipping($shipping_item_id, $args);
                    if (!$result) {
                        ExchangeSupport::error('Failed to add shippin to the order');
                    }
                }

                break;
            }
        }

        return $shipping_cost_sum;
    }
}
