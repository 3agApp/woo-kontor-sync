<?php
/**
 * Plugin Updater Class
 *
 * Handles automatic updates from GitHub Releases.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WKS_Updater {

    const GITHUB_OWNER    = '3agApp';
    const GITHUB_REPO     = 'woo-kontor-sync';
    const PRODUCT_SLUG    = 'woo-kontor-sync';
    const CACHE_KEY       = 'wks_update_data';
    const CACHE_EXPIRATION = 43200; // 12 hours

    /**
     * Constructor
     */
    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
        add_filter('auto_update_plugin', [$this, 'enable_auto_update'], 10, 2);

        add_action('wks_update_check', [$this, 'scheduled_check']);
    }

    /**
     * Enable auto-updates for this plugin
     */
    public function enable_auto_update($update, $item) {
        if (isset($item->plugin) && $item->plugin === WKS_PLUGIN_BASENAME) {
            return true;
        }
        return $update;
    }

    /**
     * Get GitHub API URL for latest release
     */
    private function get_github_api_url() {
        return sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_OWNER,
            self::GITHUB_REPO
        );
    }

    /**
     * Get GitHub repository URL
     */
    private function get_github_repo_url() {
        return sprintf(
            'https://github.com/%s/%s',
            self::GITHUB_OWNER,
            self::GITHUB_REPO
        );
    }

    /**
     * Check for updates via GitHub API
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $update_data = $this->get_update_data();

        if (!$update_data || empty($update_data['version'])) {
            return $transient;
        }

        $current_version = WKS_VERSION;

        if (version_compare($current_version, $update_data['version'], '<')) {
            $transient->response[WKS_PLUGIN_BASENAME] = (object) [
                'slug'         => self::PRODUCT_SLUG,
                'plugin'       => WKS_PLUGIN_BASENAME,
                'new_version'  => $update_data['version'],
                'url'          => $this->get_github_repo_url(),
                'package'      => $update_data['download_url'],
                'icons'        => [
                    '1x' => WKS_PLUGIN_URL . 'assets/images/icon-128x128.png',
                    '2x' => WKS_PLUGIN_URL . 'assets/images/icon-256x256.png',
                ],
                'banners'      => [
                    'low'  => WKS_PLUGIN_URL . 'assets/images/banner-772x250.png',
                    'high' => WKS_PLUGIN_URL . 'assets/images/banner-1544x500.png',
                ],
                'tested'       => '6.7',
                'requires_php' => '7.4',
                'requires'     => '5.8',
            ];
        } else {
            $transient->no_update[WKS_PLUGIN_BASENAME] = (object) [
                'slug'        => self::PRODUCT_SLUG,
                'plugin'      => WKS_PLUGIN_BASENAME,
                'new_version' => $current_version,
                'url'         => $this->get_github_repo_url(),
            ];
        }

        return $transient;
    }

    /**
     * Get update data from GitHub API or cache
     */
    public function get_update_data($force = false) {
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = wp_remote_get($this->get_github_api_url(), [
            'timeout' => 30,
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('WKS GitHub Update Check Error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data)) {
            if ($code === 404) {
                error_log('WKS GitHub Update Check: No releases found');
            } else {
                error_log('WKS GitHub Update Check HTTP ' . $code . ': ' . ($data['message'] ?? 'Unknown error'));
            }
            return null;
        }

        $version      = isset($data['tag_name']) ? ltrim($data['tag_name'], 'v') : null;
        $download_url = null;

        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (isset($asset['name']) && strpos($asset['name'], '-latest.zip') !== false) {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
                if (isset($asset['name']) && preg_match('/\.zip$/', $asset['name'])) {
                    $download_url = $asset['browser_download_url'];
                }
            }
        }

        if (empty($download_url) && !empty($data['zipball_url'])) {
            $download_url = $data['zipball_url'];
        }

        $update_data = [
            'version'      => $version,
            'download_url' => $download_url,
            'changelog'    => $data['body'] ?? '',
            'release_date' => $data['published_at'] ?? null,
            'checked'      => time(),
        ];

        set_transient(self::CACHE_KEY, $update_data, self::CACHE_EXPIRATION);

        return $update_data;
    }

    /**
     * Provide plugin info for the WordPress plugin info popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== self::PRODUCT_SLUG) {
            return $result;
        }

        $update_data = $this->get_update_data();

        $plugin_info = (object) [
            'name'           => 'Woo Kontor Sync',
            'slug'           => self::PRODUCT_SLUG,
            'version'        => $update_data['version'] ?? WKS_VERSION,
            'author'         => '<a href="https://github.com/' . self::GITHUB_OWNER . '">3agApp</a>',
            'author_profile' => 'https://github.com/' . self::GITHUB_OWNER,
            'homepage'       => $this->get_github_repo_url(),
            'requires'       => '5.8',
            'tested'         => '6.7',
            'requires_php'   => '7.4',
            'downloaded'     => 0,
            'last_updated'   => $update_data['release_date'] ?? date('Y-m-d H:i:s'),
            'sections'       => [
                'description'  => $this->get_plugin_description(),
                'installation' => $this->get_installation_instructions(),
                'changelog'    => $this->get_changelog($update_data),
            ],
            'download_link'  => $update_data['download_url'] ?? '',
            'banners'        => [
                'low'  => WKS_PLUGIN_URL . 'assets/images/banner-772x250.png',
                'high' => WKS_PLUGIN_URL . 'assets/images/banner-1544x500.png',
            ],
        ];

        return $plugin_info;
    }

    /**
     * Get plugin description
     */
    private function get_plugin_description() {
        return '<p>Woo Kontor Sync is a premium WordPress plugin that automatically synchronizes your WooCommerce products from Kontor CRM.</p>
        <h4>Key Features:</h4>
        <ul>
            <li><strong>Automatic Sync</strong> – Schedule product imports from every 5 minutes to weekly</li>
            <li><strong>Full Product Import</strong> – Imports name, description, price, stock, weight, images, and more</li>
            <li><strong>Category Mapping</strong> – Automatically creates and assigns product categories</li>
            <li><strong>Image Sideloading</strong> – Downloads and attaches product images with change detection</li>
            <li><strong>Watchdog Cron</strong> – Automatic recovery if scheduled sync stops working</li>
            <li><strong>Detailed Logs</strong> – Complete history of all sync operations</li>
            <li><strong>Modern UI</strong> – Clean, intuitive admin interface</li>
        </ul>
        <p><a href="' . esc_url($this->get_github_repo_url()) . '" target="_blank">View on GitHub</a></p>';
    }

    /**
     * Get installation instructions
     */
    private function get_installation_instructions() {
        return '<ol>
            <li>Download the latest release from <a href="' . esc_url($this->get_github_repo_url() . '/releases') . '">GitHub Releases</a></li>
            <li>Upload the plugin files to <code>/wp-content/plugins/woo-kontor-sync</code></li>
            <li>Activate the plugin through the \'Plugins\' screen in WordPress</li>
            <li>Go to Kontor Sync → License to activate your license key</li>
            <li>Configure your API settings in Kontor Sync → Settings</li>
            <li>Enable scheduled sync or run a manual sync from the Dashboard</li>
        </ol>';
    }

    /**
     * Get changelog
     */
    private function get_changelog($update_data = null) {
        if (!empty($update_data['changelog'])) {
            $changelog = $update_data['changelog'];

            $changelog = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $changelog);
            $changelog = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $changelog);
            $changelog = preg_replace('/^[*-] (.+)$/m', '<li>$1</li>', $changelog);
            $changelog = preg_replace('/(<li>.+<\/li>\n?)+/s', '<ul>$0</ul>', $changelog);
            $changelog = nl2br($changelog);

            return $changelog;
        }

        $readme_file = WKS_PLUGIN_DIR . 'readme.txt';

        if (!file_exists($readme_file)) {
            return '<p>See the <a href="' . esc_url($this->get_github_repo_url() . '/releases') . '">GitHub releases</a> for full changelog.</p>';
        }

        $readme = file_get_contents($readme_file);

        if (preg_match('/== Changelog ==(.+?)(?:== |$)/s', $readme, $matches)) {
            $changelog = trim($matches[1]);
            $changelog = preg_replace('/^= (.+?) =$/m', '<h4>$1</h4>', $changelog);
            $changelog = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $changelog);
            $changelog = preg_replace('/(<li>.+<\/li>\n?)+/s', '<ul>$0</ul>', $changelog);
            return $changelog;
        }

        return '<p>See the <a href="' . esc_url($this->get_github_repo_url() . '/releases') . '">GitHub releases</a> for full changelog.</p>';
    }

    /**
     * After plugin install, rename directory if needed
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $is_our_plugin = false;

        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === WKS_PLUGIN_BASENAME) {
            $is_our_plugin = true;
        } elseif (isset($result['destination_name']) && dirname(WKS_PLUGIN_BASENAME) === $result['destination_name']) {
            $is_our_plugin = true;
        }

        if (!$is_our_plugin) {
            return $response;
        }

        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (empty($wp_filesystem) || !is_object($wp_filesystem)) {
            return $response;
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname(WKS_PLUGIN_BASENAME);

        if ($wp_filesystem->exists($result['destination']) && $result['destination'] !== $plugin_dir) {
            $wp_filesystem->move($result['destination'], $plugin_dir);
            $result['destination'] = $plugin_dir;
        }

        activate_plugin(WKS_PLUGIN_BASENAME);

        if (!wp_next_scheduled('wks_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wks_watchdog_check');
        }
        if (!wp_next_scheduled('wks_license_check')) {
            wp_schedule_event(time(), 'daily', 'wks_license_check');
        }

        return $response;
    }

    /**
     * Scheduled update check
     */
    public function scheduled_check() {
        $this->get_update_data(true);
    }

    /**
     * Clear update cache
     */
    public function clear_cache() {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Force check for updates
     */
    public function force_check() {
        $this->clear_cache();
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
}
