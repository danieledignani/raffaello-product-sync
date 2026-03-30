<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Bulk_Actions {

    public function __construct() {
        add_action( 'admin_init', array( $this, 'init' ) );
    }

    public function init() {
        add_filter( 'bulk_actions-edit-product', array( $this, 'add_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    public function add_bulk_actions( $actions ) {
        $actions['synch_products'] = 'Forza Sincronizzazione';
        $actions['synch_data_cancellation_in_products_all'] = 'Elimina Dati sincronizzazione e prodotti relativi';

        $stores = get_option( 'wc_api_mps_stores', array() );
        foreach ( $stores as $url => $data ) {
            $name = isset( $data['store_name'] ) ? $data['store_name'] : $url;
            $actions[ 'synch_data_cancellation_' . base64_encode( $url ) ] = 'Elimina Dati sincronizzazione e prodotti da ' . $name;
        }

        return $actions;
    }

    public function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
        // Force sync (in background via Action Scheduler)
        if ( $doaction === 'synch_products' ) {
            $stores = get_option( 'wc_api_mps_stores', array() );
            $store_urls = array_keys( array_filter( $stores, function( $s ) { return ! empty( $s['status'] ); } ) );
            if ( ! empty( $store_urls ) ) {
                RPS_Background_Sync::instance()->create_batch( $post_ids, $store_urls );
            }
            return add_query_arg( 'synched_products', count( $post_ids ), $redirect_to );
        }

        // Delete all sync data
        if ( $doaction === 'synch_data_cancellation_in_products_all' ) {
            foreach ( $post_ids as $pid ) {
                RPS_Product_Sync::delete_remote_product( $pid );
                delete_post_meta( $pid, 'mpsrel' );
                delete_post_meta( $pid, 'sites_to_synch' );
                delete_post_meta( $pid, '_sites_to_synch' );
            }
            return add_query_arg( 'synch_data_delete_in_products', count( $post_ids ), $redirect_to );
        }

        // Delete sync data per store
        if ( strpos( $doaction, 'synch_data_cancellation_' ) === 0 ) {
            $encoded = str_replace( 'synch_data_cancellation_', '', $doaction );
            $store_url = base64_decode( $encoded );
            $stores = get_option( 'wc_api_mps_stores', array() );
            if ( ! isset( $stores[ $store_url ] ) ) return $redirect_to;

            $acf_val = $stores[ $store_url ]['acf_opt_value'];
            foreach ( $post_ids as $pid ) {
                RPS_Product_Sync::delete_remote_product( $pid, array( $store_url => $stores[ $store_url ] ) );
                $mps = get_post_meta( $pid, 'mpsrel', true );
                if ( is_array( $mps ) && isset( $mps[ $store_url ] ) ) {
                    unset( $mps[ $store_url ] );
                    update_post_meta( $pid, 'mpsrel', $mps );
                }
                $sites = get_post_meta( $pid, 'sites_to_synch', true );
                if ( is_array( $sites ) && in_array( $acf_val, $sites ) ) {
                    $sites = array_diff( $sites, array( $acf_val ) );
                    update_post_meta( $pid, 'sites_to_synch', $sites );
                }
                if ( empty( $mps ) && empty( $sites ) ) {
                    delete_post_meta( $pid, '_sites_to_synch' );
                    delete_post_meta( $pid, 'sites_to_synch' );
                    delete_post_meta( $pid, 'mpsrel' );
                }
            }
            return add_query_arg( 'synch_data_delete_in_products', count( $post_ids ), $redirect_to );
        }

        return $redirect_to;
    }

    public function admin_notices() {
        if ( ! empty( $_REQUEST['synched_products'] ) ) {
            $c = intval( $_REQUEST['synched_products'] );
            echo "<div class='updated fade'><p>Sync avviato in background per {$c} prodotti. <a href='" . admin_url('admin.php?page=wc_api_mps_bulk_sync') . "'>Vedi progresso</a></p></div>";
        }
        if ( ! empty( $_REQUEST['synch_data_delete_in_products'] ) ) {
            $c = intval( $_REQUEST['synch_data_delete_in_products'] );
            echo "<div class='updated fade'><p>Cancellati dati sincronizzazione di {$c} prodotti.</p></div>";
        }
    }
}

// Helper per aggiungere sito a sites_to_synch
function wc_api_mps_add_site_to_synch( $product_id, $new_site ) {
    $current = get_post_meta( $product_id, 'sites_to_synch', true );
    if ( ! is_array( $current ) ) $current = array();
    if ( ! in_array( $new_site, $current ) ) {
        $current[] = $new_site;
        update_post_meta( $product_id, 'sites_to_synch', $current );
    }
    if ( ! get_post_meta( $product_id, '_sites_to_synch', true ) ) {
        update_post_meta( $product_id, '_sites_to_synch', 'field_667bd2eb7950e' );
    }
}
