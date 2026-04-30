<?php
/**
 * Dashboard View
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wssc-wrap">
    <div class="wssc-header">
        <div class="wssc-header-left">
            <h1><?php esc_html_e('Kontor Sync Dashboard', 'woo-kontor-sync'); ?></h1>
            <p class="wssc-subtitle"><?php esc_html_e('Monitor and manage your product synchronization from Kontor CRM', 'woo-kontor-sync'); ?></p>
        </div>
        <div class="wssc-header-right">
            <?php if ($license_valid): ?>
                <span class="wssc-license-badge wssc-license-active">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('License Active', 'woo-kontor-sync'); ?>
                </span>
            <?php else: ?>
                <a href="<?php echo esc_url(WKS_Admin::get_page_url('license')); ?>" class="wssc-license-badge wssc-license-inactive">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('License Required', 'woo-kontor-sync'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$license_valid): ?>
        <div class="wssc-notice wssc-notice-warning">
            <span class="dashicons dashicons-lock"></span>
            <div>
                <strong><?php esc_html_e('License Required', 'woo-kontor-sync'); ?></strong>
                <p><?php esc_html_e('Please activate your license to enable product synchronization.', 'woo-kontor-sync'); ?></p>
                <a href="<?php echo esc_url(WKS_Admin::get_page_url('license')); ?>" class="wssc-btn wssc-btn-primary wssc-btn-sm">
                    <?php esc_html_e('Activate License', 'woo-kontor-sync'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="wssc-section">
        <div class="wssc-quick-actions">
            <div class="wssc-action-card wssc-sync-status-card">
                <div class="wssc-action-icon <?php echo $scheduler_status['enabled'] ? 'wssc-status-active' : 'wssc-status-inactive'; ?>">
                    <span class="dashicons dashicons-update-alt"></span>
                </div>
                <div class="wssc-action-content">
                    <h3><?php esc_html_e('Sync Status', 'woo-kontor-sync'); ?></h3>
                    <p class="wssc-status-text">
                        <?php if ($scheduler_status['enabled']): ?>
                            <span class="wssc-status-dot wssc-status-dot-active"></span>
                            <?php esc_html_e('Active', 'woo-kontor-sync'); ?>
                            - <?php echo esc_html($scheduler_status['interval_display']); ?>
                        <?php else: ?>
                            <span class="wssc-status-dot wssc-status-dot-inactive"></span>
                            <?php esc_html_e('Disabled', 'woo-kontor-sync'); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($scheduler_status['next_run']): ?>
                        <p class="wssc-next-run <?php echo !empty($scheduler_status['next_run_overdue']) ? 'wssc-next-run-overdue' : ''; ?>">
                            <?php
                            printf(
                                esc_html__('Next run: %s', 'woo-kontor-sync'),
                                esc_html($scheduler_status['next_run_human'])
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="wssc-action-toggle">
                    <label class="wssc-switch" <?php echo !$license_valid ? 'title="' . esc_attr__('License required', 'woo-kontor-sync') . '"' : ''; ?>>
                        <input type="checkbox" id="wssc-toggle-sync" <?php checked($scheduler_status['enabled']); ?> <?php disabled(!$license_valid); ?>>
                        <span class="wssc-slider"></span>
                    </label>
                </div>
            </div>

            <div class="wssc-action-card">
                <div class="wssc-action-icon wssc-icon-sync">
                    <span class="dashicons dashicons-controls-repeat"></span>
                </div>
                <div class="wssc-action-content">
                    <h3><?php esc_html_e('Manual Sync', 'woo-kontor-sync'); ?></h3>
                    <p><?php esc_html_e('Run sync now regardless of schedule', 'woo-kontor-sync'); ?></p>
                </div>
                <button type="button" id="wssc-run-sync" class="wssc-btn wssc-btn-primary" <?php disabled(!$license_valid); ?>>
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Run Now', 'woo-kontor-sync'); ?>
                </button>
            </div>

            <div class="wssc-action-card">
                <div class="wssc-action-icon wssc-icon-time">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="wssc-action-content">
                    <h3><?php esc_html_e('Last Sync', 'woo-kontor-sync'); ?></h3>
                    <p>
                        <?php if ($scheduler_status['last_sync']): ?>
                            <?php echo esc_html($scheduler_status['last_sync_human']); ?>
                        <?php else: ?>
                            <?php esc_html_e('Never', 'woo-kontor-sync'); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="<?php echo esc_url(WKS_Admin::get_page_url('logs')); ?>" class="wssc-btn wssc-btn-secondary">
                    <?php esc_html_e('View Logs', 'woo-kontor-sync'); ?>
                </a>
            </div>

            <div class="wssc-action-card">
                <div class="wssc-action-icon <?php echo $scheduler_status['order_sync_enabled'] ? 'wssc-status-active' : 'wssc-status-inactive'; ?>">
                    <span class="dashicons dashicons-cart"></span>
                </div>
                <div class="wssc-action-content">
                    <h3><?php esc_html_e('Order Sync', 'woo-kontor-sync'); ?></h3>
                    <p>
                        <?php if ($scheduler_status['order_sync_enabled']): ?>
                            <span class="wssc-status-dot wssc-status-dot-active"></span>
                            <?php esc_html_e('Active', 'woo-kontor-sync'); ?>
                            <?php if ($scheduler_status['order_sync_next_run']): ?>
                                — <?php printf(
                                    esc_html__('Next: %s', 'woo-kontor-sync'),
                                    esc_html(human_time_diff(time(), $scheduler_status['order_sync_next_run']))
                                ); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="wssc-status-dot wssc-status-dot-inactive"></span>
                            <?php esc_html_e('Disabled', 'woo-kontor-sync'); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <button type="button" id="wssc-run-order-sync" class="wssc-btn wssc-btn-primary" <?php disabled(!$license_valid || !$scheduler_status['order_sync_enabled']); ?>>
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e('Sync Orders', 'woo-kontor-sync'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="wssc-section">
        <h2 class="wssc-section-title"><?php esc_html_e('Last 30 Days Statistics', 'woo-kontor-sync'); ?></h2>
        <div class="wssc-stats-grid">
            <div class="wssc-stat-card">
                <div class="wssc-stat-icon wssc-stat-icon-syncs">
                    <span class="dashicons dashicons-update"></span>
                </div>
                <div class="wssc-stat-content">
                    <span class="wssc-stat-value"><?php echo esc_html($stats['total_syncs']); ?></span>
                    <span class="wssc-stat-label"><?php esc_html_e('Total Syncs', 'woo-kontor-sync'); ?></span>
                </div>
            </div>

            <div class="wssc-stat-card">
                <div class="wssc-stat-icon wssc-stat-icon-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="wssc-stat-content">
                    <span class="wssc-stat-value"><?php echo esc_html($stats['success_rate']); ?>%</span>
                    <span class="wssc-stat-label"><?php esc_html_e('Success Rate', 'woo-kontor-sync'); ?></span>
                </div>
            </div>

            <div class="wssc-stat-card">
                <div class="wssc-stat-icon wssc-stat-icon-products">
                    <span class="dashicons dashicons-plus-alt"></span>
                </div>
                <div class="wssc-stat-content">
                    <span class="wssc-stat-value"><?php echo esc_html(number_format($stats['total_created'])); ?></span>
                    <span class="wssc-stat-label"><?php esc_html_e('Products Created', 'woo-kontor-sync'); ?></span>
                </div>
            </div>

            <div class="wssc-stat-card">
                <div class="wssc-stat-icon wssc-stat-icon-updated">
                    <span class="dashicons dashicons-edit"></span>
                </div>
                <div class="wssc-stat-content">
                    <span class="wssc-stat-value"><?php echo esc_html(number_format($stats['total_updated'])); ?></span>
                    <span class="wssc-stat-label"><?php esc_html_e('Products Updated', 'woo-kontor-sync'); ?></span>
                </div>
            </div>

            <div class="wssc-stat-card">
                <div class="wssc-stat-icon wssc-stat-icon-time">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="wssc-stat-content">
                    <span class="wssc-stat-value"><?php echo esc_html($stats['avg_duration']); ?>s</span>
                    <span class="wssc-stat-label"><?php esc_html_e('Avg. Duration', 'woo-kontor-sync'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart Section -->
    <div class="wssc-section">
        <h2 class="wssc-section-title"><?php esc_html_e('Sync Activity (Last 14 Days)', 'woo-kontor-sync'); ?></h2>
        <div class="wssc-chart-container">
            <canvas id="wssc-activity-chart"></canvas>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="wssc-section">
        <div class="wssc-section-header">
            <h2 class="wssc-section-title"><?php esc_html_e('Recent Activity', 'woo-kontor-sync'); ?></h2>
            <a href="<?php echo esc_url(WKS_Admin::get_page_url('logs')); ?>" class="wssc-link">
                <?php esc_html_e('View All', 'woo-kontor-sync'); ?> →
            </a>
        </div>

        <?php if (empty($recent_logs)): ?>
            <div class="wssc-empty-state">
                <span class="dashicons dashicons-format-aside"></span>
                <p><?php esc_html_e('No sync activity yet.', 'woo-kontor-sync'); ?></p>
            </div>
        <?php else: ?>
            <div class="wssc-activity-list">
                <?php foreach ($recent_logs as $log): ?>
                    <div class="wssc-activity-item">
                        <div class="wssc-activity-status wssc-activity-status-<?php echo esc_attr($log->status); ?>">
                            <span class="dashicons dashicons-<?php echo $log->status === 'success' ? 'yes-alt' : 'warning'; ?>"></span>
                        </div>
                        <div class="wssc-activity-content">
                            <p class="wssc-activity-message"><?php echo esc_html($log->message); ?></p>
                            <div class="wssc-activity-meta">
                                <span class="wssc-activity-trigger">
                                    <?php
                                    echo $log->trigger_type === 'scheduled'
                                        ? esc_html__('Scheduled', 'woo-kontor-sync')
                                        : esc_html__('Manual', 'woo-kontor-sync');
                                    ?>
                                </span>
                                <span class="wssc-activity-time wssc-local-time" data-timestamp="<?php echo esc_attr(strtotime($log->created_at . ' UTC')); ?>">
                                    <?php echo esc_html(human_time_diff(strtotime($log->created_at . ' UTC'), time())); ?> <?php esc_html_e('ago', 'woo-kontor-sync'); ?>
                                </span>
                                <?php if (!empty($log->stats) && (isset($log->stats['created']) || isset($log->stats['updated']))): ?>
                                    <span class="wssc-activity-stats">
                                        <?php
                                        $parts = [];
                                        if (!empty($log->stats['created'])) {
                                            $parts[] = sprintf(esc_html__('%d created', 'woo-kontor-sync'), intval($log->stats['created']));
                                        }
                                        if (!empty($log->stats['updated'])) {
                                            $parts[] = sprintf(esc_html__('%d updated', 'woo-kontor-sync'), intval($log->stats['updated']));
                                        }
                                        echo implode(', ', $parts);
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="wssc-btn wssc-btn-ghost wssc-view-log" data-log-id="<?php echo esc_attr($log->id); ?>">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Log Details Modal -->
<div id="wssc-log-modal" class="wssc-modal">
    <div class="wssc-modal-content">
        <div class="wssc-modal-header">
            <h3><?php esc_html_e('Sync Log Details', 'woo-kontor-sync'); ?></h3>
            <button type="button" class="wssc-modal-close">&times;</button>
        </div>
        <div class="wssc-modal-body" id="wssc-log-modal-body">
            <!-- Content loaded via AJAX -->
        </div>
    </div>
</div>

<script>
var wsscChartData = <?php echo wp_json_encode($chart_data); ?>;
</script>
