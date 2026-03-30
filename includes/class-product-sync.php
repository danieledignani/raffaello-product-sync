<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Product_Sync {

    /**
     * Entrypoint principale per la sincronizzazione di un prodotto.
     * Sostituisce wc_api_mps_integration().
     */
    public static function sync( $product_id, $stores = array(), $product_sync_type = '', $force_sync = false ) {
        $logger = RPS_Logger::instance();
        $logger->info( 'sync', "Inizio sync prodotto {$product_id}, tipo: {$product_sync_type}", array( 'product_id' => $product_id ) );

        if ( empty( $stores ) ) return;

        if ( ! $product_sync_type ) {
            $product_sync_type = get_option( 'wc_api_mps_product_sync_type' ) ?: 'full_product';
        }

        switch ( $product_sync_type ) {
            case 'full_product':
                self::sync_full( $product_id, $stores, $force_sync );
                break;
            case 'price_and_quantity':
                self::sync_price_and_quantity( $product_id, $stores );
                break;
            case 'quantity':
                self::sync_quantity( $product_id, $stores );
                break;
            default:
                $logger->warning( 'sync', "Tipo sync sconosciuto: {$product_sync_type}", array( 'product_id' => $product_id ) );
        }
    }

    /**
     * Sync completo del prodotto.
     */
    private static function sync_full( $product_id, $stores, $force_sync ) {
        $logger = RPS_Logger::instance();
        $stock_sync = get_option( 'wc_api_mps_stock_sync' );
        $product_info = wc_get_product( $product_id );
        if ( ! $product_info ) return;

        $product_info_data = $product_info->get_data();
        $slug = $product_info_data['slug'];
        $sku  = $product_info_data['sku'];

        // Raccogli dati prodotto
        $data = self::collect_product_data( $product_info, $product_info_data, $product_id );

        $wc_api_mps = get_post_meta( $product_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

        $temp_data = $data;

        foreach ( $stores as $store_url => $store_config ) {
            if ( ! $store_config['status'] ) continue;

            $logger->info( 'sync_full', "Preparing store: {$store_url}", array( 'product_id' => $product_id, 'store_url' => $store_url ) );

            $data = $temp_data;
            $api = new WC_API_MPS( $store_url, $store_config['consumer_key'], $store_config['consumer_secret'] );

            // Exclude meta
            self::apply_meta_exclusions( $data, $store_config );

            // Check sites_to_synch (ACF)
            $exclude_term_desc = isset( $store_config['exclude_term_description'] ) ? $store_config['exclude_term_description'] : 0;
            if ( ! self::should_sync_to_store( $data, $store_config, $product_id, $force_sync ) ) {
                $logger->info( 'sync_full', "SKIPPED store {$store_url}", array( 'product_id' => $product_id, 'store_url' => $store_url ) );
                continue;
            }

            // Map categories
            if ( ! empty( $data['categories'] ) ) {
                $exclude_cats = isset( $store_config['exclude_categories_products'] ) ? $store_config['exclude_categories_products'] : array();
                $cats = array();
                foreach ( $product_info_data['category_ids'] as $cat_id ) {
                    if ( ! in_array( $cat_id, $exclude_cats ) ) {
                        $cats[]['id'] = RPS_Taxonomy_Sync::destination_category_id( $api, $store_url, $cat_id, $exclude_term_desc );
                    }
                }
                $data['categories'] = $cats;
            }

            // Map brands
            $product_brands = wp_get_post_terms( $product_id, 'product_brand' );
            if ( ! empty( $product_brands ) && ! is_wp_error( $product_brands ) ) {
                $exclude_brands = isset( $store_config['exclude_brands_products'] ) ? $store_config['exclude_brands_products'] : array();
                $brands = array();
                foreach ( $product_brands as $pb ) {
                    if ( ! in_array( $pb->term_id, $exclude_brands ) ) {
                        $brands[]['id'] = RPS_Taxonomy_Sync::destination_brand_id( $api, $store_url, $pb->term_id, $exclude_term_desc );
                    }
                }
                $data['brands'] = $brands;
            }

            // Map tags
            if ( ! empty( $data['tags'] ) ) {
                $exclude_tags = isset( $store_config['exclude_tags_products'] ) ? $store_config['exclude_tags_products'] : array();
                $tags = array();
                foreach ( $product_info_data['tag_ids'] as $tag_id ) {
                    if ( ! in_array( $tag_id, $exclude_tags ) ) {
                        $tags[]['id'] = RPS_Taxonomy_Sync::destination_tag_id( $api, $store_url, $tag_id, $exclude_term_desc );
                    }
                }
                $data['tags'] = $tags;
            }

            // Price adjustment
            RPS_Price_Adjuster::apply( $data, $product_info_data, $store_config );

            // Map attributes
            if ( ! empty( $data['attributes'] ) ) {
                $attrs = array();
                foreach ( $product_info_data['attributes'] as $attr_slug => $attr_data ) {
                    $details = $attr_data->get_data();
                    if ( $details['id'] ) {
                        $dest = RPS_Taxonomy_Sync::destination_attribute_id( $api, $store_url, $attr_slug, $details );
                        if ( $dest ) $attrs[] = $dest;
                    } else {
                        $attrs[] = array(
                            'id'        => $details['id'],
                            'name'      => $details['name'],
                            'position'  => $details['position'],
                            'visible'   => $details['visible'],
                            'variation' => $details['variation'],
                            'options'   => $details['options'],
                        );
                    }
                }
                $data['attributes'] = $attrs;
            }

            // Map images
            if ( ! empty( $data['images'] ) ) {
                $data['images'] = RPS_Image_Sync::map_product_images( $api, $store_url, $product_info_data['images'] );
            }

            // Map upsell/cross-sell/grouped
            foreach ( array( 'upsell_ids', 'cross_sell_ids', 'grouped_products' ) as $rel_field ) {
                if ( ! empty( $data[ $rel_field ] ) ) {
                    $mapped = array();
                    foreach ( $product_info_data[ $rel_field ] as $rel_id ) {
                        $rel_mps = get_post_meta( $rel_id, 'mpsrel', true );
                        if ( is_array( $rel_mps ) && isset( $rel_mps[ $store_url ] ) ) {
                            $mapped[] = $rel_mps[ $store_url ];
                        }
                    }
                    $data[ $rel_field ] = $mapped;
                }
            }

            // Stock sync
            if ( ! $stock_sync ) {
                unset( $data['manage_stock'], $data['stock_quantity'], $data['stock_status'] );
            }

            // Find destination product
            $dest_product_id = self::find_destination_product( $api, $store_url, $wc_api_mps, $slug, $sku );

            // Create or update
            if ( $dest_product_id ) {
                $logger->info( 'sync_full', "Update prodotto remoto {$dest_product_id}", array( 'product_id' => $product_id, 'store_url' => $store_url ) );
                $product = $api->updateProduct( $data, $dest_product_id );
            } else {
                $logger->info( 'sync_full', "Creazione nuovo prodotto remoto", array( 'product_id' => $product_id, 'store_url' => $store_url ) );
                $product = $api->addProduct( $data );
                if ( isset( $product->id ) ) $dest_product_id = $product->id;
            }

            // Save image mapping
            if ( isset( $product->id ) ) {
                $dest_product_id = $product->id;
                RPS_Image_Sync::save_image_mapping( $store_url, isset( $product_info_data['images'] ) ? $product_info_data['images'] : null, $product );
            }

            // Sync variations
            if ( $dest_product_id ) {
                $wc_api_mps[ $store_url ] = $dest_product_id;
                if ( isset( $product_info_data['variations'] ) ) {
                    RPS_Variation_Sync::sync( $api, $store_url, $product_info_data['variations'], $dest_product_id, $store_config );
                }
            }
        }

        update_post_meta( $product_id, 'mpsrel', $wc_api_mps );
    }

    /**
     * Sync solo prezzo e quantità.
     */
    private static function sync_price_and_quantity( $product_id, $stores ) {
        $product_info = wc_get_product( $product_id );
        if ( ! $product_info ) return;

        $pid = $product_info->get_data();
        $data = array(
            'manage_stock'   => $pid['manage_stock'],
            'stock_quantity' => $pid['stock_quantity'],
        );
        if ( isset( $pid['stock_status'] ) ) $data['stock_status'] = $pid['stock_status'];
        if ( isset( $pid['regular_price'] ) ) $data['regular_price'] = $pid['regular_price'];
        if ( isset( $pid['sale_price'] ) ) {
            $data['sale_price'] = $pid['sale_price'];
            $data['date_on_sale_from'] = ( $pid['date_on_sale_from'] ) ? $product_info->get_date_on_sale_from()->date( 'Y-m-d H:i:s' ) : '';
            $data['date_on_sale_to'] = ( $pid['date_on_sale_to'] ) ? $product_info->get_date_on_sale_to()->date( 'Y-m-d H:i:s' ) : '';
        }

        $variations = self::get_available_variations( $product_info );
        $wc_api_mps = get_post_meta( $product_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

        $temp_data = $data;
        foreach ( $stores as $store_url => $sc ) {
            if ( ! $sc['status'] ) continue;
            $data = $temp_data;
            $api = new WC_API_MPS( $store_url, $sc['consumer_key'], $sc['consumer_secret'] );
            $dest_id = self::find_destination_product( $api, $store_url, $wc_api_mps, $pid['slug'], $pid['sku'] );

            RPS_Price_Adjuster::apply( $data, $pid, $sc );

            if ( $dest_id ) {
                $api->updateProduct( $data, $dest_id );
                $wc_api_mps[ $store_url ] = $dest_id;
                if ( ! empty( $variations ) ) {
                    RPS_Variation_Sync::sync_stock( $api, $store_url, $variations, $dest_id, $sc, true );
                }
            }
        }
        update_post_meta( $product_id, 'mpsrel', $wc_api_mps );
    }

    /**
     * Sync solo quantità.
     */
    private static function sync_quantity( $product_id, $stores ) {
        $product_info = wc_get_product( $product_id );
        if ( ! $product_info ) return;

        $pid = $product_info->get_data();
        $data = array(
            'manage_stock'   => $pid['manage_stock'],
            'stock_quantity' => $pid['stock_quantity'],
        );
        if ( isset( $pid['stock_status'] ) ) $data['stock_status'] = $pid['stock_status'];

        $variations = self::get_available_variations( $product_info );
        $wc_api_mps = get_post_meta( $product_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

        foreach ( $stores as $store_url => $sc ) {
            if ( ! $sc['status'] ) continue;
            $api = new WC_API_MPS( $store_url, $sc['consumer_key'], $sc['consumer_secret'] );
            $dest_id = self::find_destination_product( $api, $store_url, $wc_api_mps, $pid['slug'], $pid['sku'] );

            if ( $dest_id ) {
                $api->updateProduct( $data, $dest_id );
                $wc_api_mps[ $store_url ] = $dest_id;
                if ( ! empty( $variations ) ) {
                    RPS_Variation_Sync::sync_stock( $api, $store_url, $variations, $dest_id, $sc, false );
                }
            }
        }
        update_post_meta( $product_id, 'mpsrel', $wc_api_mps );
    }

    /**
     * Elimina un prodotto remoto.
     */
    public static function delete_remote_product( $post_id, $specific_stores = null ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;

        $product_delete = get_option( 'wc_api_mps_product_delete' );
        if ( ! $product_delete ) return;

        if ( $post->post_type == 'product' ) {
            $mpsrel = get_post_meta( $post_id, 'mpsrel', true );
            if ( ! is_array( $mpsrel ) || empty( $mpsrel ) ) return;

            $stores = $specific_stores ?: get_option( 'wc_api_mps_stores' );
            foreach ( $stores as $url => $sc ) {
                if ( ! $sc['status'] ) continue;
                $api = new WC_API_MPS( $url, $sc['consumer_key'], $sc['consumer_secret'] );
                if ( isset( $mpsrel[ $url ] ) ) {
                    $check = $api->getProduct( $mpsrel[ $url ] );
                    if ( isset( $check->id ) ) {
                        $api->deleteProduct( $mpsrel[ $url ], 1 );
                    }
                }
            }
        } elseif ( $post->post_type == 'product_variation' ) {
            $parent_id = $post->post_parent;
            $parent_mps = get_post_meta( $parent_id, 'mpsrel', true );
            $var_mps = get_post_meta( $post_id, 'mpsrel', true );
            if ( ! is_array( $parent_mps ) || ! is_array( $var_mps ) ) return;

            $stores = get_option( 'wc_api_mps_stores' );
            foreach ( $stores as $url => $sc ) {
                if ( ! $sc['status'] ) continue;
                if ( isset( $var_mps[ $url ] ) && isset( $parent_mps[ $url ] ) ) {
                    $api = new WC_API_MPS( $url, $sc['consumer_key'], $sc['consumer_secret'] );
                    $check = $api->getProductVariation( $parent_mps[ $url ], $var_mps[ $url ] );
                    if ( isset( $check->id ) ) {
                        $api->deleteProductVariation( $parent_mps[ $url ], $var_mps[ $url ], 1 );
                    }
                }
            }
        }
    }

    // ── Helper privati ──

    private static function collect_product_data( $product_info, $pid, $product_id ) {
        $data = array();
        $simple_fields = array( 'name', 'slug', 'status', 'featured', 'global_unique_id',
            'catalog_visibility', 'description', 'short_description', 'sku',
            'regular_price', 'sale_price', 'tax_status', 'tax_class', 'virtual',
            'manage_stock', 'stock_quantity', 'stock_status', 'sold_individually',
            'weight', 'purchase_note', 'menu_order', 'reviews_allowed', 'backorders' );

        foreach ( $simple_fields as $f ) {
            if ( isset( $pid[ $f ] ) ) $data[ $f ] = $pid[ $f ];
        }

        if ( isset( $pid['date_created'] ) ) {
            $data['date_created'] = date( 'Y-m-d H:i:s', strtotime( $pid['date_created'] ) );
        }
        $data['type'] = $product_info->get_type();
        $data['shipping_class'] = $product_info->get_shipping_class();

        // Sale dates
        if ( isset( $pid['sale_price'] ) && $pid['sale_price'] !== '' ) {
            $data['date_on_sale_from'] = ( $pid['date_on_sale_from'] ) ? $product_info->get_date_on_sale_from()->date( 'Y-m-d H:i:s' ) : '';
            $data['date_on_sale_to'] = ( $pid['date_on_sale_to'] ) ? $product_info->get_date_on_sale_to()->date( 'Y-m-d H:i:s' ) : '';
        }

        // External product
        if ( isset( $pid['product_url'] ) ) $data['external_url'] = $pid['product_url'];
        if ( isset( $pid['button_text'] ) ) $data['button_text'] = $pid['button_text'];

        // Dimensions
        $data['dimensions'] = array(
            'length' => isset( $pid['length'] ) ? $pid['length'] : '',
            'width'  => isset( $pid['width'] ) ? $pid['width'] : '',
            'height' => isset( $pid['height'] ) ? $pid['height'] : '',
        );

        // Categories, tags
        if ( isset( $pid['category_ids'] ) ) $data['categories'] = $pid['category_ids'];
        if ( isset( $pid['tag_ids'] ) ) $data['tags'] = $pid['tag_ids'];

        // Brands
        $product_brands = wp_get_post_terms( $product_id, 'product_brand' );
        if ( ! empty( $product_brands ) && ! is_wp_error( $product_brands ) ) {
            $data['brands'] = array();
            foreach ( $product_brands as $pb ) $data['brands'][] = $pb->term_id;
            $pid['brand_ids'] = $data['brands'];
        }

        // Attributes
        if ( isset( $pid['attributes'] ) ) $data['attributes'] = $pid['attributes'];

        // Default attributes
        if ( ! empty( $pid['default_attributes'] ) ) {
            $defaults = array();
            foreach ( $pid['default_attributes'] as $attr_slug => $term_slug ) {
                $term = get_term_by( 'slug', $term_slug, $attr_slug );
                $defaults[] = array( 'name' => $attr_slug, 'option' => $term ? $term->name : $term_slug );
            }
            $data['default_attributes'] = $defaults;
        }

        // Images
        if ( isset( $pid['image_id'] ) && $pid['image_id'] ) {
            $data['images'][] = array( 'id' => $pid['image_id'], 'src' => wp_get_attachment_url( $pid['image_id'] ), 'position' => 0 );
            $pid['images'] = $data['images'];
        }
        if ( ! empty( $pid['gallery_image_ids'] ) ) {
            $pos = 1;
            foreach ( $pid['gallery_image_ids'] as $gid ) {
                $data['images'][] = array( 'id' => $gid, 'src' => wp_get_attachment_url( $gid ), 'position' => $pos++ );
            }
            $pid['images'] = $data['images'];
        }

        // Variations
        if ( $data['type'] == 'variable' ) {
            $children = $product_info->get_children();
            if ( $children ) {
                $avail = array();
                foreach ( $children as $child_id ) {
                    $v = wc_get_product( $child_id );
                    if ( $v ) $avail[] = $v;
                }
                $pid['variations'] = $avail;
            }
        }

        // Related products
        if ( isset( $pid['upsell_ids'] ) ) $data['upsell_ids'] = $pid['upsell_ids'];
        if ( isset( $pid['cross_sell_ids'] ) ) $data['cross_sell_ids'] = $pid['cross_sell_ids'];
        if ( isset( $pid['children'] ) ) {
            $data['grouped_products'] = $pid['children'];
            $pid['grouped_products'] = $pid['children'];
        }

        // Meta fields
        $meta_fields = self::get_product_meta_fields( $product_id );
        if ( $meta_fields ) {
            $meta_data = array();
            foreach ( $meta_fields as $mf ) {
                $meta_data[] = array( 'key' => $mf, 'value' => get_post_meta( $product_id, $mf, true ) );
            }

            // CUSTOM RAFFAELLO: bookshop_link
            $bookshop_link = array( 'title' => '', 'url' => get_permalink( $product_id ), 'target' => '' );
            $found = false;
            foreach ( $meta_data as &$m ) {
                if ( $m['key'] === 'bookshop_link' ) {
                    $m['value'] = $bookshop_link;
                    $found = true;
                    break;
                }
            }
            unset( $m );
            if ( ! $found ) {
                $meta_data[] = array( 'key' => 'bookshop_link', 'value' => $bookshop_link );
            }
            $meta_data[] = array( 'key' => 'bookshop_product_id', 'value' => $product_id );

            $data['meta_data'] = $meta_data;
        }

        return $data;
    }

    private static function should_sync_to_store( $data, $store_config, $product_id, $force_sync ) {
        $acf_opt_value = $store_config['acf_opt_value'];
        $found = false;

        if ( isset( $data['meta_data'] ) ) {
            foreach ( $data['meta_data'] as $md ) {
                if ( $md['key'] == 'sites_to_synch' ) {
                    $found = true;
                    $sites = is_array( $md['value'] ) ? $md['value'] : array();
                    if ( ! in_array( $acf_opt_value, $sites ) ) {
                        if ( ! $force_sync ) return false;
                        $sites[] = $acf_opt_value;
                        update_post_meta( $product_id, 'sites_to_synch', $sites );
                    }
                    return true;
                }
            }
        }

        if ( ! $found ) {
            if ( ! $force_sync ) return false;
            $sites = array( $acf_opt_value );
            add_post_meta( $product_id, 'sites_to_synch', $sites );
            return true;
        }

        return true;
    }

    private static function find_destination_product( $api, $store_url, $wc_api_mps, $slug, $sku ) {
        $dest_id = 0;
        $sync_by = get_option( 'wc_api_mps_old_products_sync_by' );

        if ( isset( $wc_api_mps[ $store_url ] ) ) {
            $dest_id = $wc_api_mps[ $store_url ];
            $check = $api->getProduct( $dest_id );
            if ( ! isset( $check->id ) ) {
                $dest_id = self::search_product( $api, $sync_by, $slug, $sku );
            }
        } else {
            $dest_id = self::search_product( $api, $sync_by, $slug, $sku );
        }

        return $dest_id;
    }

    private static function search_product( $api, $sync_by, $slug, $sku ) {
        $search = ( $sync_by == 'sku' && $sku ) ? $sku : $slug;
        $products = $api->getProducts( $search );
        if ( is_array( $products ) && ! empty( $products ) && isset( $products[0]->id ) ) {
            if ( $sync_by == 'sku' && $sku ) {
                if ( $products[0]->sku == $sku ) return $products[0]->id;
            } else {
                if ( $products[0]->slug == $slug ) return $products[0]->id;
            }
        }
        return 0;
    }

    private static function apply_meta_exclusions( &$data, $store_config ) {
        $exclude = isset( $store_config['exclude_meta_data'] ) ? $store_config['exclude_meta_data'] : '';
        if ( ! $exclude ) return;
        $exclude = array_map( 'trim', explode( ',', $exclude ) );
        if ( isset( $data['meta_data'] ) ) {
            foreach ( $data['meta_data'] as $k => $v ) {
                if ( in_array( $v['key'], $exclude ) ) unset( $data['meta_data'][ $k ] );
            }
        }
        foreach ( $exclude as $key ) {
            unset( $data[ $key ] );
        }
    }

    private static function get_product_meta_fields( $product_id ) {
        global $wpdb;
        $excluded = RPS_Variation_Sync::get_excluded_meta_keys();
        $fields = array();
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type='product' AND p.ID=%d",
            $product_id
        ) );
        if ( $results ) {
            foreach ( $results as $r ) {
                if ( ! in_array( $r->meta_key, $excluded ) ) $fields[] = $r->meta_key;
            }
        }
        return $fields;
    }

    private static function get_available_variations( $product_info ) {
        if ( $product_info->get_type() != 'variable' ) return array();
        $children = $product_info->get_children();
        if ( ! $children ) return array();
        $variations = array();
        foreach ( $children as $child_id ) {
            $v = wc_get_product( $child_id );
            if ( $v ) $variations[] = $v;
        }
        return $variations;
    }
}

// Backward compatibility: vecchia funzione globale
if ( ! function_exists( 'wc_api_mps_integration' ) ) {
    function wc_api_mps_integration( $product_id = 0, $stores = array(), $product_sync_type = '', $force_sync = false ) {
        RPS_Product_Sync::sync( $product_id, $stores, $product_sync_type, $force_sync );
    }
}
