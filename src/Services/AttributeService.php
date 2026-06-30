<?php

declare(strict_types=1);

namespace Woo1cSync\Services;

/**
 * WooCommerce attribute helpers, rewrite rules, activation logic, and numeric parsing.
 */
final class AttributeService
{
    /**
     * Register WordPress hooks for attributes, rewrite rules, and term cleanup.
     */
    public function registerHooks(): void
    {
        add_action('init', [$this, 'addRewriteRules'], 1000);
        add_action('delete_term', [$this, 'deleteTerm'], 10, 4);
        add_filter('woocommerce_attribute_taxonomies', [$this, 'woocommerceAttributeTaxonomies']);
        add_action('woocommerce_attribute_deleted', [$this, 'woocommerceAttributeDeleted'], 10, 3);
    }

    /**
     * Run plugin activation tasks: DB indexes, data directory, rewrite rules.
     */
    public function activate(): void
    {
        global $wpdb;

        $indexTableNames = [
            $wpdb->postmeta,
            $wpdb->termmeta,
            $wpdb->usermeta,
        ];
        foreach ($indexTableNames as $indexTableName) {
            $indexName = 'wc1c_meta_key_meta_value';
            $result = $wpdb->get_var("SHOW INDEX FROM $indexTableName WHERE Key_name = '$indexName';");
            if ($result) {
                continue;
            }

            $wpdb->query("ALTER TABLE $indexTableName ADD INDEX $indexName (meta_key, meta_value(36))");
        }

        if (!is_dir(WC1C_DATA_DIR)) {
            mkdir(WC1C_DATA_DIR);
        }
        file_put_contents(WC1C_DATA_DIR . '.htaccess', 'Deny from all');
        file_put_contents(WC1C_DATA_DIR . 'index.html', '');

        $this->addRewriteRules();
        flush_rewrite_rules();
    }

    /**
     * Drop meta indexes created during activation (used on uninstall).
     */
    public function dropMetaIndexes(): void
    {
        global $wpdb;

        $indexTableNames = [
            $wpdb->postmeta,
            $wpdb->termmeta,
            $wpdb->usermeta,
        ];
        foreach ($indexTableNames as $indexTableName) {
            $indexName = 'wc1c_meta_key_meta_value';
            $result = $wpdb->get_var("SHOW INDEX FROM $indexTableName WHERE Key_name = '$indexName';");
            if (!$result) {
                continue;
            }

            $wpdb->query("DROP INDEX $indexName ON $indexTableName");
        }
    }

    /**
     * Register pretty permalink rules for exchange and cleanup endpoints.
     */
    public function addRewriteRules(): void
    {
        add_rewrite_rule('e/?$', 'index.php?wc1c=exchange', 'top');
        add_rewrite_rule('wc1c/exc/?$', 'index.php?wc1c=exchange', 'top');
        add_rewrite_rule('wc1c/exchange', 'index.php?wc1c=exchange', 'top');
        add_rewrite_rule('wc1c/clean', 'index.php?wc1c=clean');
        add_rewrite_rule('woo-1c-sync/exchange/?$', 'index.php?wc1c=exchange', 'top');
        add_rewrite_rule('woo-1c-sync/clean/?$', 'index.php?wc1c=clean', 'top');
    }

    /**
     * Look up a WooCommerce attribute taxonomy row by ID.
     *
     * @return array<string, mixed>|null
     */
    public function woocommerceAttributeById(int $attributeId): ?array
    {
        global $wpdb;

        $cacheKey = "wc1c_woocomerce_attribute_by_id-$attributeId";
        $attribute = wp_cache_get($cacheKey);
        if ($attribute === false) {
            $attribute = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
                    $attributeId,
                ),
                ARRAY_A,
            );
            if (function_exists('wc1c_check_wpdb_error')) {
                wc1c_check_wpdb_error();
            }

