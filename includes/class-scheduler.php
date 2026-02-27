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
     * Schedule sync
     */
    public function schedule($interval = null, $force = false) {
        if (!$interval) {
            $interval = get_option('wks_schedule_interval', 'hourly');
        }

        $current_interval = get_option('wks_schedule_interval', 'hourly');
        $next_scheduled   = wp_next_scheduled('wks_sync_event');
        $interval_seconds = $this->get_interval_seconds($interval);

        if (!$force && $next_scheduled) {
            $time_until_next = $next_scheduled - time();

            if ($interval === $current_interval && $time_until_next > 0 && $time_until_next <= $interval_seconds) {
                if (!wp_next_scheduled('wks_watchdog_check')) {
                    wp_schedule_event(time(), 'hourly', 'wks_watchdog_check');
                }
                return true;
            }

            if ($interval !== $current_interval && $time_until_next > 0 && $time_until_next <= $interval_seconds) {
                update_option('wks_schedule_interval', $interval);
                if (!wp_next_scheduled('wks_watchdog_check')) {
                    wp_schedule_event(time(), 'hourly', 'wks_watchdog_check');
                }
                return true;
            }
        }

        $this->unschedule();

        wp_schedule_event(time() + $interval_seconds, $interval, 'wks_sync_event');

        if (!wp_next_scheduled('wks_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wks_watchdog_check');
        }

        update_option('wks_schedule_interval', $interval);
        update_option('wks_last_scheduled', time());

        return true;
    }

    /**
     * Unschedule sync
     */
    public function unschedule() {
        wp_clear_scheduled_hook('wks_sync_event');
        return true;
    }

    /**
     * Reschedule sync (called after each sync)
     */
    public function reschedule() {
        $enabled = get_option('wks_enabled', false);

        if (!$enabled) {
            return;
        }

        $interval = get_option('wks_schedule_interval', 'hourly');

        wp_clear_scheduled_hook('wks_sync_event');
        wp_schedule_event(time() + $this->get_interval_seconds($interval), $interval, 'wks_sync_event');
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
     * Watchdog check
     */
    public function watchdog_check() {
        $enabled = get_option('wks_enabled', false);

        if (!$enabled) {
            return;
        }

        if (!WKS()->license->is_valid()) {
            WKS()->logs->add([
                'type'    => 'watchdog',
                'status'  => 'warning',
                'message' => __('Watchdog: License invalid. Sync disabled.', 'woo-kontor-sync'),
            ]);

            update_option('wks_enabled', false);
            $this->unschedule();
            return;
        }

        $next_run = wp_next_scheduled('wks_sync_event');

        if (!$next_run) {
            $interval = get_option('wks_schedule_interval', 'hourly');
            wp_schedule_event(time(), $interval, 'wks_sync_event');

            WKS()->logs->add([
                'type'    => 'watchdog',
                'status'  => 'warning',
                'message' => __('Watchdog: Sync cron was missing. Rescheduled successfully.', 'woo-kontor-sync'),
            ]);

            return;
        }

        $interval           = get_option('wks_schedule_interval', 'hourly');
        $interval_seconds   = $this->get_interval_seconds($interval);
        $overdue_threshold  = $interval_seconds * 2;
        $time_diff          = $next_run - time();

        if ($time_diff < -$overdue_threshold) {
            wp_clear_scheduled_hook('wks_sync_event');
            wp_schedule_event(time(), $interval, 'wks_sync_event');

            WKS()->logs->add([
                'type'    => 'watchdog',
                'status'  => 'warning',
                'message' => __('Watchdog: Sync cron was stuck/overdue. Rescheduled successfully.', 'woo-kontor-sync'),
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
