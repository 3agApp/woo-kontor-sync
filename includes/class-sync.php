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
     * Order sync batch size
     */
    const ORDER_BATCH_SIZE = 50;

    /**
     * Current order sync stats
     */
    private $order_stats = [];

    /**
     * Order sync error messages
     */
    private $order_error_messages = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->stats = $this->get_default_stats();
        $this->order_stats = $this->get_default_order_stats();
        add_action('wks_sync_event', [$this, 'run_scheduled_sync']);
        add_action('wks_order_sync_event', [$this, 'run_scheduled_order_sync']);
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
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
     * Fetch categories for a specific shop from the Kontor API
     */
    public function fetch_categories($shop_id) {
        $api_host = rtrim(get_option('wks_api_host', ''), '/');
        $api_key  = get_option('wks_api_key', '');

        if (empty($api_host) || empty($api_key)) {
            return [
                'success' => false,
                'message' => __('API Host or API Key is not configured.', 'woo-kontor-sync'),
            ];
        }

        if (empty($shop_id)) {
            return [
                'success' => false,
                'message' => __('Shop ID is required to fetch categories.', 'woo-kontor-sync'),
            ];
        }

        $result = $this->api_search($api_host, $api_key, [
            'entity' => 'categories',
            'filter' => [
                'shopid' => $shop_id,
            ],
        ]);

        if (!$result['success']) {
            return $result;
        }

        return [
            'success'    => true,
            'categories' => $result['data'],
        ];
    }

    /**
     * Upsert categories to Kontor for a specific shop
     */
    public function upsert_categories($shop_id, $categories, $overwrite_all = true) {
        $api_host = rtrim(get_option('wks_api_host', ''), '/');
        $api_key  = get_option('wks_api_key', '');

        if (empty($api_host) || empty($api_key)) {
            return [
                'success' => false,
                'message' => __('API Host or API Key is not configured.', 'woo-kontor-sync'),
            ];
        }

        if (empty($shop_id)) {
            return [
                'success' => false,
                'message' => __('Shop ID is required.', 'woo-kontor-sync'),
            ];
        }

        $url = $api_host . '/api/v1/kontor/upsert';

        $payload = [
            'name'   => 'categories',
            'meta'   => ['userId' => 'WKS'],
            'params' => [
                'shopid'        => $shop_id,
                'overwrite_all' => $overwrite_all,
                'categories'    => $categories,
            ],
        ];

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

        if (empty($data) || (isset($data['success']) && $data['success'] !== true)) {
            $error_msg = isset($data['message']) ? $data['message'] : __('Unknown API error', 'woo-kontor-sync');
            return [
                'success' => false,
                'message' => sprintf(__('Kontor API error: %s', 'woo-kontor-sync'), $error_msg),
            ];
        }

        return [
            'success' => true,
            'message' => __('Categories upserted successfully.', 'woo-kontor-sync'),
            'data'    => $data,
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

        // Handle categories (new hierarchical format or legacy Katname)
        if (!empty($kontor['categories']) && is_array($kontor['categories'])) {
            $this->assign_categories_hierarchical($product_id, $kontor['categories']);
        } elseif (!empty($kontor['Katname'])) {
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
     * Assign product category (legacy single category)
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
     * Assign hierarchical categories from Kontor API response
     *
     * Each category has: katid, katidparent, katname
     */
    private function assign_categories_hierarchical($product_id, $categories) {
        if (empty($categories) || !is_array($categories)) {
            return;
        }

        // Build a map of katid => category data
        $kat_map = [];
        foreach ($categories as $cat) {
            $katid = isset($cat['katid']) ? trim((string) $cat['katid']) : '';
            if ($katid === '') {
                continue;
            }
            $kat_map[$katid] = $cat;
        }

        // Map katid => WooCommerce term_id
        $katid_to_term = [];
        $term_ids      = [];

        // Process categories in order that ensures parents are created first
        $processed = [];
        $max_passes = count($kat_map) + 1;
        $pass = 0;

        while (count($processed) < count($kat_map) && $pass < $max_passes) {
            $pass++;
            foreach ($kat_map as $katid => $cat) {
                if (isset($processed[$katid])) {
                    continue;
                }

                $parent_katid = isset($cat['katidparent']) ? trim((string) $cat['katidparent']) : '';
                $katname      = isset($cat['katname']) ? trim((string) $cat['katname']) : '';

                if (empty($katname)) {
                    $processed[$katid] = true;
                    continue;
                }

                // Determine WooCommerce parent term ID
                $wc_parent_id = 0;
                if ($parent_katid !== '' && isset($kat_map[$parent_katid])) {
                    if (!isset($katid_to_term[$parent_katid])) {
                        // Parent not yet processed, skip for now
                        continue;
                    }
                    $wc_parent_id = $katid_to_term[$parent_katid];
                }

                // Check if category exists by kontor katid meta
                $existing_terms = get_terms([
                    'taxonomy'   => 'product_cat',
                    'meta_key'   => '_wks_kontor_katid',
                    'meta_value' => $katid,
                    'hide_empty' => false,
                    'number'     => 1,
                ]);

                if (!empty($existing_terms) && !is_wp_error($existing_terms)) {
                    $term = $existing_terms[0];
                    // Update name/parent if changed
                    wp_update_term($term->term_id, 'product_cat', [
                        'name'   => $katname,
                        'parent' => $wc_parent_id,
                    ]);
                    $term_id = $term->term_id;
                } else {
                    // Try to find by name + parent
                    $term = get_term_by('name', $katname, 'product_cat');
                    if ($term && (int) $term->parent === (int) $wc_parent_id) {
                        $term_id = $term->term_id;
                    } else {
                        // Create new category
                        $result = wp_insert_term($katname, 'product_cat', [
                            'parent' => $wc_parent_id,
                        ]);
                        if (is_wp_error($result)) {
                            $processed[$katid] = true;
                            continue;
                        }
                        $term_id = $result['term_id'];
                    }
                    // Store kontor katid mapping
                    update_term_meta($term_id, '_wks_kontor_katid', $katid);
                }

                $katid_to_term[$katid] = $term_id;
                $term_ids[]            = $term_id;
                $processed[$katid]     = true;
            }
        }

        if (!empty($term_ids)) {
            wp_set_object_terms($product_id, $term_ids, 'product_cat');
        }
    }

    /**
     * Build WooCommerce categories array for Kontor upsert from a shop's WooCommerce categories
     */
    public function build_categories_for_upsert($term_ids = []) {
        if (empty($term_ids)) {
            $terms = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ]);
        } else {
            $terms = get_terms([
                'taxonomy'   => 'product_cat',
                'include'    => $term_ids,
                'hide_empty' => false,
            ]);
        }

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        // Map WC term_id => kontor katid (use stored mapping or term_id as string)
        $categories = [];
        $term_to_katid = [];

        foreach ($terms as $term) {
            $katid = get_term_meta($term->term_id, '_wks_kontor_katid', true);
            if (empty($katid)) {
                $katid = (string) $term->term_id;
            }
            $term_to_katid[$term->term_id] = $katid;
        }

        foreach ($terms as $term) {
            $katid = $term_to_katid[$term->term_id];
            $parent_katid = '';

            if ($term->parent && isset($term_to_katid[$term->parent])) {
                $parent_katid = $term_to_katid[$term->parent];
            }

            $categories[] = [
                'katid'       => $katid,
                'katidparent' => $parent_katid,
                'katname'     => $term->name,
            ];
        }

        return $categories;
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
     * Get default order sync stats
     */
    private function get_default_order_stats() {
        return [
            'total_orders'     => 0,
            'orders_uploaded'  => 0,
            'orders_failed'    => 0,
            'orders_skipped'   => 0,
            'start_time'       => 0,
            'end_time'         => 0,
        ];
    }

    /**
     * Run scheduled order sync (with lock)
     */
    public function run_scheduled_order_sync() {
        if (get_transient('wks_order_sync_running')) {
            return;
        }

        set_transient('wks_order_sync_running', true, 30 * MINUTE_IN_SECONDS);

        try {
            $this->upload_orders('scheduled');
        } finally {
            delete_transient('wks_order_sync_running');
        }
    }

    /**
     * Run manual order sync
     */
    public function run_manual_order_sync() {
        return $this->upload_orders('manual');
    }

    /**
     * Handle real-time order status change
     */
    public function on_order_status_changed($order_id, $old_status, $new_status, $order) {
        if (!get_option('wks_order_sync_enabled', false)) {
            return;
        }

        if (!WKS()->license->is_valid()) {
            return;
        }

        $synced_statuses = get_option('wks_order_statuses', ['processing', 'completed']);
        if (!is_array($synced_statuses)) {
            $synced_statuses = ['processing', 'completed'];
        }

        if (!in_array($new_status, $synced_statuses, true)) {
            return;
        }

        // Skip if already synced
        $already_synced = $order->get_meta('_wks_order_synced');
        if (!empty($already_synced)) {
            return;
        }

        $this->upload_single_order($order);
    }

    /**
     * Upload a single order to Kontor (real-time)
     */
    private function upload_single_order($order) {
        $api_host = rtrim(get_option('wks_api_host', ''), '/');
        $api_key  = get_option('wks_api_key', '');
        $shop_id  = get_option('wks_shop_id', '');

        if (empty($api_host) || empty($api_key) || empty($shop_id)) {
            return;
        }

        $order_payload = $this->build_order_payload($order);
        if (!$order_payload) {
            return;
        }

        $payload = [
            'name'   => 'orders',
            'meta'   => ['userId' => 'WKS'],
            'params' => [
                'shopid'        => $shop_id,
                'overwrite_all' => false,
                'orders'        => [$order_payload],
            ],
        ];

        $url = $api_host . '/api/v1/kontor/upsert';

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            WKS()->logs->add([
                'type'    => 'order_sync',
                'trigger' => 'realtime',
                'status'  => 'error',
                'message' => sprintf(
                    __('Real-time order sync failed for order #%s: %s', 'woo-kontor-sync'),
                    $order->get_id(),
                    $response->get_error_message()
                ),
            ]);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['success'])) {
            $error_msg = isset($body['message']) ? $body['message'] : sprintf(__('HTTP %d', 'woo-kontor-sync'), $code);
            WKS()->logs->add([
                'type'    => 'order_sync',
                'trigger' => 'realtime',
                'status'  => 'error',
                'message' => sprintf(
                    __('Real-time order sync failed for order #%s: %s', 'woo-kontor-sync'),
                    $order->get_id(),
                    $error_msg
                ),
            ]);
            return;
        }

        // Process response — store Kontor auftrnr
        if (!empty($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $result) {
                if (isset($result['status']) && $result['status'] === 'ok') {
                    $order->update_meta_data('_wks_order_synced', time());
                    if (!empty($result['auftrnr'])) {
                        $order->update_meta_data('_wks_kontor_auftrnr', sanitize_text_field($result['auftrnr']));
                    }
                    $order->save();
                }
            }
        }

        WKS()->logs->add([
            'type'    => 'order_sync',
            'trigger' => 'realtime',
            'status'  => 'success',
            'message' => sprintf(
                __('Real-time order sync completed for order #%s', 'woo-kontor-sync'),
                $order->get_id()
            ),
            'stats' => ['orders_uploaded' => 1],
        ]);
    }

    /**
     * Upload orders to Kontor API (batch)
     */
    public function upload_orders($trigger = 'manual') {
        $this->order_stats          = $this->get_default_order_stats();
        $this->order_error_messages = [];

        if (!WKS()->license->is_valid()) {
            $this->log_order_error(__('Invalid or expired license. Order sync aborted.', 'woo-kontor-sync'));
            return [
                'success' => false,
                'message' => __('Invalid or expired license.', 'woo-kontor-sync'),
            ];
        }

        $api_host = rtrim(get_option('wks_api_host', ''), '/');
        $api_key  = get_option('wks_api_key', '');
        $shop_id  = get_option('wks_shop_id', '');

        if (empty($api_host) || empty($api_key)) {
            $this->log_order_error(__('API Host or API Key is not configured.', 'woo-kontor-sync'));
            return [
                'success' => false,
                'message' => __('API Host or API Key is not configured.', 'woo-kontor-sync'),
            ];
        }

        if (empty($shop_id)) {
            $this->log_order_error(__('Shop ID is not configured. Please select a shop in settings.', 'woo-kontor-sync'));
            return [
                'success' => false,
                'message' => __('Shop ID is not configured.', 'woo-kontor-sync'),
            ];
        }

        $this->order_stats['start_time'] = microtime(true);

        set_time_limit(0);
        wp_raise_memory_limit('admin');

        // Get eligible orders
        $orders = $this->get_orders_to_sync();
        $this->order_stats['total_orders'] = count($orders);

        if (empty($orders)) {
            $this->order_stats['end_time'] = microtime(true);
            $duration = round($this->order_stats['end_time'] - $this->order_stats['start_time'], 2);

            WKS()->logs->add([
                'type'    => 'order_sync',
                'trigger' => $trigger,
                'status'  => 'success',
                'message' => __('No new orders to sync.', 'woo-kontor-sync'),
                'stats'   => $this->order_stats,
                'duration' => $duration,
            ]);

            return [
                'success' => true,
                'message' => __('No new orders to sync.', 'woo-kontor-sync'),
                'stats'   => $this->order_stats,
            ];
        }

        // Build payloads
        $order_payloads = [];
        $order_map      = []; // orderNumber => WC_Order

        foreach ($orders as $order) {
            $payload = $this->build_order_payload($order);
            if ($payload) {
                $order_payloads[] = $payload;
                $order_map[$payload['orderNumber']] = $order;
            } else {
                $this->order_stats['orders_skipped']++;
                $this->order_error_messages[] = sprintf(
                    __('Skipped order #%s: failed to build payload.', 'woo-kontor-sync'),
                    $order->get_id()
                );
            }
        }

        // Send in batches
        $batches = array_chunk($order_payloads, self::ORDER_BATCH_SIZE);

        foreach ($batches as $batch) {
            $payload = [
                'name'   => 'orders',
                'meta'   => ['userId' => 'WKS'],
                'params' => [
                    'shopid'        => $shop_id,
                    'overwrite_all' => false,
                    'orders'        => $batch,
                ],
            ];

            $url = $api_host . '/api/v1/kontor/upsert';

            $response = wp_remote_post($url, [
                'timeout' => 120,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key'    => $api_key,
                ],
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                $this->order_stats['orders_failed'] += count($batch);
                $this->order_error_messages[] = sprintf(
                    __('API request failed: %s', 'woo-kontor-sync'),
                    $response->get_error_message()
                );
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200 || empty($body['success'])) {
                $error_msg = isset($body['message']) ? $body['message'] : sprintf(__('HTTP %d', 'woo-kontor-sync'), $code);
                $this->order_stats['orders_failed'] += count($batch);
                $this->order_error_messages[] = sprintf(
                    __('API error: %s', 'woo-kontor-sync'),
                    $error_msg
                );
                continue;
            }

            // Process per-order results
            if (!empty($body['data']) && is_array($body['data'])) {
                foreach ($body['data'] as $result) {
                    $order_number = isset($result['orderNumber']) ? $result['orderNumber'] : '';

                    if (isset($result['status']) && $result['status'] === 'ok' && isset($order_map[$order_number])) {
                        $wc_order = $order_map[$order_number];
                        $wc_order->update_meta_data('_wks_order_synced', time());
                        if (!empty($result['auftrnr'])) {
                            $wc_order->update_meta_data('_wks_kontor_auftrnr', sanitize_text_field($result['auftrnr']));
                        }
                        $wc_order->save();
                        $this->order_stats['orders_uploaded']++;
                    } else {
                        $this->order_stats['orders_failed']++;
                        $error_detail = isset($result['message']) ? $result['message'] : __('Unknown error', 'woo-kontor-sync');
                        $this->order_error_messages[] = sprintf(
                            __('Order %s failed: %s', 'woo-kontor-sync'),
                            $order_number,
                            $error_detail
                        );
                    }
                }
            }
        }

        $this->order_stats['end_time'] = microtime(true);
        $duration = round($this->order_stats['end_time'] - $this->order_stats['start_time'], 2);

        $success = $this->order_stats['orders_failed'] === 0;

        WKS()->logs->add([
            'type'     => 'order_sync',
            'trigger'  => $trigger,
            'status'   => $success ? 'success' : ($this->order_stats['orders_uploaded'] > 0 ? 'warning' : 'error'),
            'message'  => sprintf(
                __('Order sync completed in %s seconds. Uploaded: %d, Failed: %d, Skipped: %d', 'woo-kontor-sync'),
                $duration,
                $this->order_stats['orders_uploaded'],
                $this->order_stats['orders_failed'],
                $this->order_stats['orders_skipped']
            ),
            'stats'    => $this->order_stats,
            'errors'   => $this->order_error_messages,
            'duration' => $duration,
        ]);

        // Reschedule
        $this->reschedule_order_sync();

        return [
            'success' => true,
            'message' => sprintf(
                __('Order sync completed. Uploaded: %d, Failed: %d, Skipped: %d', 'woo-kontor-sync'),
                $this->order_stats['orders_uploaded'],
                $this->order_stats['orders_failed'],
                $this->order_stats['orders_skipped']
            ),
            'stats' => $this->order_stats,
        ];
    }

    /**
     * Get WooCommerce orders eligible for sync
     */
    private function get_orders_to_sync() {
        $statuses = get_option('wks_order_statuses', ['processing', 'completed']);
        if (!is_array($statuses)) {
            $statuses = ['processing', 'completed'];
        }

        // Prefix statuses with 'wc-' for WooCommerce query
        $wc_statuses = array_map(function ($s) {
            return 'wc-' . $s;
        }, $statuses);

        $args = [
            'status'     => $wc_statuses,
            'limit'      => 500,
            'orderby'    => 'date',
            'order'      => 'ASC',
            'meta_query' => [
                [
                    'key'     => '_wks_order_synced',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        return wc_get_orders($args);
    }

    /**
     * Build API payload for a single WC order
     */
    private function build_order_payload($order) {
        if (!$order instanceof WC_Order) {
            return null;
        }

        $platform_id    = get_option('wks_order_platform_id', wp_parse_url(home_url(), PHP_URL_HOST));
        $account_id     = (int) get_option('wks_order_account_id', 0);
        $sales_channel  = get_option('wks_order_sales_channel', 'Webshop');

        // Determine payment state
        $status = $order->get_status();
        $payment_state = in_array($status, ['completed', 'processing'], true) ? 'paid' : 'open';

        // Determine tax status
        $tax_status = $order->get_prices_include_tax() ? 'gross' : 'net';

        // Get customer group
        $customer_id = $order->get_customer_id();
        $customer_group = 'guest';
        if ($customer_id > 0) {
            $user = get_userdata($customer_id);
            if ($user) {
                $roles = $user->roles;
                if (in_array('wholesale_customer', $roles, true)) {
                    $customer_group = 'B2B';
                } else {
                    $customer_group = 'B2C';
                }
            }
        }

        // Get language (2-char)
        $locale = get_locale();
        $language = substr($locale, 0, 2);

        // Shipping method
        $shipping_methods = $order->get_shipping_methods();
        $shipping_method  = '';
        foreach ($shipping_methods as $method) {
            $shipping_method = $method->get_method_title();
            break;
        }

        // Customer number
        $customer_number = $customer_id > 0 ? (string) $customer_id : 'guest-' . $order->get_id();

        $payload = [
            'orderId'              => (string) $order->get_order_number(),
            'orderPlatformid'      => $platform_id,
            'orderAccountid'       => $account_id,
            'orderNumber'          => (string) $order->get_id(),
            'orderDate'            => $order->get_date_created() ? $order->get_date_created()->format('c') : '',
            'salesChannelName'     => $sales_channel,
            'billingAddress'       => $this->build_address_payload($order, 'billing'),
            'deliveryAddress'      => $this->build_address_payload($order, 'shipping'),
            'shippingTotal'        => (float) $order->get_shipping_total(),
            'customerName'         => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'customerNumber'       => $customer_number,
            'customerEmail'        => $order->get_billing_email(),
            'customerPhone'        => $order->get_billing_phone(),
            'customerVatId'        => '',
            'customerGroup'        => $customer_group,
            'language'             => $language,
            'items'                => $this->build_items_payload($order),
            'paymentMethod'        => $order->get_payment_method(),
            'paymentMethodName'    => $order->get_payment_method_title(),
            'paymentTransactionId' => $order->get_transaction_id(),
            'paymentState'         => $payment_state,
            'shippingMethod'       => $shipping_method,
            'taxStatus'            => $tax_status,
            'remarks'              => $order->get_customer_note(),
            'currency'             => $order->get_currency(),
        ];

        return $payload;
    }

    /**
     * Build address payload from WC order
     */
    private function build_address_payload($order, $type = 'billing') {
        if ($type === 'shipping') {
            $first_name = $order->get_shipping_first_name();
            $last_name  = $order->get_shipping_last_name();
            $company    = $order->get_shipping_company();
            $address_1  = $order->get_shipping_address_1();
            $address_2  = $order->get_shipping_address_2();
            $city       = $order->get_shipping_city();
            $postcode   = $order->get_shipping_postcode();
            $country    = $order->get_shipping_country();
            $phone      = method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '';

            // Fall back to billing if shipping is empty
            if (empty($first_name) && empty($last_name) && empty($address_1)) {
                return $this->build_address_payload($order, 'billing');
            }
        } else {
            $first_name = $order->get_billing_first_name();
            $last_name  = $order->get_billing_last_name();
            $company    = $order->get_billing_company();
            $address_1  = $order->get_billing_address_1();
            $address_2  = $order->get_billing_address_2();
            $city       = $order->get_billing_city();
            $postcode   = $order->get_billing_postcode();
            $country    = $order->get_billing_country();
            $phone      = $order->get_billing_phone();
        }

        $country_name = '';
        if (!empty($country)) {
            $countries = WC()->countries->get_countries();
            $country_name = isset($countries[$country]) ? $countries[$country] : $country;
        }

        return [
            'firstName'         => $first_name,
            'lastName'          => $last_name,
            'company'           => $company,
            'name'              => trim($first_name . ' ' . $last_name),
            'attn'              => '',
            'additionalAddress' => $address_2,
            'department'        => '',
            'street'            => $address_1,
            'street2'           => '',
            'zipcode'           => $postcode,
            'city'              => $city,
            'countryName'       => $country_name,
            'countryCode'       => $country,
            'phone'             => $phone,
            'vatId'             => '',
            'externalId'        => '',
        ];
    }

    /**
     * Build line items payload from WC order
     */
    private function build_items_payload($order) {
        $items   = [];
        $position = 0;

        foreach ($order->get_items() as $item) {
            $position++;
            $product  = $item->get_product();
            $quantity = $item->get_quantity();

            $sku           = $product ? $product->get_sku() : '';
            $product_id    = $product ? (string) $product->get_id() : (string) $item->get_product_id();
            $regular_price = $product ? (float) $product->get_regular_price() : 0;

            // Unit price before line-level discount
            $unit_price = $quantity > 0 ? (float) $item->get_subtotal() / $quantity : 0;

            // Discount per unit
            $discount = $regular_price > $unit_price ? round($regular_price - $unit_price, 2) : 0;

            // Total price (after discount)
            $total_price = (float) $item->get_total();

            // Tax rate
            $tax_rate = 0;
            $taxes    = $item->get_taxes();
            if (!empty($taxes['subtotal'])) {
                $subtotal = (float) $item->get_subtotal();
                $tax_total = array_sum(array_map('floatval', $taxes['subtotal']));
                if ($subtotal > 0) {
                    $tax_rate = round(($tax_total / $subtotal) * 100, 1);
                }
            }

            $items[] = [
                'itemId'       => (string) $item->get_id(),
                'productId'    => $product_id,
                'sku'          => $sku,
                'quantity'     => $quantity,
                'unitPrice'    => round($unit_price, 2),
                'regularPrice' => round($regular_price, 2),
                'priceFaktor'  => 1,
                'discount'     => $discount,
                'totalPrice'   => round($total_price, 2),
                'description'  => $item->get_name(),
                'position'     => $position,
                'taxRate'      => $tax_rate,
            ];
        }

        return $items;
    }

    /**
     * Reschedule order sync
     */
    private function reschedule_order_sync() {
        $enabled = get_option('wks_order_sync_enabled', false);
        if (!$enabled) {
            return;
        }

        $interval = get_option('wks_order_sync_interval', 'hourly');
        wp_clear_scheduled_hook('wks_order_sync_event');

        $interval_seconds = WKS()->scheduler->get_interval_seconds($interval);
        wp_schedule_event(time() + $interval_seconds, $interval, 'wks_order_sync_event');
    }

    /**
     * Log order sync error
     */
    private function log_order_error($message) {
        WKS()->logs->add([
            'type'    => 'order_sync',
            'status'  => 'error',
            'message' => $message,
        ]);
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
