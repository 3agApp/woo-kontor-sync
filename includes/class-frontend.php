<?php
/**
 * Customer-facing output for Kontor shipment tracking.
 *
 * Renders the tracking info synced from Kontor (_wks_kontor_provider,
 * _wks_tracking_number, _wks_tracking_url) on the My Account order view,
 * the thank-you page and in customer emails. Nothing is rendered while
 * no tracking number has been synced yet.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WKS_Frontend {

    public function __construct() {
        // Fires on both My Account → view order and the order-received page.
        add_action('woocommerce_order_details_after_order_table', [$this, 'render_order_tracking']);

        add_action('woocommerce_email_after_order_table', [$this, 'render_email_tracking'], 10, 4);
    }

    /**
     * Tracking data for an order, or null when there is nothing to show.
     */
    private function get_tracking($order) {
        if (!$order instanceof WC_Order) {
            return null;
        }

        $tracking = trim((string) $order->get_meta('_wks_tracking_number'));
        if ($tracking === '') {
            return null;
        }

        return [
            'provider' => trim((string) $order->get_meta('_wks_kontor_provider')),
            'number'   => $tracking,
            'url'      => trim((string) $order->get_meta('_wks_tracking_url')),
        ];
    }

    /**
     * Tracking section on the order details page (My Account / thank-you).
     */
    public function render_order_tracking($order) {
        $tracking = $this->get_tracking($order);
        if ($tracking === null) {
            return;
        }
        ?>
        <section class="woocommerce-order-tracking wks-order-tracking" style="margin-bottom: 2em;">
            <h2 class="woocommerce-order-tracking__title"><?php esc_html_e('Shipment Tracking', 'woo-kontor-sync'); ?></h2>
            <table class="woocommerce-table shop_table wks-tracking-table">
                <tbody>
                    <?php if ($tracking['provider'] !== ''): ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Shipping provider:', 'woo-kontor-sync'); ?></th>
                            <td><?php echo esc_html($tracking['provider']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Tracking number:', 'woo-kontor-sync'); ?></th>
                        <td>
                            <?php if ($tracking['url'] !== ''): ?>
                                <a href="<?php echo esc_url($tracking['url']); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html($tracking['number']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($tracking['number']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($tracking['url'] !== ''): ?>
                        <tr>
                            <th scope="row">&nbsp;</th>
                            <td>
                                <a href="<?php echo esc_url($tracking['url']); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e('Track your shipment', 'woo-kontor-sync'); ?> &rarr;
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    /**
     * Tracking block in customer emails (HTML and plain text).
     */
    public function render_email_tracking($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin) {
            return;
        }

        $tracking = $this->get_tracking($order);
        if ($tracking === null) {
            return;
        }

        if ($plain_text) {
            echo "\n" . esc_html(wc_strtoupper(__('Shipment Tracking', 'woo-kontor-sync'))) . "\n\n";
            if ($tracking['provider'] !== '') {
                echo esc_html(__('Shipping provider:', 'woo-kontor-sync') . ' ' . $tracking['provider']) . "\n";
            }
            echo esc_html(__('Tracking number:', 'woo-kontor-sync') . ' ' . $tracking['number']) . "\n";
            if ($tracking['url'] !== '') {
                echo esc_html(__('Track your shipment', 'woo-kontor-sync') . ': ') . esc_url($tracking['url']) . "\n";
            }
            echo "\n";
            return;
        }
        ?>
        <div class="wks-email-tracking" style="margin-bottom: 24px;">
            <h2><?php esc_html_e('Shipment Tracking', 'woo-kontor-sync'); ?></h2>
            <p>
                <?php if ($tracking['provider'] !== ''): ?>
                    <?php esc_html_e('Shipping provider:', 'woo-kontor-sync'); ?>
                    <strong><?php echo esc_html($tracking['provider']); ?></strong><br>
                <?php endif; ?>
                <?php esc_html_e('Tracking number:', 'woo-kontor-sync'); ?>
                <strong>
                    <?php if ($tracking['url'] !== ''): ?>
                        <a href="<?php echo esc_url($tracking['url']); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($tracking['number']); ?>
                        </a>
                    <?php else: ?>
                        <?php echo esc_html($tracking['number']); ?>
                    <?php endif; ?>
                </strong>
                <?php if ($tracking['url'] !== ''): ?>
                    <br>
                    <a href="<?php echo esc_url($tracking['url']); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Track your shipment', 'woo-kontor-sync'); ?> &rarr;
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
