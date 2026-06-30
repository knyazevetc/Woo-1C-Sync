<?php

declare(strict_types=1);

namespace Woo1cSync\Services;

use Woo1cSync\Exchange\ExchangeService;

/**
 * Removes all catalog data created by the 1C synchronization plugin.
 */
final class CleanupService
{
    public function __construct(
        private readonly ExchangeService $exchangeService,
        private readonly AttributeService $attributeService,
    ) {
    }

    /**
     * Run the cleanup workflow (web form or WP-CLI).
     */
    public function run(): void
    {
        if (!defined('WP_CLI')) {
            if (!current_user_can('shop_manager') && !current_user_can('administrator')) {
                exit("No permissions\n");
            }

            if ($_SERVER['REQUEST_METHOD'] == 'GET') {
                ?>
                <form method="post">
                  <input type="submit" value="Clean">
                </form>
                <?php
            }

            if ($_SERVER['REQUEST_METHOD'] != 'POST') {
                exit;
            }
        }

        global $wpdb;

        if (!isset($wpdb->termmeta)) {
            exit("WooCommerce plugin is not active");
        }

        $this->exchangeService->disableTimeLimit();
        $this->cleanSyncedData();

        echo defined('WP_CLI') ? "\x07" : 'Done';
    }

    /**
     * Delete all terms, attributes, options, and products synced from 1C.
     */
    public function cleanSyncedData(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT tm.term_id, taxonomy FROM $wpdb->termmeta tm JOIN $wpdb->term_taxonomy tt ON tm.term_id = tt.term_id WHERE meta_key = 'wc1c_guid'",
        );
        foreach ($rows as $row) {
            wp_delete_term($row->term_id, $row->taxonomy);
        }

        $attributeIds = get_option('wc1c_guid_attributes', []);
        foreach ($attributeIds as $attributeId) {
            $this->attributeService->deleteWoocommerceAttribute((int) $attributeId);
        }
        delete_transient('wc_attribute_taxonomies');

        $optionNames = $wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'wc1c_%'");
        foreach ($optionNames as $optionName) {
            delete_option($optionName);
        }

        $postIds = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wc1c_guid'");
        foreach ($postIds as $postId) {
            $postAttachments = get_attached_media('image', $postId);
            foreach ($postAttachments as $postAttachment) {
                wp_delete_attachment($postAttachment->ID, true);
            }

            wp_delete_post($postId, true);
        }
    }
}
