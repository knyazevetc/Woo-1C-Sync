<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange\Handlers;

use Woo1cSync\Exchange\ExchangeState;
use Woo1cSync\Exchange\ExchangeSupport;

/**
 * Handles CommerceML offers XML parsing and synchronizes prices, stock, and product variations.
 */
final class OffersHandler
{
    public function __construct(
        private readonly ExchangeState $state,
    ) {
        ExchangeSupport::setOffersHandler($this);
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
        if (ExchangeSupport::xmlParentName($names, $depth) == 'ПакетПредложений' && $name == 'ТипыЦен') {
            $this->state->priceTypes = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ТипыЦен' && $name == 'ТипЦены') {
            $this->state->priceTypes[] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Предложение' && $name == 'Склад') {
            @$this->state->offer['КоличествоНаСкладе'] += $attrs['КоличествоНаСкладе'];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Предложения' && $name == 'Предложение') {
            $this->state->offer = [
                'ХарактеристикиТовара' => [],
            ];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ХарактеристикиТовара' && $name == 'ХарактеристикаТовара') {
            $this->state->offer['ХарактеристикиТовара'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Цены' && $name == 'Цена') {
            $this->state->price = [];
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
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'ТипыЦен'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ТипЦены'
            && $name != 'Налог'
        ) {
            $i = count($this->state->priceTypes) - 1;
            ExchangeSupport::xmlAppendNested($this->state->priceTypes, $i, $name, $data);
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'Предложения'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Предложение'
            && !in_array($name, ['БазоваяЕдиница', 'ХарактеристикиТовара', 'Цены'], true)
        ) {
            ExchangeSupport::xmlAppend($this->state->offer, $name, $data);
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'ХарактеристикиТовара'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ХарактеристикаТовара'
        ) {
            $i = count($this->state->offer['ХарактеристикиТовара']) - 1;
            ExchangeSupport::xmlAppendNested($this->state->offer['ХарактеристикиТовара'], $i, $name, $data);
        } elseif (
            ExchangeSupport::xmlParentName($names, $depth, 2) == 'Цены'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Цена'
        ) {
            ExchangeSupport::xmlAppend($this->state->price, $name, $data);
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
        if (ExchangeSupport::xmlParentName($names, $depth) == 'ПакетПредложений' && $name == 'ТипыЦен') {
            if (!WC1C_PRICE_TYPE) {
                $this->state->priceType = $this->state->priceTypes[0];
            } else {
                foreach ($this->state->priceTypes as $priceType) {
                    if (@$priceType['Ид'] != WC1C_PRICE_TYPE && @$priceType['Наименование'] != WC1C_PRICE_TYPE) {
                        continue;
                    }

                    $this->state->priceType = $priceType;
                    break;
                }
                if (!isset($this->state->priceType)) {
                    ExchangeSupport::error('Failed to match price type');
                }
            }

            if (!empty($this->state->priceType['Валюта'])) {
                $this->updateCurrency($this->state->priceType['Валюта']);
                update_option('wc1c_currency', $this->state->priceType['Валюта']);
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Цены' && $name == 'Цена') {
            if (
                !isset($this->state->offer['Цена'])
                && (
                    !isset($this->state->price['ИдТипаЦены'])
                    || $this->state->price['ИдТипаЦены'] == $this->state->priceType['Ид']
                )
            ) {
                $this->state->offer['Цена'] = $this->state->price;
            } else {
                $this->state->offer["Цена_{$this->state->price['ИдТипаЦены']}"] = $this->state->price;
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ХарактеристикаТовара' && $name == 'Наименование') {
            $i = count($this->state->offer['ХарактеристикиТовара']) - 1;
            $this->state->offer['ХарактеристикиТовара'][$i]['Наименование'] = preg_replace(
                "/\s+\(.*\)$/",
                '',
                $this->state->offer['ХарактеристикиТовара'][$i]['Наименование'],
            );
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Предложения' && $name == 'Предложение') {
            if (strpos($this->state->offer['Ид'], '#') === false || WC1C_DISABLE_VARIATIONS) {
                $guid = $this->state->offer['Ид'];
                $_post_id = $this->replaceOffer($isFull, $guid, $this->state->offer);
                if ($_post_id) {
                    $_product = wc_get_product($_post_id);
                    $_qnty = $_product->get_stock_quantity();
                    if (!$_qnty) {
                        update_post_meta($_post_id, '_stock_status', WC1C_OUTOFSTOCK_STATUS);
                    }
                    unset($_product, $_qnty);
                }
                unset($_post_id);
            } else {
                $guid = $this->state->offer['Ид'];
                list($product_guid, ) = explode('#', $guid, 2);

                if (
                    empty($this->state->suboffers)
                    || $this->state->suboffers[0]['product_guid'] != $product_guid
                ) {
                    if ($this->state->suboffers) {
                        $this->replaceSuboffers($isFull, $this->state->suboffers);
                    }
                    $this->state->suboffers = [];
                }

                $this->state->suboffers[] = [
                    'guid' => $this->state->offer['Ид'],
                    'product_guid' => $product_guid,
                    'offer' => $this->state->offer,
                ];
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ПакетПредложений' && $name == 'Предложения') {
            if ($this->state->suboffers) {
                $this->replaceSuboffers($isFull, $this->state->suboffers);
            }
        } elseif (!$depth && $name == 'КоммерческаяИнформация') {
            ExchangeSupport::cleanupTransients();

            do_action('wc1c_post_offers', $isFull);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $suboffers
     */
    public function replaceSuboffers($isFull, array $suboffers, bool $areProducts = false): void
    {
        if (!$suboffers) {
            return;
        }

        $product_guid = $suboffers[0]['product_guid'];
        $post_id = ExchangeSupport::postIdByMeta('_wc1c_guid', $product_guid);
        if (!$post_id && !$areProducts) {
            return;
        }

        if ($areProducts) {
            $product = $suboffers[0]['product'];
            $product['Ид'] = $product_guid;
            $post_id = ExchangeSupport::replaceProduct($suboffers[0]['is_full'], $product_guid, $product);
        }

        if (!WC1C_DISABLE_VARIATIONS) {
            $result = wp_set_post_terms($post_id, 'variable', 'product_type');
            ExchangeSupport::checkWpError($result);
        }

        $offer_characteristics = [];
        foreach ($suboffers as $suboffer) {
            if (isset($suboffer['offer']['ХарактеристикиТовара'])) {
                foreach ($suboffer['offer']['ХарактеристикиТовара'] as $suboffer_characteristic) {
                    $characteristic_name = $suboffer_characteristic['Наименование'];
                    if (!isset($offer_characteristics[$characteristic_name])) {
                        $offer_characteristics[$characteristic_name] = [];
                    }

                    $characteristic_value = @$suboffer_characteristic['Значение'];
                    if (!in_array($characteristic_value, $offer_characteristics[$characteristic_name], true)) {
                        $offer_characteristics[$characteristic_name][] = $characteristic_value;
                    }
                }
            }
        }

        if ($offer_characteristics) {
            ksort($offer_characteristics);
            foreach ($offer_characteristics as $characteristic_name => &$characteristic_values) {
                sort($characteristic_values);
            }

            $current_product_attributes = get_post_meta($post_id, '_product_attributes', true);
            if (!$current_product_attributes) {
                $current_product_attributes = [];
            }

            $product_attributes = [];
            foreach ($current_product_attributes as $current_product_attribute_key => $current_product_attribute) {
                if (!$current_product_attribute['is_variation']) {
                    $product_attributes[$current_product_attribute_key] = $current_product_attribute;
                }
            }

            foreach ($offer_characteristics as $offer_characteristic_name => $offer_characteristic_values) {
                $product_attribute_key = sanitize_title($offer_characteristic_name);
                $product_attribute_position = count($product_attributes);
                $product_attributes[$product_attribute_key] = [
                    'name' => wc_clean($offer_characteristic_name),
                    'value' => implode(' | ', $offer_characteristic_values),
                    'position' => $product_attribute_position,
                    'is_visible' => 1,
                    'is_variation' => 1,
                    'is_taxonomy' => 0,
                ];
            }

            ksort($current_product_attributes);
            $product_attributes_copy = $product_attributes;
            ksort($product_attributes_copy);
            if ($current_product_attributes != $product_attributes_copy) {
                update_post_meta($post_id, '_product_attributes', $product_attributes);
            }
        }

        $current_product_variation_ids = [];
        $product_variation_posts = get_children("post_parent=$post_id&post_type=product_variation");
        foreach ($product_variation_posts as $product_variation_post) {
            $current_product_variation_ids[] = $product_variation_post->ID;
        }

        $product_variation_ids = [];
        foreach ($suboffers as $i => $suboffer) {
            $product_variation_id = $this->replaceProductVariation($suboffer['guid'], $post_id, $i + 1);
            $product_variation_ids[] = $product_variation_id;

            $attributes = array_fill_keys(array_keys($offer_characteristics), '');
            if (isset($suboffer['offer']['ХарактеристикиТовара'])) {
                foreach ($suboffer['offer']['ХарактеристикиТовара'] as $suboffer_characteristic) {
                    $suboffer_characteristic_value = @$suboffer_characteristic['Значение'];
                    if ($suboffer_characteristic_value) {
                        $attributes[$suboffer_characteristic['Наименование']] = $suboffer_characteristic_value;
                    }
                }
            }

            if ($areProducts) {
                $this->replaceOfferPostMeta($isFull, $product_variation_id, [], $attributes);
            } else {
                $this->replaceOfferPostMeta($isFull, $product_variation_id, $suboffer['offer'], $attributes);
            }
        }

        if (!WC1C_PRESERVE_PRODUCT_VARIATIONS) {
            $deleted_product_variation_ids = array_diff($current_product_variation_ids, $product_variation_ids);
            foreach ($deleted_product_variation_ids as $deleted_product_variation_id) {
                wp_delete_post($deleted_product_variation_id, true);
            }
        }
    }

    private function updateCurrency(string $currency): void
    {
        if (!array_key_exists($currency, get_woocommerce_currencies())) {
            return;
        }

        update_option('woocommerce_currency', $currency);

        $currency_position = [
            'RUB' => 'right_space',
            'UAH' => 'right_space',
            'USD' => 'left',
        ];
        if (isset($currency_position[$currency])) {
            update_option('woocommerce_currency_pos', $currency_position[$currency]);
        }
    }

    /**
     * @param array<string, mixed> $offer
     * @param array<string, string> $attributes
     */
    private function replaceOfferPostMeta(
        bool $isFull,
        int $post_id,
        array $offer,
        array $attributes = [],
    ): void {
        $price = isset($offer['Цена']['ЦенаЗаЕдиницу'])
            ? ExchangeSupport::parseDecimal($offer['Цена']['ЦенаЗаЕдиницу'])
            : null;
        if (!is_null($price)) {
            $coefficient = isset($offer['Цена']['Коэффициент'])
                ? ExchangeSupport::parseDecimal($offer['Цена']['Коэффициент'])
                : null;
            if (!is_null($coefficient)) {
                $price *= $coefficient;
            }
        }

        $post_meta = [];
        if (!is_null($price)) {
            $post_meta['_regular_price'] = $price;
            $post_meta['_manage_stock'] = WC1C_MANAGE_STOCK;
        }

        if ($attributes) {
            foreach ($attributes as $attribute_name => $attribute_value) {
                $meta_key = 'attribute_' . sanitize_title($attribute_name);
                $post_meta[$meta_key] = $attribute_value;
            }

            $current_post_meta = get_post_meta($post_id);
            foreach ($current_post_meta as $meta_key => $meta_value) {
                $current_post_meta[$meta_key] = $meta_value[0];
            }

            foreach ($current_post_meta as $meta_key => $meta_value) {
                if (strpos($meta_key, 'attribute_') !== 0 || array_key_exists($meta_key, $post_meta)) {
                    continue;
                }

                delete_post_meta($post_id, $meta_key);
            }
        }

        if (!is_null($price)) {
            $sale_price = @$current_post_meta['_sale_price'];
            $sale_price_from = @$current_post_meta['_sale_price_dates_from'];
            $sale_price_to = @$current_post_meta['_sale_price_dates_to'];
            if (empty($current_post_meta['_sale_price'])) {
                $post_meta['_price'] = $price;
            } else {
                if (empty($sale_price_from) && empty($sale_price_to)) {
                    $post_meta['_price'] = $current_post_meta['_sale_price'];
                } else {
                    $now = strtotime('now', current_time('timestamp'));
                    if (!empty($sale_price_from) && strtotime($sale_price_from) < $now) {
                        $post_meta['_price'] = $current_post_meta['_sale_price'];
                    }
                    if (!empty($sale_price_to) && strtotime($sale_price_to) < $now) {
                        $post_meta['_price'] = $price;
                        $post_meta['_sale_price_dates_from'] = '';
                        $post_meta['_sale_price_dates_to'] = '';
                    }
                }
            }
        }

        foreach ($post_meta as $meta_key => $meta_value) {
            $current_meta_value = @$current_post_meta[$meta_key];
            if ($meta_value !== '' && $current_meta_value == $meta_value) {
                continue;
            }
            if ($meta_value === '' && $current_meta_value === $meta_value) {
                continue;
            }

            update_post_meta($post_id, $meta_key, $meta_value);
        }

        $quantity = isset($offer['Количество']) ? $offer['Количество'] : @$offer['КоличествоНаСкладе'];
        if (!is_null($quantity)) {
            $quantity = ExchangeSupport::parseDecimal((string) $quantity);
            wc_update_product_stock($post_id, $quantity);

            $stock_status = $quantity > 0 ? 'instock' : WC1C_OUTOFSTOCK_STATUS;
            update_post_meta($post_id, '_stock_status', $stock_status);
        }

        do_action('wc1c_post_offer_meta', $post_id, $offer, $isFull);
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function replaceOffer(bool $isFull, string $guid, array $offer): mixed
    {
        $post_id = ExchangeSupport::postIdByMeta('_wc1c_guid', $guid);
        if ($post_id) {
            $this->replaceOfferPostMeta($isFull, $post_id, $offer);
        }

        do_action('wc1c_post_offer', $post_id, $offer, $isFull);

        return $post_id;
    }

    private function replaceProductVariation(string $guid, int $parent_post_id, int $order): int
    {
        $post_id = ExchangeSupport::postIdByMeta('_wc1c_guid', $guid);

        $args = [
            'menu_order' => $order,
        ];

        if (!$post_id) {
            $args = array_merge($args, [
                'post_type' => 'product_variation',
                'post_parent' => $parent_post_id,
                'post_title' => "Product #$parent_post_id Variation",
                'post_status' => 'publish',
            ]);
            $post_id = wp_insert_post($args, true);
            ExchangeSupport::checkWpdbError();
            ExchangeSupport::checkWpError($post_id);

            update_post_meta($post_id, '_wc1c_guid', $guid);

            $is_added = true;
        }

        $post = get_post($post_id);
        if (!$post) {
            ExchangeSupport::error('Failed to get post');
        }

        if (empty($is_added)) {
            foreach ($args as $key => $value) {
                if ($post->$key == $value) {
                    continue;
                }

                $is_changed = true;
                break;
            }

            if (!empty($is_changed)) {
                $args = array_merge($args, [
                    'ID' => $post_id,
                ]);
                $post_id = wp_update_post($args, true);
                ExchangeSupport::checkWpError($post_id);
            }
        }

        return (int) $post_id;
    }
}
