<?php
/**
 * Settings View
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wssc-wrap">
    <div class="wssc-header">
        <div class="wssc-header-left">
            <h1><?php esc_html_e('Settings', 'woo-kontor-sync'); ?></h1>
            <p class="wssc-subtitle"><?php esc_html_e('Configure your Kontor CRM synchronization settings', 'woo-kontor-sync'); ?></p>
        </div>
    </div>

    <?php if (!$license_valid): ?>
        <div class="wssc-notice wssc-notice-warning">
            <span class="dashicons dashicons-lock"></span>
            <div>
                <strong><?php esc_html_e('License Required', 'woo-kontor-sync'); ?></strong>
                <p><?php esc_html_e('Please activate your license before configuring settings.', 'woo-kontor-sync'); ?></p>
                <a href="<?php echo esc_url(WKS_Admin::get_page_url('license')); ?>" class="wssc-btn wssc-btn-primary wssc-btn-sm">
                    <?php esc_html_e('Activate License', 'woo-kontor-sync'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <form id="wssc-settings-form" class="wssc-form <?php echo !$license_valid ? 'wssc-form-disabled' : ''; ?>">
        <!-- API Configuration -->
        <div class="wssc-section wssc-card">
            <div class="wssc-card-header">
                <h2>
                    <span class="dashicons dashicons-rest-api"></span>
                    <?php esc_html_e('Kontor API Configuration', 'woo-kontor-sync'); ?>
                </h2>
            </div>
            <div class="wssc-card-body">
                <div class="wssc-form-row">
                    <label for="wks-api-host" class="wssc-label">
                        <?php esc_html_e('API Host URL', 'woo-kontor-sync'); ?>
                        <span class="wssc-required">*</span>
                    </label>
                    <div class="wssc-input-group">
                        <input type="url"
                               id="wks-api-host"
                               name="api_host"
                               value="<?php echo esc_attr($api_host); ?>"
                               class="wssc-input wssc-input-lg"
                               placeholder="https://sp3api.kontor-crm.de"
                               <?php disabled(!$license_valid); ?>>
                        <button type="button" id="wssc-test-connection" class="wssc-btn wssc-btn-secondary" <?php disabled(!$license_valid); ?>>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e('Test Connection', 'woo-kontor-sync'); ?>
                        </button>
                    </div>
                    <p class="wssc-help-text">
                        <?php esc_html_e('Enter the Kontor CRM API host URL (without trailing slash).', 'woo-kontor-sync'); ?>
                    </p>
                </div>

                <div class="wssc-form-row">
                    <label for="wks-api-key" class="wssc-label">
                        <?php esc_html_e('API Key', 'woo-kontor-sync'); ?>
                        <span class="wssc-required">*</span>
                    </label>
                    <input type="password"
                           id="wks-api-key"
                           name="api_key"
                           value="<?php echo esc_attr($api_key); ?>"
                           class="wssc-input wssc-input-lg"
                           placeholder="<?php esc_attr_e('Your Kontor API key', 'woo-kontor-sync'); ?>"
                           <?php disabled(!$license_valid); ?>>
                    <p class="wssc-help-text">
                        <?php esc_html_e('Enter the API key for authenticating with Kontor CRM.', 'woo-kontor-sync'); ?>
                    </p>
                </div>

                <div class="wssc-form-row">
                    <label for="wks-image-prefix-url" class="wssc-label">
                        <?php esc_html_e('Image Prefix URL', 'woo-kontor-sync'); ?>
                    </label>
                    <input type="url"
                           id="wks-image-prefix-url"
                           name="image_prefix_url"
                           value="<?php echo esc_attr($image_prefix_url); ?>"
                           class="wssc-input wssc-input-lg"
                           placeholder="https://example.com/images/"
                           <?php disabled(!$license_valid); ?>>
                    <p class="wssc-help-text">
                        <?php esc_html_e('Base URL prepended to image filenames from Kontor (e.g. MainImageURL, ImageURL_1, etc). Include trailing slash.', 'woo-kontor-sync'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Manufacturer Filter -->
        <div class="wssc-section wssc-card">
            <div class="wssc-card-header">
                <h2>
                    <span class="dashicons dashicons-filter"></span>
                    <?php esc_html_e('Hersteller (Manufacturer) Filter', 'woo-kontor-sync'); ?>
                </h2>
            </div>
            <div class="wssc-card-body">
                <div class="wssc-form-row">
                    <label for="wks-manufacturer-input" class="wssc-label">
                        <?php esc_html_e('Manufacturer IDs to Import', 'woo-kontor-sync'); ?>
                    </label>
                    <div class="wssc-tags-wrapper" id="wks-manufacturer-tags-wrapper">
                        <?php
                        $manufacturers = array_filter(array_map('trim', explode(',', $manufacturer_filter)));
                        foreach ($manufacturers as $mfr): ?>
                            <span class="wssc-tag">
                                <?php echo esc_html($mfr); ?>
                                <button type="button" class="wssc-tag-remove" data-value="<?php echo esc_attr($mfr); ?>">&times;</button>
                            </span>
                        <?php endforeach; ?>
                        <input type="text"
                               id="wks-manufacturer-input"
                               class="wssc-tags-input"
                               inputmode="numeric"
                               autocomplete="off"
                               placeholder="<?php esc_attr_e('Type Hersteller ID and press Enter…', 'woo-kontor-sync'); ?>"
                               <?php disabled(!$license_valid); ?>>
                    </div>
                    <input type="hidden" id="wks-manufacturer-filter" name="manufacturer_filter" value="<?php echo esc_attr($manufacturer_filter); ?>">
                    <p class="wssc-help-text">
                        <?php esc_html_e('Use numeric Hersteller IDs only (you can paste multiple values; commas/spaces are supported). Leave empty to import all products.', 'woo-kontor-sync'); ?>
                    </p>
                    <div class="wssc-fetch-manufacturers">
                        <button type="button" id="wssc-fetch-manufacturers" class="wssc-btn wssc-btn-secondary wssc-btn-sm" <?php disabled(!$license_valid); ?>>
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Fetch Available Manufacturers from API', 'woo-kontor-sync'); ?>
                        </button>
                        <div id="wssc-manufacturers-list" class="wssc-manufacturers-list" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Configuration -->
        <div class="wssc-section wssc-card">
            <div class="wssc-card-header">
                <h2>
                    <span class="dashicons dashicons-clock"></span>
                    <?php esc_html_e('Schedule Configuration', 'woo-kontor-sync'); ?>
                </h2>
            </div>
            <div class="wssc-card-body">
                <div class="wssc-form-row">
                    <label for="wks-schedule-interval" class="wssc-label">
                        <?php esc_html_e('Sync Interval', 'woo-kontor-sync'); ?>
                    </label>
                    <select id="wks-schedule-interval" name="schedule_interval" class="wssc-select" <?php disabled(!$license_valid); ?>>
                        <?php foreach ($intervals as $key => $interval): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($current_interval, $key); ?>>
                                <?php echo esc_html($interval['display']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="wssc-help-text">
                        <?php esc_html_e('How often the product sync should run automatically.', 'woo-kontor-sync'); ?>
                    </p>
                </div>

                <div class="wssc-form-row">
                    <label class="wssc-label">
                        <?php esc_html_e('Enable Automatic Sync', 'woo-kontor-sync'); ?>
                    </label>
                    <div class="wssc-toggle-row">
                        <label class="wssc-switch">
                            <input type="checkbox" id="wks-enabled" name="enabled" value="1" <?php checked($enabled); ?> <?php disabled(!$license_valid); ?>>
                            <span class="wssc-slider"></span>
                        </label>
                        <span class="wssc-toggle-label">
                            <?php esc_html_e('Enable scheduled product synchronization', 'woo-kontor-sync'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Box -->
        <div class="wssc-section wssc-card wssc-card-info">
            <div class="wssc-card-body">
                <div class="wssc-info-grid">
                    <div class="wssc-info-item">
                        <span class="dashicons dashicons-shield"></span>
                        <div>
                            <strong><?php esc_html_e('Watchdog Protection', 'woo-kontor-sync'); ?></strong>
                            <p><?php esc_html_e('A watchdog cron runs every hour to ensure the sync schedule is working properly.', 'woo-kontor-sync'); ?></p>
                        </div>
                    </div>
                    <div class="wssc-info-item">
                        <span class="dashicons dashicons-performance"></span>
                        <div>
                            <strong><?php esc_html_e('Paginated API Requests', 'woo-kontor-sync'); ?></strong>
                            <p><?php esc_html_e('Products are fetched in batches using Kontor API pagination for optimal performance.', 'woo-kontor-sync'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="wssc-form-actions">
            <button type="submit" class="wssc-btn wssc-btn-primary wssc-btn-lg" <?php disabled(!$license_valid); ?>>
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e('Save Settings', 'woo-kontor-sync'); ?>
            </button>
        </div>
    </form>
</div>
