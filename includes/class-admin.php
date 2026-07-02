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
        add_action('admin_post_wks_export_product_category_excel', [$this, 'export_product_category_excel']);
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
                'sync_running'       => __('Sync in progress...', 'woo-kontor-sync'),
                'sync_complete'      => __('Sync completed!', 'woo-kontor-sync'),
                'sync_error'         => __('Sync failed!', 'woo-kontor-sync'),
                'confirm_sync'       => __('Are you sure you want to run a manual sync now?', 'woo-kontor-sync'),
                'confirm_order_sync' => __('Are you sure you want to upload orders to Kontor now?', 'woo-kontor-sync'),
                'order_sync_running' => __('Syncing orders...', 'woo-kontor-sync'),
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

        register_setting('wks_settings', 'wks_schedule_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
        ]);

        register_setting('wks_settings', 'wks_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        register_setting('wks_settings', 'wks_order_sync_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        register_setting('wks_settings', 'wks_order_statuses', [
            'type'    => 'array',
            'default' => ['processing', 'completed'],
        ]);

        register_setting('wks_settings', 'wks_order_platform_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('wks_settings', 'wks_order_sales_channel', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Webshop',
        ]);

        register_setting('wks_settings', 'wks_order_sync_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'hourly',
        ]);

        register_setting('wks_settings', 'wks_manufacturer_filter', [
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }

                $parts = preg_split('/[\s,]+/', sanitize_text_field((string) $value));
                $parts = is_array($parts) ? $parts : [];

                $ids = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '' && preg_match('/^\d+$/', $part)) {
                        $ids[] = $part;
                    }
                }

                return implode(',', array_values(array_unique($ids)));
            },
            'default'           => '',
        ]);

        register_setting('wks_settings', 'wks_shoptype', [
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                $value = strtoupper(sanitize_text_field((string) $value));
                return in_array($value, ['B2B', 'B2C', 'EDU'], true) ? $value : 'B2B';
            },
            'default'           => 'B2B',
        ]);

        register_setting('wks_settings', 'wks_stock_sync_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ]);

        register_setting('wks_settings', 'wks_stock_sync_interval', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'wks_15min',
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
        $enabled              = get_option('wks_enabled', false);
        $manufacturer_filter  = get_option('wks_manufacturer_filter', '');
        $shop_id              = get_option('wks_shop_id', '');
        $shoptype             = get_option('wks_shoptype', 'B2B');

        // Order sync settings
        $order_sync_enabled   = get_option('wks_order_sync_enabled', false);
        $order_statuses       = get_option('wks_order_statuses', ['processing', 'completed']);
        $order_platform_id    = get_option('wks_order_platform_id', '');
        $order_sales_channel  = get_option('wks_order_sales_channel', 'Webshop');
        $order_sync_interval  = get_option('wks_order_sync_interval', 'hourly');

        // Stock sync settings
        $stock_sync_enabled   = get_option('wks_stock_sync_enabled', false);
        $stock_sync_interval  = get_option('wks_stock_sync_interval', 'wks_15min');

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
        $export_url     = wp_nonce_url(
            admin_url('admin-post.php?action=wks_export_product_category_excel'),
            'wks_export_product_category_excel'
        );

        include WKS_PLUGIN_DIR . 'includes/views/license.php';
    }

    /**
     * Export products and product categories as a two-sheet Excel workbook.
     */
    public function export_product_category_excel() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'woo-kontor-sync'));
        }

        check_admin_referer('wks_export_product_category_excel');

        if (!class_exists('WooCommerce') || !class_exists('ZipArchive')) {
            wp_die(esc_html__('Excel export is unavailable on this server.', 'woo-kontor-sync'));
        }

        $category_rows = $this->get_category_export_rows();
        $products      = wc_get_products([
            'limit'   => -1,
            'status'  => array_keys(wc_get_product_statuses()),
            'return'  => 'ids',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        $product_rows = [
            [
                __('Product SKU', 'woo-kontor-sync'),
                __('Product Title', 'woo-kontor-sync'),
                __('Category IDs', 'woo-kontor-sync'),
            ],
        ];

        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            $category_ids = [];
            foreach ($product->get_category_ids() as $term_id) {
                $term_id        = (int) $term_id;
                $category_ids[] = isset($category_rows['id_map'][$term_id]) ? $category_rows['id_map'][$term_id] : (string) $term_id;
            }

            $product_rows[] = [
                $product->get_sku(),
                $product->get_name(),
                implode(', ', array_values(array_unique($category_ids))),
            ];
        }

        $workbook = $this->build_xlsx([
            'Products'   => $product_rows,
            'Categories' => $category_rows['rows'],
        ]);

        if (is_wp_error($workbook)) {
            wp_die(esc_html($workbook->get_error_message()));
        }

        $filename = 'woo-kontor-products-categories-' . wp_date('Y-m-d-His') . '.xlsx';

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($workbook));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        readfile($workbook);
        unlink($workbook);
        exit;
    }

    /**
     * Build category rows and a term_id => exported category ID map.
     */
    private function get_category_export_rows() {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $rows = [
            [
                __('Category ID', 'woo-kontor-sync'),
                __('Category', 'woo-kontor-sync'),
                __('Parent ID', 'woo-kontor-sync'),
            ],
        ];

        if (is_wp_error($terms) || empty($terms)) {
            return [
                'rows'   => $rows,
                'id_map' => [],
            ];
        }

        $id_map = [];

        foreach ($terms as $term) {
            $katid = get_term_meta($term->term_id, '_wks_kontor_katid', true);
            if ($katid === '') {
                $katid = (string) $term->term_id;
            }

            $id_map[(int) $term->term_id] = (string) $katid;
        }

        foreach ($terms as $term) {
            $parent_id = '';
            if ($term->parent && isset($id_map[(int) $term->parent])) {
                $parent_id = $id_map[(int) $term->parent];
            }

            $rows[] = [
                $id_map[(int) $term->term_id],
                $term->name,
                $parent_id,
            ];
        }

        return [
            'rows'   => $rows,
            'id_map' => $id_map,
        ];
    }

    /**
     * Create a minimal Office Open XML workbook.
     */
    private function build_xlsx($sheets) {
        $file = wp_tempnam('wks-product-category-export');
        if (!$file) {
            return new WP_Error('wks_export_temp_file', __('Could not create a temporary export file.', 'woo-kontor-sync'));
        }

        $xlsx_file = $file . '.xlsx';
        rename($file, $xlsx_file);

        $zip = new ZipArchive();
        if ($zip->open($xlsx_file, ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('wks_export_zip', __('Could not create the Excel workbook.', 'woo-kontor-sync'));
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsx_content_types(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->xlsx_root_relationships());
        $zip->addFromString('docProps/app.xml', $this->xlsx_app_properties($sheets));
        $zip->addFromString('docProps/core.xml', $this->xlsx_core_properties());
        $zip->addFromString('xl/workbook.xml', $this->xlsx_workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsx_workbook_relationships($sheets));
        $zip->addFromString('xl/styles.xml', $this->xlsx_styles());

        $index = 1;
        foreach ($sheets as $rows) {
            $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $this->xlsx_sheet($rows));
            $index++;
        }

        $zip->close();

        return $xlsx_file;
    }

    private function xlsx_content_types($sheet_count) {
        $overrides = '';
        for ($i = 1; $i <= $sheet_count; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function xlsx_root_relationships() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function xlsx_app_properties($sheets) {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Woo Kontor Sync</Application>'
            . '<TitlesOfParts><vt:vector size="' . count($sheets) . '" baseType="lpstr">'
            . implode('', array_map(function ($name) {
                return '<vt:lpstr>' . $this->xml($name) . '</vt:lpstr>';
            }, array_keys($sheets)))
            . '</vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private function xlsx_core_properties() {
        $created = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Woo Kontor Sync</dc:creator>'
            . '<cp:lastModifiedBy>Woo Kontor Sync</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function xlsx_workbook($sheets) {
        $sheet_xml = '';
        $index     = 1;
        foreach (array_keys($sheets) as $name) {
            $sheet_xml .= '<sheet name="' . $this->xml($name) . '" sheetId="' . $index . '" r:id="rId' . $index . '"/>';
            $index++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheet_xml . '</sheets>'
            . '</workbook>';
    }

    private function xlsx_workbook_relationships($sheets) {
        $rels  = '';
        $index = 1;
        foreach ($sheets as $rows) {
            $rels .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
            $index++;
        }
        $rels .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function xlsx_styles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function xlsx_sheet($rows) {
        $sheet_data = '';
        foreach ($rows as $row_index => $row) {
            $row_number = $row_index + 1;
            $cells      = '';

            foreach ($row as $column_index => $value) {
                $reference = $this->xlsx_column_name($column_index + 1) . $row_number;
                $style     = $row_number === 1 ? ' s="1"' : '';
                $cells    .= '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t>' . $this->xml((string) $value) . '</t></is></c>';
            }

            $sheet_data .= '<row r="' . $row_number . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols><col min="1" max="1" width="18" customWidth="1"/><col min="2" max="2" width="45" customWidth="1"/><col min="3" max="3" width="32" customWidth="1"/></cols>'
            . '<sheetData>' . $sheet_data . '</sheetData>'
            . '</worksheet>';
    }

    private function xlsx_column_name($number) {
        $name = '';

        while ($number > 0) {
            $mod    = ($number - 1) % 26;
            $name   = chr(65 + $mod) . $name;
            $number = (int) (($number - $mod) / 26);
        }

        return $name;
    }

    private function xml($value) {
        $value = (string) $value;

        if (function_exists('wp_check_invalid_utf8')) {
            $value = wp_check_invalid_utf8($value, true);
        }

        $clean_value = preg_replace('/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);
        if ($clean_value !== null) {
            $value = $clean_value;
        }

        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
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
