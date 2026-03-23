<?php
/**
 * Sync Engine
 *
 * Handles the actual Kontor API fetching and product import/update process.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WKS_Sync {

    /**
     * Batch size for WooCommerce processing
     */
    const BATCH_SIZE = 50;

    /**
     * Current sync stats
     */
    private $stats = [];

    /**
     * Error messages
     */
    private $error_messages = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->stats = $this->get_default_stats();
        add_action('wks_sync_event', [$this, 'run_scheduled_sync']);
    }

    /**
     * Run scheduled sync (with lock to prevent concurrent runs)
     */
    public function run_scheduled_sync() {
        if (WKS()->scheduler->is_running()) {
            return;
        }

        WKS()->scheduler->set_running(true);

        try {
            $this->run('scheduled');
        } finally {
            WKS()->scheduler->set_running(false);
        }
    }

    /**
     * Run manual sync
     */
    public function run_manual_sync() {
        return $this->run('manual');
    }

    /**
     * Get default stats array
     */
    private function get_default_stats() {
        return [
            'total_products' => 0,
            'total_api'      => 0,
            'processed'      => 0,
            'created'        => 0,
            'updated'        => 0,
            'skipped'        => 0,
            'errors'         => 0,
            'images_set'     => 0,
            'pages_fetched'  => 0,
            'start_time'     => 0,
            'end_time'       => 0,
        ];
    }

    /**
     * Main sync process
     */
    public function run($trigger = 'manual') {
        $this->stats          = $this->get_default_stats();
        $this->error_messages = [];

        // Check license
        if (!WKS()->license->is_valid()) {
            $this->log_error(__('Invalid or expired license. Sync aborted.', 'woo-kontor-sync'));
            return [
                'success' => false,
                'message' => __('Invalid or expired license.', 'woo-kontor-sync'),
            ];
        }

        // Get settings
        $api_host = get_option('wks_api_host', '');
        $api_key  = get_option('wks_api_key', '');

        if (empty($api_host) || empty($api_key)) {
            $this->log_error(__('API Host or API Key is not configured.', 'woo-kontor-sync'));
            return [
                'success' => false,
                'message' => __('API Host or API Key is not configured.', 'woo-kontor-sync'),
            ];
        }

        // Initialize stats
        $this->stats['start_time'] = microtime(true);
        $this->stats['trigger']    = $trigger;

        // Set up error handling
        set_time_limit(0);
        wp_raise_memory_limit('admin');

        // Fetch all products from Kontor API (paginated)
        $products = $this->fetch_all_products();

        if (!$products['success']) {
            $this->log_sync($trigger, false, $products['message']);
            return $products;
        }

        $this->stats['total_products'] = count($products['data']);
        $this->stats['total_api']      = $products['total_count'];

        // Process in batches
        $batches = array_chunk($products['data'], self::BATCH_SIZE);

        foreach ($batches as $batch) {
            $this->process_batch($batch);
        }

        // Finalize
        $this->stats['end_time'] = microtime(true);
        $duration = round($this->stats['end_time'] - $this->stats['start_time'], 2);

        $this->log_sync($trigger, true, null, $duration);

        // Re-schedule next sync
        WKS()->scheduler->reschedule();

        return [
            'success' => true,
            'message' => sprintf(
                __('Sync completed. Created: %d, Updated: %d, Skipped: %d, Errors: %d', 'woo-kontor-sync'),
                $this->stats['created'],
                $this->stats['updated'],
                $this->stats['skipped'],
                $this->stats['errors']
            ),
            'stats' => $this->stats,
        ];
    }

    /**
     * Fetch all products from Kontor API with pagination
     */
    private function fetch_all_products() {
        $api_host  = rtrim(get_option('wks_api_host', ''), '/');
        $api_key   = get_option('wks_api_key', '');
        $page_size = 2000;
        $manufacturer_ids = $this->get_manufacturer_filter_ids();

        $all_products = [];
        $skip         = 0;
        $total_count  = 0;

        while (true) {
            $result = $this->fetch_page($api_host, $api_key, $skip, $page_size, $manufacturer_ids);

            if (!$result['success']) {
                return $result;
            }

            $page_data   = $result['data'];
            $total_count = $result['total_count'];

            $all_products = array_merge($all_products, $page_data);

            $this->stats['pages_fetched']++;
            $skip += $page_size;

            // If we received less than page_size, we've reached the end
            if (count($page_data) < $page_size) {
                break;
            }

            // If we've fetched all available products
            if ($skip >= $total_count) {
                break;
            }
        }

        return [
            'success'     => true,
            'data'        => $all_products,
            'total_count' => $total_count,
        ];
    }

    /**
     * Fetch a single page of products from Kontor API
     */
    private function fetch_page($api_host, $api_key, $skip, $take, $manufacturer_ids = []) {
        $url = $api_host . '/api/v1/kontor/search';

        $payload = [
            'entity' => 'products',
            'paging' => [
                'skip' => $skip,
                'take' => $take,
            ],
        ];

        if (!empty($manufacturer_ids)) {
            $payload['filter'] = [
                'herstellerids' => implode(',', $manufacturer_ids),
            ];
        }

        $body = wp_json_encode($payload);

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Kontor API request failed: %s', 'woo-kontor-sync'),
                    $response->get_error_message()
                ),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Kontor API returned HTTP %d', 'woo-kontor-sync'),
                    $code
                ),
            ];
        }

        $response_body = wp_remote_retrieve_body($response);
        $data          = json_decode($response_body, true);

        if (empty($data) || !isset($data['success']) || $data['success'] !== true) {
            $error_msg = isset($data['message']) ? $data['message'] : __('Unknown API error', 'woo-kontor-sync');
            return [
                'success' => false,
                'message' => sprintf(
                    __('Kontor API error: %s', 'woo-kontor-sync'),
                    $error_msg
                ),
            ];
        }

        return [
            'success'     => true,
            'data'        => isset($data['data']) ? $data['data'] : [],
            'total_count' => isset($data['meta']['totalCount']) ? intval($data['meta']['totalCount']) : 0,
        ];
    }

    /**
     * Get selected manufacturer IDs from settings
     */
    private function get_manufacturer_filter_ids() {
        $raw = get_option('wks_manufacturer_filter', '');
        if (empty($raw)) {
            return [];
        }

        $parts = preg_split('/[\s,]+/', (string) $raw);
        $parts = is_array($parts) ? $parts : [];

        $ids = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match('/^\d+$/', $part)) {
                $ids[] = $part;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Fetch all unique manufacturers from the Kontor API
     */
    public function fetch_manufacturers() {
        $api_host = rtrim(get_option('wks_api_host', ''), '/');
        $api_key  = get_option('wks_api_key', '');

        if (empty($api_host) || empty($api_key)) {
            return [
                'success' => false,
                'message' => __('API Host or API Key is not configured.', 'woo-kontor-sync'),
            ];
        }

        $result = $this->api_search($api_host, $api_key, ['entity' => 'manufacturer']);

        if (!$result['success']) {
            return $result;
        }

        $manufacturers = [];
        foreach ($result['data'] as $item) {
            $id   = isset($item['Herstellerid']) ? trim((string) $item['Herstellerid']) : '';
            $name = isset($item['Hersteller']) ? trim((string) $item['Hersteller']) : '';

            if ($id !== '' && !isset($manufacturers[$id])) {
                $manufacturers[$id] = [
                    'id'   => $id,
                    'name' => $name,
                ];
            }
        }

        ksort($manufacturers, SORT_NATURAL);

        return [
            'success'       => true,
            'manufacturers' => array_values($manufacturers),
        ];
    }

    /**
     * Fetch all shops from the Kontor API
     */
    public function fetch_shops() {
        $api_host = rtrim(get_option('wks_api_host', ''), '/');
        $api_key  = get_option('wks_api_key', '');

        if (empty($api_host) || empty($api_key)) {
            return [
                'success' => false,
                'message' => __('API Host or API Key is not configured.', 'woo-kontor-sync'),
            ];
        }

        $result = $this->api_search($api_host, $api_key, ['entity' => 'shops']);

        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'shops'   => $result['data'],
        ];
    }

    /**
     * Generic API search call (non-paginated)
     */
    private function api_search($api_host, $api_key, $payload) {
        $url = $api_host . '/api/v1/kontor/search';

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Kontor API request failed: %s', 'woo-kontor-sync'),
                    $response->get_error_message()
                ),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Kontor API returned HTTP %d', 'woo-kontor-sync'),
                    $code
                ),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['success']) || $data['success'] !== true) {
            $error_msg = isset($data['message']) ? $data['message'] : __('Unknown API error', 'woo-kontor-sync');
            return [
                'success' => false,
                'message' => sprintf(__('Kontor API error: %s', 'woo-kontor-sync'), $error_msg),
            ];
        }

        return [
            'success' => true,
            'data'    => isset($data['data']) ? $data['data'] : [],
        ];
    }

    /**
     * Process a batch of Kontor products
     */
    private function process_batch($batch) {
        $image_prefix = rtrim(get_option('wks_image_prefix_url', ''), '/');

        foreach ($batch as $kontor_product) {
            $this->stats['processed']++;

            try {
                $this->import_or_update_product($kontor_product, $image_prefix);
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->error_messages[] = sprintf(
                    __('Error processing SKU %s: %s', 'woo-kontor-sync'),
                    isset($kontor_product['Artnr']) ? $kontor_product['Artnr'] : 'unknown',
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Import or update a single product from Kontor data
     */
    private function import_or_update_product($kontor, $image_prefix) {
        $sku = isset($kontor['Artnr']) ? trim($kontor['Artnr']) : '';

        if (empty($sku)) {
            $this->stats['skipped']++;
            return;
        }

        // Look up existing product by SKU
        $product_id = wc_get_product_id_by_sku($sku);
        $is_new     = empty($product_id);

        if ($is_new) {
            $product = new WC_Product_Simple();
        } else {
            $product = wc_get_product($product_id);
            if (!$product) {
                $this->stats['errors']++;
                $this->error_messages[] = sprintf(
                    __('Could not load product ID %d for SKU %s', 'woo-kontor-sync'),
                    $product_id,
                    $sku
                );
                return;
            }
        }

        // Set core fields
        $product->set_sku($sku);

        // Name
        if (!empty($kontor['Bez1'])) {
            $product->set_name($kontor['Bez1']);
        }

        // Description
        if (!empty($kontor['Langtext'])) {
            $product->set_description($kontor['Langtext']);
        }

        // Regular price (UVP)
        if (isset($kontor['UVP']) && $kontor['UVP'] !== null) {
            $product->set_regular_price(floatval($kontor['UVP']));
        }

        // Weight (Gewnetto) - in kg
        if (isset($kontor['Gewnetto']) && $kontor['Gewnetto'] !== null) {
            $product->set_weight(floatval($kontor['Gewnetto']));
        }

        // Stock
        if (isset($kontor['Lagerbestand']) && $kontor['Lagerbestand'] !== null) {
            $product->set_manage_stock(true);
            $quantity = intval($kontor['Lagerbestand']);
            $product->set_stock_quantity($quantity);
            $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
        }

        // EAN as meta
        if (!empty($kontor['Artean'])) {
            $product->update_meta_data('_ean', sanitize_text_field($kontor['Artean']));
        }

        // Manufacturer / Brand as meta
        if (!empty($kontor['Hersteller'])) {
            $product->update_meta_data('_manufacturer', sanitize_text_field($kontor['Hersteller']));
        }

        // MPN as meta
        if (!empty($kontor['Mpn'])) {
            $product->update_meta_data('_mpn', sanitize_text_field($kontor['Mpn']));
        }

        // Cost price as meta
        if (isset($kontor['Ek']) && $kontor['Ek'] !== null) {
            $product->update_meta_data('_cost_price', floatval($kontor['Ek']));
        }

        // Central number as meta
        if (!empty($kontor['Artzentralnr'])) {
            $product->update_meta_data('_kontor_central_nr', sanitize_text_field($kontor['Artzentralnr']));
        }

        // Store last sync timestamp
        $product->update_meta_data('_wks_last_sync', current_time('timestamp'));

        // Ensure product is published
        if ($is_new) {
            $product->set_status('publish');
        }

        // Save product first (needed for image attachment)
        $product->save();
        $product_id = $product->get_id();

        // Handle category
        if (!empty($kontor['Katname'])) {
            $this->assign_category($product_id, $kontor['Katname']);
        }

        // Handle images
        if (!empty($image_prefix)) {
            $this->handle_images($product, $kontor, $image_prefix);
        }

        if ($is_new) {
            $this->stats['created']++;
        } else {
            $this->stats['updated']++;
        }
    }

    /**
     * Assign product category
     */
    private function assign_category($product_id, $category_name) {
        $category_name = trim($category_name);
        if (empty($category_name)) {
            return;
        }

        // Check if the category already exists
        $term = get_term_by('name', $category_name, 'product_cat');

        if (!$term) {
            // Create the category
            $result = wp_insert_term($category_name, 'product_cat');
            if (is_wp_error($result)) {
                return;
            }
            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }

        wp_set_object_terms($product_id, [$term_id], 'product_cat');
    }

    /**
     * Handle product images
     */
    private function handle_images($product, $kontor, $image_prefix) {
        $product_id = $product->get_id();

        // Collect all image URLs
        $image_fields = ['MainImageURL', 'ImageURL_1', 'ImageURL_2', 'ImageURL_3', 'ImageURL_4', 'ImageURL_5', 'ImageURL_6', 'ImageURL_7', 'ImageURL_8', 'ImageURL_9'];
        $image_urls   = [];

        foreach ($image_fields as $field) {
            if (!empty($kontor[$field]) && $kontor[$field] !== null) {
                $filename  = trim($kontor[$field]);
                $image_url = $image_prefix . '/' . $filename;
                $image_urls[] = $image_url;
            }
        }

        if (empty($image_urls)) {
            return;
        }

        // Check if images have changed by comparing stored URLs
        $stored_urls = get_post_meta($product_id, '_wks_image_urls', true);
        if ($stored_urls && is_array($stored_urls) && $stored_urls === $image_urls) {
            // No change, skip image processing
            return;
        }

        // Get old attachment IDs before setting new ones (for cleanup)
        $old_image_id      = $product->get_image_id();
        $old_gallery_ids   = $product->get_gallery_image_ids();
        $old_attachment_ids = array_filter(array_merge([$old_image_id], $old_gallery_ids));

        // Download and attach images
        $attachment_ids = [];

        foreach ($image_urls as $image_url) {
            $attachment_id = $this->sideload_image($image_url, $product_id);
            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
                $this->stats['images_set']++;
            }
        }

        if (!empty($attachment_ids)) {
            // Set featured image (first one)
            $product->set_image_id($attachment_ids[0]);

            // Set gallery images (rest)
            if (count($attachment_ids) > 1) {
                $product->set_gallery_image_ids(array_slice($attachment_ids, 1));
            } else {
                $product->set_gallery_image_ids([]);
            }

            $product->save();

            // Store image URLs for change detection
            update_post_meta($product_id, '_wks_image_urls', $image_urls);

            // Cleanup old attachments that are no longer used
            $this->cleanup_old_attachments($old_attachment_ids, $attachment_ids);
        }
    }

    /**
     * Remove old image attachments that are no longer assigned to any product
     */
    private function cleanup_old_attachments($old_ids, $new_ids) {
        $orphaned = array_diff($old_ids, $new_ids);

        foreach ($orphaned as $attachment_id) {
            if (empty($attachment_id)) {
                continue;
            }

            // Check if any other product is still using this attachment
            global $wpdb;
            $in_use = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('_thumbnail_id', '_product_image_gallery')
                 AND meta_value LIKE %s
                 AND post_id != %d",
                '%' . $wpdb->esc_like($attachment_id) . '%',
                wp_get_post_parent_id($attachment_id)
            ));

            if (empty($in_use)) {
                wp_delete_attachment($attachment_id, true);
            }
        }
    }

    /**
     * Sideload an image from URL and attach to product
     */
    private function sideload_image($url, $product_id) {
        // Require needed files
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Check if this image was already downloaded anywhere (global dedup)
        $existing = get_posts([
            'post_type'   => 'attachment',
            'meta_key'    => '_wks_source_url',
            'meta_value'  => $url,
            'fields'      => 'ids',
            'numberposts' => 1,
            'post_status' => 'any',
        ]);

        if (!empty($existing)) {
            $attachment_id = $existing[0];
            // Re-attach to this product if needed
            if ((int) wp_get_post_parent_id($attachment_id) !== (int) $product_id) {
                wp_update_post([
                    'ID'          => $attachment_id,
                    'post_parent' => $product_id,
                ]);
            }
            return $attachment_id;
        }

        // Download the file
        $tmp = download_url($url, 60);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = [
            'name'     => basename(wp_parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        ];

        // Sideload
        $id = media_handle_sideload($file_array, $product_id);

        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }

        // Store source URL for deduplication
        update_post_meta($id, '_wks_source_url', $url);

        return $id;
    }

    /**
     * Test API connection
     */
    public function test_connection($host = null, $key = null) {
        if (!$host) {
            $host = get_option('wks_api_host', '');
        }
        if (!$key) {
            $key = get_option('wks_api_key', '');
        }

        if (empty($host) || empty($key)) {
            return [
                'success' => false,
                'message' => __('API Host and API Key are required.', 'woo-kontor-sync'),
            ];
        }

        $host = rtrim($host, '/');

        // Fetch a small page to test
        $result = $this->fetch_page($host, $key, 0, 5);

        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found %d total products in Kontor.', 'woo-kontor-sync'),
                $result['total_count']
            ),
            'total_count' => $result['total_count'],
            'sample'      => array_slice($result['data'], 0, 3),
        ];
    }

    /**
     * Log sync result
     */
    private function log_sync($trigger, $success, $error_message = null, $duration = 0) {
        $log_data = [
            'type'    => 'sync',
            'trigger' => $trigger,
            'status'  => $success ? 'success' : 'error',
            'message' => $success
                ? sprintf(
                    __('Sync completed in %s seconds', 'woo-kontor-sync'),
                    $duration
                )
                : $error_message,
            'stats'  => $this->stats,
            'errors' => $this->error_messages,
        ];

        WKS()->logs->add($log_data);
    }

    /**
     * Log error
     */
    private function log_error($message) {
        WKS()->logs->add([
            'type'    => 'sync',
            'status'  => 'error',
            'message' => $message,
        ]);
    }
}
