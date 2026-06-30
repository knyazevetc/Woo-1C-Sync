<?php

declare(strict_types=1);

namespace Woo1cSync\Services;

/**
 * Manages plugin settings schema, persistence, sanitization, and constant application.
 */
final class SettingsService
{
    /**
     * Constants defined by this plugin (defaults or saved settings), not wp-config.php.
     *
     * @var array<int, string>
     */
    private static array $pluginDefinedConstants = [];

    /**
     * Remember that the plugin itself defined a settings constant.
     */
    public static function markPluginDefined(string $constant): void
    {
        if (!in_array($constant, self::$pluginDefinedConstants, true)) {
            self::$pluginDefinedConstants[] = $constant;
        }
    }

    /**
     * Whether a constant was set outside the plugin (typically wp-config.php).
     */
    public function isConfigOverride(string $constant): bool
    {
        return defined($constant) && !in_array($constant, self::$pluginDefinedConstants, true);
    }

    /**
     * Return constants overridden outside the plugin.
     *
     * @return array<int, string>
     */
    public function configOverrides(): array
    {
        $overrides = [];
        foreach ($this->schema() as $field) {
            if ($this->isConfigOverride($field['constant'])) {
                $overrides[] = $field['constant'];
            }
        }

        return $overrides;
    }

    /**
     * Return the settings field schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        return [
            'suppress_notices' => [
                'constant' => 'WC1C_SUPPRESS_NOTICES',
                'type' => 'bool',
                'default' => false,
                'section' => 'exchange',
                'label' => __('Подавлять PHP notices', 'woo-1c-sync'),
                'description' => __('Скрывать некритичные PHP-уведомления во время обмена.', 'woo-1c-sync'),
                'help' => __('Если 1С пишет «ошибка разбора ответа» или вместо success приходит HTML с предупреждениями PHP — включите эту опцию. Также проверьте, что на сайте нет вывода до ответа плагина (пробелы в wp-config, отладочные плагины).', 'woo-1c-sync'),
            ],
            'file_limit' => [
                'constant' => 'WC1C_FILE_LIMIT',
                'type' => 'nullable_string',
                'default' => null,
                'section' => 'exchange',
                'label' => __('Лимит размера файла', 'woo-1c-sync'),
                'description' => __('Дополнительный лимит для 1С (например, 100M). Пусто — только лимиты сервера.', 'woo-1c-sync'),
                'placeholder' => '100M',
                'help' => __('При ошибке загрузки файла или обрыве на больших каталогах уменьшите лимит (например, 10M), чтобы 1С дробила выгрузку на части. Значение не должно превышать post_max_size и memory_limit на сервере.', 'woo-1c-sync'),
            ],
            'xml_charset' => [
                'constant' => 'WC1C_XML_CHARSET',
                'type' => 'select',
                'default' => 'UTF-8',
                'section' => 'exchange',
                'label' => __('Кодировка XML', 'woo-1c-sync'),
                'description' => __('Кодировка ответов при обмене с 1С.', 'woo-1c-sync'),
                'help' => __('Если в 1С «кракозябры» в заказах или ошибка кодировки — попробуйте windows-1251. Для современных конфигураций УТ обычно достаточно UTF-8. Кодировка должна совпадать с настройками обмена в 1С.', 'woo-1c-sync'),
                'options' => [
                    'UTF-8' => 'UTF-8',
                    'windows-1251' => 'Windows-1251',
                ],
            ],
            'disable_variations' => [
                'constant' => 'WC1C_DISABLE_VARIATIONS',
                'type' => 'bool',
                'default' => false,
                'section' => 'exchange',
                'label' => __('Отключить вариации', 'woo-1c-sync'),
                'description' => __('Импортировать товары с «#» в GUID как простые товары, без вариаций.', 'woo-1c-sync'),
                'help' => __('Включайте, если вариации дублируются, не создаются или ломают карточку товара. Подходит для каталогов, где каждый размер/цвет выгружается как отдельный товар без характеристик.', 'woo-1c-sync'),
            ],
            'outofstock_status' => [
                'constant' => 'WC1C_OUTOFSTOCK_STATUS',
                'type' => 'select',
                'default' => 'outofstock',
                'section' => 'exchange',
                'label' => __('Статус «нет в наличии»', 'woo-1c-sync'),
                'description' => __('Статус WooCommerce при нулевом остатке.', 'woo-1c-sync'),
                'help' => __('Если товары с нулевым остатком остаются «в наличии» или наоборот скрываются не так, как нужно — проверьте эту настройку и опцию «Управление запасами».', 'woo-1c-sync'),
                'options' => [
                    'outofstock' => 'outofstock',
                    'onbackorder' => 'onbackorder',
                    'instock' => 'instock',
                ],
            ],
            'manage_stock' => [
                'constant' => 'WC1C_MANAGE_STOCK',
                'type' => 'select',
                'default' => 'yes',
                'section' => 'exchange',
                'label' => __('Управление запасами', 'woo-1c-sync'),
                'description' => __('Включать учёт остатков у импортированных товаров.', 'woo-1c-sync'),
                'help' => __('При значении «no» WooCommerce не будет учитывать количество из offers.xml — остатки перестанут обновляться. Оставляйте «yes», если синхронизируете склад из 1С.', 'woo-1c-sync'),
                'options' => [
                    'yes' => 'yes',
                    'no' => 'no',
                ],
            ],
            'cleanup_garbage' => [
                'constant' => 'WC1C_CLEANUP_GARBAGE',
                'type' => 'bool',
                'default' => true,
                'section' => 'exchange',
                'label' => __('Очищать временные файлы', 'woo-1c-sync'),
                'description' => __('Удалять старые файлы обмена в начале каждой сессии.', 'woo-1c-sync'),
                'help' => __('Если обмен обрывается на полпути и следующая выгрузка «залипает» на старых файлах — оставьте включённым. Отключайте только при отладке, когда нужно вручную смотреть XML в каталоге данных.', 'woo-1c-sync'),
            ],
            'currency' => [
                'constant' => 'WC1C_CURRENCY',
                'type' => 'nullable_string',
                'default' => null,
                'section' => 'orders',
                'label' => __('Валюта заказов', 'woo-1c-sync'),
                'description' => __('Код валюты для выгрузки заказов в 1С (например, RUB). Пусто — из заказа WooCommerce.', 'woo-1c-sync'),
                'placeholder' => 'RUB',
                'help' => __('Если 1С не принимает заказы или ругается на валюту — укажите код, который есть в справочнике 1С (RUB, USD, EUR). Код должен совпадать с настройками учёта в УТ.', 'woo-1c-sync'),
            ],
            'price_type' => [
                'constant' => 'WC1C_PRICE_TYPE',
                'type' => 'nullable_string',
                'default' => null,
                'section' => 'offers',
                'label' => __('Тип цены', 'woo-1c-sync'),
                'description' => __('GUID или название типа цены из 1С. Пусто — первая цена из выгрузки.', 'woo-1c-sync'),
                'placeholder' => '',
                'help' => __('Если на сайте неверная цена (опт вместо розницы) — скопируйте точное название или GUID типа цены из offers.xml или из 1С. При нескольких типах цен в выгрузке без этой настройки берётся первая попавшаяся.', 'woo-1c-sync'),
            ],
            'preserve_product_variations' => [
                'constant' => 'WC1C_PRESERVE_PRODUCT_VARIATIONS',
                'type' => 'bool',
                'default' => false,
                'section' => 'offers',
                'label' => __('Сохранять вариации при частичном обмене', 'woo-1c-sync'),
                'description' => __('Не удалять вариации, отсутствующие в частичной выгрузке offers.xml.', 'woo-1c-sync'),
                'help' => __('При частичной выгрузке остатков вариации могут пропадать с сайта. Включите, если 1С отдаёт не все предложения в каждом обмене. При полной выгрузке offers.xml эта опция не нужна.', 'woo-1c-sync'),
            ],
            'product_description_to_content' => [
                'constant' => 'WC1C_PRODUCT_DESCRIPTION_TO_CONTENT',
                'type' => 'bool',
                'default' => false,
                'section' => 'import',
                'label' => __('Описание в контент', 'woo-1c-sync'),
                'description' => __('Записывать описание товара из 1С в post_content (иначе в excerpt).', 'woo-1c-sync'),
                'help' => __('Зависит от темы WooCommerce: одни темы выводят полное описание (content), другие — краткое (excerpt). Если описание не видно на витрине — переключите эту опцию.', 'woo-1c-sync'),
            ],
            'prevent_clean' => [
                'constant' => 'WC1C_PREVENT_CLEAN',
                'type' => 'bool',
                'default' => false,
                'section' => 'import',
                'label' => __('Не удалять отсутствующие данные', 'woo-1c-sync'),
                'description' => __('При полной выгрузке не удалять категории, атрибуты и товары, которых нет в XML.', 'woo-1c-sync'),
                'help' => __('Защита от массового удаления: если полная выгрузка из 1С пришла неполной или пустой, без этой опции плагин может снести товары, которых нет в XML. Включайте на первом обмене или при сомнениях в выгрузке.', 'woo-1c-sync'),
            ],
            'update_post_name' => [
                'constant' => 'WC1C_UPDATE_POST_NAME',
                'type' => 'bool',
                'default' => false,
                'section' => 'import',
                'label' => __('Обновлять slug товара', 'woo-1c-sync'),
                'description' => __('Обновлять post_name (ЧПУ) при каждом импорте.', 'woo-1c-sync'),
                'help' => __('Включение меняет URL товаров при переименовании в 1С — ломаются закладки и SEO. Оставляйте выключенным, если ссылки уже проиндексированы. Включайте только при первичном импорте.', 'woo-1c-sync'),
            ],
            'match_by_sku' => [
                'constant' => 'WC1C_MATCH_BY_SKU',
                'type' => 'bool',
                'default' => false,
                'section' => 'import',
                'label' => __('Сопоставлять по артикулу', 'woo-1c-sync'),
                'description' => __('Искать существующий товар по SKU, если GUID не найден.', 'woo-1c-sync'),
                'help' => __('Полезно при переезде с другого плагина или ручном создании товаров. Риск: при совпадении артикулов разные товары из 1С могут «склеиться» в один. Артикулы должны быть уникальны.', 'woo-1c-sync'),
            ],
            'match_categories_by_title' => [
                'constant' => 'WC1C_MATCH_CATEGORIES_BY_TITLE',
                'type' => 'bool',
                'default' => false,
                'section' => 'import',
                'label' => __('Категории по названию', 'woo-1c-sync'),
                'description' => __('Сопоставлять категории по названию, если GUID не найден.', 'woo-1c-sync'),
                'help' => __('Если после импорта дублируются категории с одинаковыми названиями — выключите или сначала привяжите GUID вручную. Включайте при первом обмене с уже существующим деревом категорий на сайте.', 'woo-1c-sync'),
            ],
            'match_properties_by_title' => [
                'constant' => 'WC1C_MATCH_PROPERTIES_BY_TITLE',
                'type' => 'bool',
                'default' => false,
                'section' => 'import',
                'label' => __('Свойства по названию', 'woo-1c-sync'),
                'description' => __('Сопоставлять атрибуты по названию, если GUID не найден.', 'woo-1c-sync'),
                'help' => __('Аналогично категориям: помогает не плодить дубликаты атрибутов «Цвет», «Размер» и т.д. Одинаковые названия в 1С и WooCommerce должны означать одно и то же свойство.', 'woo-1c-sync'),
            ],
            'match_property_options_by_title' => [
                'constant' => 'WC1C_MATCH_PROPERTY_OPTIONS_BY_TITLE',
                'type' => 'bool',
                'default' => false,
                'section' => 'import',
                'label' => __('Значения свойств по названию', 'woo-1c-sync'),
                'description' => __('Сопоставлять значения атрибутов по названию, если GUID не найден.', 'woo-1c-sync'),
                'help' => __('Если у вариаций «плывут» значения (Красный vs красный) — проверьте эту опцию и «GUID как slug». Для кириллических slug в URL может понадобиться отключить GUID как slug.', 'woo-1c-sync'),
            ],
            'use_guid_as_property_option_slug' => [
                'constant' => 'WC1C_USE_GUID_AS_PROPERTY_OPTION_SLUG',
                'type' => 'bool',
                'default' => true,
                'section' => 'import',
                'label' => __('GUID как slug значения свойства', 'woo-1c-sync'),
                'description' => __('Использовать GUID 1С в slug терминов атрибутов.', 'woo-1c-sync'),
                'help' => __('По умолчанию включено для стабильного сопоставления. Отключите, если в URL фильтров появляются длинные GUID или ломается кириллица в ЧПУ — тогда slug будет из названия значения.', 'woo-1c-sync'),
            ],
            'multiple_values_delimeter' => [
                'constant' => 'WC1C_MULTIPLE_VALUES_DELIMETER',
                'type' => 'nullable_string',
                'default' => null,
                'section' => 'import',
                'label' => __('Разделитель множественных значений', 'woo-1c-sync'),
                'description' => __('Символ для разбиения строки на несколько значений свойства. Пусто — одно значение.', 'woo-1c-sync'),
                'placeholder' => ',',
                'help' => __('Если одно свойство в 1С приходит строкой «Красный;Синий», укажите разделитель (; или ,). Без разделителя всё запишется как одно значение атрибута.', 'woo-1c-sync'),
            ],
        ];
    }

    /**
     * Return merged settings from the database and schema defaults.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        static $settings = null;
        if ($settings !== null) {
            return $settings;
        }

        $saved = get_option('wc1c_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $settings = [];
        foreach ($this->schema() as $key => $field) {
            if (array_key_exists($key, $saved)) {
                $settings[$key] = $saved[$key];
            } else {
                $settings[$key] = $field['default'];
            }
        }

        return $settings;
    }

    /**
     * Sanitize settings input from the admin form.
     *
     * @param mixed $input Raw form input.
     *
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        $activeSection = isset($input['_active_section'])
            ? sanitize_key((string) $input['_active_section'])
            : null;
        unset($input['_active_section']);

        $existing = get_option('wc1c_settings', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $sanitized = [];
        foreach ($this->schema() as $key => $field) {
            $isActiveSection = $activeSection === null || $field['section'] === $activeSection;

            if (!$isActiveSection) {
                $sanitized[$key] = array_key_exists($key, $existing)
                    ? $existing[$key]
                    : $field['default'];
                continue;
            }

            switch ($field['type']) {
                case 'bool':
                    $sanitized[$key] = !empty($input[$key]);
                    break;

                case 'select':
                    $value = isset($input[$key]) ? $input[$key] : $field['default'];
                    $sanitized[$key] = array_key_exists($value, $field['options']) ? $value : $field['default'];
                    break;

                case 'nullable_string':
                    $value = isset($input[$key]) ? trim(wp_unslash((string) $input[$key])) : '';
                    $sanitized[$key] = $value === '' ? null : sanitize_text_field($value);
                    break;

                default:
                    $sanitized[$key] = isset($input[$key])
                        ? sanitize_text_field(wp_unslash((string) $input[$key]))
                        : $field['default'];
            }
        }

        return $sanitized;
    }

    /**
     * Define PHP constants from saved settings when not already defined in wp-config.
     */
    public function applySettings(): void
    {
        foreach ($this->schema() as $key => $field) {
            if (defined($field['constant'])) {
                continue;
            }

            $value = $this->getSettings()[$key];
            if ($field['type'] === 'nullable_string' && ($value === null || $value === '')) {
                continue;
            }

            define($field['constant'], $value);
            self::markPluginDefined($field['constant']);
        }
    }

    /**
     * Return human-readable section titles for the settings page.
     *
     * @return array<string, string>
     */
    public function sectionTitles(): array
    {
        return [
            'connection' => __('Подключение 1С', 'woo-1c-sync'),
            'exchange' => __('Обмен', 'woo-1c-sync'),
            'import' => __('Импорт каталога', 'woo-1c-sync'),
            'offers' => __('Цены и остатки', 'woo-1c-sync'),
            'orders' => __('Заказы', 'woo-1c-sync'),
            'tools' => __('Инструменты', 'woo-1c-sync'),
        ];
    }

    /**
     * Return exchange endpoint URLs for display in admin.
     *
     * @return array<string, string>
     */
    public function exchangeUrls(): array
    {
        return [
            'tiny' => home_url('/e'),
            'truncated' => home_url('/wc1c/exc'),
            'short' => home_url('/?wc1c=exchange'),
            'pretty' => home_url('/wc1c/exchange/'),
            'pretty_sync' => home_url('/woo-1c-sync/exchange/'),
            'clean' => home_url('/?wc1c=clean'),
            'clean_pretty' => home_url('/wc1c/clean'),
            'clean_sync' => home_url('/woo-1c-sync/clean/'),
        ];
    }
}
