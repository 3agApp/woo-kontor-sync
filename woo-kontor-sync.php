<?php
/**
 * Plugin Name: Woo Kontor Sync
 * Plugin URI: https://3ag.app/products/woo-kontor-sync
 * Description: Sync WooCommerce products from Kontor CRM via API — import/update products with scheduled and manual sync.
 * Version: 1.0.6
 * Author: 3AG
 * Author URI: https://3ag.app
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-kontor-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WKS_VERSION', '1.0.6');
define('WKS_PLUGIN_FILE', __FILE__);
define('WKS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WKS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WKS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WKS_PRODUCT_SLUG', 'woo-kontor-sync');

/**
 * Get clean domain for license validation
 *
 * @return string The clean domain
 */
function wks_get_domain() {
    $site_url = site_url();
    $parsed = wp_parse_url($site_url);
    $domain = isset($parsed['host']) ? $parsed['host'] : '';

    // Remove www prefix
    $domain = preg_replace('/^www\./', '', $domain);

    // Remove port if present
    $domain = preg_replace('/:\d+$/', '', $domain);

    return $domain;
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'WKS_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $class_name = str_replace($prefix, '', $class);
    $class_name = strtolower(str_replace('_', '-', $class_name));
    $file = WKS_PLUGIN_DIR . 'includes/class-' . $class_name . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Main Plugin Class
 */
final class Woo_Kontor_Sync {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    public $license;
    public $sync;
    public $scheduler;
    public $logs;
    public $admin;
    public $ajax;
    public $updater;

    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'ensure_crons_after_update'], 25);
        add_action('init', [$this, 'load_textdomain']);
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check WooCommerce dependency
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        // Initialize components
        $this->license   = new WKS_License();
        $this->logs      = new WKS_Logs();
        $this->sync      = new WKS_Sync();
        $this->scheduler = new WKS_Scheduler();
        $this->ajax      = new WKS_Ajax();
        $this->updater   = new WKS_Updater();

        if (is_admin()) {
            $this->admin = new WKS_Admin();
        }

        // HPOS compatibility
        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
    }

    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('woo-kontor-sync', false, dirname(WKS_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Activation
     */
    public function activate() {
        // Create logs table
        WKS_Logs::create_table();

        // Set default options
        $defaults = [
            'api_host'          => '',
            'api_key'           => '',
            'image_prefix_url'  => '',
            'schedule_interval' => 'hourly',
            'enabled'           => false,
        ];

        foreach ($defaults as $key => $value) {
            if (get_option('wks_' . $key) === false) {
                update_option('wks_' . $key, $value);
            }
        }

        // Register cron intervals before scheduling
        $this->register_cron_intervals();

        // Schedule watchdog (uses built-in 'hourly' for reliability)
        if (!wp_next_scheduled('wks_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wks_watchdog_check');
        }

        // Reschedule sync event if sync was previously enabled
        if (get_option('wks_enabled', false) && !wp_next_scheduled('wks_sync_event')) {
            $interval = get_option('wks_schedule_interval', 'hourly');
            wp_schedule_event(time(), $interval, 'wks_sync_event');
        }

        flush_rewrite_rules();
    }

    /**
     * Register custom cron intervals
     */
    private function register_cron_intervals() {
        add_filter('cron_schedules', [$this, 'add_cron_intervals_callback']);
    }

    /**
     * Cron intervals callback
     */
    public function add_cron_intervals_callback($schedules) {
        $intervals = self::get_custom_cron_intervals();

        foreach ($intervals as $key => $data) {
            if (!isset($schedules[$key])) {
                $schedules[$key] = $data;
            }
        }

        return $schedules;
    }

    /**
     * Get custom cron intervals definition
     */
    public static function get_custom_cron_intervals() {
        return [
            'wks_5min' => [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every 5 Minutes', 'woo-kontor-sync'),
            ],
            'wks_15min' => [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 Minutes', 'woo-kontor-sync'),
            ],
            'wks_30min' => [
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => __('Every 30 Minutes', 'woo-kontor-sync'),
            ],
            'wks_2hours' => [
                'interval' => 2 * HOUR_IN_SECONDS,
                'display'  => __('Every 2 Hours', 'woo-kontor-sync'),
            ],
            'wks_4hours' => [
                'interval' => 4 * HOUR_IN_SECONDS,
                'display'  => __('Every 4 Hours', 'woo-kontor-sync'),
            ],
            'wks_6hours' => [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => __('Every 6 Hours', 'woo-kontor-sync'),
            ],
            'wks_12hours' => [
                'interval' => 12 * HOUR_IN_SECONDS,
                'display'  => __('Every 12 Hours', 'woo-kontor-sync'),
            ],
            'wks_2days' => [
                'interval' => 2 * DAY_IN_SECONDS,
                'display'  => __('Every 2 Days', 'woo-kontor-sync'),
            ],
        ];
    }

    /**
     * Ensure crons are scheduled after a plugin update
     */
    public function ensure_crons_after_update() {
        if (!class_exists('WooCommerce') || !$this->scheduler || !$this->license) {
            return;
        }

        if (!wp_next_scheduled('wks_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wks_watchdog_check');

            $enabled = get_option('wks_enabled', false);
            if ($enabled && !wp_next_scheduled('wks_sync_event')) {
                $interval = get_option('wks_schedule_interval', 'hourly');
                wp_schedule_event(time(), $interval, 'wks_sync_event');
            }

            if ($this->logs) {
                $this->logs->add([
                    'type'    => 'watchdog',
                    'trigger' => 'system',
                    'status'  => 'warning',
                    'message' => __('Cron recovery: Watchdog was missing (likely after plugin update). All crons rescheduled.', 'woo-kontor-sync'),
                ]);
            }
        }

        if (!wp_next_scheduled('wks_license_check')) {
            wp_schedule_event(time(), 'daily', 'wks_license_check');
        }

        if (!wp_next_scheduled('wks_update_check')) {
            wp_schedule_event(time(), 'twicedaily', 'wks_update_check');
        }
    }

    /**
     * Deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('wks_sync_event');
        wp_clear_scheduled_hook('wks_watchdog_check');
        wp_clear_scheduled_hook('wks_license_check');
        wp_clear_scheduled_hook('wks_update_check');
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Woo Kontor Sync requires WooCommerce to be installed and active.', 'woo-kontor-sync'); ?></p>
        </div>
        <?php
    }

    /**
     * Get plugin URL
     */
    public function plugin_url() {
        return WKS_PLUGIN_URL;
    }

    /**
     * Get plugin path
     */
    public function plugin_path() {
        return WKS_PLUGIN_DIR;
    }
}

/**
 * Main instance
 */
function WKS() {
    return Woo_Kontor_Sync::instance();
}

// Initialize
WKS();
