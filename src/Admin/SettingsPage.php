<?php

declare(strict_types=1);

namespace Woo1cSync\Admin;

use Woo1cSync\Services\SettingsService;

/**
 * Registers the WooCommerce 1C sync settings admin page and related hooks.
 */
final class SettingsPage
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly string $pluginFile,
    ) {
    }

    /**
     * Register WordPress admin hooks for the settings UI.
     */
    public function registerHooks(): void
    {
        add_filter('plugin_action_links_' . WC1C_PLUGIN_BASENAME, [$this, 'pluginActionLinks']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('wp_redirect', [$this, 'preserveTabOnRedirect'], 10, 2);
    }

    /**
     * Enqueue admin styles on the plugin settings page.
     *
     * @param string $hook
     */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_woo-1c-sync') {
            return;
        }

        $pluginData = get_plugin_data($this->pluginFile);
        wp_enqueue_style(
            'woo-1c-sync-admin',
            plugins_url('assets/css/admin-settings.css', $this->pluginFile),
            [],
            $pluginData['Version'],
        );
    }

    /**
     * Add a settings link on the plugins list screen.
     *
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function pluginActionLinks(array $actions): array
    {
        $actionsBefore = [
            'settings' => sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=woo-1c-sync'),
                esc_html__('Настройки', 'woo-1c-sync'),
            ),
        ];

        return array_merge($actionsBefore, $actions);
    }

    /**
     * Register the settings option group and sanitizer.
     */
    public function registerSettings(): void
    {
        register_setting('wc1c_settings_group', 'wc1c_settings', [$this->settingsService, 'sanitize']);
    }

    /**
     * Add the top-level admin menu page.
     */
    public function registerMenu(): void
    {
        add_menu_page(
            __('1С', 'woo-1c-sync'),
            __('1С', 'woo-1c-sync'),
            'manage_woocommerce',
            'woo-1c-sync',
            [$this, 'renderPage'],
            'dashicons-update',
            56,
        );
    }

    /**
     * Keep the active tab in the URL after saving settings via options.php.
     *
     * @param string $location
     * @param int $status
     */
    public function preserveTabOnRedirect($location, $status): string
    {
        if (
            is_string($location)
            && strpos($location, 'page=woo-1c-sync') !== false
            && isset($_POST['wc1c_settings']['_active_section'])
        ) {
            $location = add_query_arg(
                'tab',
                sanitize_key((string) $_POST['wc1c_settings']['_active_section']),
                $location,
            );
        }

        return $location;
    }

    /**
     * Render a question-mark help tooltip.
     */
    private function renderHelpTip(string $text): void
    {
        if ($text === '') {
            return;
        }

        printf(
            '<span class="wc1c-help-tip" tabindex="0" role="button" aria-label="%1$s"><span class="wc1c-help-tip-text">%2$s</span>?</span>',
            esc_attr(wp_strip_all_tags($text)),
            esc_html($text),
        );
    }

    /**
     * Render a table header label with an optional help tooltip.
     */
    private function renderThLabel(string $label, string $for = '', ?string $help = null): void
    {
        if ($for !== '') {
            printf('<label for="%s">%s</label>', esc_attr($for), esc_html($label));
        } else {
            echo esc_html($label);
        }

        if ($help !== null && $help !== '') {
            $this->renderHelpTip($help);
        }
    }

    /**
     * Render a single settings field control.
     *
     * @param array<string, mixed> $field
     * @param mixed $value
     */
    public function renderField(string $key, array $field, $value): void
    {
        $name = 'wc1c_settings[' . esc_attr($key) . ']';
        $id = 'wc1c_setting_' . esc_attr($key);

        switch ($field['type']) {
            case 'bool':
                printf(
                    '<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
                    $id,
                    $name,
                    checked(!empty($value), true, false),
                    esc_html__('Включено', 'woo-1c-sync'),
                );
                break;

            case 'select':
                printf('<select id="%s" name="%s">', $id, $name);
                foreach ($field['options'] as $optionValue => $optionLabel) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr((string) $optionValue),
                        selected($value, $optionValue, false),
                        esc_html((string) $optionLabel),
                    );
                }
                echo '</select>';
                break;

            case 'nullable_string':
                printf(
                    '<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" />',
                    $id,
                    $name,
                    esc_attr($value === null ? '' : (string) $value),
                    esc_attr(isset($field['placeholder']) ? (string) $field['placeholder'] : ''),
                );
                break;
        }

        if (!empty($field['description'])) {
            printf('<p class="description">%s</p>', esc_html($field['description']));
        }

        if (!empty($field['constant'])) {
            $definedInConfig = $this->settingsService->isConfigOverride($field['constant']);
            printf(
                '<p class="description"><code>%s</code>%s</p>',
                esc_html($field['constant']),
                $definedInConfig ? ' — ' . esc_html__('переопределено в wp-config.php', 'woo-1c-sync') : '',
            );
        }
    }

    /**
     * Return the active settings tab key from the request.
     */
    private function activeTab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'connection';
        $sections = $this->settingsService->sectionTitles();

        return array_key_exists($tab, $sections) ? $tab : 'connection';
    }

    /**
     * Build the admin URL for a settings tab.
     */
    private function tabUrl(string $tab): string
    {
        return add_query_arg(
            [
                'page' => 'woo-1c-sync',
                'tab' => $tab,
            ],
            admin_url('admin.php'),
        );
    }

    /**
     * Render the tab navigation bar.
     *
     * @param array<string, string> $sections
     */
    private function renderTabs(string $activeTab, array $sections): void
    {
        echo '<nav class="nav-tab-wrapper wc1c-nav-tabs" aria-label="' . esc_attr__('Разделы настроек', 'woo-1c-sync') . '">';
        foreach ($sections as $sectionKey => $sectionTitle) {
            $isActive = $sectionKey === $activeTab;
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($this->tabUrl($sectionKey)),
                $isActive ? ' nav-tab-active' : '',
                esc_html($sectionTitle),
            );
        }
        echo '</nav>';
    }

    /**
     * Render settings fields for a schema section inside a form.
     *
     * @param array<string, array<string, mixed>> $grouped
     * @param array<string, mixed> $settings
     */
    private function renderSettingsSection(
        string $sectionKey,
        array $grouped,
        array $settings,
    ): void {
        if (empty($grouped[$sectionKey])) {
            return;
        }

        ?>
        <form method="post" action="options.php">
          <?php settings_fields('wc1c_settings_group'); ?>
          <input type="hidden" name="wc1c_settings[_active_section]" value="<?php echo esc_attr($sectionKey); ?>" />
          <table class="form-table" role="presentation">
            <?php foreach ($grouped[$sectionKey] as $key => $field): ?>
              <tr>
                <th scope="row"><?php $this->renderThLabel($field['label'], 'wc1c_setting_' . $key, $field['help'] ?? null); ?></th>
                <td><?php $this->renderField($key, $field, $settings[$key]); ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
          <?php submit_button(__('Сохранить настройки', 'woo-1c-sync')); ?>
        </form>
        <?php
    }

    /**
     * Render the connection tab content.
     *
     * @param array<string, string> $urls
     */
    private function renderConnectionTab(array $urls): void
    {
        ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php $this->renderThLabel(__('URL обмена', 'woo-1c-sync'), '', __('Самые частые ошибки: 404 Not Found (не сохранены постоянные ссылки), слишком длинный URL для поля в 1С (используйте /e или /wc1c/exc), HTTPS с просроченным сертификатом. После обновления плагина обязательно: Настройки → Постоянные ссылки → Сохранить.', 'woo-1c-sync')); ?></th>
            <td>
              <p><strong><?php esc_html_e('Для 1С (макс. 35 символов):', 'woo-1c-sync'); ?></strong></p>
              <p><code><?php echo esc_html($urls['tiny']); ?></code> (<?php echo strlen($urls['tiny']); ?> <?php esc_html_e('симв.', 'woo-1c-sync'); ?>)</p>
              <p><code><?php echo esc_html($urls['truncated']); ?></code> (<?php echo strlen($urls['truncated']); ?> <?php esc_html_e('симв.', 'woo-1c-sync'); ?>)</p>
              <p><code><?php echo esc_html($urls['short']); ?></code></p>
              <p><code><?php echo esc_html($urls['pretty']); ?></code></p>
              <p><code><?php echo esc_html($urls['pretty_sync']); ?></code></p>
              <p class="description"><?php esc_html_e('После обновления плагина: Настройки → Постоянные ссылки → Сохранить. Требуется для nginx (не только .htaccess).', 'woo-1c-sync'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php $this->renderThLabel(__('Аутентификация', 'woo-1c-sync'), '', __('Ошибки «No authentication credentials» и «Invalid cookie» чаще всего из-за FastCGI/nginx: заголовок Authorization не доходит до PHP. Не используйте подписчиков и редакторов — нужна роль «Управляющий магазином» или «Администратор». Пароль приложения WordPress не подойдёт — только обычный пароль пользователя.', 'woo-1c-sync')); ?></th>
            <td>
              <p><?php esc_html_e('Используйте логин и пароль пользователя WordPress с ролью «Управляющий магазином» или «Администратор».', 'woo-1c-sync'); ?></p>
              <p class="description"><?php esc_html_e('Если аутентификация не работает на FastCGI, добавьте эту строку в .htaccess после RewriteEngine On:', 'woo-1c-sync'); ?></p>
              <p><code>RewriteRule . - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php $this->renderThLabel(__('Каталог данных', 'woo-1c-sync'), '', __('Сюда 1С загружает XML и ZIP. Если обмен падает на этапе file — проверьте права на запись в wp-content/uploads/ и свободное место на диске. Каталог закрыт от прямого доступа через .htaccess.', 'woo-1c-sync')); ?></th>
            <td><code><?php echo esc_html(WC1C_DATA_DIR); ?></code></td>
          </tr>
          <tr>
            <th scope="row"><?php $this->renderThLabel(__('Лимиты сервера', 'woo-1c-sync'), '', __('При «Failed to save file» или таймауте увеличьте post_max_size, upload_max_filesize, memory_limit и max_execution_time у хостинга. Лимит в настройках обмена не может быть больше post_max_size. Для больших каталогов настройте пакетную выгрузку в 1С.', 'woo-1c-sync')); ?></th>
            <td>
              <ul style="margin:0;">
                <li>post_max_size: <code><?php echo esc_html(ini_get('post_max_size')); ?></code></li>
                <li>upload_max_filesize: <code><?php echo esc_html(ini_get('upload_max_filesize')); ?></code></li>
                <li>memory_limit: <code><?php echo esc_html(ini_get('memory_limit')); ?></code></li>
                <li>max_execution_time: <code><?php echo esc_html(ini_get('max_execution_time')); ?></code></li>
              </ul>
            </td>
          </tr>
        </table>
        <?php
    }

    /**
     * Render the tools tab content.
     *
     * @param array<string, string> $urls
     */
    private function renderToolsTab(array $urls): void
    {
        ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php $this->renderThLabel(__('Очистить данные обмена', 'woo-1c-sync'), '', __('Необратимая операция: удаляются все категории, атрибуты и товары, созданные плагином (по метке _wc1c_guid). Заказы и ручные товары без GUID не затрагиваются. Сделайте резервную копию перед очисткой.', 'woo-1c-sync')); ?></th>
            <td>
              <p><?php esc_html_e('Удаляет категории, атрибуты и товары, созданные плагином.', 'woo-1c-sync'); ?></p>
              <p>
                <a class="button button-secondary" href="<?php echo esc_url($urls['clean']); ?>" target="_blank" rel="noopener noreferrer">
                  <?php esc_html_e('Открыть страницу очистки', 'woo-1c-sync'); ?>
                </a>
              </p>
            </td>
          </tr>
        </table>
        <?php
    }

    /**
     * Render the full settings admin page.
     */
    public function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = $this->settingsService->getSettings();
        $schema = $this->settingsService->schema();
        $sections = $this->settingsService->sectionTitles();
        $urls = $this->settingsService->exchangeUrls();
        $activeTab = $this->activeTab();
        $grouped = [];

        foreach ($schema as $key => $field) {
            $grouped[$field['section']][$key] = $field;
        }

        $overridden = $this->settingsService->configOverrides();
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('Настройки обмена с 1С', 'woo-1c-sync'); ?></h1>

          <?php if ($overridden): ?>
            <div class="notice notice-warning">
              <p><?php esc_html_e('Некоторые опции переопределены константами в wp-config.php и не могут быть изменены здесь:', 'woo-1c-sync'); ?>
                <code><?php echo esc_html(implode(', ', $overridden)); ?></code>
              </p>
            </div>
          <?php endif; ?>

          <?php $this->renderTabs($activeTab, $sections); ?>

          <div class="wc1c-settings-tab-content" style="margin-top: 1em;">
            <?php
            switch ($activeTab) {
                case 'connection':
                    $this->renderConnectionTab($urls);
                    break;

                case 'tools':
                    $this->renderToolsTab($urls);
                    break;

                default:
                    $this->renderSettingsSection($activeTab, $grouped, $settings);
                    break;
            }
            ?>
          </div>
        </div>
        <?php
    }
}
