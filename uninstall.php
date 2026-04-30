<?php
/**
 * Uninstall Woo Kontor Sync
 *
 * Removes all plugin data when the plugin is deleted via WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin options
$options = [
    'wks_api_host',
    'wks_api_key',
    'wks_image_prefix_url',
    'wks_schedule_interval',
    'wks_enabled',
    'wks_license_key',
    'wks_license_status',
    'wks_license_data',
    'wks_license_last_check',
    'wks_sync_disabled_by_license',
    'wks_last_sync',
    'wks_last_manual_sync',
    'wks_order_sync_enabled',
    'wks_order_statuses',
    'wks_order_platform_id',
    'wks_order_account_id',
    'wks_order_sales_channel',
    'wks_order_sync_interval',
];

foreach ($options as $option) {
    delete_option($option);
}

// Delete all transients
$transients = [
    'wks_sync_running',
    'wks_update_data',
    'wks_order_sync_running',
    'wks_last_manual_order_sync',
];

foreach ($transients as $transient) {
    delete_transient($transient);
}

// Clear all scheduled hooks
$cron_hooks = [
    'wks_sync_event',
    'wks_watchdog_check',
    'wks_license_check',
    'wks_update_check',
    'wks_order_sync_event',
];

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Drop the logs table
global $wpdb;
$table_name = $wpdb->prefix . 'wks_logs';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