            if ($attribute) {
                $attribute['taxonomy'] = wc_attribute_taxonomy_name($attribute['attribute_name']);
                wp_cache_set($cacheKey, $attribute);
            }
        }

        return $attribute ?: null;
    }

    /**
     * Delete a WooCommerce attribute and all of its terms.
     */
    public function deleteWoocommerceAttribute(int $attributeId): bool
    {
        global $wpdb;

        $attribute = $this->woocommerceAttributeById($attributeId);
        if (!$attribute) {
            return false;
        }

        delete_option("{$attribute['taxonomy']}_children");

        $terms = get_terms($attribute['taxonomy'], 'hide_empty=0');
        foreach ($terms as $term) {
            wp_delete_term($term->term_id, $attribute['taxonomy']);
        }

        $wpdb->delete("{$wpdb->prefix}woocommerce_attribute_taxonomies", ['attribute_id' => $attributeId]);
        if (function_exists('wc1c_check_wpdb_error')) {
            wc1c_check_wpdb_error();
        }

        return true;
    }

    /**
     * Remove term meta when a synced category or attribute term is deleted.
     *
     * @param mixed $ttId
     * @param mixed $deletedTerm
     */
    public function deleteTerm(int $termId, $ttId, string $taxonomy, $deletedTerm): void
    {
        global $wpdb;

        if ($taxonomy != 'product_cat' && strpos($taxonomy, 'pa_') !== 0) {
            return;
        }

        $wpdb->delete($wpdb->termmeta, ['term_id' => $termId]);
        if (function_exists('wc1c_check_wpdb_error')) {
            wc1c_check_wpdb_error();
        }
    }

    /**
     * Parse a decimal number from 1C format (comma decimal separator).
     */
    public function parseDecimal(string $number): float
    {
        $number = str_replace([',', ' '], ['.', ''], $number);

        return (float) $number;
    }

    /**
     * Sort attribute taxonomies by plugin-defined order.
     *
     * @param array<int, object> $attributeTaxonomies
     *
     * @return array<int, object>
     */
    public function woocommerceAttributeTaxonomies(array $attributeTaxonomies): array
    {
        $orders = get_option('wc1c_order_attributes', []);
        foreach ($attributeTaxonomies as $attributeTaxonomy) {
            $order = array_search($attributeTaxonomy->attribute_id, $orders);
            if ($order !== false) {
                $attributeTaxonomy->wc1c_order = $order;
            }
        }
        usort($attributeTaxonomies, [$this, 'attributeTaxonomyCompare']);

        return $attributeTaxonomies;
    }

    /**
     * Clean up plugin options when a WooCommerce attribute is deleted.
     */
    public function woocommerceAttributeDeleted(int $attributeId, string $attributeName, string $taxonomy): void
    {
        $guids = get_option('wc1c_guid_attributes', []);
        $guid = array_search($attributeId, $guids);
        if ($guid === false) {
            return;
        }

        if (isset($guids[$guid])) {
            unset($guids[$guid]);
            update_option('wc1c_guid_attributes', $guids);
        }

        $timestamps = get_option('wc1c_timestamp_attributes', []);
        if (isset($timestamps[$guid])) {
            unset($timestamps[$guid]);
            update_option('wc1c_timestamp_attributes', $timestamps);
        }

        $orders = get_option('wc1c_order_attributes', []);
        $orderIndex = array_search($attributeId, $orders);
        if ($orderIndex !== false) {
            unset($orders[$orderIndex]);
            update_option('wc1c_order_attributes', $orders);
        }
    }

    /**
     * @param object $a
     * @param object $b
     */
    private function attributeTaxonomyCompare($a, $b): int
    {
        $orderA = property_exists($a, 'wc1c_order') ? $a->wc1c_order : 1000 + $a->attribute_id;
        $orderB = property_exists($b, 'wc1c_order') ? $b->wc1c_order : 1000 + $b->attribute_id;

        if ($orderA == $orderB) {
            return 0;
        }

        return $orderA < $orderB ? -1 : 1;
    }
}
