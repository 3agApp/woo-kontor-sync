<?php
/**
 * Admin Class
 *
 * Handles admin menu, pages, and assets.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WKS_Admin {

    /**
     * Menu slug
     */
    const MENU_SLUG = 'woo-kontor-sync';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_tracking_meta_box'], 10, 2);
    }

    /**
     * Kontor tracking meta box on the order edit screen (legacy + HPOS).
     */
    public function add_tracking_meta_box($post_type, $post) {
        $screen = 'shop_order';
        if (
            class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ) {
            $screen = wc_get_page_screen_id('shop-order');
        }

        if ($post_type !== $screen) {
            return;
        }

        add_meta_box(
            'wks-kontor-tracking',
            __('Kontor Shipment Tracking', 'woo-kontor-sync'),
            [$this, 'render_tracking_meta_box'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render the (read-only) Kontor tracking meta box.
     */
    public function render_tracking_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order instanceof WC_Order) {
            return;
        }

        $kontor_status = trim((string) $order->get_meta('_wks_kontor_orderstatus'));
        $provider      = trim((string) $order->get_meta('_wks_kontor_provider'));
        $tracking      = trim((string) $order->get_meta('_wks_tracking_number'));
        $tracking_url  = trim((string) $order->get_meta('_wks_tracking_url'));
        $synced        = (int) $order->get_meta('_wks_status_synced');

        if ($kontor_status === '' && $tracking === '' && $synced === 0) {
            echo '<p>' . esc_html__('No tracking data has been synced from Kontor for this order yet.', 'woo-kontor-sync') . '</p>';
            return;
        }
        ?>
        <p>
            <?php if ($kontor_status !== ''): ?>
                <strong><?php esc_html_e('Kontor status:', 'woo-kontor-sync'); ?></strong>
                <code><?php echo esc_html($kontor_status); ?></code><br>
            <?php endif; ?>
            <?php if ($provider !== ''): ?>
                <strong><?php esc_html_e('Shipping provider:', 'woo-kontor-sync'); ?></strong>
                <?php echo esc_html($provider); ?><br>
            <?php endif; ?>
            <?php if ($tracking !== ''): ?>
                <strong><?php esc_html_e('Tracking number:', 'woo-kontor-sync'); ?></strong>
                <?php if ($tracking_url !== ''): ?>
                    <a href="<?php echo esc_url($tracking_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($tracking); ?></a>
                <?php else: ?>
                    <?php echo esc_html($tracking); ?>
                <?php endif; ?>
                <br>
            <?php endif; ?>
            <?php if ($synced > 0): ?>
                <strong><?php esc_html_e('Last synced:', 'woo-kontor-sync'); ?></strong>
                <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $synced)); ?>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Add admin menu
     */
    public function add_menu() {
        // Main menu
        add_menu_page(
            __('Kontor Sync', 'woo-kontor-sync'),
            __('Kontor Sync', 'woo-kontor-sync'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_dashboard_page'],
            'dashicons-update',
            56
        );

        // Dashboard submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'woo-kontor-sync'),
            __('Dashboard', 'woo-kontor-sync'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_dashboard_page']
        );

        // Logs submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Sync Logs', 'woo-kontor-sync'),
            __('Logs', 'woo-kontor-sync'),
            'manage_woocommerce',
            self::MENU_SLUG . '-logs',
            [$this, 'render_logs_page']
        );

        // Settings submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'woo-kontor-sync'),
            __('Settings', 'woo-kontor-sync'),
            'manage_woocommerce',
            self::MENU_SLUG . '-settings',
            [$this, 'render_settings_page']
        );

        // License submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('License', 'woo-kontor-sync'),
            __('License', 'woo-kontor-sync'),
            'manage_woocommerce',
            self::MENU_SLUG . '-license',
            [$this, 'render_license_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'wks-admin',
            WKS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WKS_VERSION
        );

        // JS
        wp_enqueue_script(
            'wks-admin',
            WKS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WKS_VERSION,
            true
        );

        // Localize script
        wp_localize_script('wks-admin', 'wks_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wks_admin_nonce'),
            'strings'  => [
                'sync_running'       => __('Sync in progress...', 'woo-kontor-sync'),
                'sync_complete'      => __('Sync completed!', 'woo-kontor-sync'),
                'sync_error'         => __('Sync failed!', 'woo-kontor-sync'),
                'confirm_sync'       => __('Are you sure you want to run a manual sync now?', 'woo-kontor-sync'),
                'confirm_order_sync' => __('Are you sure you want to upload orders to Kontor now?', 'woo-kontor-sync'),
                'order_sync_running' => __('Syncing orders...', 'woo-kontor-sync'),
                'confirm_clear_logs' => __('Are you sure you want to clear all logs?', 'woo-kontor-sync'),
                'testing'          => __('Testing connection...', 'woo-kontor-sync'),
                'saving'           => __('Saving...', 'woo-kontor-sync'),
                'activating'       => __('Activating license...', 'woo-kontor-sync'),
                'deactivating'     => __('Deactivating license...', 'woo-kontor-sync'),
            ],
        ]);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wks_settings', 'wks_api_host', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);

        register_setting('wks_settings', 'wks_api_key', [
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return trim(wp_strip_all_tags($value));
            },
        ]);

        register_setting('wks_settings', 'wks_image_prefix_url', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);

        register_setting('wks_settings', 'wks_schedule_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
        ]);

        register_setting('wks_settings', 'wks_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        register_setting('wks_settings', 'wks_order_sync_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        register_setting('wks_settings', 'wks_order_statuses', [
            'type'    => 'array',
            'default' => ['processing', 'completed'],
        ]);

        register_setting('wks_settings', 'wks_order_platform_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('wks_settings', 'wks_order_sales_channel', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Webshop',
        ]);

        register_setting('wks_settings', 'wks_order_sync_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
        ]);

        register_setting('wks_settings', 'wks_manufacturer_filter', [
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }

                $parts = preg_split('/[\s,]+/', sanitize_text_field((string) $value));
                $parts = is_array($parts) ? $parts : [];

                $ids = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '' && preg_match('/^\d+$/', $part)) {
                        $ids[] = $part;
                    }
                }

                return implode(',', array_values(array_unique($ids)));
            },
            'default'           => '',
        ]);

        register_setting('wks_settings', 'wks_shoptype', [
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                $value = strtoupper(sanitize_text_field((string) $value));
                return in_array($value, ['B2B', 'B2C', 'EDU'], true) ? $value : 'B2B';
            },
            'default'           => 'B2B',
        ]);

        register_setting('wks_settings', 'wks_stock_sync_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        register_setting('wks_settings', 'wks_stock_sync_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'wks_15min',
        ]);

        register_setting('wks_settings', 'wks_status_sync_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        register_setting('wks_settings', 'wks_status_sync_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
        ]);
    }

    /**
     * Render Dashboard Page
     */
    public function render_dashboard_page() {
        $license_valid    = WKS()->license->is_valid();
        $stats            = WKS()->logs->get_stats(30);
        $scheduler_status = WKS()->scheduler->get_status();
        $recent_logs      = WKS()->logs->get(['limit' => 5, 'type' => 'sync']);
        $chart_data       = WKS()->logs->get_chart_data(14);

        include WKS_PLUGIN_DIR . 'includes/views/dashboard.php';
    }

    /**
     * Render Logs Page
     */
    public function render_logs_page() {
        $page     = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset   = ($page - 1) * $per_page;

        $type_filter   = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : null;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;

        $logs = WKS()->logs->get([
            'type'   => $type_filter,
            'status' => $status_filter,
            'limit'  => $per_page,
            'offset' => $offset,
        ]);

        $total       = WKS()->logs->get_count([
            'type'   => $type_filter,
            'status' => $status_filter,
        ]);
        $total_pages = ceil($total / $per_page);

        include WKS_PLUGIN_DIR . 'includes/views/logs.php';
    }

    /**
     * Render Settings Page
     */
    public function render_settings_page() {
        $license_valid    = WKS()->license->is_valid();
        $intervals        = WKS()->scheduler->get_intervals();
        $current_interval = get_option('wks_schedule_interval', 'hourly');
        $api_host         = get_option('wks_api_host', '');
        $api_key          = get_option('wks_api_key', '');
        $image_prefix_url = get_option('wks_image_prefix_url', '');
        $enabled              = get_option('wks_enabled', false);
        $manufacturer_filter  = get_option('wks_manufacturer_filter', '');
        $shop_id              = get_option('wks_shop_id', '');
        $shoptype             = get_option('wks_shoptype', 'B2B');

        // Order sync settings
        $order_sync_enabled   = get_option('wks_order_sync_enabled', false);
        $order_statuses       = get_option('wks_order_statuses', ['processing', 'completed']);
        $order_platform_id    = get_option('wks_order_platform_id', '');
        $order_sales_channel  = get_option('wks_order_sales_channel', 'Webshop');
        $order_sync_interval  = get_option('wks_order_sync_interval', 'hourly');

        // Stock sync settings
        $stock_sync_enabled   = get_option('wks_stock_sync_enabled', false);
        $stock_sync_interval  = get_option('wks_stock_sync_interval', 'wks_15min');

        // Order status sync settings (Kontor -> WooCommerce)
        $status_sync_enabled  = get_option('wks_status_sync_enabled', false);
        $status_sync_interval = get_option('wks_status_sync_interval', 'hourly');

        include WKS_PLUGIN_DIR . 'includes/views/settings.php';
    }

    /**
     * Render License Page
     */
    public function render_license_page() {
        $license_key    = get_option('wks_license_key', '');
        $license_status = get_option('wks_license_status', '');
        $license_data   = WKS()->license->get_data();
        $last_check     = get_option('wks_license_last_check');

        include WKS_PLUGIN_DIR . 'includes/views/license.php';
    }

    /**
     * Get admin page URL
     */
    public static function get_page_url($page = '') {
        $slug = self::MENU_SLUG;
        if ($page) {
            $slug .= '-' . $page;
        }
        return admin_url('admin.php?page=' . $slug);
    }
}
