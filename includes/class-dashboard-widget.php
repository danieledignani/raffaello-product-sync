<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Dashboard_Widget {

    public function __construct() {
        add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
    }

    public function register_widget() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        wp_add_dashboard_widget(
            'rps_sync_status',
            'Product Sync Status',
            array( $this, 'render' )
        );
    }

    public function render() {
        global $wpdb;
        $log_table = $wpdb->prefix . 'rps_sync_log';

        // Ultimo sync riuscito
        $last_sync = $wpdb->get_row(
            "SELECT timestamp, message, store_url, product_id FROM $log_table WHERE context IN ('addProduct','updateProduct') AND level = 'info' ORDER BY timestamp DESC LIMIT 1"
        );

        // Errori ultime 24h
        $errors_24h = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $log_table WHERE level = 'error' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        // Sync ultime 24h
        $syncs_24h = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $log_table WHERE context IN ('addProduct','updateProduct') AND level = 'info' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        // Batch attivo
        $active_batch = null;
        $batch_results = $wpdb->get_results(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'rps_batch_%' ORDER BY option_id DESC LIMIT 1"
        );
        if ( $batch_results ) {
            $batch = maybe_unserialize( $batch_results[0]->option_value );
            if ( is_array( $batch ) && $batch['status'] === 'running' ) {
                $active_batch = $batch;
            }
        }

        ?>
        <div class="rps-dashboard-widget">
            <?php if ( $active_batch ) : ?>
                <div style="background:#f0f6fc;padding:10px;border-left:4px solid #0073aa;margin-bottom:12px;">
                    <strong>Sync in corso:</strong>
                    <?php echo esc_html( $active_batch['completed'] + $active_batch['failed'] ); ?> / <?php echo esc_html( $active_batch['total'] ); ?> completati
                    <?php if ( $active_batch['failed'] ) : ?>
                        <span style="color:#dc3232;">(<?php echo esc_html( $active_batch['failed'] ); ?> errori)</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <table class="widefat" style="border:0;">
                <tbody>
                    <tr>
                        <td><strong>Sync ultime 24h</strong></td>
                        <td><?php echo esc_html( $syncs_24h ); ?> prodotti</td>
                    </tr>
                    <tr>
                        <td><strong>Errori ultime 24h</strong></td>
                        <td style="<?php echo $errors_24h ? 'color:#dc3232;font-weight:bold' : ''; ?>">
                            <?php echo esc_html( $errors_24h ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Ultimo sync</strong></td>
                        <td>
                            <?php if ( $last_sync ) : ?>
                                <?php echo esc_html( $last_sync->timestamp ); ?>
                                <?php if ( $last_sync->product_id ) : ?>
                                    — <a href="<?php echo esc_url( get_edit_post_link( $last_sync->product_id ) ); ?>">
                                        #<?php echo esc_html( $last_sync->product_id ); ?>
                                    </a>
                                <?php endif; ?>
                            <?php else : ?>
                                Nessun sync registrato
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top:12px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc_api_mps_bulk_sync' ) ); ?>" class="button">Bulk Sync</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc_api_mps_sync_log' ) ); ?>" class="button">Sync Log</a>
            </p>
        </div>
        <?php
    }
}
