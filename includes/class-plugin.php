<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Plugin {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Logger (crea tabella se necessario)
        RPS_Logger::instance();

        // Admin
        if ( is_admin() ) {
            new RPS_Admin_Pages();
            new RPS_Metabox();
            new RPS_Bulk_Actions();
        }

        // Admin Columns (hooks per rendering)
        new RPS_Admin_Columns();
        new RPS_Dashboard_Widget();
        new RPS_Test_Runner();

        // Background sync
        RPS_Background_Sync::instance();

        // Hook per auto-sync
        $this->register_sync_hooks();
    }

    private function register_sync_hooks() {
        // Auto sync on product save (admin footer AJAX)
        add_action( 'admin_footer', array( $this, 'admin_footer_auto_sync' ), 20 );
        add_action( 'wp_ajax_wc_api_mps_auto_sync', array( $this, 'ajax_auto_sync' ), 20 );

        // Manual sync AJAX
        add_action( 'wp_ajax_wc_api_mps_manual_sync', array( $this, 'ajax_manual_sync' ), 20 );

        // Save post hook (inline edit, REST API)
        add_action( 'save_post', array( $this, 'save_post' ), 20, 1 );

        // Sync on product trash/delete
        add_action( 'wp_trash_post', array( $this, 'trash_post' ), 20, 1 );
        add_action( 'before_delete_post', array( $this, 'before_delete_post' ), 20, 1 );

        // Exclude mpsrel from duplicate
        add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_meta_on_duplicate' ), 20, 1 );

        // ACF sync removal
        add_action( 'woocommerce_update_product', array( $this, 'handle_acf_sync_removal' ), 20, 1 );

        // Force sync AJAX
        add_action( 'wp_ajax_rps_force_sync_count', array( $this, 'ajax_force_sync_count' ) );
        add_action( 'wp_ajax_rps_force_sync_start', array( $this, 'ajax_force_sync_start' ) );
        add_action( 'wp_ajax_rps_sync_all_filtered', array( $this, 'ajax_sync_all_filtered' ) );

        // URL migration AJAX
        add_action( 'wp_ajax_rps_migrate_urls', array( $this, 'ajax_migrate_urls' ) );

        // Enqueue admin assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'wc_api_mps' ) !== false ) {
            wp_enqueue_style( 'rps-admin', RPS_PLUGIN_URL . 'assets/css/admin.css', array(), RPS_VERSION );
            wp_enqueue_script( 'rps-bulk-sync', RPS_PLUGIN_URL . 'assets/js/bulk-sync.js', array( 'jquery' ), RPS_VERSION, true );
            wp_localize_script( 'rps-bulk-sync', 'rps_ajax', array(
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'rps_bulk_sync' ),
                'log_page_url' => admin_url( 'admin.php?page=wc_api_mps_sync_log' ),
            ) );
        }
    }

    public function admin_footer_auto_sync() {
        $post_id = ( isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0 );
        if ( $post_id && isset( $_REQUEST['message'] ) ) {
            $post_type = get_post_type( $post_id );
            $post_status = get_post_status( $post_id );
            if ( $post_type == 'product' && $post_status != 'draft' ) {
                $sync_type = get_option( 'wc_api_mps_sync_type' );
                $disable_auto_sync = get_post_meta( $post_id, 'wc_api_mps_disable_auto_sync', true );
                if ( $sync_type == 'auto' && ! $disable_auto_sync ) {
                    ?>
                    <script type="text/javascript">
                        jQuery( document ).ready( function( $ ) {
                            $.post( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                                'action': 'wc_api_mps_auto_sync',
                                '_ajax_nonce': '<?php echo wp_create_nonce( 'rps_sync_action' ); ?>',
                                'product_id': <?php echo esc_attr( $post_id ); ?>
                            });
                        });
                    </script>
                    <?php
                }
            }
        }
    }

    public function ajax_auto_sync() {
        check_ajax_referer( 'rps_sync_action' );
        $product_id = ( isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0 );
        if ( $product_id ) {
            $stores = get_option( 'wc_api_mps_stores' );
            RPS_Product_Sync::sync( $product_id, $stores );
        }
        wp_die();
    }

    public function ajax_manual_sync() {
        check_ajax_referer( 'rps_sync_action' );
        $product_id = ( isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0 );
        $selected_stores = ( isset( $_POST['stores'] ) ? array_map( 'sanitize_text_field', $_POST['stores'] ) : array() );
        if ( $product_id && ! empty( $selected_stores ) ) {
            $stores = get_option( 'wc_api_mps_stores' );
            $filtered = array();
            foreach ( $selected_stores as $url ) {
                if ( isset( $stores[ $url ] ) ) {
                    $filtered[ $url ] = $stores[ $url ];
                }
            }
            if ( ! empty( $filtered ) ) {
                RPS_Product_Sync::sync( $product_id, $filtered );
            }
        }
        wp_die();
    }

    public function save_post( $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( $post_type != 'product' ) return;

        if ( isset( $_POST['wc_api_mps_disable_auto_sync'] ) ) {
            update_post_meta( $post_id, 'wc_api_mps_disable_auto_sync', (int) $_POST['wc_api_mps_disable_auto_sync'] );
        }

        $disable_auto_sync = get_post_meta( $post_id, 'wc_api_mps_disable_auto_sync', true );
        $sync_type = get_option( 'wc_api_mps_sync_type' );
        $inline_edit = ( isset( $_POST['_inline_edit'] ) ? 1 : 0 );
        $is_rest_api = ( defined( 'REST_REQUEST' ) && REST_REQUEST );

        if ( ( $inline_edit || $is_rest_api ) && $sync_type == 'auto' && ! $disable_auto_sync ) {
            $stores = get_option( 'wc_api_mps_stores' );
            RPS_Logger::instance()->info( 'save_post', "Sync triggered for product {$post_id} via " . ( $inline_edit ? 'inline edit' : 'REST API' ) );
            RPS_Product_Sync::sync( $post_id, $stores );
        }
    }

    public function trash_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type != 'product' ) return;

        $wc_api_mps = get_post_meta( $post_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) return;

        $product_delete = get_option( 'wc_api_mps_product_delete' );
        if ( $product_delete && ! empty( $wc_api_mps ) ) {
            RPS_Product_Sync::delete_remote_product( $post_id );
        }
    }

    public function before_delete_post( $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( $post_type === 'product' || $post_type === 'product_variation' ) {
            RPS_Product_Sync::delete_remote_product( $post_id );
        }
    }

    public function exclude_meta_on_duplicate( $meta_to_exclude ) {
        $meta_to_exclude[] = 'mpsrel';
        return $meta_to_exclude;
    }

    public function handle_acf_sync_removal( $product_id ) {
        if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;

        $stores = get_option( 'wc_api_mps_stores', array() );
        $old_sites = get_post_meta( $product_id, 'sites_to_synch', true );
        if ( ! is_array( $old_sites ) ) $old_sites = array();

        $new_sites = isset( $_POST['acf']['field_667bd2eb7950e'] ) ? (array) $_POST['acf']['field_667bd2eb7950e'] : array();
        $removed = array_diff( $old_sites, $new_sites );
        if ( empty( $removed ) ) return;

        $mpsrel = get_post_meta( $product_id, 'mpsrel', true );
        if ( ! is_array( $mpsrel ) ) $mpsrel = array();

        foreach ( $stores as $store_url => $store_data ) {
            if ( in_array( $store_data['acf_opt_value'], $removed ) ) {
                if ( isset( $mpsrel[ $store_url ] ) && $mpsrel[ $store_url ] ) {
                    RPS_Product_Sync::delete_remote_product( $product_id, array( $store_url => $store_data ) );
                    unset( $mpsrel[ $store_url ] );
                }
            }
        }

        update_post_meta( $product_id, 'mpsrel', $mpsrel );
        update_post_meta( $product_id, 'sites_to_synch', $new_sites );
        RPS_Logger::instance()->info( 'acf_removal', "Prodotto {$product_id}, siti rimossi: " . implode( ', ', $removed ) );
    }

    public function ajax_force_sync_count() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $store_url = isset( $_POST['store_url'] ) ? sanitize_text_field( $_POST['store_url'] ) : '';
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'all';
        $product_ids = self::get_flagged_product_ids( $store_url, $mode );
        wp_send_json_success( array( 'count' => count( $product_ids ) ) );
    }

    public function ajax_force_sync_start() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $store_url = isset( $_POST['store_url'] ) ? sanitize_text_field( $_POST['store_url'] ) : '';
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'all';
        $product_ids = self::get_flagged_product_ids( $store_url, $mode );

        if ( empty( $product_ids ) ) {
            wp_send_json_error( 'Nessun prodotto trovato' );
            return;
        }

        // Determina quali store usare
        $all_stores = get_option( 'wc_api_mps_stores', array() );
        if ( $store_url === '__all__' ) {
            $store_urls = array_keys( array_filter( $all_stores, function( $s ) { return ! empty( $s['status'] ); } ) );
        } else {
            $store_urls = array( $store_url );
        }

        $batch_id = RPS_Background_Sync::instance()->create_batch( $product_ids, $store_urls );
        wp_send_json_success( array( 'batch_id' => $batch_id, 'count' => count( $product_ids ) ) );
    }

    public function ajax_sync_all_filtered() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $store_urls = isset( $_POST['stores'] ) ? array_map( 'sanitize_text_field', $_POST['stores'] ) : array();
        // Se nessuno store selezionato, usa tutti gli store attivi
        // (ogni prodotto verrà sincronizzato verso i suoi store configurati in ACF)
        if ( empty( $store_urls ) ) {
            $all_stores = get_option( 'wc_api_mps_stores', array() );
            $store_urls = array_keys( array_filter( $all_stores, function( $s ) { return ! empty( $s['status'] ); } ) );
            if ( empty( $store_urls ) ) {
                wp_send_json_error( 'Nessuno store configurato e attivo' );
                return;
            }
        }

        // Ricostruisci la query dai parametri filtro
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );

        $s = isset( $_POST['s'] ) ? sanitize_text_field( $_POST['s'] ) : '';
        $product_cat = isset( $_POST['product_cat'] ) ? (int) $_POST['product_cat'] : 0;
        $product_brand = isset( $_POST['product_brand'] ) ? (int) $_POST['product_brand'] : 0;
        $product_tag = isset( $_POST['product_tag'] ) ? (int) $_POST['product_tag'] : 0;
        $status = isset( $_POST['sync_status'] ) ? sanitize_text_field( $_POST['sync_status'] ) : '';
        $store_filter = isset( $_POST['store_filter'] ) ? sanitize_text_field( $_POST['store_filter'] ) : '';

        if ( $s ) $args['s'] = $s;
        if ( $product_cat ) $args['tax_query'][] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $product_cat );
        if ( $product_brand ) $args['tax_query'][] = array( 'taxonomy' => 'product_brand', 'field' => 'term_id', 'terms' => $product_brand );
        if ( $product_tag ) $args['tax_query'][] = array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $product_tag );

        if ( $status == 'synced' ) {
            if ( $store_filter ) { $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => $store_filter, 'compare' => 'LIKE' ); }
            else { $args['meta_query'][] = array( 'key' => 'mpsrel', 'compare' => 'EXISTS' ); $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => 'a:0:{}', 'compare' => '!=' ); }
        } elseif ( $status == 'not-synced' ) {
            $args['meta_query']['relation'] = 'OR';
            if ( $store_filter ) { $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => $store_filter, 'compare' => 'NOT LIKE' ); }
            $args['meta_query'][] = array( 'key' => 'mpsrel', 'compare' => 'NOT EXISTS' );
            $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => 'a:0:{}', 'compare' => '=' );
        }

        $query = new \WP_Query( $args );
        $product_ids = $query->posts;

        if ( empty( $product_ids ) ) {
            wp_send_json_error( 'Nessun prodotto trovato con i filtri correnti' );
            return;
        }

        $batch_id = RPS_Background_Sync::instance()->create_batch( $product_ids, $store_urls );
        wp_send_json_success( array( 'batch_id' => $batch_id, 'count' => count( $product_ids ) ) );
    }

    /**
     * Trova tutti i product ID flaggati per un dato store (o per qualsiasi store).
     */
    private static function get_flagged_product_ids( $store_url, $mode = 'all' ) {
        $stores = get_option( 'wc_api_mps_stores', array() );

        $acf_values = array();
        $store_urls_to_check = array();
        if ( $store_url === '__all__' ) {
            foreach ( $stores as $url => $data ) {
                if ( ! empty( $data['status'] ) && ! empty( $data['acf_opt_value'] ) ) {
                    $acf_values[] = $data['acf_opt_value'];
                    $store_urls_to_check[] = $url;
                }
            }
        } else {
            if ( isset( $stores[ $store_url ] ) && ! empty( $stores[ $store_url ]['acf_opt_value'] ) ) {
                $acf_values[] = $stores[ $store_url ]['acf_opt_value'];
                $store_urls_to_check[] = $store_url;
            }
        }

        if ( empty( $acf_values ) ) return array();

        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array( 'relation' => 'OR' ),
        );

        foreach ( $acf_values as $val ) {
            $args['meta_query'][] = array(
                'key'     => 'sites_to_synch',
                'value'   => $val,
                'compare' => 'LIKE',
            );
        }

        $query = new \WP_Query( $args );
        $product_ids = $query->posts;

        // Se modalità "missing", filtra solo quelli senza mpsrel per gli store selezionati
        if ( $mode === 'missing' && ! empty( $product_ids ) ) {
            $filtered = array();
            foreach ( $product_ids as $pid ) {
                $mpsrel = get_post_meta( $pid, 'mpsrel', true );
                if ( ! is_array( $mpsrel ) ) {
                    $filtered[] = $pid;
                    continue;
                }
                // Controlla se manca l'ID remoto per almeno uno degli store selezionati
                foreach ( $store_urls_to_check as $surl ) {
                    if ( ! isset( $mpsrel[ $surl ] ) || ! $mpsrel[ $surl ] ) {
                        $filtered[] = $pid;
                        break;
                    }
                }
            }
            $product_ids = $filtered;
        }

        return $product_ids;
    }

    public function ajax_migrate_urls() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $old_url = isset( $_POST['old_url'] ) ? rtrim( sanitize_text_field( $_POST['old_url'] ), '/' ) : '';
        $new_url = isset( $_POST['new_url'] ) ? rtrim( sanitize_text_field( $_POST['new_url'] ), '/' ) : '';

        if ( ! $old_url || ! $new_url || $old_url === $new_url ) {
            wp_send_json_error( 'URL non validi o identici' );
            return;
        }

        global $wpdb;
        $changes = array();

        // 1. wp_options: wc_api_mps_stores - aggiorna la chiave dell'array (URL dello store)
        $stores = get_option( 'wc_api_mps_stores', array() );
        if ( isset( $stores[ $old_url ] ) ) {
            $stores[ $new_url ] = $stores[ $old_url ];
            unset( $stores[ $old_url ] );
            update_option( 'wc_api_mps_stores', $stores );
            $changes[] = 'wp_options: wc_api_mps_stores aggiornato';
        }

        // 2. wp_postmeta: mpsrel - trova e sostituisci la chiave URL nei valori serializzati
        $mpsrel_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'mpsrel' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like( $old_url ) . '%'
            )
        );
        $post_count = 0;
        foreach ( $mpsrel_posts as $row ) {
            $data = maybe_unserialize( $row->meta_value );
            if ( is_array( $data ) && isset( $data[ $old_url ] ) ) {
                $data[ $new_url ] = $data[ $old_url ];
                unset( $data[ $old_url ] );
                update_post_meta( $row->post_id, 'mpsrel', $data );
                $post_count++;
            }
        }
        if ( $post_count ) {
            $changes[] = "wp_postmeta: mpsrel aggiornato su {$post_count} prodotti/variazioni/immagini";
        }

        // 3. wp_termmeta: mpsrel - stessa cosa per categorie/tag/attributi/brand
        $mpsrel_terms = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'mpsrel' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like( $old_url ) . '%'
            )
        );
        $term_count = 0;
        foreach ( $mpsrel_terms as $row ) {
            $data = maybe_unserialize( $row->meta_value );
            if ( is_array( $data ) && isset( $data[ $old_url ] ) ) {
                $data[ $new_url ] = $data[ $old_url ];
                unset( $data[ $old_url ] );
                update_term_meta( $row->term_id, 'mpsrel', $data );
                $term_count++;
            }
        }
        if ( $term_count ) {
            $changes[] = "wp_termmeta: mpsrel aggiornato su {$term_count} termini (categorie/tag/brand/attributi)";
        }

        // 4. rps_sync_log: store_url
        $log_table = $wpdb->prefix . 'rps_sync_log';
        $log_count = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$log_table} SET store_url = %s WHERE store_url = %s",
                $new_url, $old_url
            )
        );
        if ( $log_count ) {
            $changes[] = "rps_sync_log: {$log_count} righe aggiornate";
        }

        if ( empty( $changes ) ) {
            wp_send_json_error( "Nessun dato trovato per l'URL: {$old_url}" );
            return;
        }

        RPS_Logger::instance()->info( 'url_migration', "URL migrato: {$old_url} -> {$new_url}", array(
            'store_url' => $new_url,
            'request_data' => array( 'old_url' => $old_url, 'new_url' => $new_url, 'changes' => $changes ),
        ) );

        wp_send_json_success( array( 'changes' => $changes ) );
    }

    public static function activate() {
        // Crea tabella log
        RPS_Logger::create_table();

        // Default options (solo se non esistono)
        if ( ! get_option( 'wc_api_mps_sync_type' ) ) {
            update_option( 'wc_api_mps_sync_type', 'auto' );
        }
        if ( ! get_option( 'wc_api_mps_authorization' ) ) {
            update_option( 'wc_api_mps_authorization', 'query' );
        }
        if ( ! get_option( 'wc_api_mps_old_products_sync_by' ) ) {
            update_option( 'wc_api_mps_old_products_sync_by', 'slug' );
        }
        if ( ! get_option( 'wc_api_mps_product_sync_type' ) ) {
            update_option( 'wc_api_mps_product_sync_type', 'full_product' );
        }
        if ( ! get_option( 'wc_api_mps_stock_sync' ) ) {
            update_option( 'wc_api_mps_stock_sync', 1 );
        }
    }
}
