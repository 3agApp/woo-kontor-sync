<?php
/**
 * Custom WooCommerce order status for Kontor's "partially_completed".
 *
 * Kontor reports four order statuses; three map onto core WooCommerce statuses,
 * but "partially_completed" has no equivalent, so this class registers a custom
 * "Partially Completed" status (slug: partial-complete).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WKS_Order_Status {

    /**
     * Status slug without the "wc-" prefix.
     *
     * Kept short on purpose: the full "wc-partial-complete" key (19 chars) must fit
     * the legacy posts.post_status varchar(20) column on non-HPOS stores.
     */
    const STATUS = 'partial-complete';

    public function __construct() {
        add_action('init', [$this, 'register_status']);
        add_filter('wc_order_statuses', [$this, 'add_order_status']);
        add_filter('woocommerce_order_is_paid_statuses', [$this, 'add_paid_status']);

        // "Change status to Partially Completed" bulk action on the orders list.
        // WooCommerce handles any "mark_<status>" bulk action generically, so only
        // the action labels need registering (legacy post table + HPOS screens).
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_action']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_bulk_action']);
    }

    /**
     * Register the post status backing the custom order status.
     */
    public function register_status() {
        register_post_status('wc-' . self::STATUS, [
            'label'                     => __('Partially Completed', 'woo-kontor-sync'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop(
                'Partially Completed <span class="count">(%s)</span>',
                'Partially Completed <span class="count">(%s)</span>',
                'woo-kontor-sync'
            ),
        ]);
    }

    /**
     * Insert the custom status after "Processing" and re-label the core statuses
     * used by the Kontor mapping through this plugin's text domain, so the German
     * translation files control the exact wording (Abgebrochen / in Bearbeitung /
     * Abgeschlossen) instead of WooCommerce's own language pack.
     */
    public function add_order_status($statuses) {
        $updated = [];
        foreach ($statuses as $key => $label) {
            $updated[$key] = $label;
            if ($key === 'wc-processing') {
                $updated['wc-' . self::STATUS] = __('Partially Completed', 'woo-kontor-sync');
            }
        }

        if (!isset($updated['wc-' . self::STATUS])) {
            $updated['wc-' . self::STATUS] = __('Partially Completed', 'woo-kontor-sync');
        }

        if (isset($updated['wc-cancelled'])) {
            $updated['wc-cancelled'] = __('Cancelled', 'woo-kontor-sync');
        }
        if (isset($updated['wc-processing'])) {
            $updated['wc-processing'] = __('Processing', 'woo-kontor-sync');
        }
        if (isset($updated['wc-completed'])) {
            $updated['wc-completed'] = __('Completed', 'woo-kontor-sync');
        }

        return $updated;
    }

    /**
     * A partially completed order is a paid order (reports, downloads, etc.).
     */
    public function add_paid_status($statuses) {
        $statuses[] = self::STATUS;
        return array_unique($statuses);
    }

    /**
     * Add the bulk action to the orders list.
     */
    public function add_bulk_action($actions) {
        $updated = [];
        foreach ($actions as $key => $label) {
            $updated[$key] = $label;
            if ($key === 'mark_processing') {
                $updated['mark_' . self::STATUS] = __('Change status to partially completed', 'woo-kontor-sync');
            }
        }

        if (!isset($updated['mark_' . self::STATUS])) {
            $updated['mark_' . self::STATUS] = __('Change status to partially completed', 'woo-kontor-sync');
        }

        return $updated;
    }
}
