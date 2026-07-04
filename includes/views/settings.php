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

    <nav class="wssc-tabs" role="tablist" aria-label="<?php esc_attr_e('Settings sections', 'woo-kontor-sync'); ?>">
        <button type="button" class="wssc-tab is-active" data-tab="connection" role="tab">
            <span class="dashicons dashicons-rest-api"></span>
            <?php esc_html_e('Connection', 'woo-kontor-sync'); ?>
        </button>
        <button type="button" class="wssc-tab" data-tab="products" role="tab">
            <span class="dashicons dashicons-products"></span>
            <?php esc_html_e('Products', 'woo-kontor-sync'); ?>
        </button>
        <button type="button" class="wssc-tab" data-tab="stock" role="tab">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e('Stock', 'woo-kontor-sync'); ?>
        </button>
        <button type="button" class="wssc-tab" data-tab="orders" role="tab">
            <span class="dashicons dashicons-cart"></span>
            <?php esc_html_e('Orders', 'woo-kontor-sync'); ?>
        </button>
        <button type="button" class="wssc-tab" data-tab="categories" role="tab">
            <span class="dashicons dashicons-category"></span>
            <?php esc_html_e('Categories', 'woo-kontor-sync'); ?>
        </button>
    </nav>

    <form id="wssc-settings-form" class="wssc-form <?php echo !$license_valid ? 'wssc-form-disabled' : ''; ?>">

        <!-- ============================================================
             TAB: Connection
             ============================================================ -->
        <section class="wssc-tab-panel" data-tab="connection" role="tabpanel">
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

            <div class="wssc-section wssc-card">
                <div class="wssc-card-header">
                    <h2>
                        <span class="dashicons dashicons-store"></span>
                        <?php esc_html_e('Kontor Shop', 'woo-kontor-sync'); ?>
                    </h2>
                </div>
                <div class="wssc-card-body">
                    <div class="wssc-form-row">
                        <label for="wks-shop-id" class="wssc-label">
                            <?php esc_html_e('Active Shop', 'woo-kontor-sync'); ?>
                        </label>
                        <div class="wssc-input-group">
                            <select id="wks-shop-id" name="shop_id" class="wssc-select" <?php disabled(!$license_valid); ?>>
                                <option value=""><?php esc_html_e('— Select a shop —', 'woo-kontor-sync'); ?></option>
                                <?php if (!empty($shop_id)): ?>
                                    <option value="<?php echo esc_attr($shop_id); ?>" selected>
                                        <?php echo esc_html($shop_id); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <button type="button" id="wssc-fetch-shops" class="wssc-btn wssc-btn-secondary" <?php disabled(!$license_valid); ?>>
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Load Shops', 'woo-kontor-sync'); ?>
                            </button>
                        </div>
                        <p class="wssc-help-text">
                            <?php esc_html_e('The Kontor shop used by Category Management and Order Sync. Click "Load Shops" to fetch available shops from the API.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================
             TAB: Products
             ============================================================ -->
        <section class="wssc-tab-panel" data-tab="products" role="tabpanel" hidden>
            <div class="wssc-section wssc-card">
                <div class="wssc-card-header">
                    <h2>
                        <span class="dashicons dashicons-clock"></span>
                        <?php esc_html_e('Product Sync Schedule', 'woo-kontor-sync'); ?>
                    </h2>
                </div>
                <div class="wssc-card-body">
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
                                <?php esc_html_e('Pull products from Kontor on a schedule', 'woo-kontor-sync'); ?>
                            </span>
                        </div>
                    </div>

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
                </div>
            </div>

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

            <div class="wssc-section wssc-card">
                <div class="wssc-card-header">
                    <h2>
                        <span class="dashicons dashicons-tag"></span>
                        <?php esc_html_e('Shop Type (Shoptype)', 'woo-kontor-sync'); ?>
                    </h2>
                </div>
                <div class="wssc-card-body">
                    <div class="wssc-form-row">
                        <label for="wks-shoptype" class="wssc-label">
                            <?php esc_html_e('Shop type for product descriptions', 'woo-kontor-sync'); ?>
                        </label>
                        <select id="wks-shoptype" name="shoptype" class="wssc-select" <?php disabled(!$license_valid); ?>>
                            <?php foreach (['B2B', 'B2C', 'EDU'] as $opt): ?>
                                <option value="<?php echo esc_attr($opt); ?>" <?php selected($shoptype, $opt); ?>><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="wssc-help-text">
                            <?php esc_html_e('Controls which Shoptitel / Kurztext / Langtext variant Kontor returns. Defaults to B2B.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================
             TAB: Stock
             ============================================================ -->
        <section class="wssc-tab-panel" data-tab="stock" role="tabpanel" hidden>
            <div class="wssc-section wssc-card">
                <div class="wssc-card-header">
                    <h2>
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Stock Sync', 'woo-kontor-sync'); ?>
                    </h2>
                </div>
                <div class="wssc-card-body">
                    <div class="wssc-form-row">
                        <label class="wssc-label">
                            <?php esc_html_e('Enable Stock Sync', 'woo-kontor-sync'); ?>
                        </label>
                        <div class="wssc-toggle-row">
                            <label class="wssc-switch">
                                <input type="checkbox" id="wks-stock-sync-enabled" name="stock_sync_enabled" value="1" <?php checked($stock_sync_enabled); ?> <?php disabled(!$license_valid); ?>>
                                <span class="wssc-slider"></span>
                            </label>
                            <span class="wssc-toggle-label">
                                <?php esc_html_e('Refresh WooCommerce stock quantities from Kontor on a schedule', 'woo-kontor-sync'); ?>
                            </span>
                        </div>
                        <p class="wssc-help-text">
                            <?php esc_html_e('Uses the lightweight search/stock endpoint to update only stock levels (Lagerbestand) without re-fetching product descriptions or images. Independent of the Product Sync schedule.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div class="wssc-form-row">
                        <label for="wks-stock-sync-interval" class="wssc-label">
                            <?php esc_html_e('Stock Sync Interval', 'woo-kontor-sync'); ?>
                        </label>
                        <select id="wks-stock-sync-interval" name="stock_sync_interval" class="wssc-select" <?php disabled(!$license_valid); ?>>
                            <?php foreach ($intervals as $key => $interval): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($stock_sync_interval, $key); ?>>
                                    <?php echo esc_html($interval['display']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="wssc-help-text">
                            <?php esc_html_e('How often stock quantities should be refreshed. A short interval (e.g. every 15 minutes) is recommended.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================
             TAB: Orders
             ============================================================ -->
        <section class="wssc-tab-panel" data-tab="orders" role="tabpanel" hidden>
            <div class="wssc-section wssc-card">
                <div class="wssc-card-header">
                    <h2>
                        <span class="dashicons dashicons-cart"></span>
                        <?php esc_html_e('Order Sync', 'woo-kontor-sync'); ?>
                    </h2>
                </div>
                <div class="wssc-card-body">
                    <div class="wssc-form-row">
                        <label class="wssc-label">
                            <?php esc_html_e('Enable Order Sync', 'woo-kontor-sync'); ?>
                        </label>
                        <div class="wssc-toggle-row">
                            <label class="wssc-switch">
                                <input type="checkbox" id="wks-order-sync-enabled" name="order_sync_enabled" value="1" <?php checked($order_sync_enabled); ?> <?php disabled(!$license_valid); ?>>
                                <span class="wssc-slider"></span>
                            </label>
                            <span class="wssc-toggle-label">
                                <?php esc_html_e('Upload WooCommerce orders to Kontor CRM', 'woo-kontor-sync'); ?>
                            </span>
                        </div>
                        <p class="wssc-help-text">
                            <?php esc_html_e('When enabled, orders are uploaded in real-time on status change and on a schedule as catch-up.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div class="wssc-form-row">
                        <label class="wssc-label">
                            <?php esc_html_e('Order Statuses to Sync', 'woo-kontor-sync'); ?>
                        </label>
                        <div class="wssc-checkbox-group">
                            <?php
                            $all_statuses = wc_get_order_statuses();
                            $selected_statuses = is_array($order_statuses) ? $order_statuses : ['processing', 'completed'];
                            foreach ($all_statuses as $status_key => $status_label):
                                $clean_key = str_replace('wc-', '', $status_key);
                            ?>
                                <label class="wssc-checkbox-label">
                                    <input type="checkbox"
                                           name="order_statuses[]"
                                           value="<?php echo esc_attr($clean_key); ?>"
                                           <?php checked(in_array($clean_key, $selected_statuses, true)); ?>
                                           <?php disabled(!$license_valid); ?>>
                                    <?php echo esc_html($status_label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="wssc-help-text">
                            <?php esc_html_e('Orders with these statuses will be uploaded to Kontor. Both real-time (on status change) and scheduled sync respect this setting.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div class="wssc-form-row">
                        <label for="wks-order-platform-id" class="wssc-label">
                            <?php esc_html_e('Platform ID', 'woo-kontor-sync'); ?>
                        </label>
                        <input type="text"
                               id="wks-order-platform-id"
                               name="order_platform_id"
                               value="<?php echo esc_attr($order_platform_id); ?>"
                               class="wssc-input"
                               placeholder="<?php echo esc_attr(wp_parse_url(home_url(), PHP_URL_HOST)); ?>"
                               <?php disabled(!$license_valid); ?>>
                        <p class="wssc-help-text">
                            <?php esc_html_e('A string identifying your platform (e.g. your shop name). Used as orderPlatformid in the API. Defaults to your site domain if empty.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div class="wssc-form-row">
                        <label for="wks-order-sales-channel" class="wssc-label">
                            <?php esc_html_e('Sales Channel Name', 'woo-kontor-sync'); ?>
                        </label>
                        <input type="text"
                               id="wks-order-sales-channel"
                               name="order_sales_channel"
                               value="<?php echo esc_attr($order_sales_channel); ?>"
                               class="wssc-input"
                               placeholder="Webshop"
                               <?php disabled(!$license_valid); ?>>
                        <p class="wssc-help-text">
                            <?php esc_html_e('The sales channel name sent with each order (e.g. "Webshop", "Online Store").', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div class="wssc-form-row">
                        <label for="wks-order-sync-interval" class="wssc-label">
                            <?php esc_html_e('Order Sync Interval', 'woo-kontor-sync'); ?>
                        </label>
                        <select id="wks-order-sync-interval" name="order_sync_interval" class="wssc-select" <?php disabled(!$license_valid); ?>>
                            <?php foreach ($intervals as $key => $interval): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($order_sync_interval, $key); ?>>
                                    <?php echo esc_html($interval['display']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="wssc-help-text">
                            <?php esc_html_e('How often the scheduled order sync should run as a catch-up for any missed real-time uploads.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="wssc-section wssc-card">
                <div class="wssc-card-header">
                    <h2>
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Order Status Sync (Kontor → WooCommerce)', 'woo-kontor-sync'); ?>
                    </h2>
                </div>
                <div class="wssc-card-body">
                    <div class="wssc-form-row">
                        <label class="wssc-label">
                            <?php esc_html_e('Enable Order Status Sync', 'woo-kontor-sync'); ?>
                        </label>
                        <div class="wssc-toggle-row">
                            <label class="wssc-switch">
                                <input type="checkbox" id="wks-status-sync-enabled" name="status_sync_enabled" value="1" <?php checked($status_sync_enabled); ?> <?php disabled(!$license_valid); ?>>
                                <span class="wssc-slider"></span>
                            </label>
                            <span class="wssc-toggle-label">
                                <?php esc_html_e('Pull order status & tracking from Kontor and update WooCommerce orders on a schedule', 'woo-kontor-sync'); ?>
                            </span>
                        </div>
                        <p class="wssc-help-text">
                            <?php esc_html_e('Only orders previously uploaded to Kontor are updated. Kontor drives the order status and shipment/tracking data (provider, tracking number, tracking URL).', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div class="wssc-form-row">
                        <label for="wks-status-sync-interval" class="wssc-label">
                            <?php esc_html_e('Status Sync Interval', 'woo-kontor-sync'); ?>
                        </label>
                        <select id="wks-status-sync-interval" name="status_sync_interval" class="wssc-select" <?php disabled(!$license_valid); ?>>
                            <?php foreach ($intervals as $key => $interval): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($status_sync_interval, $key); ?>>
                                    <?php echo esc_html($interval['display']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="wssc-help-text">
                            <?php esc_html_e('How often WooCommerce should fetch current order status/tracking from Kontor.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div class="wssc-form-row">
                        <label class="wssc-label">
                            <?php esc_html_e('Status Mapping', 'woo-kontor-sync'); ?>
                        </label>
                        <p class="wssc-help-text" style="margin-top: 0;">
                            <?php esc_html_e('Kontor order statuses are mapped to WooCommerce statuses automatically as follows. Tracking data (provider, tracking number, tracking URL) is synced for every order regardless of status.', 'woo-kontor-sync'); ?>
                        </p>

                        <?php $wc_statuses = wc_get_order_statuses(); ?>
                        <table class="widefat striped" style="max-width: 520px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Kontor status', 'woo-kontor-sync'); ?></th>
                                    <th><?php esc_html_e('WooCommerce status', 'woo-kontor-sync'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (WKS_Sync::KONTOR_STATUS_MAP as $from => $to): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($from); ?></code></td>
                                        <td>
                                            <?php
                                            echo isset($wc_statuses['wc-' . $to])
                                                ? esc_html($wc_statuses['wc-' . $to])
                                                : esc_html($to);
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================
             TAB: Categories
             ============================================================ -->
        <section class="wssc-tab-panel" data-tab="categories" role="tabpanel" hidden>
            <?php if (empty($shop_id)): ?>
                <div class="wssc-notice wssc-notice-warning">
                    <span class="dashicons dashicons-info"></span>
                    <div>
                        <strong><?php esc_html_e('Shop not selected', 'woo-kontor-sync'); ?></strong>
                        <p><?php esc_html_e('Category management needs an active Kontor shop. Pick one in the Connection tab and save first.', 'woo-kontor-sync'); ?></p>
                        <button type="button" class="wssc-btn wssc-btn-secondary wssc-btn-sm wssc-tab-link" data-tab="connection">
                            <?php esc_html_e('Go to Connection tab', 'woo-kontor-sync'); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="wssc-section wssc-card">
                <div class="wssc-card-header">
                    <h2>
                        <span class="dashicons dashicons-category"></span>
                        <?php esc_html_e('Category Management', 'woo-kontor-sync'); ?>
                    </h2>
                </div>
                <div class="wssc-card-body">
                    <p class="wssc-help-text" style="margin-bottom: 16px;">
                        <?php esc_html_e('View categories stored in Kontor for the selected shop, or push your WooCommerce categories to Kontor.', 'woo-kontor-sync'); ?>
                    </p>

                    <div class="wssc-category-actions">
                        <button type="button" id="wssc-fetch-categories" class="wssc-btn wssc-btn-secondary wssc-btn-sm" <?php disabled(!$license_valid); ?>>
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Fetch Kontor Categories', 'woo-kontor-sync'); ?>
                        </button>
                        <button type="button" id="wssc-push-categories" class="wssc-btn wssc-btn-primary wssc-btn-sm" <?php disabled(!$license_valid); ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e('Push WooCommerce Categories to Kontor', 'woo-kontor-sync'); ?>
                        </button>
                    </div>

                    <div class="wssc-form-row" style="margin-top: 12px;">
                        <label class="wssc-label">
                            <input type="checkbox" id="wks-overwrite-all" value="1" checked <?php disabled(!$license_valid); ?>>
                            <?php esc_html_e('Overwrite all categories (replaces entire category tree for the shop)', 'woo-kontor-sync'); ?>
                        </label>
                        <p class="wssc-help-text wssc-help-text-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e('Warning: Overwrite mode replaces all categories. Existing product-to-category assignments in Kontor may be lost if category IDs change.', 'woo-kontor-sync'); ?>
                        </p>
                    </div>

                    <div id="wssc-kontor-categories" class="wssc-kontor-categories" style="display:none;">
                        <h4><?php esc_html_e('Kontor Categories', 'woo-kontor-sync'); ?></h4>
                        <div id="wssc-kontor-categories-list" class="wssc-kontor-categories-list"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Save Bar (sticky) -->
        <div class="wssc-form-actions wssc-sticky-bottom">
            <button type="submit" class="wssc-btn wssc-btn-primary wssc-btn-lg" <?php disabled(!$license_valid); ?>>
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e('Save Settings', 'woo-kontor-sync'); ?>
            </button>
        </div>
    </form>
</div>
