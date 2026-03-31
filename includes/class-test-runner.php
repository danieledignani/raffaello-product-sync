<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Test_Runner {

    const TEST_PREFIX = '[RPS TEST] ';

    public function __construct() {
        add_action( 'wp_ajax_rps_run_tests', array( $this, 'ajax_run_tests' ) );
        add_action( 'wp_ajax_rps_cleanup_tests', array( $this, 'ajax_cleanup_tests' ) );
    }

    public function ajax_run_tests() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $store_url = isset( $_POST['store_url'] ) ? sanitize_text_field( $_POST['store_url'] ) : '';
        $stores = get_option( 'wc_api_mps_stores', array() );
        if ( ! isset( $stores[ $store_url ] ) ) {
            wp_send_json_error( 'Store non trovato' );
            return;
        }

        $store = $stores[ $store_url ];
        $api = new WC_API_MPS( $store_url, $store['consumer_key'], $store['consumer_secret'] );
        $results = array();

        $cleanup = array(
            'local_products'    => array(),
            'local_categories'  => array(),
            'local_tags'        => array(),
            'local_brands'      => array(),
            'remote_products'   => array(),
            'remote_categories' => array(),
            'remote_tags'       => array(),
            'remote_brands'     => array(),
        );

        // ── TEST 1: Connessione API ──
        $results[] = $this->test_connection( $api );
        if ( $results[0]['status'] === 'fail' ) {
            wp_send_json_success( array( 'results' => $results ) );
            return;
        }

        // ── TEST 2: Crea categoria test ──
        $cat_result = $this->test_create_category( $api, $store_url, $cleanup );
        $results[] = $cat_result;
        $test_cat_id = isset( $cat_result['data']['local_id'] ) ? $cat_result['data']['local_id'] : 0;

        // ── TEST 3: Crea tag test ──
        $tag_result = $this->test_create_tag( $api, $store_url, $cleanup );
        $results[] = $tag_result;
        $test_tag_id = isset( $tag_result['data']['local_id'] ) ? $tag_result['data']['local_id'] : 0;

        // ── TEST 4: Crea brand test ──
        $brand_result = $this->test_create_brand( $api, $store_url, $cleanup );
        $results[] = $brand_result;
        $test_brand_id = isset( $brand_result['data']['local_id'] ) ? $brand_result['data']['local_id'] : 0;

        // ── TEST 5: Crea prodotto semplice (PRIVATO, molti campi) ──
        $simple_result = $this->test_create_simple_product( $test_cat_id, $test_tag_id, $test_brand_id, $cleanup );
        $results[] = $simple_result;
        $simple_id = isset( $simple_result['data']['product_id'] ) ? $simple_result['data']['product_id'] : 0;

        // ── TEST 5: Sync prodotto semplice ──
        if ( $simple_id ) {
            $results[] = $this->test_sync_product( $simple_id, $store_url, $store, $api, $cleanup );
        }

        // ── TEST 6: Verifica prodotto remoto (tutti i campi) ──
        if ( $simple_id ) {
            $results[] = $this->test_verify_remote_product( $simple_id, $store_url, $api, 'semplice' );
        }

        // ── TEST 8: Verifica categoria, tag e brand remoti ──
        $results[] = $this->test_verify_remote_taxonomies( $test_cat_id, $test_tag_id, $test_brand_id, $store_url, $api, $cleanup );

        // ── TEST 8: Update e re-sync ──
        if ( $simple_id ) {
            $results[] = $this->test_update_and_resync( $simple_id, $store_url, $store, $api );
        }

        // ── TEST 9: Crea prodotto variabile (PRIVATO) ──
        $variable_result = $this->test_create_variable_product( $test_cat_id, $cleanup );
        $results[] = $variable_result;
        $variable_id = isset( $variable_result['data']['product_id'] ) ? $variable_result['data']['product_id'] : 0;

        // ── TEST 10: Sync prodotto variabile ──
        if ( $variable_id ) {
            $results[] = $this->test_sync_product( $variable_id, $store_url, $store, $api, $cleanup );
        }

        // ── TEST 11: Verifica variazioni remote ──
        if ( $variable_id ) {
            $results[] = $this->test_verify_remote_variations( $variable_id, $store_url, $api );
        }

        // ── TEST 12: Cancella prodotti remoti ──
        $results[] = $this->test_delete_remote_products( $cleanup, $api );

        // ── TEST 13: Cancella tassonomie remote ──
        $results[] = $this->test_delete_remote_taxonomies( $cleanup, $api );

        // ── TEST 14: Verifica cancellazione ──
        $results[] = $this->test_verify_deletion( $cleanup, $api );

        // ── CLEANUP locale ──
        $this->cleanup_local( $cleanup );
        $results[] = array(
            'name' => 'Pulizia locale',
            'status' => 'pass',
            'message' => 'Rimossi ' . count( $cleanup['local_products'] ) . ' prodotti, ' . count( $cleanup['local_categories'] ) . ' categorie, ' . count( $cleanup['local_tags'] ) . ' tag, ' . count( $cleanup['local_brands'] ) . ' brand di test',
        );

        wp_send_json_success( array( 'results' => $results ) );
    }

    // ── Test methods ──

    private function test_connection( $api ) {
        $auth = $api->authentication();
        if ( isset( $auth->code ) ) {
            return array( 'name' => 'Connessione API', 'status' => 'fail', 'message' => 'Autenticazione fallita: ' . ( isset( $auth->message ) ? $auth->message : $auth->code ) );
        }
        return array( 'name' => 'Connessione API', 'status' => 'pass', 'message' => 'Connessione riuscita' );
    }

    private function test_create_category( $api, $store_url, &$cleanup ) {
        $cat_name = self::TEST_PREFIX . 'Categoria ' . wp_generate_password( 4, false );
        $cat = wp_insert_term( $cat_name, 'product_cat', array(
            'slug'        => sanitize_title( $cat_name ),
            'description' => 'Categoria di test creata da RPS Test Runner',
        ) );
        if ( is_wp_error( $cat ) ) {
            return array( 'name' => 'Crea categoria test', 'status' => 'fail', 'message' => $cat->get_error_message() );
        }
        $cleanup['local_categories'][] = $cat['term_id'];
        return array(
            'name' => 'Crea categoria test',
            'status' => 'pass',
            'message' => "'{$cat_name}' creata (ID: {$cat['term_id']})",
            'data' => array( 'local_id' => $cat['term_id'] ),
        );
    }

    private function test_create_tag( $api, $store_url, &$cleanup ) {
        $tag_name = self::TEST_PREFIX . 'Tag ' . wp_generate_password( 4, false );
        $tag = wp_insert_term( $tag_name, 'product_tag', array(
            'slug'        => sanitize_title( $tag_name ),
            'description' => 'Tag di test creato da RPS Test Runner',
        ) );
        if ( is_wp_error( $tag ) ) {
            return array( 'name' => 'Crea tag test', 'status' => 'fail', 'message' => $tag->get_error_message() );
        }
        $cleanup['local_tags'][] = $tag['term_id'];
        return array(
            'name' => 'Crea tag test',
            'status' => 'pass',
            'message' => "'{$tag_name}' creato (ID: {$tag['term_id']})",
            'data' => array( 'local_id' => $tag['term_id'] ),
        );
    }

    private function test_create_brand( $api, $store_url, &$cleanup ) {
        // Verifica che la tassonomia product_brand esista
        if ( ! taxonomy_exists( 'product_brand' ) ) {
            return array( 'name' => 'Crea brand test', 'status' => 'skip', 'message' => 'Tassonomia product_brand non registrata (plugin brand non attivo)' );
        }
        $brand_name = self::TEST_PREFIX . 'Brand ' . wp_generate_password( 4, false );
        $brand = wp_insert_term( $brand_name, 'product_brand', array(
            'slug'        => sanitize_title( $brand_name ),
            'description' => 'Brand di test creato da RPS Test Runner',
        ) );
        if ( is_wp_error( $brand ) ) {
            return array( 'name' => 'Crea brand test', 'status' => 'fail', 'message' => $brand->get_error_message() );
        }
        $cleanup['local_brands'][] = $brand['term_id'];
        return array(
            'name' => 'Crea brand test',
            'status' => 'pass',
            'message' => "'{$brand_name}' creato (ID: {$brand['term_id']})",
            'data' => array( 'local_id' => $brand['term_id'] ),
        );
    }

    private function test_create_simple_product( $cat_id, $tag_id, $brand_id, &$cleanup ) {
        $name = self::TEST_PREFIX . 'Prodotto Semplice ' . wp_generate_password( 4, false );
        $product = new \WC_Product_Simple();
        $product->set_name( $name );
        $product->set_status( 'private' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_featured( true );
        $product->set_regular_price( '19.99' );
        $product->set_sale_price( '14.99' );
        // Date sconto: da oggi a tra 30 giorni
        $product->set_date_on_sale_from( date( 'Y-m-d', time() ) );
        $product->set_date_on_sale_to( date( 'Y-m-d', strtotime( '+30 days' ) ) );
        $product->set_sku( 'rps-test-' . wp_generate_password( 6, false ) );
        $product->set_description( '<p>Prodotto di test creato da <strong>RPS Test Runner</strong>.</p><p>Contiene HTML per verificare che la descrizione venga sincronizzata correttamente.</p><ul><li>Punto 1</li><li>Punto 2</li></ul>' );
        $product->set_short_description( 'Descrizione breve di test RPS con <em>formattazione</em>.' );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( 10 );
        $product->set_stock_status( 'instock' );
        $product->set_weight( '0.5' );
        $product->set_length( '20' );
        $product->set_width( '15' );
        $product->set_height( '5' );
        $product->set_tax_status( 'taxable' );
        $product->set_tax_class( '' );
        $product->set_sold_individually( true );
        $product->set_backorders( 'notify' );
        $product->set_purchase_note( 'Nota di test per il cliente — grazie per l\'acquisto!' );
        $product->set_menu_order( 99 );
        $product->set_reviews_allowed( true );
        $product->set_virtual( false );
        if ( $cat_id ) $product->set_category_ids( array( $cat_id ) );
        if ( $tag_id ) $product->set_tag_ids( array( $tag_id ) );
        $id = $product->save();

        if ( ! $id ) {
            return array( 'name' => 'Crea prodotto semplice (privato)', 'status' => 'fail', 'message' => 'Impossibile creare il prodotto' );
        }

        // Brand (tassonomia custom, non gestita da WC_Product)
        if ( $brand_id ) {
            wp_set_object_terms( $id, array( $brand_id ), 'product_brand' );
        }

        // Global Unique ID (ISBN/EAN) - WooCommerce 8.5+
        if ( method_exists( $product, 'set_global_unique_id' ) ) {
            $product->set_global_unique_id( '978-88-472-0000-0' );
            $product->save();
        }

        // Meta personalizzati
        update_post_meta( $id, 'test_custom_field', 'valore personalizzato di test' );
        update_post_meta( $id, 'test_numeric_field', '42' );
        // bookshop_link: meta specifico Raffaello che il sync gestisce
        update_post_meta( $id, 'bookshop_link', array(
            'title'  => 'Vedi nel bookshop',
            'url'    => 'https://example.com/test-bookshop',
            'target' => '_blank',
        ) );

        $cleanup['local_products'][] = $id;

        $fields_summary = "SKU: {$product->get_sku()}, Prezzo: 19.99/14.99 (sconto 30gg), Peso: 0.5kg, Dim: 20x15x5, Stock: 10, Featured, Sold individually, Backorders: notify";
        if ( $brand_id ) $fields_summary .= ', Brand';
        return array(
            'name' => 'Crea prodotto semplice (privato)',
            'status' => 'pass',
            'message' => "'{$name}' (ID: {$id}) — {$fields_summary}",
            'data' => array( 'product_id' => $id ),
        );
    }

    private function test_create_variable_product( $cat_id, &$cleanup ) {
        $name = self::TEST_PREFIX . 'Prodotto Variabile ' . wp_generate_password( 4, false );

        $attr_id = wc_attribute_taxonomy_id_by_name( 'pa_rps-test-size' );
        if ( ! $attr_id ) {
            $attr_id = wc_create_attribute( array(
                'name'     => 'RPS Test Size',
                'slug'     => 'rps-test-size',
                'type'     => 'select',
                'order_by' => 'menu_order',
            ) );
            register_taxonomy( 'pa_rps-test-size', 'product', array( 'hierarchical' => false ) );
        }

        $term_s = wp_insert_term( 'Small', 'pa_rps-test-size' );
        $term_m = wp_insert_term( 'Medium', 'pa_rps-test-size' );
        $term_s_id = is_wp_error( $term_s ) ? $term_s->get_error_data() : $term_s['term_id'];
        $term_m_id = is_wp_error( $term_m ) ? $term_m->get_error_data() : $term_m['term_id'];

        $product = new \WC_Product_Variable();
        $product->set_name( $name );
        $product->set_status( 'private' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_sku( 'rps-test-var-' . wp_generate_password( 6, false ) );
        $product->set_description( 'Prodotto variabile di test RPS con 2 variazioni (Small/Medium).' );
        $product->set_short_description( 'Test variabile RPS' );
        $product->set_weight( '1.0' );
        if ( $cat_id ) $product->set_category_ids( array( $cat_id ) );

        $attribute = new \WC_Product_Attribute();
        $attribute->set_id( $attr_id );
        $attribute->set_name( 'pa_rps-test-size' );
        $attribute->set_options( array( $term_s_id, $term_m_id ) );
        $attribute->set_visible( true );
        $attribute->set_variation( true );
        $product->set_attributes( array( $attribute ) );
        $parent_id = $product->save();

        if ( ! $parent_id ) {
            return array( 'name' => 'Crea prodotto variabile (privato)', 'status' => 'fail', 'message' => 'Impossibile creare il prodotto variabile' );
        }
        $cleanup['local_products'][] = $parent_id;

        $var1 = new \WC_Product_Variation();
        $var1->set_parent_id( $parent_id );
        $var1->set_attributes( array( 'pa_rps-test-size' => 'small' ) );
        $var1->set_regular_price( '24.99' );
        $var1->set_sale_price( '19.99' );
        $var1->set_sku( 'rps-test-var-s-' . wp_generate_password( 4, false ) );
        $var1->set_manage_stock( true );
        $var1->set_stock_quantity( 5 );
        $var1->set_weight( '0.8' );
        $var1->set_status( 'publish' );
        $var1_id = $var1->save();
        $cleanup['local_products'][] = $var1_id;

        $var2 = new \WC_Product_Variation();
        $var2->set_parent_id( $parent_id );
        $var2->set_attributes( array( 'pa_rps-test-size' => 'medium' ) );
        $var2->set_regular_price( '29.99' );
        $var2->set_sku( 'rps-test-var-m-' . wp_generate_password( 4, false ) );
        $var2->set_manage_stock( true );
        $var2->set_stock_quantity( 8 );
        $var2->set_weight( '1.2' );
        $var2->set_status( 'publish' );
        $var2_id = $var2->save();
        $cleanup['local_products'][] = $var2_id;

        return array(
            'name' => 'Crea prodotto variabile (privato)',
            'status' => 'pass',
            'message' => "'{$name}' (ID: {$parent_id}) — 2 variazioni: Small (ID:{$var1_id}, 24.99/19.99) e Medium (ID:{$var2_id}, 29.99)",
            'data' => array( 'product_id' => $parent_id, 'variation_ids' => array( $var1_id, $var2_id ) ),
        );
    }

    private function test_sync_product( $product_id, $store_url, $store, $api, &$cleanup ) {
        $product = wc_get_product( $product_id );
        $type_label = $product->get_type() === 'variable' ? 'variabile' : 'semplice';

        $acf_val = $store['acf_opt_value'];
        update_post_meta( $product_id, 'sites_to_synch', array( $acf_val ) );
        update_post_meta( $product_id, '_sites_to_synch', 'field_667bd2eb7950e' );

        RPS_Product_Sync::sync( $product_id, array( $store_url => $store ), 'full_product', true );

        $mpsrel = get_post_meta( $product_id, 'mpsrel', true );
        if ( is_array( $mpsrel ) && isset( $mpsrel[ $store_url ] ) && $mpsrel[ $store_url ] ) {
            $remote_id = $mpsrel[ $store_url ];
            $cleanup['remote_products'][] = $remote_id;
            return array(
                'name' => "Sync prodotto {$type_label}",
                'status' => 'pass',
                'message' => "Prodotto {$product_id} -> remoto ID: {$remote_id}",
            );
        }

        return array(
            'name' => "Sync prodotto {$type_label}",
            'status' => 'fail',
            'message' => "Sync fallito: mpsrel non contiene l'ID remoto",
        );
    }

    private function test_verify_remote_product( $product_id, $store_url, $api, $type_label ) {
        $mpsrel = get_post_meta( $product_id, 'mpsrel', true );
        if ( ! isset( $mpsrel[ $store_url ] ) ) {
            return array( 'name' => "Verifica remoto ({$type_label})", 'status' => 'skip', 'message' => 'Sync non riuscito' );
        }

        $remote = $api->getProduct( $mpsrel[ $store_url ] );
        if ( ! isset( $remote->id ) ) {
            return array( 'name' => "Verifica remoto ({$type_label})", 'status' => 'fail', 'message' => 'Prodotto remoto non trovato' );
        }

        $local = wc_get_product( $product_id );
        $checks = array();

        // Nome, SKU, prezzo, stato
        $checks[] = ( $remote->name === $local->get_name() ) ? 'nome OK' : "nome FAIL ({$remote->name})";
        $checks[] = ( $remote->sku === $local->get_sku() ) ? 'SKU OK' : "SKU FAIL ({$remote->sku})";
        $checks[] = ( (float) $remote->regular_price === (float) $local->get_regular_price() ) ? 'prezzo OK' : "prezzo FAIL ({$remote->regular_price})";
        $checks[] = ( $remote->status === $local->get_status() ) ? 'stato OK' : "stato FAIL ({$remote->status})";

        // Peso, dimensioni
        $checks[] = ( $remote->weight === $local->get_weight() ) ? 'peso OK' : "peso FAIL ({$remote->weight})";
        if ( isset( $remote->dimensions ) ) {
            $d = $remote->dimensions;
            $checks[] = ( $d->length === $local->get_length() && $d->width === $local->get_width() && $d->height === $local->get_height() ) ? 'dimensioni OK' : 'dimensioni FAIL';
        }

        // Stock (solo se stock sync è abilitato nelle impostazioni)
        $stock_sync = get_option( 'wc_api_mps_stock_sync' );
        if ( $stock_sync ) {
            $checks[] = ( (int) $remote->stock_quantity === $local->get_stock_quantity() ) ? 'stock OK' : "stock FAIL ({$remote->stock_quantity})";
            $checks[] = ( $remote->manage_stock == $local->get_manage_stock() ) ? 'manage_stock OK' : 'manage_stock FAIL';
        } else {
            $checks[] = 'stock SKIP (stock sync disabilitato)';
        }

        // Descrizione (verifica che contenga HTML)
        $has_html = ( strpos( $remote->description, '<strong>' ) !== false || strpos( $remote->description, '<p>' ) !== false );
        $checks[] = $has_html ? 'descrizione HTML OK' : 'descrizione HTML FAIL';

        // Featured
        if ( isset( $remote->featured ) ) {
            $checks[] = $remote->featured ? 'featured OK' : 'featured FAIL';
        }

        // Sale price + date
        if ( $local->get_sale_price() ) {
            $checks[] = ( (float) $remote->sale_price === (float) $local->get_sale_price() ) ? 'sale_price OK' : "sale_price FAIL ({$remote->sale_price})";
            if ( isset( $remote->date_on_sale_from ) && $remote->date_on_sale_from ) {
                $checks[] = 'date_on_sale_from OK';
            } else {
                $checks[] = 'date_on_sale_from FAIL';
            }
            if ( isset( $remote->date_on_sale_to ) && $remote->date_on_sale_to ) {
                $checks[] = 'date_on_sale_to OK';
            } else {
                $checks[] = 'date_on_sale_to FAIL';
            }
        }

        // Sold individually
        if ( isset( $remote->sold_individually ) ) {
            $checks[] = $remote->sold_individually ? 'sold_individually OK' : 'sold_individually FAIL';
        }

        // Backorders (dipende da stock sync attivo)
        if ( $stock_sync && isset( $remote->backorders ) ) {
            $checks[] = ( $remote->backorders === 'notify' ) ? 'backorders OK' : "backorders FAIL ({$remote->backorders})";
        }

        // Purchase note
        if ( isset( $remote->purchase_note ) && strpos( $remote->purchase_note, 'test' ) !== false ) {
            $checks[] = 'purchase_note OK';
        }

        // Categorie
        if ( isset( $remote->categories ) && count( $remote->categories ) > 0 ) {
            $checks[] = 'categorie OK (' . count( $remote->categories ) . ')';
        } else {
            $checks[] = 'categorie FAIL (0)';
        }

        // Tag
        if ( isset( $remote->tags ) && count( $remote->tags ) > 0 ) {
            $checks[] = 'tag OK (' . count( $remote->tags ) . ')';
        } else {
            $checks[] = 'tag WARN (0)';
        }

        // Brand
        if ( isset( $remote->brands ) && count( $remote->brands ) > 0 ) {
            $checks[] = 'brand OK (' . count( $remote->brands ) . ')';
        } elseif ( isset( $remote->brands ) ) {
            $checks[] = 'brand WARN (0)';
        }

        // Meta data
        $meta_ok = 0;
        $meta_details = array();
        if ( isset( $remote->meta_data ) ) {
            foreach ( $remote->meta_data as $meta ) {
                if ( $meta->key === 'test_custom_field' && $meta->value === 'valore personalizzato di test' ) { $meta_ok++; $meta_details[] = 'custom_field'; }
                if ( $meta->key === 'test_numeric_field' && $meta->value === '42' ) { $meta_ok++; $meta_details[] = 'numeric_field'; }
                if ( $meta->key === 'bookshop_link' ) { $meta_ok++; $meta_details[] = 'bookshop_link'; }
                if ( $meta->key === 'bookshop_product_id' ) { $meta_ok++; $meta_details[] = 'bookshop_product_id'; }
            }
        }
        $checks[] = ( $meta_ok >= 3 ) ? "meta OK ({$meta_ok}: " . implode( ', ', $meta_details ) . ')' : "meta FAIL ({$meta_ok}/4: " . implode( ', ', $meta_details ) . ')';

        $has_fail = false;
        foreach ( $checks as $c ) {
            if ( strpos( $c, 'FAIL' ) !== false ) $has_fail = true;
        }

        return array(
            'name' => "Verifica remoto ({$type_label})",
            'status' => $has_fail ? 'warn' : 'pass',
            'message' => implode( ' | ', $checks ),
        );
    }

    private function test_verify_remote_taxonomies( $cat_id, $tag_id, $brand_id, $store_url, $api, &$cleanup ) {
        $checks = array();

        // Categoria
        if ( $cat_id ) {
            $cat_mpsrel = get_term_meta( $cat_id, 'mpsrel', true );
            if ( is_array( $cat_mpsrel ) && isset( $cat_mpsrel[ $store_url ] ) ) {
                $remote_cat = $api->getCategory( $cat_mpsrel[ $store_url ] );
                if ( isset( $remote_cat->id ) ) {
                    $checks[] = "categoria OK (ID: {$remote_cat->id}, {$remote_cat->name})";
                    $cleanup['remote_categories'][] = $remote_cat->id;
                } else {
                    $checks[] = 'categoria FAIL (non trovata)';
                }
            } else {
                $checks[] = 'categoria FAIL (non mappata)';
            }
        }

        // Tag
        if ( $tag_id ) {
            $tag_mpsrel = get_term_meta( $tag_id, 'mpsrel', true );
            if ( is_array( $tag_mpsrel ) && isset( $tag_mpsrel[ $store_url ] ) ) {
                $remote_tag = $api->getTag( $tag_mpsrel[ $store_url ] );
                if ( isset( $remote_tag->id ) ) {
                    $checks[] = "tag OK (ID: {$remote_tag->id}, {$remote_tag->name})";
                    $cleanup['remote_tags'][] = $remote_tag->id;
                } else {
                    $checks[] = 'tag FAIL (non trovato)';
                }
            } else {
                $checks[] = 'tag FAIL (non mappato)';
            }
        }

        // Brand
        if ( $brand_id ) {
            $brand_mpsrel = get_term_meta( $brand_id, 'mpsrel', true );
            if ( is_array( $brand_mpsrel ) && isset( $brand_mpsrel[ $store_url ] ) ) {
                $remote_brand = $api->getBrand( $brand_mpsrel[ $store_url ] );
                if ( isset( $remote_brand->id ) ) {
                    $checks[] = "brand OK (ID: {$remote_brand->id}, {$remote_brand->name})";
                    $cleanup['remote_brands'][] = $remote_brand->id;
                } else {
                    $checks[] = 'brand FAIL (non trovato)';
                }
            } else {
                $checks[] = 'brand FAIL (non mappato)';
            }
        }

        $has_fail = false;
        foreach ( $checks as $c ) {
            if ( strpos( $c, 'FAIL' ) !== false ) $has_fail = true;
        }

        return array(
            'name' => 'Verifica tassonomie remote',
            'status' => $has_fail ? 'warn' : 'pass',
            'message' => implode( ' | ', $checks ),
        );
    }

    private function test_update_and_resync( $product_id, $store_url, $store, $api ) {
        $product = wc_get_product( $product_id );
        $new_price = '25.50';
        $new_stock = 7;
        $product->set_regular_price( $new_price );
        $product->set_stock_quantity( $new_stock );
        $product->set_short_description( 'Aggiornato dal test RPS - ' . current_time( 'mysql' ) );
        $product->save();
        update_post_meta( $product_id, 'test_custom_field', 'valore aggiornato' );

        RPS_Product_Sync::sync( $product_id, array( $store_url => $store ), 'full_product', true );

        $mpsrel = get_post_meta( $product_id, 'mpsrel', true );
        if ( ! isset( $mpsrel[ $store_url ] ) ) {
            return array( 'name' => 'Update e re-sync', 'status' => 'fail', 'message' => 'Re-sync fallito' );
        }

        $remote = $api->getProduct( $mpsrel[ $store_url ] );
        if ( ! isset( $remote->id ) ) {
            return array( 'name' => 'Update e re-sync', 'status' => 'fail', 'message' => 'Prodotto remoto non trovato' );
        }

        $checks = array();
        $checks[] = ( (float) $remote->regular_price === (float) $new_price ) ? "prezzo OK ({$new_price})" : "prezzo FAIL ({$remote->regular_price})";
        $stock_sync = get_option( 'wc_api_mps_stock_sync' );
        if ( $stock_sync ) {
            $checks[] = ( (int) $remote->stock_quantity === $new_stock ) ? "stock OK ({$new_stock})" : "stock FAIL ({$remote->stock_quantity})";
        } else {
            $checks[] = 'stock SKIP (stock sync disabilitato)';
        }

        // Verifica meta aggiornato
        $meta_ok = false;
        if ( isset( $remote->meta_data ) ) {
            foreach ( $remote->meta_data as $m ) {
                if ( $m->key === 'test_custom_field' && $m->value === 'valore aggiornato' ) $meta_ok = true;
            }
        }
        $checks[] = $meta_ok ? 'meta aggiornato OK' : 'meta aggiornato FAIL';

        $has_fail = false;
        foreach ( $checks as $c ) {
            if ( strpos( $c, 'FAIL' ) !== false ) $has_fail = true;
        }

        return array(
            'name' => 'Update e re-sync',
            'status' => $has_fail ? 'warn' : 'pass',
            'message' => implode( ' | ', $checks ),
        );
    }

    private function test_verify_remote_variations( $product_id, $store_url, $api ) {
        $mpsrel = get_post_meta( $product_id, 'mpsrel', true );
        if ( ! isset( $mpsrel[ $store_url ] ) ) {
            return array( 'name' => 'Verifica variazioni remote', 'status' => 'skip', 'message' => 'Sync non riuscito' );
        }

        $remote_vars = $api->getProductVariations( $mpsrel[ $store_url ] );
        if ( ! is_array( $remote_vars ) ) {
            return array( 'name' => 'Verifica variazioni remote', 'status' => 'fail', 'message' => 'Impossibile recuperare variazioni' );
        }

        $local_product = wc_get_product( $product_id );
        $local_count = count( $local_product->get_children() );

        $details = array();
        foreach ( $remote_vars as $rv ) {
            $attrs = array();
            if ( isset( $rv->attributes ) ) {
                foreach ( $rv->attributes as $a ) $attrs[] = $a->option;
            }
            $info = implode( '/', $attrs ) . " @ {$rv->regular_price}";
            if ( $rv->sale_price ) $info .= " (sale: {$rv->sale_price})";
            $info .= " stock:{$rv->stock_quantity} peso:{$rv->weight}";
            $details[] = $info;
        }

        $count_ok = count( $remote_vars ) === $local_count;
        return array(
            'name' => 'Verifica variazioni remote',
            'status' => $count_ok ? 'pass' : 'warn',
            'message' => count( $remote_vars ) . "/{$local_count} variazioni — " . implode( ' | ', $details ),
        );
    }

    private function test_delete_remote_products( $cleanup, $api ) {
        $deleted = 0;
        foreach ( $cleanup['remote_products'] as $remote_id ) {
            $result = $api->deleteProduct( $remote_id, 1 );
            if ( isset( $result->id ) ) $deleted++;
        }
        $total = count( $cleanup['remote_products'] );
        return array(
            'name' => 'Cancella prodotti remoti',
            'status' => $deleted === $total ? 'pass' : 'warn',
            'message' => "Eliminati {$deleted}/{$total} prodotti remoti (force delete)",
        );
    }

    private function test_delete_remote_taxonomies( $cleanup, $api ) {
        $checks = array();

        foreach ( $cleanup['remote_categories'] as $remote_cat_id ) {
            $result = $api->deleteCategory( $remote_cat_id );
            $checks[] = isset( $result->id ) ? "cat {$remote_cat_id} OK" : "cat {$remote_cat_id} FAIL";
        }

        foreach ( $cleanup['remote_tags'] as $remote_tag_id ) {
            $result = $api->deleteTag( $remote_tag_id );
            $checks[] = isset( $result->id ) ? "tag {$remote_tag_id} OK" : "tag {$remote_tag_id} FAIL";
        }

        foreach ( $cleanup['remote_brands'] as $remote_brand_id ) {
            $result = $api->deleteBrand( $remote_brand_id );
            $checks[] = isset( $result->id ) ? "brand {$remote_brand_id} OK" : "brand {$remote_brand_id} FAIL";
        }

        if ( empty( $checks ) ) {
            return array( 'name' => 'Pulizia tassonomie remote', 'status' => 'pass', 'message' => 'Nessuna tassonomia da pulire' );
        }

        $has_fail = false;
        foreach ( $checks as $c ) {
            if ( strpos( $c, 'FAIL' ) !== false ) $has_fail = true;
        }

        return array(
            'name' => 'Pulizia tassonomie remote',
            'status' => $has_fail ? 'warn' : 'pass',
            'message' => implode( ' | ', $checks ),
        );
    }

    private function test_verify_deletion( $cleanup, $api ) {
        $still_exists = 0;
        foreach ( $cleanup['remote_products'] as $remote_id ) {
            $check = $api->getProduct( $remote_id );
            if ( isset( $check->id ) && ( ! isset( $check->status ) || $check->status !== 'trash' ) ) {
                $still_exists++;
            }
        }

        if ( $still_exists === 0 ) {
            return array( 'name' => 'Verifica cancellazione remota', 'status' => 'pass', 'message' => 'Tutti i dati test rimossi dal sito remoto' );
        }
        return array( 'name' => 'Verifica cancellazione remota', 'status' => 'warn', 'message' => "{$still_exists} prodotti ancora presenti" );
    }

    // ── Cleanup ──

    private function cleanup_local( $cleanup ) {
        foreach ( $cleanup['local_products'] as $pid ) {
            wp_delete_post( $pid, true );
        }
        foreach ( $cleanup['local_categories'] as $tid ) {
            delete_term_meta( $tid, 'mpsrel' );
            wp_delete_term( $tid, 'product_cat' );
        }
        foreach ( $cleanup['local_tags'] as $tid ) {
            delete_term_meta( $tid, 'mpsrel' );
            wp_delete_term( $tid, 'product_tag' );
        }
        foreach ( $cleanup['local_brands'] as $tid ) {
            delete_term_meta( $tid, 'mpsrel' );
            wp_delete_term( $tid, 'product_brand' );
        }

        // Attributo test
        $attr_id = wc_attribute_taxonomy_id_by_name( 'pa_rps-test-size' );
        if ( $attr_id ) {
            $terms = get_terms( array( 'taxonomy' => 'pa_rps-test-size', 'hide_empty' => false, 'fields' => 'ids' ) );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $tid ) {
                    delete_term_meta( $tid, 'mpsrel' );
                    wp_delete_term( $tid, 'pa_rps-test-size' );
                }
            }
            wc_delete_attribute( $attr_id );
        }
    }

    public function ajax_cleanup_tests() {
        check_ajax_referer( 'rps_bulk_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $cleaned = 0;

        // Prodotti test
        $products = get_posts( array(
            'post_type'      => array( 'product', 'product_variation' ),
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            's'              => self::TEST_PREFIX,
        ) );
        foreach ( $products as $pid ) {
            wp_delete_post( $pid, true );
            $cleaned++;
        }

        // Categorie test
        $cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'search' => self::TEST_PREFIX ) );
        if ( is_array( $cats ) ) {
            foreach ( $cats as $cat ) {
                delete_term_meta( $cat->term_id, 'mpsrel' );
                wp_delete_term( $cat->term_id, 'product_cat' );
                $cleaned++;
            }
        }

        // Tag test
        $tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false, 'search' => self::TEST_PREFIX ) );
        if ( is_array( $tags ) ) {
            foreach ( $tags as $tag ) {
                delete_term_meta( $tag->term_id, 'mpsrel' );
                wp_delete_term( $tag->term_id, 'product_tag' );
                $cleaned++;
            }
        }

        // Brand test
        if ( taxonomy_exists( 'product_brand' ) ) {
            $brands = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false, 'search' => self::TEST_PREFIX ) );
            if ( is_array( $brands ) ) {
                foreach ( $brands as $brand ) {
                    delete_term_meta( $brand->term_id, 'mpsrel' );
                    wp_delete_term( $brand->term_id, 'product_brand' );
                    $cleaned++;
                }
            }
        }

        // Attributo test
        $attr_id = wc_attribute_taxonomy_id_by_name( 'pa_rps-test-size' );
        if ( $attr_id ) {
            $terms = get_terms( array( 'taxonomy' => 'pa_rps-test-size', 'hide_empty' => false, 'fields' => 'ids' ) );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $tid ) {
                    delete_term_meta( $tid, 'mpsrel' );
                    wp_delete_term( $tid, 'pa_rps-test-size' );
                }
            }
            wc_delete_attribute( $attr_id );
            $cleaned++;
        }

        wp_send_json_success( array( 'cleaned' => $cleaned ) );
    }
}
