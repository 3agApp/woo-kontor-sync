<?php
/**
 * AJAX Handler Class
 *
 * Handles all AJAX requests for the plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WKS_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_wks_run_sync', [$this, 'run_sync']);
        add_action('wp_ajax_wks_test_connection', [$this, 'test_connection']);
        add_action('wp_ajax_wks_save_settings', [$this, 'save_settings']);
        add_action('wp_ajax_wks_activate_license', [$this, 'activate_license']);
        add_action('wp_ajax_wks_activate_domain', [$this, 'activate_domain']);
        add_action('wp_ajax_wks_deactivate_license', [$this, 'deactivate_license']);
        add_action('wp_ajax_wks_check_license', [$this, 'check_license']);
        add_action('wp_ajax_wks_clear_logs', [$this, 'clear_logs']);
        add_action('wp_ajax_wks_get_log_details', [$this, 'get_log_details']);
        add_action('wp_ajax_wks_get_sync_status', [$this, 'get_sync_status']);
        add_action('wp_ajax_wks_toggle_sync', [$this, 'toggle_sync']);
        add_action('wp_ajax_wks_check_update', [$this, 'check_update']);
        add_action('wp_ajax_wks_install_update', [$this, 'install_update']);
        add_action('wp_ajax_wks_fetch_manufacturers', [$this, 'fetch_manufacturers']);
        add_action('wp_ajax_wks_fetch_shops', [$this, 'fetch_shops']);
        add_action('wp_ajax_wks_fetch_categories', [$this, 'fetch_categories']);
        add_action('wp_ajax_wks_upsert_categories', [$this, 'upsert_categories']);
        add_action('wp_ajax_wks_run_order_sync', [$this, 'run_order_sync']);
    }

    /**
     * Verify nonce
     */
    private function verify_nonce() {
        if (!check_ajax_referer('wks_admin_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed.', 'woo-kontor-sync'),
            ]);
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'message' => __('Permission denied.', 'woo-kontor-sync'),
            ]);
        }
    }

    /**
     * Run manual sync
     */
    public function run_sync() {
        $this->verify_nonce();

        if (WKS()->scheduler->is_running()) {
            wp_send_json_error([
                'message' => __('A sync is already in progress.', 'woo-kontor-sync'),
            ]);
        }

        // Rate limiting
        $last_manual = get_transient('wks_last_manual_sync');
        if ($last_manual !== false) {
            $wait_time = 30 - (time() - $last_manual);
            if ($wait_time > 0) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Please wait %d seconds before starting another sync.', 'woo-kontor-sync'),
                        $wait_time
                    ),
                ]);
            }
        }
        set_transient('wks_last_manual_sync', time(), 60);

        WKS()->scheduler->set_running(true);

        try {
            $result = WKS()->sync->run_manual_sync();
            WKS()->scheduler->set_running(false);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            WKS()->scheduler->set_running(false);
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch available manufacturers from Kontor API
     */
    public function fetch_manufacturers() {
        $this->verify_nonce();

        if (!WKS()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-kontor-sync'),
            ]);
        }

        set_time_limit(0);
        wp_raise_memory_limit('admin');

        $result = WKS()->sync->fetch_manufacturers();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Fetch available shops from Kontor API
     */
    public function fetch_shops() {
        $this->verify_nonce();

        if (!WKS()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-kontor-sync'),
            ]);
        }

        $result = WKS()->sync->fetch_shops();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Fetch categories for a shop from Kontor API
     */
    public function fetch_categories() {
        $this->verify_nonce();

        if (!WKS()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-kontor-sync'),
            ]);
        }

        $shop_id = isset($_POST['shop_id']) ? sanitize_text_field(wp_unslash($_POST['shop_id'])) : '';

        if (empty($shop_id)) {
            wp_send_json_error([
                'message' => __('Please select a shop first.', 'woo-kontor-sync'),
            ]);
        }

        $result = WKS()->sync->fetch_categories($shop_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Upsert (push) WooCommerce categories to Kontor
     */
    public function upsert_categories() {
        $this->verify_nonce();

        if (!WKS()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-kontor-sync'),
            ]);
        }

        $shop_id       = isset($_POST['shop_id']) ? sanitize_text_field(wp_unslash($_POST['shop_id'])) : '';
        $overwrite_all = isset($_POST['overwrite_all']) && $_POST['overwrite_all'] === 'true';

        if (empty($shop_id)) {
            wp_send_json_error([
                'message' => __('Please select a shop first.', 'woo-kontor-sync'),
            ]);
        }

        // Build categories from WooCommerce
        $categories = WKS()->sync->build_categories_for_upsert();

        if (empty($categories)) {
            wp_send_json_error([
                'message' => __('No WooCommerce product categories found to push.', 'woo-kontor-sync'),
            ]);
        }

        $result = WKS()->sync->upsert_categories($shop_id, $categories, $overwrite_all);

        if ($result['success']) {
            wp_send_json_success([
                'message'         => sprintf(
                    __('Successfully pushed %d categories to Kontor.', 'woo-kontor-sync'),
                    count($categories)
                ),
                'categories_count' => count($categories),
            ]);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        $this->verify_nonce();

        if (!WKS()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-kontor-sync'),
            ]);
        }

        $host = !empty($_POST['api_host']) ? esc_url_raw($_POST['api_host']) : get_option('wks_api_host', '');
        $key  = !empty($_POST['api_key']) ? trim(wp_unslash($_POST['api_key'])) : get_option('wks_api_key', '');

        if (empty($host) || empty($key)) {
            wp_send_json_error([
                'message' => __('Please provide both API Host and API Key.', 'woo-kontor-sync'),
            ]);
        }

        $result = WKS()->sync->test_connection($host, $key);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Save settings
     */
    public function save_settings() {
        $this->verify_nonce();

        if (!WKS()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-kontor-sync'),
            ]);
        }

        $api_host         = isset($_POST['api_host']) ? esc_url_raw($_POST['api_host']) : '';
        $api_key          = isset($_POST['api_key']) ? trim(wp_unslash($_POST['api_key'])) : '';
        $image_prefix_url = isset($_POST['image_prefix_url']) ? esc_url_raw($_POST['image_prefix_url']) : '';
        $interval         = isset($_POST['schedule_interval']) ? sanitize_text_field($_POST['schedule_interval']) : 'hourly';
        $enabled              = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        $shop_id              = isset($_POST['shop_id']) ? sanitize_text_field(wp_unslash($_POST['shop_id'])) : '';
        $manufacturer_filter  = '';

        if (isset($_POST['manufacturer_filter'])) {
            $raw_filter = sanitize_text_field(wp_unslash($_POST['manufacturer_filter']));
            $parts      = preg_split('/[\s,]+/', (string) $raw_filter);
            $parts      = is_array($parts) ? $parts : [];

            $ids = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '' && preg_match('/^\d+$/', $part)) {
                    $ids[] = $part;
                }
            }

            $manufacturer_filter = implode(',', array_values(array_unique($ids)));
        }

        // Save settings
        update_option('wks_api_host', $api_host);
        update_option('wks_api_key', $api_key);
        update_option('wks_image_prefix_url', $image_prefix_url);
        update_option('wks_schedule_interval', $interval);
        update_option('wks_enabled', $enabled);
        update_option('wks_manufacturer_filter', $manufacturer_filter);
        update_option('wks_shop_id', $shop_id);

        // Order sync settings
        $order_sync_enabled  = isset($_POST['order_sync_enabled']) && $_POST['order_sync_enabled'] === 'true';
        $order_statuses      = isset($_POST['order_statuses']) && is_array($_POST['order_statuses'])
            ? array_map('sanitize_text_field', $_POST['order_statuses'])
            : ['processing', 'completed'];
        $order_platform_id   = isset($_POST['order_platform_id']) ? sanitize_text_field(wp_unslash($_POST['order_platform_id'])) : '';
        $order_sales_channel = isset($_POST['order_sales_channel']) ? sanitize_text_field(wp_unslash($_POST['order_sales_channel'])) : 'Webshop';
        $order_sync_interval = isset($_POST['order_sync_interval']) ? sanitize_text_field($_POST['order_sync_interval']) : 'hourly';

        update_option('wks_order_sync_enabled', $order_sync_enabled);
        update_option('wks_order_statuses', $order_statuses);
        update_option('wks_order_platform_id', $order_platform_id);
        update_option('wks_order_sales_channel', $order_sales_channel);
        update_option('wks_order_sync_interval', $order_sync_interval);

        // Handle order sync scheduling
        if ($order_sync_enabled && !empty($api_host) && !empty($api_key)) {
            WKS()->scheduler->schedule_order_sync($order_sync_interval);
        } else {
            WKS()->scheduler->unschedule_order_sync();
        }

        // Handle scheduling
        if ($enabled && !empty($api_host) && !empty($api_key)) {
            WKS()->scheduler->schedule($interval);
        } else {
            WKS()->scheduler->unschedule();
        }

        wp_send_json_success([
            'message' => __('Settings saved successfully.', 'woo-kontor-sync'),
        ]);
    }

    /**
     * Activate license
     */
    public function activate_license() {
        $this->verify_nonce();

        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';

        if (empty($license_key)) {
            wp_send_json_error([
                'message' => __('Please enter a license key.', 'woo-kontor-sync'),
            ]);
        }

        $result = WKS()->license->activate($license_key);

        if ($result['success']) {
            wp_send_json_success([
                'message' => __('License activated successfully!', 'woo-kontor-sync'),
                'data'    => $result['data'],
            ]);
        } else {
            $message = isset($result['message']) ? $result['message'] : __('Activation failed.', 'woo-kontor-sync');

            if (strpos($message, 'Domain limit') !== false || strpos($message, 'limit reached') !== false) {
                $message = __('Domain activation limit reached. Please deactivate another domain from your license dashboard first.', 'woo-kontor-sync');
            }

            if (strpos($message, 'not active') !== false || strpos($message, 'is not active') !== false) {
                $message = __('This license is not active. It may be expired, suspended, or cancelled.', 'woo-kontor-sync');
            }

            wp_send_json_error([
                'message' => $message,
            ]);
        }
    }

    /**
     * Activate domain for existing license
     */
    public function activate_domain() {
        $this->verify_nonce();

        $license_key = WKS()->license->get_key();

        if (empty($license_key)) {
            wp_send_json_error([
                'message' => __('No license key found. Please enter a license key.', 'woo-kontor-sync'),
            ]);
        }

        $result = WKS()->license->activate($license_key);

        if ($result['success']) {
            wp_send_json_success([
                'message' => __('Domain activated successfully!', 'woo-kontor-sync'),
                'data'    => $result['data'],
            ]);
        } else {
            $message = isset($result['message']) ? $result['message'] : __('Activation failed.', 'woo-kontor-sync');

            if (strpos($message, 'Domain limit') !== false || strpos($message, 'limit reached') !== false) {
                $message = __('Domain activation limit reached. Please deactivate another domain from your license dashboard first.', 'woo-kontor-sync');
            }

            wp_send_json_error([
                'message' => $message,
            ]);
        }
    }

    /**
     * Deactivate license
     */
    public function deactivate_license() {
        $this->verify_nonce();

        $result = WKS()->license->deactivate();

        update_option('wks_enabled', false);
        delete_option('wks_sync_disabled_by_license');
        WKS()->scheduler->unschedule();

        if ($result['success']) {
            wp_send_json_success([
                'message' => __('License deactivated successfully.', 'woo-kontor-sync'),
            ]);
        } else {
            wp_send_json_success([
                'message' => __('License deactivated locally.', 'woo-kontor-sync'),
            ]);
        }
    }

    /**
     * Check/validate license status
     */
    public function check_license() {
        $this->verify_nonce();

        $result = WKS()->license->validate();

        if ($result['success'] && isset($result['data'])) {
            wp_send_json_success([
                'valid'     => !empty($result['data']['valid']),
                'activated' => !empty($result['data']['activated']),
                'data'      => $result['data'],
            ]);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Clear logs
     */
    public function clear_logs() {
        $this->verify_nonce();

        WKS()->logs->clear();

        wp_send_json_success([
            'message' => __('Logs cleared successfully.', 'woo-kontor-sync'),
        ]);
    }

    /**
     * Get log details
     */
    public function get_log_details() {
        $this->verify_nonce();

        $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;

        if (!$log_id) {
            wp_send_json_error([
                'message' => __('Invalid log ID.', 'woo-kontor-sync'),
            ]);
        }

        $log = WKS()->logs->get_by_id($log_id);

        if (!$log) {
            wp_send_json_error([
                'message' => __('Log not found.', 'woo-kontor-sync'),
            ]);
        }

        wp_send_json_success([
            'log' => $log,
        ]);
    }

    /**
     * Get sync status
     */
    public function get_sync_status() {
        $this->verify_nonce();

        $status     = WKS()->scheduler->get_status();
        $is_running = WKS()->scheduler->is_running();

        wp_send_json_success([
            'status'     => $status,
            'is_running' => $is_running,
        ]);
    }

    /**
     * Toggle sync enabled/disabled
     */
    public function toggle_sync() {
        $this->verify_nonce();

        if (!WKS()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-kontor-sync'),
            ]);
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';

        update_option('wks_enabled', $enabled);

        if ($enabled) {
            $api_host = get_option('wks_api_host');
            $api_key  = get_option('wks_api_key');
            if (empty($api_host) || empty($api_key)) {
                update_option('wks_enabled', false);
                wp_send_json_error([
                    'message' => __('Please configure API Host and API Key in settings first.', 'woo-kontor-sync'),
                ]);
            }

            WKS()->scheduler->schedule();
            $message = __('Sync enabled successfully.', 'woo-kontor-sync');
        } else {
            WKS()->scheduler->unschedule();
            $message = __('Sync disabled.', 'woo-kontor-sync');
        }

        wp_send_json_success([
            'message' => $message,
            'enabled' => $enabled,
        ]);
    }

    /**
     * Run manual order sync
     */
    public function run_order_sync() {
        $this->verify_nonce();

        if (!get_option('wks_order_sync_enabled', false)) {
            wp_send_json_error([
                'message' => __('Order sync is not enabled. Please enable it in settings first.', 'woo-kontor-sync'),
            ]);
        }

        if (get_transient('wks_order_sync_running')) {
            wp_send_json_error([
                'message' => __('An order sync is already in progress.', 'woo-kontor-sync'),
            ]);
        }

        // Rate limiting
        $last_manual = get_transient('wks_last_manual_order_sync');
        if ($last_manual !== false) {
            $wait_time = 30 - (time() - $last_manual);
            if ($wait_time > 0) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Please wait %d seconds before starting another order sync.', 'woo-kontor-sync'),
                        $wait_time
                    ),
                ]);
            }
        }
        set_transient('wks_last_manual_order_sync', time(), 60);

        set_transient('wks_order_sync_running', true, 30 * MINUTE_IN_SECONDS);

        try {
            $result = WKS()->sync->run_manual_order_sync();
            delete_transient('wks_order_sync_running');

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            delete_transient('wks_order_sync_running');
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check for plugin updates
     */
    public function check_update() {
        $this->verify_nonce();

        WKS()->updater->force_check();

        $update_data     = get_transient('wks_update_data');
        $current_version = WKS_VERSION;
        $has_update      = $update_data && !empty($update_data['version']) && version_compare($current_version, $update_data['version'], '<');

        if ($has_update) {
            wp_send_json_success([
                'message'         => sprintf(
                    __('Update available! Version %s is ready to install.', 'woo-kontor-sync'),
                    $update_data['version']
                ),
                'has_update'      => true,
                'current_version' => $current_version,
                'new_version'     => $update_data['version'],
            ]);
        } else {
            wp_send_json_success([
                'message'         => __('You are running the latest version.', 'woo-kontor-sync'),
                'has_update'      => false,
                'current_version' => $current_version,
            ]);
        }
    }

    /**
     * Install plugin update
     */
    public function install_update() {
        $this->verify_nonce();

        $update_data = get_transient('wks_update_data');

        if (!$update_data || empty($update_data['download_url'])) {
            wp_send_json_error([
                'message' => __('No update available or download URL missing.', 'woo-kontor-sync'),
            ]);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        deactivate_plugins(WKS_PLUGIN_BASENAME);

        $result = $upgrader->install($update_data['download_url'], [
            'overwrite_package' => true,
        ]);

        if (is_wp_error($result)) {
            activate_plugin(WKS_PLUGIN_BASENAME);
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ]);
        }

        if ($result === false) {
            activate_plugin(WKS_PLUGIN_BASENAME);
            wp_send_json_error([
                'message' => __('Update failed. Please try again or update manually.', 'woo-kontor-sync'),
            ]);
        }

        activate_plugin(WKS_PLUGIN_BASENAME);

        WKS()->updater->clear_cache();
        delete_site_transient('update_plugins');

        wp_send_json_success([
            'message'     => sprintf(
                __('Successfully updated to version %s. Please refresh the page.', 'woo-kontor-sync'),
                $update_data['version']
            ),
            'new_version' => $update_data['version'],
            'reload'      => true,
        ]);
    }
}
