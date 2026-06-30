<?php

declare(strict_types=1);

/**
 * Legacy global function wrappers for backward compatibility with handler bootstraps.
 */

use Woo1cSync\Exchange\ExchangeService;
use Woo1cSync\Plugin;

function wc1c_query_vars(array $query_vars): array
{
    return ExchangeService::instance()->queryVars($query_vars);
}

function wc1c_is_debug(): bool
{
    return ExchangeService::instance()->isDebug();
}

function wc1c_wpdb_end(bool $is_commit = false, bool $no_check = false): void
{
    ExchangeService::instance()->wpdbEnd($is_commit, $no_check);
}

function wc1c_full_request_uri(): string
{
    return ExchangeService::instance()->fullRequestUri();
}

function wc1c_error(string $message, string $type = 'Error', bool $no_exit = false): void
{
    ExchangeService::instance()->error($message, $type, $no_exit);
}

function wc1c_set_strict_mode(): void
{
    ExchangeService::instance()->setStrictMode();
}

function wc1c_set_output_callback(): void
{
    ExchangeService::instance()->setOutputCallback();
}

function wc1c_fix_fastcgi_get(): void
{
    ExchangeService::instance()->fixFastcgiGet();
}

/**
 * @param array<int, string> $names
 */
function wc1c_xml_parent_name(array $names, int $depth, int $ancestor = 1): ?string
{
    return ExchangeService::instance()->xmlParentName($names, $depth, $ancestor);
}

function wc1c_cleanup_transients(): void
{
    ExchangeService::instance()->cleanupTransients();
}

/**
 * @param array<string|int, mixed> $array
 * @param string|int $key
 */
function wc1c_xml_append(array &$array, $key, string $data): void
{
    ExchangeService::instance()->xmlAppend($array, $key, $data);
}

/**
 * @param array<int, array<string, mixed>> $array
 */
function wc1c_xml_append_nested(array &$array, int $index, string $key, string $data): void
{
    ExchangeService::instance()->xmlAppendNested($array, $index, $key, $data);
}

function wc1c_cleanup_dir(string $path_dir): void
{
    ExchangeService::instance()->cleanupDir($path_dir);
}

function wc1c_check_permissions($user): void
{
    ExchangeService::instance()->checkPermissions($user);
}

function wc1c_wp_error($wp_error, ?string $only_error_code = null): void
{
    ExchangeService::instance()->wpError($wp_error, $only_error_code);
}

function wc1c_check_wp_error($wp_error): void
{
    ExchangeService::instance()->checkWpError($wp_error);
}

function wc1c_mode_checkauth(): void
{
    ExchangeService::instance()->modeCheckauth();
}

function wc1c_check_auth(): void
{
    ExchangeService::instance()->checkAuth();
}

/**
 * @param string|int $filesize
 */
function wc1c_filesize_to_bytes($filesize): int
{
    return ExchangeService::instance()->filesizeToBytes($filesize);
}

function wc1c_mode_init(string $type): void
{
    ExchangeService::instance()->modeInit($type);
}

function wc1c_mode_file(string $type, string $filename): void
{
    ExchangeService::instance()->modeFile($type, $filename);
}

function wc1c_check_wpdb_error(): void
{
    ExchangeService::instance()->checkWpdbError();
}

function wc1c_disable_time_limit(): void
{
    ExchangeService::instance()->disableTimeLimit();
}

function wc1c_set_transaction_mode(): void
{
    ExchangeService::instance()->setTransactionMode();
}

function wc1c_transaction_shutdown_function(): void
{
    ExchangeService::instance()->transactionShutdown();
}

function wc1c_unpack_files(string $type): void
{
    ExchangeService::instance()->unpackFiles($type);
}

function wc1c_mode_import(string $type, string $filename, ?string $namespace = null): void
{
    ExchangeService::instance()->modeImport($type, $filename, $namespace);
}

/**
 * @param mixed $value
 *
 * @return int|string|null
 */
function wc1c_post_id_by_meta(string $key, $value)
{
    return ExchangeService::instance()->postIdByMeta($key, $value);
}

function wc1c_mode_query(string $type): void
{
    ExchangeService::instance()->modeQuery($type);
}

function wc1c_mode_success(string $type): void
{
    ExchangeService::instance()->modeSuccess($type);
}

function wc1c_exchange(): void
{
    ExchangeService::instance()->exchange();
}

function wc1c_template_redirect(): void
{
    ExchangeService::instance()->templateRedirect();
}

function wc1c_settings_schema(): array
{
    return Plugin::instance()->settings()->schema();
}

function wc1c_get_settings(): array
{
    return Plugin::instance()->settings()->getSettings();
}

/**
 * @param mixed $input
 */
function wc1c_sanitize_settings($input): array
{
    return Plugin::instance()->settings()->sanitize($input);
}

function wc1c_apply_settings(): void
{
    Plugin::instance()->settings()->applySettings();
}

function wc1c_settings_section_titles(): array
{
    return Plugin::instance()->settings()->sectionTitles();
}

function wc1c_exchange_urls(): array
{
    return Plugin::instance()->settings()->exchangeUrls();
}

function wc1c_add_rewrite_rules(): void
{
    Plugin::instance()->attributes()->addRewriteRules();
}

function wc1c_delete_term(int $term_id, $tt_id, string $taxonomy, $deleted_term): void
{
    Plugin::instance()->attributes()->deleteTerm($term_id, $tt_id, $taxonomy, $deleted_term);
}

/**
 * @return array<string, mixed>|null
 */
function wc1c_woocommerce_attribute_by_id(int $attribute_id): ?array
{
    return Plugin::instance()->attributes()->woocommerceAttributeById($attribute_id);
}

function wc1c_delete_woocommerce_attribute(int $attribute_id): bool
{
    return Plugin::instance()->attributes()->deleteWoocommerceAttribute($attribute_id);
}

function wc1c_parse_decimal(string $number): float
{
    return Plugin::instance()->attributes()->parseDecimal($number);
}
