<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Logger {

    const MAX_ENTRIES = 500;

    private static $instance = null;
    private $table_name;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rps_sync_log';

        // Verifica se la tabella esiste, altrimenti la crea
        if ( get_option( 'rps_db_version' ) !== RPS_VERSION ) {
            self::create_table();
            update_option( 'rps_db_version', RPS_VERSION );
        }

        // AJAX per log viewer
        add_action( 'wp_ajax_rps_get_logs', array( $this, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_rps_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_rps_export_logs', array( $this, 'ajax_export_logs' ) );
    }

    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rps_sync_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            context varchar(100) NOT NULL DEFAULT '',
            message text NOT NULL,
            product_id bigint(20) unsigned DEFAULT NULL,
            store_url varchar(255) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            request_data longtext DEFAULT NULL,
            response_data longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_level (level),
            KEY idx_product_id (product_id),
            KEY idx_store_url (store_url(191))
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    private function log( $level, $context, $message, $extra = array() ) {
        global $wpdb;

        $data = array(
            'level'      => $level,
            'context'    => substr( $context, 0, 100 ),
            'message'    => $message,
            'user_id'    => get_current_user_id() ?: null,
        );

        if ( isset( $extra['product_id'] ) ) {
            $data['product_id'] = (int) $extra['product_id'];
        }
        if ( isset( $extra['store_url'] ) ) {
            $data['store_url'] = substr( $extra['store_url'], 0, 255 );
        }
        if ( isset( $extra['request_data'] ) ) {
            $safe = is_string( $extra['request_data'] ) ? $extra['request_data'] : wp_json_encode( self::mask_sensitive_data( $extra['request_data'] ), JSON_UNESCAPED_UNICODE );
            $data['request_data'] = $safe;
        }
        if ( isset( $extra['response_data'] ) ) {
            $safe = is_string( $extra['response_data'] ) ? $extra['response_data'] : wp_json_encode( self::mask_sensitive_data( $extra['response_data'] ), JSON_UNESCAPED_UNICODE );
            $data['response_data'] = $safe;
        }

        $wpdb->insert( $this->table_name, $data );

        // Prune: mantieni solo le ultime MAX_ENTRIES righe
        self::prune_old_entries();
    }

    /**
     * Maschera dati sensibili (consumer_key, consumer_secret, password, token).
     */
    private static function mask_sensitive_data( $data ) {
        if ( ! is_array( $data ) && ! is_object( $data ) ) {
            return $data;
        }

        $sensitive_keys = array( 'consumer_key', 'consumer_secret', 'password', 'access_token', 'refresh_token', 'client_secret' );
        $result = (array) $data;

        foreach ( $result as $key => &$value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = self::mask_sensitive_data( $value );
            } elseif ( is_string( $value ) && in_array( $key, $sensitive_keys, true ) ) {
                $value = strlen( $value ) > 8 ? substr( $value, 0, 4 ) . '****' . substr( $value, -4 ) : '****';
            }
        }

        return $result;
    }

    /**
     * Rimuove i log più vecchi mantenendo solo le ultime MAX_ENTRIES.
     */
    private static function prune_old_entries() {
        global $wpdb;
        $table = $wpdb->prefix . 'rps_sync_log';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

        if ( $count > self::MAX_ENTRIES ) {
            $delete_count = $count - self::MAX_ENTRIES;
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM $table ORDER BY timestamp ASC LIMIT %d",
                $delete_count
            ) );
        }
    }

    public function debug( $context, $message, $extra = array() ) {
        $this->log( 'debug', $context, $message, $extra );
    }

    public function info( $context, $message, $extra = array() ) {
        $this->log( 'info', $context, $message, $extra );
    }

    public function warning( $context, $message, $extra = array() ) {
        $this->log( 'warning', $context, $message, $extra );
    }

    public function error( $context, $message, $extra = array() ) {
        $this->log( 'error', $context, $message, $extra );
    }

    public function get_logs( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'level'      => '',
            'store_url'  => '',
            'product_id' => 0,
            'user_id'    => 0,
            'search'     => '',
            'date_from'  => '',
            'date_to'    => '',
            'per_page'   => 50,
            'page'       => 1,
            'orderby'    => 'timestamp',
            'order'      => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );
        $where = array( '1=1' );
        $values = array();

        if ( $args['level'] ) {
            $where[] = 'level = %s';
            $values[] = $args['level'];
        }
        if ( $args['store_url'] ) {
            $where[] = 'store_url = %s';
            $values[] = $args['store_url'];
        }
        if ( $args['product_id'] ) {
            $where[] = 'product_id = %d';
            $values[] = $args['product_id'];
        }
        if ( $args['user_id'] ) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }
        if ( $args['search'] ) {
            $where[] = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }
        if ( $args['date_from'] ) {
            $where[] = 'timestamp >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }
        if ( $args['date_to'] ) {
            $where[] = 'timestamp <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );
        $orderby = in_array( $args['orderby'], array( 'timestamp', 'level', 'context' ) ) ? $args['orderby'] : 'timestamp';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $offset = ( max( 1, $args['page'] ) - 1 ) * $args['per_page'];

        // Count
        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}";
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Results
        $sql = "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $all_values = array_merge( $values, array( $args['per_page'], $offset ) );
        $results = $wpdb->get_results( $wpdb->prepare( $sql, $all_values ) );

        return array(
            'items'      => $results,
            'total'      => $total,
            'pages'      => ceil( $total / $args['per_page'] ),
            'page'       => $args['page'],
            'per_page'   => $args['per_page'],
        );
    }

    public function cleanup_old_logs() {
        // Con MAX_ENTRIES + prune_old_entries(), la pulizia per data è un fallback extra
        self::prune_old_entries();
    }

    public function ajax_get_logs() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $result = $this->get_logs( array(
            'level'      => isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : '',
            'store_url'  => isset( $_POST['store_url'] ) ? sanitize_text_field( $_POST['store_url'] ) : '',
            'product_id' => isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0,
            'search'     => isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '',
            'date_from'  => isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '',
            'date_to'    => isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '',
            'per_page'   => isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 50,
            'page'       => isset( $_POST['page'] ) ? (int) $_POST['page'] : 1,
        ) );

        wp_send_json_success( $result );
    }

    public function ajax_clear_logs() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
        wp_send_json_success();
    }

    public function ajax_export_logs() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $result = $this->get_logs( array(
            'level'    => isset( $_GET['level'] ) ? sanitize_text_field( $_GET['level'] ) : '',
            'per_page' => 10000,
            'page'     => 1,
        ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=rps-sync-log-' . date( 'Y-m-d' ) . '.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'ID', 'Timestamp', 'Level', 'Context', 'Message', 'Product ID', 'Store URL', 'User ID' ) );

        foreach ( $result['items'] as $row ) {
            fputcsv( $output, array(
                $row->id, $row->timestamp, $row->level, $row->context,
                $row->message, $row->product_id, $row->store_url, $row->user_id,
            ) );
        }

        fclose( $output );
        exit;
    }
}
