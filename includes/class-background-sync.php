<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Background_Sync {

    private static $instance = null;
    private $batch_option_prefix = 'rps_batch_';

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Action Scheduler hook per processare singoli prodotti
        add_action( 'rps_sync_single_product', array( $this, 'process_single_product' ), 10, 3 );

        // AJAX per polling stato batch
        add_action( 'wp_ajax_rps_batch_status', array( $this, 'ajax_batch_status' ) );
        add_action( 'wp_ajax_rps_cancel_batch', array( $this, 'ajax_cancel_batch' ) );
        add_action( 'wp_ajax_rps_get_active_batches', array( $this, 'ajax_get_active_batches' ) );
    }

    /**
     * Crea un batch di sincronizzazione.
     * Schedula un'azione per ogni prodotto tramite Action Scheduler.
     */
    public function create_batch( $product_ids, $store_urls ) {
        $batch_id = 'batch_' . time() . '_' . wp_generate_password( 6, false );

        // Filtra gli store selezionati
        $all_stores = get_option( 'wc_api_mps_stores', array() );
        $selected_stores = array();
        foreach ( $store_urls as $url ) {
            if ( isset( $all_stores[ $url ] ) ) {
                $selected_stores[ $url ] = $all_stores[ $url ];
            }
        }

        // Salva stato batch
        $batch_data = array(
            'id'         => $batch_id,
            'total'      => count( $product_ids ),
            'completed'  => 0,
            'failed'     => 0,
            'status'     => 'running',
            'created_at' => current_time( 'mysql' ),
            'user_id'    => get_current_user_id(),
            'stores'     => array_keys( $selected_stores ),
            'errors'     => array(),
        );
        update_option( $this->batch_option_prefix . $batch_id, $batch_data, false );

        // Schedula un'azione per ogni prodotto
        foreach ( $product_ids as $pid ) {
            // Aggiungi sites_to_synch per ogni prodotto e store
            foreach ( $selected_stores as $url => $sc ) {
                wc_api_mps_add_site_to_synch( $pid, $sc['acf_opt_value'] );
            }

            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( time(), 'rps_sync_single_product', array(
                    'product_id'      => (int) $pid,
                    'batch_id'        => $batch_id,
                    'store_urls_json' => wp_json_encode( array_keys( $selected_stores ) ),
                ), 'rps-bulk-sync' );
            }
        }

        $logger = RPS_Logger::instance();
        $logger->info( 'batch_create', "Batch {$batch_id} creato: " . count( $product_ids ) . " prodotti, " . count( $selected_stores ) . " store" );

        return $batch_id;
    }

    /**
     * Processa un singolo prodotto (chiamato da Action Scheduler).
     */
    public function process_single_product( $product_id, $batch_id, $store_urls_json ) {
        $batch_data = get_option( $this->batch_option_prefix . $batch_id );
        if ( ! $batch_data || $batch_data['status'] === 'cancelled' ) return;

        $store_urls = json_decode( $store_urls_json, true );
        $all_stores = get_option( 'wc_api_mps_stores', array() );
        $selected = array();
        foreach ( $store_urls as $url ) {
            if ( isset( $all_stores[ $url ] ) ) $selected[ $url ] = $all_stores[ $url ];
        }

        $success = false;
        try {
            RPS_Product_Sync::sync( $product_id, $selected, '', true );
            $success = true;
        } catch ( \Exception $e ) {
            RPS_Logger::instance()->error( 'batch_sync', "Errore sync prodotto {$product_id}: " . $e->getMessage(), array( 'product_id' => $product_id ) );
        }

        // Aggiorna contatori batch — wp_cache_delete forza lettura dal DB
        // per ridurre race condition con worker concorrenti
        $option_name = $this->batch_option_prefix . $batch_id;
        wp_cache_delete( $option_name, 'options' );
        $batch_data = get_option( $option_name );
        if ( ! $batch_data ) return;

        if ( $success ) {
            $batch_data['completed']++;
        } else {
            $batch_data['failed']++;
        }

        if ( ( $batch_data['completed'] + $batch_data['failed'] ) >= $batch_data['total'] ) {
            $batch_data['status'] = 'completed';
            RPS_Logger::instance()->info( 'batch_complete', "Batch {$batch_id} completato: {$batch_data['completed']} ok, {$batch_data['failed']} errori" );

            // Notifica email opzionale
            self::maybe_send_completion_email( $batch_data );
        }

        update_option( $option_name, $batch_data, false );
    }

    /**
     * Invia email di notifica al completamento del batch (se abilitato nelle impostazioni).
     */
    private static function maybe_send_completion_email( $batch_data ) {
        $enabled = get_option( 'wc_api_mps_email_notification', 0 );
        if ( ! $enabled ) return;

        $to = get_option( 'wc_api_mps_email_recipient', get_option( 'admin_email' ) );
        if ( ! $to ) return;

        $subject = sprintf( 'Product Sync completato: %d ok, %d errori', $batch_data['completed'], $batch_data['failed'] );

        $body = sprintf(
            "Batch ID: %s\nCompletati: %d / %d\nErrori: %d\nAvviato da: utente #%s\nData: %s\n\nVedi il log completo: %s",
            $batch_data['id'],
            $batch_data['completed'],
            $batch_data['total'],
            $batch_data['failed'],
            $batch_data['user_id'] ?? '-',
            $batch_data['created_at'] ?? '-',
            admin_url( 'admin.php?page=wc_api_mps_sync_log' )
        );

        wp_mail( $to, $subject, $body );
    }

    public function ajax_batch_status() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( $_POST['batch_id'] ) : '';
        $data = get_option( $this->batch_option_prefix . $batch_id );
        if ( ! $data ) {
            wp_send_json_error( 'Batch not found' );
            return;
        }
        wp_send_json_success( $data );
    }

    public function ajax_cancel_batch() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( $_POST['batch_id'] ) : '';
        $data = get_option( $this->batch_option_prefix . $batch_id );
        if ( $data ) {
            $data['status'] = 'cancelled';
            update_option( $this->batch_option_prefix . $batch_id, $data, false );

            // Le azioni pendenti di questo batch verranno skippate al check
            // 'status === cancelled' in process_single_product().
            // Non cancelliamo con as_unschedule_all_actions perché cancellerebbe anche altri batch.

            RPS_Logger::instance()->info( 'batch_cancel', "Batch {$batch_id} annullato" );
        }
        wp_send_json_success();
    }

    public function ajax_get_active_batches() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'rps_batch_%' ORDER BY option_id DESC LIMIT 5"
        );

        $batches = array();
        foreach ( $results as $r ) {
            $data = maybe_unserialize( $r->option_value );
            if ( is_array( $data ) ) $batches[] = $data;
        }

        wp_send_json_success( $batches );
    }
}
