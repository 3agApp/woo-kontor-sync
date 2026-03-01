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
                'sync_running'     => __('Sync in progress...', 'woo-kontor-sync'),
                'sync_complete'    => __('Sync completed!', 'woo-kontor-sync'),
                'sync_error'       => __('Sync failed!', 'woo-kontor-sync'),
                'confirm_sync'     => __('Are you sure you want to run a manual sync now?', 'woo-kontor-sync'),
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

        register_setting('wks_settings', 'wks_page_size', [
            'type'    => 'integer',
            'default' => 500,
        ]);

        register_setting('wks_settings', 'wks_max_pages', [
            'type'    => 'integer',
            'default' => 2,
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

        register_setting('wks_settings', 'wks_manufacturer_filter', [
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                if (is_array($value)) {
                    return implode(',', array_map('sanitize_text_field', array_filter($value)));
                }
                return sanitize_text_field($value);
            },
            'default'           => '',
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
        $page_size        = get_option('wks_page_size', 500);
        $max_pages        = get_option('wks_max_pages', 2);
        $enabled              = get_option('wks_enabled', false);
        $manufacturer_filter  = get_option('wks_manufacturer_filter', '');

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
