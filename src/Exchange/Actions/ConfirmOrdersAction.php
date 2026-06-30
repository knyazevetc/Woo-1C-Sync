<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange\Actions;

/**
 * Marks orders as successfully exported after 1C confirms receipt.
 */
final class ConfirmOrdersAction
{
    /**
     * Mark queried orders as exported by setting the wc1c_queried meta flag.
     */
    public function execute(): void
    {
        $orderStatuses = array_keys(wc_get_order_statuses());
        $orderPosts = get_posts([
            'post_type' => 'shop_order',
            'post_status' => $orderStatuses,
            'meta_query' => [
                [
                    'key' => 'wc1c_querying',
                    'value' => 1,
                ],
                [
                    'key' => 'wc1c_queried',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        foreach ($orderPosts as $orderPost) {
            update_post_meta($orderPost->ID, 'wc1c_queried', 1);
        }
    }
}
