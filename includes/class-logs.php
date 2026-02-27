<?php
/**
 * Logs Management Class
 *
 * Handles logging and history of sync operations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WKS_Logs {

    /**
     * Table name
     */
    private $table_name;

    /**
     * Maximum logs to keep
     */
    const MAX_LOGS = 100;

    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wks_logs';
        $this->maybe_create_or_update_table();
    }

    /**
     * Check and create/update table if needed
     */
    private function maybe_create_or_update_table() {
        global $wpdb;

        $installed_version = get_option('wks_db_version', '0');

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_name
        ));

        if ($table_exists !== $this->table_name) {
            self::create_table();
            update_option('wks_db_version', self::DB_VERSION);
        } elseif (version_compare($installed_version, self::DB_VERSION, '<')) {
            $this->upgrade_table($installed_version);
            update_option('wks_db_version', self::DB_VERSION);
        }
    }

    /**
     * Upgrade table schema if needed
     */
    private function upgrade_table($from_version) {
        self::create_table();
    }

    /**
     * Create logs table
     */
    public static function create_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'wks_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL DEFAULT 'sync',
            trigger_type varchar(50) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'success',
            message text DEFAULT NULL,
            stats longtext DEFAULT NULL,
            errors longtext DEFAULT NULL,
            duration float DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_status (type, status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add log entry
     */
    public function add($data) {
        global $wpdb;

        $this->maybe_create_or_update_table();

        $defaults = [
            'type'     => 'sync',
            'trigger'  => null,
            'status'   => 'success',
            'message'  => '',
            'stats'    => [],
            'errors'   => [],
            'duration' => 0,
        ];

        $data = wp_parse_args($data, $defaults);

        if (isset($data['stats']['start_time']) && isset($data['stats']['end_time'])) {
            $data['duration'] = round($data['stats']['end_time'] - $data['stats']['start_time'], 2);
        }

        $result = $wpdb->insert(
            $this->table_name,
            [
                'type'         => sanitize_text_field($data['type']),
                'trigger_type' => isset($data['trigger']) ? sanitize_text_field($data['trigger']) : null,
                'status'       => sanitize_text_field($data['status']),
                'message'      => sanitize_textarea_field($data['message']),
                'stats'        => maybe_serialize($data['stats']),
                'errors'       => maybe_serialize($data['errors']),
                'duration'     => floatval($data['duration']),
                'created_at'   => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s']
        );

        if ($result === false) {
            error_log('WKS Log Insert Error: ' . $wpdb->last_error);
        }

        if ($data['type'] === 'sync' && $data['status'] === 'success') {
            update_option('wks_last_sync_time', time());
            update_option('wks_last_sync_stats', $data['stats']);
        }

        $this->cleanup();

        return $wpdb->insert_id;
    }

    /**
     * Get logs
     */
    public function get($args = []) {
        global $wpdb;

        $defaults = [
            'type'    => null,
            'status'  => null,
            'limit'   => 20,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where  = ['1=1'];
        $values = [];

        if ($args['type']) {
            $where[]  = 'type = %s';
            $values[] = $args['type'];
        }

        if ($args['status']) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode(' AND ', $where);
        $orderby      = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'created_at DESC';
        }

        $sql      = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $values[] = intval($args['limit']);
        $values[] = intval($args['offset']);

        $results = $wpdb->get_results($wpdb->prepare($sql, $values));

        foreach ($results as &$row) {
            $row->stats  = maybe_unserialize($row->stats);
            $row->errors = maybe_unserialize($row->errors);
        }

        return $results;
    }

    /**
     * Get single log
     */
    public function get_by_id($id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));

        if ($row) {
            $row->stats  = maybe_unserialize($row->stats);
            $row->errors = maybe_unserialize($row->errors);
        }

        return $row;
    }

    /**
     * Get total count
     */
    public function get_count($args = []) {
        global $wpdb;

        $defaults = [
            'type'   => null,
            'status' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        $where  = ['1=1'];
        $values = [];

        if ($args['type']) {
            $where[]  = 'type = %s';
            $values[] = $args['type'];
        }

        if ($args['status']) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode(' AND ', $where);

        if (empty($values)) {
            return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}");
        }

        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}",
            $values
        ));
    }

    /**
     * Get sync statistics
     */
    public function get_stats($days = 30) {
        global $wpdb;

        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $total_syncs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE type = 'sync' AND created_at >= %s",
            $date_from
        ));

        $successful_syncs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE type = 'sync' AND status = 'success' AND created_at >= %s",
            $date_from
        ));

        $failed_syncs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE type = 'sync' AND status = 'error' AND created_at >= %s",
            $date_from
        ));

        $avg_duration = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(duration) FROM {$this->table_name} WHERE type = 'sync' AND status = 'success' AND created_at >= %s",
            $date_from
        ));

        $sync_logs = $wpdb->get_col($wpdb->prepare(
            "SELECT stats FROM {$this->table_name} WHERE type = 'sync' AND status = 'success' AND created_at >= %s",
            $date_from
        ));

        $total_created   = 0;
        $total_updated   = 0;
        $total_processed = 0;

        foreach ($sync_logs as $stats_raw) {
            $stats = maybe_unserialize($stats_raw);
            if (is_array($stats)) {
                $total_created   += isset($stats['created']) ? intval($stats['created']) : 0;
                $total_updated   += isset($stats['updated']) ? intval($stats['updated']) : 0;
                $total_processed += isset($stats['processed']) ? intval($stats['processed']) : 0;
            }
        }

        $last_success = $wpdb->get_row(
            "SELECT * FROM {$this->table_name} WHERE type = 'sync' AND status = 'success' ORDER BY created_at DESC LIMIT 1"
        );

        if ($last_success) {
            $last_success->stats = maybe_unserialize($last_success->stats);
        }

        $by_trigger = $wpdb->get_results($wpdb->prepare(
            "SELECT trigger_type, COUNT(*) as count FROM {$this->table_name} WHERE type = 'sync' AND created_at >= %s GROUP BY trigger_type",
            $date_from
        ));

        $trigger_counts = [];
        foreach ($by_trigger as $row) {
            $trigger_counts[$row->trigger_type ?: 'unknown'] = intval($row->count);
        }

        return [
            'total_syncs'      => intval($total_syncs),
            'successful_syncs' => intval($successful_syncs),
            'failed_syncs'     => intval($failed_syncs),
            'success_rate'     => $total_syncs > 0 ? round(($successful_syncs / $total_syncs) * 100, 1) : 0,
            'avg_duration'     => round(floatval($avg_duration), 2),
            'total_created'    => $total_created,
            'total_updated'    => $total_updated,
            'total_processed'  => $total_processed,
            'last_success'     => $last_success,
            'by_trigger'       => $trigger_counts,
        ];
    }

    /**
     * Clear all logs
     */
    public function clear() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }

    /**
     * Cleanup old logs
     */
    private function cleanup() {
        global $wpdb;

        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        if ($count > self::MAX_LOGS) {
            $delete_count = $count - self::MAX_LOGS;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table_name} ORDER BY created_at ASC LIMIT %d",
                $delete_count
            ));
        }
    }

    /**
     * Delete log by ID
     */
    public function delete($id) {
        global $wpdb;

        return $wpdb->delete(
            $this->table_name,
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Get daily sync chart data
     */
    public function get_chart_data($days = 14) {
        global $wpdb;

        $data = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date        = gmdate('Y-m-d', strtotime("-{$i} days"));
            $data[$date] = [
                'date'    => $date,
                'success' => 0,
                'error'   => 0,
                'created' => 0,
                'updated' => 0,
            ];
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, status, COUNT(*) as count, GROUP_CONCAT(stats) as all_stats
             FROM {$this->table_name}
             WHERE type = 'sync' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at), status",
            $days
        ));

        foreach ($results as $row) {
            if (isset($data[$row->date])) {
                $data[$row->date][$row->status] = intval($row->count);

                if ($row->status === 'success' && $row->all_stats) {
                    $stats_parts = explode(',', $row->all_stats);
                    foreach ($stats_parts as $stats_raw) {
                        $stats = maybe_unserialize(trim($stats_raw));
                        if (is_array($stats)) {
                            if (isset($stats['created'])) {
                                $data[$row->date]['created'] += intval($stats['created']);
                            }
                            if (isset($stats['updated'])) {
                                $data[$row->date]['updated'] += intval($stats['updated']);
                            }
                        }
                    }
                }
            }
        }

        return array_values($data);
    }
}
