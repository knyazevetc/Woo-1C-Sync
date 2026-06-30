<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange\Handlers;

use Woo1cSync\Exchange\ExchangeState;
use Woo1cSync\Exchange\ExchangeSupport;

/**
 * Handles CommerceML catalog XML import: categories, attributes, and products.
 */
final class ImportHandler
{
    private ExchangeState $state;

    public function __construct(ExchangeState $state)
    {
        $this->state = $state;
    }

    /**
     * Register WordPress hooks required during catalog import.
     */
    public function registerHooks(): void
    {
        add_filter('wp_unique_term_slug', [$this, 'wpUniqueTermSlug'], 10, 3);
    }

    /**
     * Handle XML start element events during catalog import.
     *
     * @param mixed $isFull Whether this is a full catalog sync.
     * @param array<int, string> $names Stack of element names.
     * @param int $depth Current element depth.
     * @param string $name Current element name.
     * @param array<string, string> $attrs Element attributes.
     */
    public function startElementHandler($isFull, array $names, int $depth, string $name, array $attrs): void
    {
        if (!$depth && $name != 'КоммерческаяИнформация') {
            ExchangeSupport::error('XML parser misbehavior.');
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Классификатор' && $name == 'Группы') {
            $this->state->groups = [];
            $this->state->groupDepth = -1;
            $this->state->groupOrder = 1;
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Группы' && $name == 'Группа') {
            $this->state->groupDepth++;
            $this->state->groups[] = [
                'ИдРодителя' => isset($this->state->groups[$this->state->groupDepth - 1])
                    ? $this->state->groups[$this->state->groupDepth - 1]['Ид']
                    : null,
            ];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Группа' && $name == 'Группы') {
            $result = $this->replaceGroup($isFull, $this->state->groups[$this->state->groupDepth], $this->state->groupOrder, $this->state->groups);
            if ($result) {
                $this->state->groupOrder++;
            }

            $this->state->groups[$this->state->groupDepth]['Группы'] = true;
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Классификатор' && $name == 'Свойства') {
            $this->state->propertyOrder = 1;
            $this->state->requisiteProperties = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Свойства' && $name == 'Свойство') {
            $this->state->property = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Свойство' && $name == 'ВариантыЗначений') {
            $this->state->property['ВариантыЗначений'] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ВариантыЗначений' && $name == 'Справочник') {
            $this->state->property['ВариантыЗначений'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товары' && $name == 'Товар') {
            $this->state->product = [
                'ХарактеристикиТовара' => [],
                'ЗначенияСвойств' => [],
                'ЗначенияРеквизитов' => [],
            ];
            if (isset($attrs['Статус'])) {
                $this->state->product['Статус'] = $attrs['Статус'];
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товар' && $name == 'Группы') {
            $this->state->product['Группы'] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Группы' && $name == 'Ид') {
            $this->state->product['Группы'][] = '';
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товар' && $name == 'Картинка') {
            if (!isset($this->state->product['Картинка'])) {
                $this->state->product['Картинка'] = [];
            }
            $this->state->product['Картинка'][] = '';
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товар' && $name == 'Изготовитель') {
            $this->state->product['Изготовитель'] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ХарактеристикиТовара' && $name == 'ХарактеристикаТовара') {
            $this->state->product['ХарактеристикиТовара'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ЗначенияСвойств' && $name == 'ЗначенияСвойства') {
            $this->state->product['ЗначенияСвойств'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ЗначенияСвойства' && $name == 'Значение') {
            $i = count($this->state->product['ЗначенияСвойств']) - 1;
            if (!isset($this->state->product['ЗначенияСвойств'][$i]['Значение'])) {
                $this->state->product['ЗначенияСвойств'][$i]['Значение'] = [];
            }
            $this->state->product['ЗначенияСвойств'][$i]['Значение'][] = '';
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ЗначенияРеквизитов' && $name == 'ЗначениеРеквизита') {
            $this->state->product['ЗначенияРеквизитов'][] = [];
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'ЗначениеРеквизита' && $name == 'Значение') {
            $i = count($this->state->product['ЗначенияРеквизитов']) - 1;
            if (!isset($this->state->product['ЗначенияРеквизитов'][$i]['Значение'])) {
                $this->state->product['ЗначенияРеквизитов'][$i]['Значение'] = [];
            }
            $this->state->product['ЗначенияРеквизитов'][$i]['Значение'][] = '';
        }
    }

    /**
     * Handle XML character data events during catalog import.
     *
     * @param mixed $isFull Whether this is a full catalog sync.
     * @param array<int, string> $names Stack of element names.
     * @param int $depth Current element depth.
     * @param string $name Current element name.
     * @param string $data Character data content.
     */
    public function characterDataHandler($isFull, array $names, int $depth, string $name, string $data): void
    {
        if (ExchangeSupport::xmlParentName($names, $depth, 2) == 'Группы'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Группа'
            && $name != 'Группы'
        ) {
            ExchangeSupport::xmlAppendNested($this->state->groups, $this->state->groupDepth, $name, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'Свойства'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Свойство'
            && $name != 'ВариантыЗначений'
        ) {
            ExchangeSupport::xmlAppend($this->state->property, $name, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'ХарактеристикиТовара'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ХарактеристикаТовара'
        ) {
            $i = count($this->state->product['ХарактеристикиТовара']) - 1;
            ExchangeSupport::xmlAppendNested($this->state->product['ХарактеристикиТовара'], $i, $name, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'ВариантыЗначений'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Справочник'
        ) {
            $i = count($this->state->property['ВариантыЗначений']) - 1;
            ExchangeSupport::xmlAppendNested($this->state->property['ВариантыЗначений'], $i, $name, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'Товары'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Товар'
            && !in_array($name, ['Группы', 'Картинка', 'Изготовитель', 'ХарактеристикиТовара', 'ЗначенияСвойств', 'СтавкиНалогов', 'ЗначенияРеквизитов'], true)
        ) {
            ExchangeSupport::xmlAppend($this->state->product, $name, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'БазоваяЕдиница'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Пересчет'
        ) {
            if (!isset($this->state->product['Пересчет'])) {
                $this->state->product['Пересчет'] = [];
            }
            ExchangeSupport::xmlAppend($this->state->product['Пересчет'], $name, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'Товар'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Группы'
            && $name == 'Ид'
        ) {
            $i = count($this->state->product['Группы']) - 1;
            ExchangeSupport::xmlAppend($this->state->product['Группы'], $i, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'Товары'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Товар'
            && $name == 'Картинка'
        ) {
            if (!isset($this->state->product['Картинка'])) {
                $this->state->product['Картинка'] = [];
            }
            $i = count($this->state->product['Картинка']) - 1;
            if ($i >= 0) {
                ExchangeSupport::xmlAppend($this->state->product['Картинка'], $i, $data);
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'Товар'
            && ExchangeSupport::xmlParentName($names, $depth) == 'Изготовитель'
        ) {
            if (!isset($this->state->product['Изготовитель'])) {
                $this->state->product['Изготовитель'] = [];
            }
            ExchangeSupport::xmlAppend($this->state->product['Изготовитель'], $name, $data);
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'ЗначенияСвойств'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ЗначенияСвойства'
        ) {
            $i = count($this->state->product['ЗначенияСвойств']) - 1;
            if ($name != 'Значение') {
                ExchangeSupport::xmlAppendNested($this->state->product['ЗначенияСвойств'], $i, $name, $data);
            } else {
                $j = count($this->state->product['ЗначенияСвойств'][$i]['Значение']) - 1;
                ExchangeSupport::xmlAppend($this->state->product['ЗначенияСвойств'][$i]['Значение'], $j, $data);
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth, 2) == 'ЗначенияРеквизитов'
            && ExchangeSupport::xmlParentName($names, $depth) == 'ЗначениеРеквизита'
        ) {
            $i = count($this->state->product['ЗначенияРеквизитов']) - 1;
            if ($name != 'Значение') {
                ExchangeSupport::xmlAppendNested($this->state->product['ЗначенияРеквизитов'], $i, $name, $data);
            } else {
                $j = count($this->state->product['ЗначенияРеквизитов'][$i]['Значение']) - 1;
                ExchangeSupport::xmlAppend($this->state->product['ЗначенияРеквизитов'][$i]['Значение'], $j, $data);
            }
        }
    }

    /**
     * Handle XML end element events during catalog import.
     *
     * @param mixed $isFull Whether this is a full catalog sync.
     * @param array<int, string> $names Stack of element names.
     * @param int $depth Current element depth.
     * @param string $name Current element name.
     */
    public function endElementHandler($isFull, array $names, int $depth, string $name): void
    {
        if (ExchangeSupport::xmlParentName($names, $depth) == 'Группы' && $name == 'Группа') {
            if (empty($this->state->groups[$this->state->groupDepth]['Группы'])) {
                $result = $this->replaceGroup($isFull, $this->state->groups[$this->state->groupDepth], $this->state->groupOrder, $this->state->groups);
                if ($result) {
                    $this->state->groupOrder++;
                }
            }

            array_pop($this->state->groups);
            $this->state->groupDepth--;
        }
        if (ExchangeSupport::xmlParentName($names, $depth) == 'Классификатор' && $name == 'Группы') {
            $this->cleanWoocommerceCategories($isFull);
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Свойства' && $name == 'Свойство') {
            $result = $this->replaceProperty($isFull, $this->state->property, $this->state->propertyOrder);
            if ($result) {
                $attributeTaxonomy = $result;
                $this->state->propertyOrder++;

                $this->cleanWoocommerceAttributeOptions($isFull, $attributeTaxonomy);
            } else {
                $this->state->requisiteProperties[$this->state->property['Ид']] = $this->state->property;
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Классификатор' && $name == 'Свойства') {
            $this->cleanWoocommerceAttributes($isFull);

            delete_transient('wc_attribute_taxonomies');
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Товары' && $name == 'Товар') {
            if ($this->state->requisiteProperties) {
                foreach ($this->state->product['ЗначенияСвойств'] as $productProperty) {
                    if (!array_key_exists($productProperty['Ид'], $this->state->requisiteProperties)) {
                        continue;
                    }

                    $property = $this->state->requisiteProperties[$productProperty['Ид']];
                    $this->state->product['ЗначенияРеквизитов'][] = [
                        'Наименование' => $property['Наименование'],
                        'Значение' => $productProperty['Значение'],
                    ];
                }
            }

            if (strpos($this->state->product['Ид'], '#') === false || WC1C_DISABLE_VARIATIONS) {
                $guid = $this->state->product['Ид'];
                if (WC1C_MATCH_BY_SKU) {
                    $sku = @$this->state->product['Артикул'];
                    if ($sku) {
                        $_post_id = ExchangeSupport::postIdByMeta('_sku', $sku);
                        if ($_post_id) {
                            update_post_meta($_post_id, '_wc1c_guid', $guid);
                        }
                    }
                }
                $_post_id = $this->replaceProduct($isFull, $guid, $this->state->product);
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
                $guid = $this->state->product['Ид'];
                list($product_guid, ) = explode('#', $guid, 2);

                if (empty($this->state->subproducts) || $this->state->subproducts[0]['product_guid'] != $product_guid) {
                    if ($this->state->subproducts) {
                        $this->replaceSubproducts($isFull, $this->state->subproducts);
                    }
                    $this->state->subproducts = [];
                }

                $this->state->subproducts[] = [
                    'guid' => $this->state->product['Ид'],
                    'product_guid' => $product_guid,
                    'characteristics' => $this->state->product['ХарактеристикиТовара'],
                    'is_full' => $isFull,
                    'product' => $this->state->product,
                ];
            }
        } elseif (ExchangeSupport::xmlParentName($names, $depth) == 'Каталог' && $name == 'Товары') {
            if ($this->state->subproducts) {
                $this->replaceSubproducts($isFull, $this->state->subproducts);
            }

            $this->cleanProducts($isFull);
            $this->cleanProductTerms();
        } elseif (!$depth && $name == 'КоммерческаяИнформация') {
            ExchangeSupport::cleanupTransients();

            do_action('wc1c_post_import', $isFull);
        }
    }

    /**
     * Create or update a WooCommerce product from CommerceML product data.
     *
     * @param mixed $isFull Whether this is a full catalog sync.
     * @param string $guid Product GUID.
     * @param array<string, mixed> $product Parsed product XML data.
     *
     * @return int|false|null Post ID on success.
     */
    public function replaceProduct($isFull, string $guid, array $product)
    {
        $product = apply_filters('wc1c_import_product_xml', $product, $isFull);
        if (!$product) {
            return;
        }

        $preserveFields = apply_filters('wc1c_import_preserve_product_fields', [], $product, $isFull);

        $isDeleted = @$product['Статус'] == 'Удален';
        $isDraft = @$product['Статус'] == 'Черновик';

        $postTitle = @$product['Наименование'];
        if (!$postTitle) {
            return;
        }

        $postContent = '';

        $postMeta = [
            '_sku' => @$product['Артикул'],
            '_manage_stock' => WC1C_MANAGE_STOCK,
        ];

        foreach ($product['ЗначенияРеквизитов'] as $i => $requisite) {
            $value = @$requisite['Значение'][0];
            if (!$value) {
                continue;
            }
            if ($requisite['Наименование'] == 'Полное наименование') {
                if ($this->state->isMoysklad) {
                    $postContent = $value;
                } else {
                    $postTitle = $value;
                }
                unset($product['ЗначенияРеквизитов'][$i]);
            } elseif ($requisite['Наименование'] == 'ОписаниеВФорматеHTML') {
                $postContent = $value;
                unset($product['ЗначенияРеквизитов'][$i]);
            } elseif ($requisite['Наименование'] == 'Длина') {
                $postMeta['_length'] = floatval($value);
                unset($product['ЗначенияРеквизитов'][$i]);
            } elseif ($requisite['Наименование'] == 'Ширина') {
                $postMeta['_width'] = floatval($value);
                unset($product['ЗначенияРеквизитов'][$i]);
            } elseif ($requisite['Наименование'] == 'Высота') {
                $postMeta['_height'] = floatval($value);
                unset($product['ЗначенияРеквизитов'][$i]);
            } elseif ($requisite['Наименование'] == 'Вес') {
                $postMeta['_weight'] = floatval($value);
                unset($product['ЗначенияРеквизитов'][$i]);
            }
        }

        $postName = sanitize_title($postTitle);
        $postName = apply_filters('wc1c_import_product_slug', $postName, $product, $isFull);

        $description = isset($product['Описание']) ? $product['Описание'] : '';
        list($isAdded, $postId, $postMeta) = $this->replacePost(
            $guid,
            'product',
            $isDeleted,
            $isDraft,
            $postTitle,
            $postName,
            $description,
            $postContent,
            $postMeta,
            'product_cat',
            @$product['Группы'],
            $preserveFields
        );

        $currentProductAttributes = isset($postMeta['_product_attributes'])
            ? maybe_unserialize($postMeta['_product_attributes'])
            : [];

        $currentProductAttributeVariations = [];
        foreach ($currentProductAttributes as $currentProductAttributeKey => $currentProductAttribute) {
            if (!$currentProductAttribute['is_variation']) {
                continue;
            }

            unset($currentProductAttributes[$currentProductAttributeKey]);
            $currentProductAttributeVariations[$currentProductAttributeKey] = $currentProductAttribute;
        }

        $productAttributes = [];

        $productAttributeValues = [];
        if (!empty($product['Изготовитель']['Наименование'])) {
            $productAttributeValues['Наименование изготовителя'] = $product['Изготовитель']['Наименование'];
        }
        if (!empty($product['БазоваяЕдиница']) && trim($product['БазоваяЕдиница'])) {
            $productAttributeValues['Базовая единица'] = trim($product['БазоваяЕдиница']);
        }

        foreach ($productAttributeValues as $productAttributeName => $productAttributeValue) {
            $productAttributeKey = sanitize_title($productAttributeName);
            $productAttributePosition = count($productAttributes);
            $productAttributes[$productAttributeKey] = [
                'name' => wc_clean($productAttributeName),
                'value' => $productAttributeValue,
                'position' => $productAttributePosition,
                'is_visible' => 0,
                'is_variation' => 0,
                'is_taxonomy' => 0,
            ];
        }

        if ($product['ЗначенияСвойств']) {
            $attributeGuids = get_option('wc1c_guid_attributes', []);
            $terms = [];
            foreach ($product['ЗначенияСвойств'] as $property) {
                $attributeGuid = $property['Ид'];
                $attributeId = @$attributeGuids[$attributeGuid];
                if (!$attributeId) {
                    continue;
                }

                $attribute = ExchangeSupport::woocommerceAttributeById((int) $attributeId);
                if (!$attribute) {
                    ExchangeSupport::error('Failed to get attribute');
                }

                $attributeTerms = [];
                $attributeValues = [];
                $propertyValues = @$property['Значение'];
                if ($propertyValues) {
                    foreach ($propertyValues as $propertyValue) {
                        if (!$propertyValue) {
                            continue;
                        }

                        if ($attribute['attribute_type'] == 'select' && preg_match("/^\w+-\w+-\w+-\w+-\w+$/", $propertyValue)) {
                            $termId = $this->termIdByMeta('wc1c_guid', "{$attribute['taxonomy']}::$propertyValue");
                            if ($termId) {
                                $attributeTerms[] = (int) $termId;
                            }
                        } else {
                            $delimiter = defined('WC1C_MULTIPLE_VALUES_DELIMETER') ? WC1C_MULTIPLE_VALUES_DELIMETER : null;
                            if ($delimiter === null || $delimiter === '') {
                                $attributeValues[] = $propertyValue;
                            } else {
                                $termNames = explode($delimiter, $propertyValue);
                                $termNames = array_map('trim', $termNames);
                                foreach ($termNames as $termName) {
                                    $result = get_term_by('name', $termName, $attribute['taxonomy'], ARRAY_A);
                                    if (!$result) {
                                        $slug = $this->uniqueTermSlug($termName, $attribute['taxonomy']);
                                        $args = [
                                            'slug' => $slug,
                                        ];
                                        $result = wp_insert_term($termName, $attribute['taxonomy'], $args);
                                        ExchangeSupport::checkWpdbError();
                                        ExchangeSupport::checkWpError($result);
                                    }
                                    $attributeTerms[] = $result['term_id'];
                                }
                            }
                        }
                    }
                }

                if ($attributeTerms || $attributeValues) {
                    $productAttribute = [
                        'name' => null,
                        'value' => '',
                        'position' => count($productAttributes),
                        'is_visible' => 1,
                        'is_variation' => 0,
                        'is_taxonomy' => 0,
                    ];

                    if ($attributeTerms) {
                        $productAttribute['name'] = $attribute['taxonomy'];
                        $productAttribute['is_taxonomy'] = 1;
                    } elseif ($attributeValues) {
                        $productAttribute['name'] = $attribute['attribute_label'];
                        $productAttribute['value'] = implode(' | ', $attributeValues);
                    }

                    $productAttributeKey = sanitize_title($attribute['taxonomy']);
                    $productAttributes[$productAttributeKey] = $productAttribute;
                }

                if ($attributeTerms) {
                    if (!isset($terms[$attribute['taxonomy']])) {
                        $terms[$attribute['taxonomy']] = [];
                    }
                    $terms[$attribute['taxonomy']] = array_merge($terms[$attribute['taxonomy']], $attributeTerms);
                }
            }

            foreach ($terms as $attributeTaxonomy => $attributeTerms) {
                register_taxonomy($attributeTaxonomy, null);
                $result = wp_set_post_terms($postId, $attributeTerms, $attributeTaxonomy);
                ExchangeSupport::checkWpError($result);
            }
        }

        foreach ($product['ЗначенияРеквизитов'] as $requisite) {
            $attributeValues = @$requisite['Значение'];
            if (!$attributeValues) {
                continue;
            }
            if (strpos($attributeValues[0], 'import_files/') === 0) {
                continue;
            }

            $requisiteName = isset($requisite['Наименование']) ? $requisite['Наименование'] : null;
            if (!$requisiteName) {
                continue;
            }
            $productAttributeName = strpos($requisiteName, ' ') === false
                ? preg_replace_callback('/(?<!^)\p{Lu}/u', [$this, 'replaceRequisiteNameCallback'], $requisiteName)
                : $requisiteName;
            $productAttributeKey = sanitize_title($requisiteName);
            $productAttributePosition = count($productAttributes);
            $productAttributes[$productAttributeKey] = [
                'name' => wc_clean($productAttributeName),
                'value' => implode(' | ', $attributeValues),
                'position' => $productAttributePosition,
                'is_visible' => 0,
                'is_variation' => 0,
                'is_taxonomy' => 0,
            ];
        }

        foreach ($product['ХарактеристикиТовара'] as $characteristic) {
            $attributeValue = @$characteristic['Значение'];
            if (!$attributeValue) {
                continue;
            }

            $productAttributeName = isset($characteristic['Наименование']) ? $characteristic['Наименование'] : null;
            if (!$productAttributeName) {
                continue;
            }
            $productAttributeKey = sanitize_title($productAttributeName);
            $productAttributePosition = count($productAttributes);
            $productAttributes[$productAttributeKey] = [
                'name' => wc_clean($productAttributeName),
                'value' => $attributeValue,
                'position' => $productAttributePosition,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 0,
            ];
        }

        if (!in_array('attributes', $preserveFields, true)) {
            $oldProductAttributes = array_diff_key($currentProductAttributes, $productAttributes);
            $oldTaxonomies = [];
            foreach ($oldProductAttributes as $oldProductAttribute) {
                if ($oldProductAttribute['is_taxonomy']) {
                    $oldTaxonomies[] = $oldProductAttribute['name'];
                } else {
                    $key = array_search($oldProductAttribute, $productAttributes);
                    if ($key !== false) {
                        unset($productAttributes[$key]);
                    }
                }
            }
            foreach ($oldTaxonomies as $oldTaxonomy) {
                register_taxonomy($oldTaxonomy, null);
            }
            wp_delete_object_term_relationships($postId, $oldTaxonomies);

            ksort($currentProductAttributes);
            $productAttributesCopy = $productAttributes;
            ksort($productAttributesCopy);
            if ($currentProductAttributes != $productAttributesCopy) {
                $productAttributes = array_merge($productAttributes, $currentProductAttributeVariations);
                update_post_meta($postId, '_product_attributes', $productAttributes);
            }
        }

        if (!in_array('attachments', $preserveFields, true)) {
            $attachments = [];
            if (!empty($product['Картинка'])) {
                $attachments = array_filter($product['Картинка']);
                $attachments = array_fill_keys($attachments, []);
            }

            if ($product['ЗначенияРеквизитов']) {
                $attachmentKeys = [
                    'ОписаниеФайла' => 'description',
                ];
                foreach ($product['ЗначенияРеквизитов'] as $requisite) {
                    $attributeName = $requisite['Наименование'];
                    if (!isset($attachmentKeys[$attributeName])) {
                        continue;
                    }

                    $attributeValues = @$requisite['Значение'];
                    if (!$attributeValues) {
                        continue;
                    }

                    $attributeValue = $attributeValues[0];
                    if (strpos($attributeValue, 'import_files/') !== 0) {
                        continue;
                    }

                    list($picturePath, $attributeValue) = explode('#', $attributeValue, 2);
                    if (!isset($attachments[$picturePath])) {
                        continue;
                    }

                    $attachmentKey = $attachmentKeys[$attributeName];
                    $attachments[$picturePath][$attachmentKey] = $attributeValue;
                }
            }

            if ($attachments) {
                $attachmentIds = $this->replacePostAttachments($postId, $attachments);

                if ($attachmentIds) {
                    $newPostMeta = [
                        '_product_image_gallery' => implode(',', array_slice($attachmentIds, 1)),
                        '_thumbnail_id' => @$attachmentIds[0],
                    ];
                    foreach ($newPostMeta as $metaKey => $metaValue) {
                        if ($metaValue != @$postMeta[$metaKey]) {
                            update_post_meta($postId, $metaKey, $metaValue);
                        }
                    }
                }
            }
        }

        do_action('wc1c_post_product', $postId, $isAdded, $product, $isFull);

        return $postId;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function termIdByMeta(string $key, $value)
    {
        global $wpdb;

        if ($value === null) {
            return;
        }

        $cacheKey = "wc1c_term_id_by_meta-$key-$value";
        $termId = wp_cache_get($cacheKey);
        if ($termId === false) {
            $termId = $wpdb->get_var($wpdb->prepare(
                "SELECT tm.term_id FROM $wpdb->termmeta tm JOIN $wpdb->terms t ON tm.term_id = t.term_id WHERE meta_key = %s AND meta_value = %s",
                $key,
                $value
            ));
            ExchangeSupport::checkWpdbError();

            if ($termId) {
                wp_cache_set($cacheKey, $termId);
            }
        }

        return $termId;
    }

    /**
     * @param mixed $parent
     */
    private function uniqueTermName(string $name, string $taxonomy, $parent = null): string
    {
        global $wpdb;

        $name = htmlspecialchars($name);

        $sql = "SELECT * FROM $wpdb->terms NATURAL JOIN $wpdb->term_taxonomy WHERE name = %s AND taxonomy = %s AND parent = %d LIMIT 1";
        if (!$parent) {
            $parent = 0;
        }
        $term = $wpdb->get_row($wpdb->prepare($sql, $name, $taxonomy, $parent));
        ExchangeSupport::checkWpdbError();
        if (!$term) {
            return $name;
        }

        $number = 2;
        while (true) {
            $newName = "$name ($number)";
            $number++;

            $term = $wpdb->get_row($wpdb->prepare($sql, $newName, $taxonomy, $parent));
            ExchangeSupport::checkWpdbError();
            if (!$term) {
                return $newName;
            }
        }
    }

    /**
     * @param mixed $parent
     */
    private function uniqueTermSlug(string $slug, string $taxonomy, $parent = null): string
    {
        global $wpdb;

        while (true) {
            $sanitizedSlug = sanitize_title($slug);
            if (strlen($sanitizedSlug) <= 195) {
                break;
            }

            $slug = mb_substr($slug, 0, mb_strlen($slug) - 3);
        }

        $sql = "SELECT * FROM $wpdb->terms NATURAL JOIN $wpdb->term_taxonomy WHERE slug = %s AND taxonomy = %s AND parent = %d LIMIT 1";
        if (!$parent) {
            $parent = 0;
        }
        $term = $wpdb->get_row($wpdb->prepare($sql, $sanitizedSlug, $taxonomy, $parent));
        ExchangeSupport::checkWpdbError();
        if (!$term) {
            return $slug;
        }

        $number = 2;
        while (true) {
            $newSlug = "$slug-$number";
            $newSanitizedSlug = "$sanitizedSlug-$number";
            $number++;

            $term = $wpdb->get_row($wpdb->prepare($sql, $newSanitizedSlug, $taxonomy, $parent));
            ExchangeSupport::checkWpdbError();
            if (!$term) {
                return $newSlug;
            }
        }
    }

    /**
     * Truncate term slugs longer than 200 characters for WooCommerce compatibility.
     *
     * @param string $slug Proposed term slug.
     * @param mixed $term Term object or array.
     * @param mixed $originalSlug Original slug before sanitization.
     *
     * @return string Adjusted slug.
     */
    public function wpUniqueTermSlug(string $slug, $term, $originalSlug): string
    {
        if (mb_strlen($slug) <= 200) {
            return $slug;
        }

        do {
            $slug = urldecode($slug);
            $slug = mb_substr($slug, 0, mb_strlen($slug) - 1);
            $slug = urlencode($slug);
            $slug = wp_unique_term_slug($slug, $term);
        } while (mb_strlen($slug) > 200);

        return $slug;
    }

    /**
     * @param mixed $isFull
     * @param mixed $parentGuid
     * @param mixed $order
     */
    private function replaceTerm($isFull, string $guid, $parentGuid, string $name, string $taxonomy, $order, bool $useGuidAsSlug = false): void
    {
        global $wpdb;

        $termId = $this->termIdByMeta('wc1c_guid', "$taxonomy::$guid");
        if (!$termId) {
            if (WC1C_MATCH_CATEGORIES_BY_TITLE && $taxonomy === 'product_cat') {
                $termId = $wpdb->get_var($wpdb->prepare(
                    "SELECT term_id FROM {$wpdb->prefix}terms WHERE name = %s LIMIT 1",
                    $name
                ));
            } elseif (WC1C_MATCH_PROPERTY_OPTIONS_BY_TITLE && substr($taxonomy, 0, 3) === 'pa_') {
                $termId = $wpdb->get_var($wpdb->prepare(
                    "SELECT t.term_id FROM $wpdb->terms t LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id WHERE t.name = %s AND tt.taxonomy = %s LIMIT 1",
                    $name,
                    $taxonomy
                ));
            }
            if ($termId) {
                update_term_meta($termId, 'wc1c_guid', "$taxonomy::$guid");
            }
        }
        if ($termId) {
            $term = get_term($termId, $taxonomy);
        }

        $parent = $parentGuid ? $this->termIdByMeta('wc1c_guid', "$taxonomy::$parentGuid") : null;

        if (!$termId || !$term) {
            $name = $this->uniqueTermName($name, $taxonomy, $parent);
            $slug = $this->uniqueTermSlug($name, $taxonomy, $parent);
            $args = [
                'slug' => $slug,
                'parent' => $parent,
            ];
            if ($useGuidAsSlug) {
                $args['slug'] = $guid;
            }
            $result = wp_insert_term($name, $taxonomy, $args);
            ExchangeSupport::checkWpdbError();
            ExchangeSupport::checkWpError($result);

            $termId = $result['term_id'];
            update_term_meta($termId, 'wc1c_guid', "$taxonomy::$guid");

            $isAdded = true;
        }

        if (empty($isAdded)) {
            if (trim($name) != $term->name) {
                $name = $this->uniqueTermName($name, $taxonomy, $parent);
            }
            $parent = $parentGuid ? $this->termIdByMeta('wc1c_guid', "$taxonomy::$parentGuid") : null;
            $args = [
                'name' => $name,
                'parent' => $parent,
            ];

            $result = wp_update_term($termId, $taxonomy, $args);
            ExchangeSupport::checkWpError($result);
        }

        if ($isFull) {
            wc_set_term_order($termId, $order, $taxonomy);
        }

        update_term_meta($termId, 'wc1c_timestamp', WC1C_TIMESTAMP);
    }

    /**
     * @param array<string, mixed> $group
     * @param array<int, array<string, mixed>> $groups
     *
     * @return bool
     */
    private function replaceGroup($isFull, array $group, int $order, array $groups): bool
    {
        $parentGroups = array_slice($groups, 0, -1);
        $group = apply_filters('wc1c_import_group_xml', $group, $parentGroups, $isFull);
        if (!$group) {
            return false;
        }

        $groupName = isset($group['Наименование']) ? $group['Наименование'] : $group['Ид'];
        $this->replaceTerm($isFull, $group['Ид'], $group['ИдРодителя'], $groupName, 'product_cat', $order);

        return true;
    }

    private function uniqueWoocommerceAttributeName(string $attributeLabel): string
    {
        global $wpdb;

        $attributeName = wc_sanitize_taxonomy_name($attributeLabel);
        $maxLength = 32 - strlen('pa_') - strlen('-00');
        while (strlen($attributeName) > $maxLength) {
            $attributeName = mb_substr($attributeName, 0, mb_strlen($attributeName) - 1);
        }

        $sql = "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s";
        $attribute = $wpdb->get_row($wpdb->prepare($sql, $attributeName));
        ExchangeSupport::checkWpdbError();
        if (!$attribute) {
            return $attributeName;
        }

        $number = 2;
        while (true) {
            $newAttributeName = "$attributeName-$number";
            $number++;

            $attribute = $wpdb->get_row($wpdb->prepare($sql, $newAttributeName));
            if (!$attribute) {
                return $newAttributeName;
            }
        }
    }

    /**
     * @param array<int, string> $preserveFields
     *
     * @return int
     */
    private function replaceWoocommerceAttribute($isFull, string $guid, string $attributeLabel, string $attributeType, int $order, array $preserveFields): int
    {
        global $wpdb;

        $guids = get_option('wc1c_guid_attributes', []);
        $attributeId = @$guids[$guid];

        if ($attributeId) {
            $attributeId = $wpdb->get_var($wpdb->prepare(
                "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
                $attributeId
            ));
            ExchangeSupport::checkWpdbError();
        }

        $data = [
            'attribute_label' => $attributeLabel,
            'attribute_type' => $attributeType,
        ];

        if (WC1C_MATCH_PROPERTIES_BY_TITLE && !$attributeId) {
            $attributeId = $wpdb->get_var($wpdb->prepare(
                "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_label = %s",
                $attributeLabel
            ));
            $guids[$guid] = $attributeId;
            update_option('wc1c_guid_attributes', $guids);
        }

        if (!$attributeId) {
            $attributeName = $this->uniqueWoocommerceAttributeName($attributeLabel);
            $data = array_merge($data, [
                'attribute_name' => $attributeName,
                'attribute_orderby' => 'menu_order',
            ]);
            $wpdb->insert("{$wpdb->prefix}woocommerce_attribute_taxonomies", $data);
            ExchangeSupport::checkWpdbError();

            $attributeId = $wpdb->insert_id;
            $isAdded = true;

            $guids[$guid] = $attributeId;
            update_option('wc1c_guid_attributes', $guids);
        }

        if (empty($isAdded)) {
            if (in_array('label', $preserveFields, true)) {
                unset($data['attribute_label']);
            }
            if (in_array('type', $preserveFields, true)) {
                unset($data['attribute_type']);
            }

            $wpdb->update("{$wpdb->prefix}woocommerce_attribute_taxonomies", $data, ['attribute_id' => $attributeId]);
            ExchangeSupport::checkWpdbError();
        }

        if ($isFull) {
            $orders = get_option('wc1c_order_attributes', []);
            $orderIndex = array_search($attributeId, $orders) or 0;
            if ($orderIndex !== false) {
                unset($orders[$orderIndex]);
            }
            array_splice($orders, $order, 0, $attributeId);
            update_option('wc1c_order_attributes', $orders);
        }

        $timestamps = get_option('wc1c_timestamp_attributes', []);
        $timestamps[$guid] = WC1C_TIMESTAMP;
        update_option('wc1c_timestamp_attributes', $timestamps);

        return (int) $attributeId;
    }

    /**
     * @param array<string, mixed> $propertyOption
     */
    private function replacePropertyOption(array $propertyOption, string $attributeTaxonomy, int $order): void
    {
        if (!isset($propertyOption['ИдЗначения'], $propertyOption['Значение'])) {
            return;
        }

        $this->replaceTerm(
            true,
            $propertyOption['ИдЗначения'],
            null,
            $propertyOption['Значение'],
            $attributeTaxonomy,
            $order,
            WC1C_USE_GUID_AS_PROPERTY_OPTION_SLUG
        );
    }

    /**
     * @param array<string, mixed> $property
     *
     * @return string|false
     */
    private function replaceProperty($isFull, array $property, int $order)
    {
        $property = apply_filters('wc1c_import_property_xml', $property, $isFull);
        if (!$property) {
            return false;
        }

        $preserveFields = apply_filters('wc1c_import_preserve_property_fields', [], $property, $isFull);

        $attributeName = !empty($property['Наименование']) ? $property['Наименование'] : $property['Ид'];
        $attributeType = (empty($property['ТипЗначений']) || $property['ТипЗначений'] == 'Справочник' || defined('WC1C_MULTIPLE_VALUES_DELIMETER')) ? 'select' : 'text';
        $attributeId = $this->replaceWoocommerceAttribute($isFull, $property['Ид'], $attributeName, $attributeType, $order, $preserveFields);

        $attribute = ExchangeSupport::woocommerceAttributeById($attributeId);
        if (!$attribute) {
            ExchangeSupport::error('Failed to get attribute');
        }

        register_taxonomy($attribute['taxonomy'], null);

        if ($attributeType == 'select' && !empty($property['ВариантыЗначений'])) {
            foreach ($property['ВариантыЗначений'] as $i => $propertyOption) {
                $this->replacePropertyOption($propertyOption, $attribute['taxonomy'], $i + 1);
            }
        }

        return $attribute['taxonomy'];
    }

    /**
     * @param array<string, mixed> $postMeta
     * @param mixed $categoryGuids
     * @param array<int, string> $preserveFields
     *
     * @return array{0: bool, 1: int, 2: array<string, mixed>}
     */
    private function replacePost(
        string $guid,
        string $postType,
        bool $isDeleted,
        bool $isDraft,
        string $postTitle,
        string $postName,
        string $postExcerpt,
        string $postContent,
        array $postMeta,
        string $categoryTaxonomy,
        $categoryGuids,
        array $preserveFields
    ): array {
        $postId = ExchangeSupport::postIdByMeta('_wc1c_guid', $guid);

        if (!$postExcerpt) {
            $postExcerpt = '';
        }
        if (WC1C_PRODUCT_DESCRIPTION_TO_CONTENT) {
            $postContent = $postExcerpt;
            $postExcerpt = '';
        }

        $args = [
            'post_type' => $postType,
            'post_title' => $postTitle,
            'post_excerpt' => $postExcerpt,
            'post_content' => $postContent,
        ];

        if (!$postId) {
            $args = array_merge($args, [
                'post_name' => $postName,
                'post_status' => $isDraft ? 'draft' : 'publish',
            ]);
            $postId = wp_insert_post($args, true);
            ExchangeSupport::checkWpdbError();
            ExchangeSupport::checkWpError($postId);

            update_post_meta($postId, '_visibility', 'visible');
            update_post_meta($postId, '_wc1c_guid', $guid);

            $isAdded = true;
        } else {
            $isAdded = false;
        }

        $post = get_post($postId);
        if (!$post) {
            ExchangeSupport::error('Failed to get post');
        }

        if (!$isAdded) {
            if (in_array('title', $preserveFields, true)) {
                unset($args['post_title']);
            }
            if (in_array('excerpt', $preserveFields, true)) {
                unset($args['post_excerpt']);
            }
            if (in_array('body', $preserveFields, true)) {
                unset($args['post_content']);
            }
            if (WC1C_UPDATE_POST_NAME) {
                $args['post_name'] = $postName;
            }

            foreach ($args as $key => $value) {
                if ($post->$key == $value) {
                    continue;
                }

                $isChanged = true;
                break;
            }

            if (!empty($isChanged)) {
                $postDate = current_time('mysql');
                $args = array_merge($args, [
                    'ID' => $postId,
                    'post_date' => $postDate,
                    'post_date_gmt' => get_gmt_from_date($postDate),
                ]);
                $postId = wp_update_post($args, true);
                ExchangeSupport::checkWpError($postId);
            }
        }

        if ($isDeleted && $post->post_status != 'trash') {
            wp_trash_post($postId);
        } elseif (!$isDeleted && $post->post_status == 'trash') {
            wp_untrash_post($postId);
        }

        $currentPostMeta = get_post_meta($postId);
        foreach ($currentPostMeta as $metaKey => $metaValue) {
            $currentPostMeta[$metaKey] = $metaValue[0];
        }

        foreach ($postMeta as $metaKey => $metaValue) {
            $currentMetaValue = @$currentPostMeta[$metaKey];
            if ($currentMetaValue == $metaValue) {
                continue;
            }

            update_post_meta($postId, $metaKey, $metaValue);
        }

        if (!in_array('categories', $preserveFields, true)) {
            $currentCategoryIds = wp_get_post_terms($postId, $categoryTaxonomy, 'fields=ids');
            ExchangeSupport::checkWpError($currentCategoryIds);

            $categoryIds = [];
            if ($categoryGuids) {
                foreach ($categoryGuids as $categoryGuid) {
                    $categoryId = $this->termIdByMeta('wc1c_guid', "product_cat::$categoryGuid");
                    if ($categoryId) {
                        $categoryIds[] = $categoryId;
                    }
                }
            }

            sort($currentCategoryIds);
            sort($categoryIds);
            if ($currentCategoryIds != $categoryIds) {
                $result = wp_set_post_terms($postId, $categoryIds, $categoryTaxonomy);
                ExchangeSupport::checkWpError($result);
            }
        }

        update_post_meta($postId, '_wc1c_timestamp', WC1C_TIMESTAMP);

        return [$isAdded, $postId, $currentPostMeta];
    }

    private function isReadableImageFile(string $path): bool
    {
        if (!is_readable($path)) {
            return false;
        }

        $size = @filesize($path);
        if (!$size) {
            return false;
        }

        return @getimagesize($path) !== false;
    }

    /**
     * @param array<string, array<string, string>> $attachments
     *
     * @return array<int, int>
     */
    private function replacePostAttachments(int $postId, array $attachments): array
    {
        $dataDir = WC1C_DATA_DIR . 'catalog';

        $attachmentPathByHash = [];
        foreach ($attachments as $attachmentPath => $attachment) {
            $attachmentPath = "$dataDir/$attachmentPath";
            if (!$this->isReadableImageFile($attachmentPath)) {
                continue;
            }

            $attachmentHash = basename($attachmentPath) . md5_file($attachmentPath);
            $attachmentPathByHash[$attachmentHash] = $attachmentPath;
        }
        $attachmentHashByPath = array_flip($attachmentPathByHash);

        $postAttachments = get_attached_media('image', $postId);
        $postAttachmentIdByHash = [];
        foreach ($postAttachments as $postAttachment) {
            $postAttachmentPath = get_attached_file($postAttachment->ID, true);
            if (file_exists($postAttachmentPath)) {
                $postAttachmentHash = basename($postAttachmentPath) . md5_file($postAttachmentPath);
                $postAttachmentIdByHash[$postAttachmentHash] = $postAttachment->ID;
                if (isset($attachmentPathByHash[$postAttachmentHash])) {
                    unset($attachmentPathByHash[$postAttachmentHash]);
                    continue;
                }
            }

            $result = wp_delete_attachment($postAttachment->ID);
            if ($result === false) {
                ExchangeSupport::error('Failed to delete post attachment');
            }
        }

        $attachmentIds = [];
        foreach ($attachments as $attachmentPath => $attachment) {
            $attachmentPath = "$dataDir/$attachmentPath";
            if (!$this->isReadableImageFile($attachmentPath)) {
                continue;
            }
            if (!isset($attachmentHashByPath[$attachmentPath])) {
                continue;
            }

            $attachmentHash = $attachmentHashByPath[$attachmentPath];
            $attachmentId = @$postAttachmentIdByHash[$attachmentHash];
            if (!$attachmentId) {
                $file = [
                    'tmp_name' => $attachmentPath,
                    'name' => basename($attachmentPath),
                ];
                $attachmentId = @media_handle_sideload($file, $postId, @$attachment['description']);
                if (is_wp_error($attachmentId)) {
                    continue;
                }

                $uploadedAttachmentPath = get_attached_file($attachmentId);
                if ($uploadedAttachmentPath) {
                    copy($uploadedAttachmentPath, $attachmentPath);
                }
            }

            $attachmentIds[] = $attachmentId;
        }

        return $attachmentIds;
    }

    /**
     * Lowercase uppercase letters in requisite names for display attributes.
     *
     * @param array<int, string> $matches Regex capture groups.
     *
     * @return string Transformed match with leading space.
     */
    public function replaceRequisiteNameCallback(array $matches): string
    {
        return ' ' . mb_convert_case($matches[0], MB_CASE_LOWER, 'UTF-8');
    }

    /**
     * @param array<int, array<string, mixed>> $subproducts
     */
    private function replaceSubproducts($isFull, array $subproducts): void
    {
        ExchangeSupport::replaceSuboffers($isFull, $subproducts, true);
    }

    /**
     * @param mixed $isFull
     */
    private function cleanWoocommerceCategories($isFull): void
    {
        global $wpdb;

        if (!$isFull || WC1C_PREVENT_CLEAN) {
            return;
        }

        $termIds = $wpdb->get_col($wpdb->prepare(
            "SELECT tm.term_id FROM $wpdb->termmeta tm JOIN $wpdb->term_taxonomy tt ON tm.term_id = tt.term_id WHERE taxonomy = 'product_cat' AND meta_key = 'wc1c_timestamp' AND meta_value != %d",
            WC1C_TIMESTAMP
        ));
        ExchangeSupport::checkWpdbError();

        $termIds = apply_filters('wc1c_clean_categories', $termIds);
        if (!$termIds) {
            return;
        }

        foreach ($termIds as $termId) {
            $result = wp_delete_term($termId, 'product_cat');
            ExchangeSupport::checkWpError($result);
        }
    }

    /**
     * @param mixed $isFull
     */
    private function cleanWoocommerceAttributes($isFull): void
    {
        global $wpdb;

        if (!$isFull || WC1C_PREVENT_CLEAN) {
            return;
        }

        $timestamps = get_option('wc1c_timestamp_attributes', []);
        if (!$timestamps) {
            return;
        }

        $guids = get_option('wc1c_guid_attributes', []);

        $attributeIds = [];
        foreach ($timestamps as $guid => $timestamp) {
            if ($timestamp != WC1C_TIMESTAMP) {
                $attributeIds[] = $guids[$guid];
            }
        }

        $attributeIds = apply_filters('wc1c_clean_attributes', $attributeIds);
        if (!$attributeIds) {
            return;
        }

        foreach ($attributeIds as $attributeId) {
            $attribute = ExchangeSupport::woocommerceAttributeById((int) $attributeId);
            if (!$attribute) {
                continue;
            }

            ExchangeSupport::deleteWoocommerceAttribute((int) $attributeId);

            unset($guids[$guid]);
            unset($timestamps[$guid]);

            $isDeleted = true;
        }

        if (!empty($isDeleted)) {
            $orders = get_option('wc1c_order_attributes', []);
            $orderIndex = array_search($attributeId, $orders);
            if ($orderIndex !== false) {
                unset($orders[$orderIndex]);
                update_option('wc1c_order_attributes', $orders);
            }

            update_option('wc1c_guid_attributes', $guids);
            update_option('wc1c_timestamp_attributes', $timestamps);
        }
    }

    /**
     * @param mixed $isFull
     */
    private function cleanWoocommerceAttributeOptions($isFull, string $attributeTaxonomy): void
    {
        global $wpdb;

        if (!$isFull || WC1C_PREVENT_CLEAN) {
            return;
        }

        $termIds = $wpdb->get_col($wpdb->prepare(
            "SELECT tm.term_id FROM $wpdb->termmeta tm JOIN $wpdb->term_taxonomy tt ON tm.term_id = tt.term_id WHERE taxonomy = %s AND meta_key = 'wc1c_timestamp' AND meta_value != %d",
            $attributeTaxonomy,
            WC1C_TIMESTAMP
        ));
        ExchangeSupport::checkWpdbError();

        foreach ($termIds as $termId) {
            $result = wp_delete_term($termId, $attributeTaxonomy);
            ExchangeSupport::checkWpError($result);
        }
    }

    private function cleanPosts(string $postType): void
    {
        global $wpdb;

        $postIds = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta JOIN $wpdb->posts ON post_id = ID WHERE post_type = %s AND meta_key = '_wc1c_timestamp' AND meta_value != %d",
            $postType,
            WC1C_TIMESTAMP
        ));
        ExchangeSupport::checkWpdbError();

        foreach ($postIds as $postId) {
            wp_trash_post($postId);
        }
    }

    /**
     * @param mixed $isFull
     */
    private function cleanProducts($isFull): void
    {
        if (!$isFull || WC1C_PREVENT_CLEAN) {
            return;
        }

        $this->cleanPosts('product');
    }

    private function cleanProductTerms(): void
    {
        global $wpdb;

        $wpdb->query("UPDATE $wpdb->term_taxonomy tt SET count = (SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id = tt.term_taxonomy_id) WHERE taxonomy LIKE 'pa_%'");
        ExchangeSupport::checkWpdbError();

        $rows = $wpdb->get_results("SELECT tm.term_id, taxonomy FROM $wpdb->term_taxonomy tt LEFT JOIN $wpdb->termmeta tm ON tt.term_id = tm.term_id AND meta_key = 'wc1c_guid' WHERE meta_value IS NULL AND taxonomy LIKE 'pa_%' AND count = 0");
        ExchangeSupport::checkWpdbError();

        foreach ($rows as $row) {
            register_taxonomy($row->taxonomy, null);
            $result = wp_delete_term($row->term_id, $row->taxonomy);
            ExchangeSupport::checkWpError($result);
        }
    }
}
