<?php

declare(strict_types=1);

namespace Woo1cSync;

use Woo1cSync\Admin\SettingsPage;
use Woo1cSync\Exchange\ExchangeService;
use Woo1cSync\Services\AttributeService;
use Woo1cSync\Services\CleanupService;
use Woo1cSync\Services\SettingsService;

/**
 * Main plugin bootstrap: autoloading, constants, services, and WordPress hooks.
 */
final class Plugin
{
    private static ?self $instance = null;

    private string $pluginFile;

    private SettingsService $settings;

    private ExchangeService $exchange;

    private AttributeService $attributes;

    private CleanupService $cleanup;

    private SettingsPage $settingsPage;

    /**
     * Boot the plugin from the main WordPress plugin file.
     */
    public static function boot(string $pluginFile): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        self::loadAutoloader($pluginFile);
        self::defineConstants($pluginFile);

        self::$instance = new self($pluginFile);
        require_once dirname($pluginFile) . '/src/Legacy/functions.php';
        self::$instance->register();
    }

    /**
     * Return the running plugin instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Plugin has not been booted.');
        }

        return self::$instance;
    }

    /**
     * Return the cleanup service.
     */
    public function cleanup(): CleanupService
    {
        return $this->cleanup;
    }

    /**
     * Return the settings service.
     */
    public function settings(): SettingsService
    {
        return $this->settings;
    }

    /**
     * Return the attribute service.
     */
    public function attributes(): AttributeService
    {
        return $this->attributes;
    }

    /**
     * Return the exchange service.
     */
    public function exchange(): ExchangeService
    {
        return $this->exchange;
    }

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->settings = new SettingsService();
        $this->exchange = ExchangeService::instance();
        $this->attributes = new AttributeService();
        $this->cleanup = new CleanupService($this->exchange, $this->attributes);
        $this->settingsPage = new SettingsPage($this->settings, $pluginFile);
    }

    /**
     * Register WordPress hooks and apply runtime configuration.
     */
    private function register(): void
    {
        $this->defineExchangeFallbackConstants();
        $this->settings->applySettings();

        add_action('init', [$this, 'onInit']);
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
        add_filter('plugin_locale', [$this, 'pluginLocale'], 10, 2);

        register_activation_hook($this->pluginFile, [$this->attributes, 'activate']);
        register_deactivation_hook($this->pluginFile, 'flush_rewrite_rules');

        $this->exchange->registerHooks();
        $this->attributes->registerHooks();
        $this->settingsPage->registerHooks();
    }

    /**
     * Check WooCommerce dependency and show admin notice when missing.
     */
    public function onInit(): void
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active('woocommerce/woocommerce.php')) {
            add_action('admin_notices', [$this, 'woocommerceAdminNotice']);
        }
    }

    /**
     * Load translations and define the plugin version constant.
     */
    public function onPluginsLoaded(): void
    {
        $pluginData = get_plugin_data($this->pluginFile);
        $languagesDir = WC1C_PLUGIN_BASEDIR . $pluginData['DomainPath'];
        load_plugin_textdomain('woo-1c-sync', false, $languagesDir);

        $revision = trim(str_replace('Revision', '', '$Revision$'), '$: ');
        if (!defined('WC1C_VERSION')) {
            define('WC1C_VERSION', sprintf('%sr%s', $pluginData['Version'], $revision));
        }
    }

    /**
     * Force Russian locale for this plugin unless English is already selected.
     *
     * @param string $locale
     * @param string $domain
     */
    public function pluginLocale(string $locale, string $domain): string
    {
        if ($domain !== 'woo-1c-sync') {
            return $locale;
        }
        if (strpos($locale, 'en_') === 0) {
            return $locale;
        }

        return 'ru_RU';
    }

    /**
     * Display a notice when WooCommerce is not active.
     */
    public function woocommerceAdminNotice(): void
    {
        $pluginData = get_plugin_data($this->pluginFile);
        $message = sprintf(
            __('Плагин <strong>%s</strong> требует установки и активации плагина <strong>WooCommerce</strong>.', 'woo-1c-sync'),
            $pluginData['Name'],
        );
        printf('<div class="updated"><p>%s</p></div>', $message);
    }

    /**
     * Register the PSR-4 autoloader for plugin classes.
     */
    private static function loadAutoloader(string $pluginFile): void
    {
        require_once dirname($pluginFile) . '/src/Autoloader.php';
        Autoloader::register(dirname($pluginFile));
    }

    /**
     * Define core plugin path and data directory constants.
     */
    private static function defineConstants(string $pluginFile): void
    {
        if (!function_exists('plugin_basename')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!defined('WC1C_PLUGIN_DIR')) {
            define('WC1C_PLUGIN_DIR', dirname($pluginFile) . '/');
        }
        if (!defined('WC1C_PLUGIN_BASENAME')) {
            define('WC1C_PLUGIN_BASENAME', plugin_basename($pluginFile));
        }
        if (!defined('WC1C_PLUGIN_BASEDIR')) {
            define('WC1C_PLUGIN_BASEDIR', dirname(WC1C_PLUGIN_BASENAME) . '/');
        }
        if (!defined('WC1C_DATA_DIR')) {
            $uploadDir = wp_upload_dir();
            define('WC1C_DATA_DIR', "{$uploadDir['basedir']}/woo-1c-sync/");
        }
    }

    /**
     * Define exchange constants not covered by nullable settings fields.
     */
    private function defineExchangeFallbackConstants(): void
    {
        $fallbacks = [
            'WC1C_SUPPRESS_NOTICES' => false,
            'WC1C_FILE_LIMIT' => null,
            'WC1C_XML_CHARSET' => 'UTF-8',
            'WC1C_DISABLE_VARIATIONS' => false,
            'WC1C_OUTOFSTOCK_STATUS' => 'outofstock',
            'WC1C_MANAGE_STOCK' => 'yes',
            'WC1C_CLEANUP_GARBAGE' => true,
        ];

        foreach ($fallbacks as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
                SettingsService::markPluginDefined($name);
            }
        }
    }
}
