<?php
/**
 * License Management Class
 *
 * Handles license validation, activation, deactivation, and status checks
 * using the 3AG License API v3.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WKS_License {

    const API_URL      = 'https://3ag.app/api/v3';
    const PRODUCT_SLUG = 'woo-kontor-sync';

    const OPTION_LICENSE_KEY    = 'wks_license_key';
    const OPTION_LICENSE_STATUS = 'wks_license_status';
    const OPTION_LICENSE_DATA   = 'wks_license_data';
    const OPTION_LAST_CHECK     = 'wks_license_last_check';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wks_license_check', [$this, 'daily_check']);

        if (!wp_next_scheduled('wks_license_check')) {
            wp_schedule_event(time(), 'daily', 'wks_license_check');
        }
    }

    /**
     * Get current domain
     */
    private function get_domain() {
        return wks_get_domain();
    }

    /**
     * Make API request
     */
    private function api_request($endpoint, $body) {
        $response = wp_remote_post(self::API_URL . $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code          = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data          = json_decode($response_body, true);

        if ($code === 204) {
            return [
                'success' => true,
                'message' => __('Operation successful.', 'woo-kontor-sync'),
            ];
        }

        if ($code >= 200 && $code < 300) {
            return [
                'success' => true,
                'data'    => isset($data['data']) ? $data['data'] : $data,
            ];
        }

        return [
            'success' => false,
            'message' => isset($data['message']) ? $data['message'] : __('Unknown error occurred.', 'woo-kontor-sync'),
            'errors'  => isset($data['errors']) ? $data['errors'] : [],
        ];
    }

    /**
     * Validate license
     */
    public function validate($license_key = null) {
        if (!$license_key) {
            $license_key = get_option(self::OPTION_LICENSE_KEY);
        }

        if (!$license_key) {
            return [
                'success' => false,
                'message' => __('No license key found.', 'woo-kontor-sync'),
            ];
        }

        $result = $this->api_request('/licenses/validate', [
            'license_key'  => $license_key,
            'product_slug' => self::PRODUCT_SLUG,
            'domain'       => $this->get_domain(),
        ]);

        if ($result['success'] && isset($result['data'])) {
            update_option(self::OPTION_LAST_CHECK, time());

            $is_valid     = !empty($result['data']['valid']);
            $is_activated = !empty($result['data']['activated']);

            if ($is_valid && $is_activated) {
                update_option(self::OPTION_LICENSE_STATUS, 'active');
                update_option(self::OPTION_LICENSE_DATA, $result['data']);
            } elseif ($is_valid && !$is_activated) {
                update_option(self::OPTION_LICENSE_STATUS, 'not_activated');
                update_option(self::OPTION_LICENSE_DATA, $result['data']);
            } else {
                update_option(self::OPTION_LICENSE_STATUS, 'invalid');
                update_option(self::OPTION_LICENSE_DATA, $result['data']);
            }
        } elseif (!$result['success']) {
            update_option(self::OPTION_LICENSE_STATUS, 'invalid');
            delete_option(self::OPTION_LICENSE_DATA);
        }

        return $result;
    }

    /**
     * Activate license
     */
    public function activate($license_key) {
        if (empty($license_key)) {
            return [
                'success' => false,
                'message' => __('License key is required.', 'woo-kontor-sync'),
            ];
        }

        $result = $this->api_request('/licenses/activate', [
            'license_key'  => $license_key,
            'product_slug' => self::PRODUCT_SLUG,
            'domain'       => $this->get_domain(),
        ]);

        if ($result['success'] && isset($result['data'])) {
            update_option(self::OPTION_LICENSE_KEY, $license_key);
            update_option(self::OPTION_LICENSE_STATUS, 'active');
            update_option(self::OPTION_LICENSE_DATA, $result['data']);
            update_option(self::OPTION_LAST_CHECK, time());
        }

        return $result;
    }

    /**
     * Deactivate license
     */
    public function deactivate($license_key = null) {
        if (!$license_key) {
            $license_key = get_option(self::OPTION_LICENSE_KEY);
        }

        if (!$license_key) {
            return [
                'success' => false,
                'message' => __('No license key found.', 'woo-kontor-sync'),
            ];
        }

        $result = $this->api_request('/licenses/deactivate', [
            'license_key'  => $license_key,
            'product_slug' => self::PRODUCT_SLUG,
            'domain'       => $this->get_domain(),
        ]);

        $this->clear_local_data();

        return $result;
    }

    /**
     * Clear all local license data
     */
    private function clear_local_data() {
        delete_option(self::OPTION_LICENSE_KEY);
        delete_option(self::OPTION_LICENSE_STATUS);
        delete_option(self::OPTION_LICENSE_DATA);
        delete_option(self::OPTION_LAST_CHECK);
        delete_option('wks_sync_disabled_by_license');
    }

    /**
     * Daily license verification
     */
    public function daily_check() {
        $license_key = get_option(self::OPTION_LICENSE_KEY);
        if (!$license_key) {
            return;
        }

        $result = $this->validate($license_key);

        $is_valid = $result['success']
            && isset($result['data']['valid'])
            && $result['data']['valid'] === true;

        $is_activated = $result['success']
            && isset($result['data']['activated'])
            && $result['data']['activated'] === true;

        if ($is_valid && $is_activated) {
            $this->maybe_restore_sync();
        } else {
            $this->disable_sync_due_to_license($result);
        }
    }

    /**
     * Disable sync due to license issues
     */
    private function disable_sync_due_to_license($result) {
        $currently_enabled = get_option('wks_enabled', false);

        if (!$currently_enabled) {
            return;
        }

        update_option('wks_sync_disabled_by_license', true);
        update_option('wks_enabled', false);
        wp_clear_scheduled_hook('wks_sync_event');

        $reason   = __('License validation failed.', 'woo-kontor-sync');
        $is_valid = isset($result['data']['valid']) && $result['data']['valid'] === true;

        if ($result['success'] && isset($result['data'])) {
            if (!$is_valid) {
                $status = isset($result['data']['status']) ? $result['data']['status'] : 'unknown';
                $reason = sprintf(__('License is %s.', 'woo-kontor-sync'), $status);
            } else {
                $reason = __('License is not activated on this domain.', 'woo-kontor-sync');
            }
        }

        if (class_exists('WKS_Logs') && WKS()->logs) {
            WKS()->logs->add([
                'type'    => 'license',
                'status'  => 'error',
                'message' => $reason . ' ' . __('Sync has been disabled.', 'woo-kontor-sync'),
            ]);
        }
    }

    /**
     * Restore sync if it was previously disabled due to license issues
     */
    private function maybe_restore_sync() {
        $was_disabled_by_license = get_option('wks_sync_disabled_by_license', false);

        if (!$was_disabled_by_license) {
            return;
        }

        delete_option('wks_sync_disabled_by_license');

        $api_host = get_option('wks_api_host', '');
        $api_key  = get_option('wks_api_key', '');
        if (empty($api_host) || empty($api_key)) {
            return;
        }

        update_option('wks_enabled', true);

        $interval = get_option('wks_schedule_interval', 'hourly');
        if (class_exists('WKS_Scheduler') && WKS()->scheduler) {
            WKS()->scheduler->schedule($interval);
        }

        if (class_exists('WKS_Logs') && WKS()->logs) {
            WKS()->logs->add([
                'type'    => 'license',
                'status'  => 'success',
                'message' => __('License is now valid. Sync has been automatically re-enabled.', 'woo-kontor-sync'),
            ]);
        }
    }

    /**
     * Check if license is valid and activated
     */
    public function is_valid() {
        $status      = get_option(self::OPTION_LICENSE_STATUS);
        $license_key = get_option(self::OPTION_LICENSE_KEY);

        return !empty($license_key) && $status === 'active';
    }

    /**
     * Check if license needs activation
     */
    public function needs_activation() {
        $status = get_option(self::OPTION_LICENSE_STATUS);
        return $status === 'not_activated';
    }

    /**
     * Get stored license data
     */
    public function get_data() {
        return get_option(self::OPTION_LICENSE_DATA, []);
    }

    /**
     * Get stored license key
     */
    public function get_key() {
        return get_option(self::OPTION_LICENSE_KEY, false);
    }

    /**
     * Get license status
     */
    public function get_status() {
        return get_option(self::OPTION_LICENSE_STATUS, '');
    }

    /**
     * Get last verification timestamp
     */
    public function get_last_check() {
        return get_option(self::OPTION_LAST_CHECK, false);
    }

    /**
     * Get license expiry date
     */
    public function get_expiry() {
        $data = $this->get_data();
        return isset($data['expires_at']) ? $data['expires_at'] : null;
    }

    /**
     * Check if license is expired
     */
    public function is_expired() {
        $expires_at = $this->get_expiry();

        if (!$expires_at) {
            return false;
        }

        $expiry_time = strtotime($expires_at);
        return $expiry_time < time();
    }

    /**
     * Get remaining days until expiry
     */
    public function get_remaining_days() {
        $expires_at = $this->get_expiry();

        if (!$expires_at) {
            return null;
        }

        $expiry_time = strtotime($expires_at);
        $diff        = $expiry_time - time();

        return max(0, (int) floor($diff / DAY_IN_SECONDS));
    }

    /**
     * Get activations info
     */
    public function get_activations() {
        $data = $this->get_data();
        return isset($data['activations']) ? $data['activations'] : ['limit' => 0, 'used' => 0];
    }

    /**
     * Get product name
     */
    public function get_product_name() {
        $data = $this->get_data();
        return isset($data['product']) ? $data['product'] : '';
    }

    /**
     * Get package/tier name
     */
    public function get_package() {
        $data = $this->get_data();
        return isset($data['package']) ? $data['package'] : '';
    }

    /**
     * Get license API status
     */
    public function get_api_status() {
        $data = $this->get_data();
        return isset($data['status']) ? $data['status'] : '';
    }
}
