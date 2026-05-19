<?php
/**
 * Scheduler Class
 *
 * Manages cron jobs for Kontor sync and watchdog monitoring.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WKS_Scheduler {

    /**
     * Available schedule intervals
     */
    private $intervals = [];

    /**
     * Constructor
     */
    public function __construct() {
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        add_action('wks_watchdog_check', [$this, 'watchdog_check']);

        $custom_intervals = Woo_Kontor_Sync::get_custom_cron_intervals();

        $builtin_intervals = [
            'hourly' => [
                'interval' => HOUR_IN_SECONDS,
                'display'  => __('Hourly', 'woo-kontor-sync'),
            ],
            'daily' => [
                'interval' => DAY_IN_SECONDS,
                'display'  => __('Daily', 'woo-kontor-sync'),
            ],
            'weekly' => [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Weekly', 'woo-kontor-sync'),
            ],
        ];

        $this->intervals = array_merge(
            ['wks_5min'    => $custom_intervals['wks_5min']],
            ['wks_15min'   => $custom_intervals['wks_15min']],
            ['wks_30min'   => $custom_intervals['wks_30min']],
            ['hourly'      => $builtin_intervals['hourly']],
            ['wks_2hours'  => $custom_intervals['wks_2hours']],
            ['wks_4hours'  => $custom_intervals['wks_4hours']],
            ['wks_6hours'  => $custom_intervals['wks_6hours']],
            ['wks_12hours' => $custom_intervals['wks_12hours']],
            ['daily'       => $builtin_intervals['daily']],
            ['wks_2days'   => $custom_intervals['wks_2days']],
            ['weekly'      => $builtin_intervals['weekly']]
        );
    }

    /**
     * Add custom cron intervals to WordPress
     */
    public function add_cron_intervals($schedules) {
        $custom_intervals = Woo_Kontor_Sync::get_custom_cron_intervals();

        foreach ($custom_intervals as $key => $data) {
            if (!isset($schedules[$key])) {
                $schedules[$key] = $data;
            }
        }

        return $schedules;
    }

    /**
     * Get available intervals
     */
    public function get_intervals() {
        return $this->intervals;
    }

    /**
     * Schedule a cron event. Generic — used by every sync type.
     *
     * If $force is false and a future run is already within the interval, keep it.
     * Otherwise clear the hook and schedule a fresh run interval_seconds in the future.
     */
    public function schedule($event_name, $interval = null, $force = false) {
        if (!$interval) {
            $interval = 'hourly';
        }

        $next_scheduled   = wp_next_scheduled($event_name);
        $interval_seconds = $this->get_interval_seconds($interval);

        if (!$force && $next_scheduled) {
            $time_until_next = $next_scheduled - time();
            if ($time_until_next > 0 && $time_until_next <= $interval_seconds) {
                $this->ensure_watchdog();
                return true;
            }
        }

        wp_clear_scheduled_hook($event_name);
        wp_schedule_event(time() + $interval_seconds, $interval, $event_name);
        $this->ensure_watchdog();

        return true;
    }

    /**
     * Unschedule a cron event.
     */
    public function unschedule($event_name) {
        wp_clear_scheduled_hook($event_name);
        return true;
    }

    /**
     * Rebuild every sync's schedule from current option state.
     * Called by save_settings and by ensure_crons_after_update.
     */
    public function reschedule_all() {
        $this->reschedule_product_sync();
        $this->reschedule_order_sync();
        $this->reschedule_stock_sync();
    }

    /**
     * Rebuild the product-sync schedule.
     */
    public function reschedule_product_sync() {
        wp_clear_scheduled_hook('wks_sync_event');

        if (get_option('wks_enabled', false) && WKS()->license->is_valid()) {
            $interval = get_option('wks_schedule_interval', 'hourly');
            $this->schedule('wks_sync_event', $interval, true);
            update_option('wks_last_scheduled', time());
        }
    }

    /**
     * Rebuild the order-sync schedule.
     */
    public function reschedule_order_sync() {
        wp_clear_scheduled_hook('wks_order_sync_event');

        if (get_option('wks_order_sync_enabled', false) && WKS()->license->is_valid()) {
            $interval = get_option('wks_order_sync_interval', 'hourly');
            $this->schedule('wks_order_sync_event', $interval, true);
        }
    }

    /**
     * Rebuild the stock-sync schedule.
     */
    public function reschedule_stock_sync() {
        wp_clear_scheduled_hook('wks_stock_sync_event');

        if (get_option('wks_stock_sync_enabled', false) && WKS()->license->is_valid()) {
            $interval = get_option('wks_stock_sync_interval', 'wks_15min');
            $this->schedule('wks_stock_sync_event', $interval, true);
        }
    }

    /**
     * Post-sync reschedule (called by WKS_Sync::run() at the end of a product sync).
     * Back-compat alias for reschedule_product_sync().
     */
    public function reschedule() {
        $this->reschedule_product_sync();
    }

    /**
     * Ensure the watchdog hook is scheduled.
     */
    private function ensure_watchdog() {
        if (!wp_next_scheduled('wks_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wks_watchdog_check');
        }
    }

    /**
     * Is this cron event so far in the past that we should reschedule it?
     * Threshold: more than 2 × the interval overdue.
     */
    private function is_overdue($next_run, $interval) {
        $interval_seconds = $this->get_interval_seconds($interval);
        return ($next_run - time()) < -($interval_seconds * 2);
    }

    /**
     * Get interval in seconds
     */
    public function get_interval_seconds($interval_key) {
        $schedules = wp_get_schedules();

        if (isset($schedules[$interval_key])) {
            return $schedules[$interval_key]['interval'];
        }

        return HOUR_IN_SECONDS;
    }

    /**
     * Get next scheduled run
     */
    public function get_next_run() {
        $timestamp = wp_next_scheduled('wks_sync_event');
        return $timestamp ? $timestamp : null;
    }

    /**
     * Get time until next run
     */
    public function get_time_until_next_run() {
        $next = $this->get_next_run();

        if (!$next) {
            return null;
        }

        $diff = $next - time();

        if ($diff < 0) {
            return __('Overdue', 'woo-kontor-sync');
        }

        return human_time_diff(time(), $next);
    }

    /**
     * Watchdog: hourly self-heal pass over every enabled sync.
     * Reschedules any cron that is missing OR more than 2× the interval overdue.
     */
    public function watchdog_check() {
        if (!WKS()->license->is_valid()) {
            WKS()->logs->add([
                'type'    => 'watchdog',
                'trigger' => 'scheduled',
                'status'  => 'warning',
                'message' => __('Watchdog: License invalid. All syncs disabled.', 'woo-kontor-sync'),
            ]);

            update_option('wks_enabled', false);
            update_option('wks_order_sync_enabled', false);
            update_option('wks_stock_sync_enabled', false);

            $this->unschedule('wks_sync_event');
            $this->unschedule('wks_order_sync_event');
            $this->unschedule('wks_stock_sync_event');

            update_option('wks_watchdog_last_check', time());
            return;
        }

        $checks = [
            ['wks_sync_event',       'wks_enabled',            'wks_schedule_interval',   'reschedule_product_sync', 'hourly'],
            ['wks_order_sync_event', 'wks_order_sync_enabled', 'wks_order_sync_interval', 'reschedule_order_sync',   'hourly'],
            ['wks_stock_sync_event', 'wks_stock_sync_enabled', 'wks_stock_sync_interval', 'reschedule_stock_sync',   'wks_15min'],
        ];

        $rescheduled = [];
        foreach ($checks as $check) {
            list($event, $enabled_opt, $interval_opt, $reschedule_method, $default_interval) = $check;
            if (!get_option($enabled_opt, false)) {
                continue;
            }
            $next_run = wp_next_scheduled($event);
            $interval = get_option($interval_opt, $default_interval);
            if (!$next_run || $this->is_overdue($next_run, $interval)) {
                $this->$reschedule_method();
                $rescheduled[] = $event;
            }
        }

        if (!empty($rescheduled)) {
            WKS()->logs->add([
                'type'    => 'watchdog',
                'trigger' => 'scheduled',
                'status'  => 'warning',
                'message' => sprintf(
                    /* translators: %s: comma-separated list of cron event names */
                    __('Watchdog: Rescheduled stuck crons: %s', 'woo-kontor-sync'),
                    implode(', ', $rescheduled)
                ),
            ]);
        }

        update_option('wks_watchdog_last_check', time());
    }

    /**
     * Get sync status info
     */
    public function get_status() {
        $enabled          = get_option('wks_enabled', false);
        $interval         = get_option('wks_schedule_interval', 'hourly');
        $next_run         = $this->get_next_run();
        $last_sync        = get_option('wks_last_sync_time');
        $watchdog_last    = get_option('wks_watchdog_last_check');

        $schedules        = wp_get_schedules();
        $interval_display = isset($schedules[$interval]) ? $schedules[$interval]['display'] : $interval;

        $next_run_human = null;
        if ($next_run) {
            $time_diff = $next_run - time();
            if ($time_diff < 0) {
                $next_run_human = sprintf(
                    __('Overdue by %s', 'woo-kontor-sync'),
                    human_time_diff($next_run, time())
                );
            } else {
                $next_run_human = human_time_diff(time(), $next_run);
            }
        }

        return [
            'enabled'            => $enabled,
            'interval'           => $interval,
            'interval_display'   => $interval_display,
            'next_run'           => $next_run,
            'next_run_human'     => $next_run_human,
            'next_run_formatted' => $next_run ? wp_date('Y-m-d H:i:s', $next_run) : null,
            'next_run_overdue'   => $next_run && ($next_run < time()),
            'last_sync'          => $last_sync,
            'last_sync_human'    => $last_sync ? human_time_diff($last_sync, time()) . ' ' . __('ago', 'woo-kontor-sync') : null,
            'watchdog_last'      => $watchdog_last,
            'watchdog_last_human' => $watchdog_last ? human_time_diff($watchdog_last, time()) . ' ' . __('ago', 'woo-kontor-sync') : null,
            'order_sync_enabled'      => get_option('wks_order_sync_enabled', false),
            'order_sync_next_run'     => wp_next_scheduled('wks_order_sync_event'),
            'order_sync_last_run'     => get_option('wks_last_order_sync_time'),
            'order_sync_last_run_human' => get_option('wks_last_order_sync_time')
                ? human_time_diff(get_option('wks_last_order_sync_time'), time()) . ' ' . __('ago', 'woo-kontor-sync')
                : null,
            'stock_sync_enabled'      => get_option('wks_stock_sync_enabled', false),
            'stock_sync_next_run'     => wp_next_scheduled('wks_stock_sync_event'),
            'stock_sync_last_run'     => get_option('wks_last_stock_sync_time'),
            'stock_sync_last_run_human' => get_option('wks_last_stock_sync_time')
                ? human_time_diff(get_option('wks_last_stock_sync_time'), time()) . ' ' . __('ago', 'woo-kontor-sync')
                : null,
        ];
    }

    /**
     * Check if sync is currently running
     */
    public function is_running() {
        return get_transient('wks_sync_running') ? true : false;
    }

    /**
     * Set running status
     */
    public function set_running($running = true) {
        if ($running) {
            set_transient('wks_sync_running', true, 30 * MINUTE_IN_SECONDS);
        } else {
            delete_transient('wks_sync_running');
        }
    }
}
