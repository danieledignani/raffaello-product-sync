<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Variation_Sync {

    /**
     * Sincronizza le variazioni di un prodotto variable verso lo store remoto.
     */
    public static function sync( $api, $url, $variations, $dest_product_id, $store_config ) {
        $logger = RPS_Logger::instance();
        $stock_sync = get_option( 'wc_api_mps_stock_sync' );

        // Recupera variazioni remote per matching
        $dest_skus = array();
        $dest_variations_map = array();
        $dest_variations = $api->getProductVariations( $dest_product_id );
        if ( $dest_variations ) {
            foreach ( $dest_variations as $dv ) {
                if ( $dv->sku ) {
                    $dest_skus[ $dv->sku ] = $dv->id;
                }
                if ( $dv->attributes ) {
                    $key = self::build_attributes_key( $dv->attributes );
                    if ( $key ) $dest_variations_map[ $key ] = $dv->id;
                }
            }
        }

        foreach ( $variations as $variation ) {
            $product_info = $variation;
            $vid_data = $product_info->get_data();
            $variation_id = $vid_data['id'];

            $wc_api_mps = get_post_meta( $variation_id, 'mpsrel', true );
            if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

            // Trova la variazione remota
            $dest_variation_id = 0;
            if ( isset( $wc_api_mps[ $url ] ) ) {
                $dest_variation_id = $wc_api_mps[ $url ];
                $check = $api->getProductVariation( $dest_product_id, $dest_variation_id );
                if ( ! isset( $check->id ) ) $dest_variation_id = 0;
            }

            if ( ! $dest_variation_id ) {
                // Match by SKU
                if ( $vid_data['sku'] && isset( $dest_skus[ $vid_data['sku'] ] ) ) {
                    $dest_variation_id = $dest_skus[ $vid_data['sku'] ];
                }
                // Match by attributes
                if ( ! $dest_variation_id && ! empty( $vid_data['attributes'] ) ) {
                    $key = strtolower( str_replace( ' ', '-', implode( '', $vid_data['attributes'] ) ) );
                    if ( isset( $dest_variations_map[ $key ] ) ) {
                        $dest_variation_id = $dest_variations_map[ $key ];
                    }
                }
            }

            // Prepara dati variazione
            $data = self::build_variation_data( $product_info, $vid_data );

            // Map variation image (cerca ID remoto prima di inviare src)
            if ( ! empty( $vid_data['image_id'] ) ) {
                $dest_img_id = RPS_Image_Sync::get_destination_image_id( $api, $url, $vid_data['image_id'] );
                if ( $dest_img_id ) {
                    $data['image'] = array( 'id' => $dest_img_id );
                } else {
                    $data['image'] = array( 'src' => wp_get_attachment_url( $vid_data['image_id'] ) );
                }
            }

            // Price adjustment
            RPS_Price_Adjuster::apply( $data, $vid_data, $store_config );

            // Meta fields
            $meta_fields = self::get_variation_meta_fields( $variation_id );
            if ( $meta_fields ) {
                $meta_data = array();
                foreach ( $meta_fields as $mf ) {
                    $meta_data[] = array( 'key' => $mf, 'value' => get_post_meta( $variation_id, $mf, true ) );
                }
                $data['meta_data'] = $meta_data;
            }

            // Exclude meta
            self::apply_meta_exclusions( $data, $store_config );

            // Stock sync
            if ( ! $stock_sync ) {
                unset( $data['manage_stock'], $data['stock_quantity'], $data['stock_status'] );
            }

            // Create or update
            if ( $dest_variation_id ) {
                $result = $api->updateProductVariation( $data, $dest_product_id, $dest_variation_id );
                $logger->info( 'variation_sync', "Update variazione #{$variation_id} -> remoto #{$dest_variation_id}", array( 'store_url' => $url, 'product_id' => $variation_id, 'request_data' => $data, 'response_data' => $result ) );
            } else {
                $result = $api->addProductVariation( $data, $dest_product_id );
                if ( isset( $result->id ) ) $dest_variation_id = $result->id;
                $logger->info( 'variation_sync', "Creazione variazione #{$variation_id} -> remoto #{$dest_variation_id}", array( 'store_url' => $url, 'product_id' => $variation_id, 'request_data' => $data, 'response_data' => $result ) );
            }

            // Save image mapping
            if ( isset( $result->id ) && $result->image && ! empty( $vid_data['image_id'] ) ) {
                RPS_Image_Sync::set_destination_image_id( $url, $vid_data['image_id'], $result->image->id );
            }

            $wc_api_mps[ $url ] = $dest_variation_id;
            update_post_meta( $variation_id, 'mpsrel', $wc_api_mps );
        }
    }

    /**
     * Sync variazioni per price_and_quantity o quantity mode.
     */
    public static function sync_stock( $api, $url, $variations, $dest_product_id, $store_config, $include_price = false ) {
        $dest_skus = array();
        $dest_variations_map = array();
        $dest_variations = $api->getProductVariations( $dest_product_id );
        if ( $dest_variations ) {
            foreach ( $dest_variations as $dv ) {
                if ( $dv->sku ) $dest_skus[ $dv->sku ] = $dv->id;
                if ( $dv->attributes ) {
                    $key = self::build_attributes_key( $dv->attributes );
                    if ( $key ) $dest_variations_map[ $key ] = $dv->id;
                }
            }
        }

        foreach ( $variations as $variation ) {
            $vid_data = $variation->get_data();
            $variation_id = $vid_data['id'];

            $data = array(
                'manage_stock'   => $vid_data['manage_stock'],
                'stock_quantity' => $vid_data['stock_quantity'],
            );
            if ( isset( $vid_data['stock_status'] ) ) $data['stock_status'] = $vid_data['stock_status'];

            if ( $include_price ) {
                if ( isset( $vid_data['regular_price'] ) ) $data['regular_price'] = $vid_data['regular_price'];
                if ( isset( $vid_data['sale_price'] ) ) {
                    $data['sale_price'] = $vid_data['sale_price'];
                    if ( isset( $vid_data['date_on_sale_from'] ) && $vid_data['date_on_sale_from'] ) {
                        $data['date_on_sale_from'] = $variation->get_date_on_sale_from()->date( 'Y-m-d H:i:s' );
                    } else { $data['date_on_sale_from'] = ''; }
                    if ( isset( $vid_data['date_on_sale_to'] ) && $vid_data['date_on_sale_to'] ) {
                        $data['date_on_sale_to'] = $variation->get_date_on_sale_to()->date( 'Y-m-d H:i:s' );
                    } else { $data['date_on_sale_to'] = ''; }
                }
                RPS_Price_Adjuster::apply( $data, $vid_data, $store_config );
            }

            // Find remote variation
            $wc_api_mps = get_post_meta( $variation_id, 'mpsrel', true );
            if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

            $dest_variation_id = 0;
            if ( $vid_data['sku'] && isset( $dest_skus[ $vid_data['sku'] ] ) ) {
                $dest_variation_id = $dest_skus[ $vid_data['sku'] ];
            }
            if ( ! $dest_variation_id && ! empty( $vid_data['attributes'] ) ) {
                $key = strtolower( str_replace( ' ', '-', implode( '', $vid_data['attributes'] ) ) );
                if ( isset( $dest_variations_map[ $key ] ) ) $dest_variation_id = $dest_variations_map[ $key ];
            }

            if ( $dest_variation_id ) {
                $api->updateProductVariation( $data, $dest_product_id, $dest_variation_id );
                $wc_api_mps[ $url ] = $dest_variation_id;
                update_post_meta( $variation_id, 'mpsrel', $wc_api_mps );
            }
        }
    }

    private static function build_attributes_key( $attributes ) {
        $options = '';
        foreach ( $attributes as $attr ) {
            $options .= strtolower( $attr->option );
        }
        return $options ? str_replace( ' ', '-', $options ) : '';
    }

    private static function build_variation_data( $product_info, $data ) {
        $fields = array( 'description', 'sku', 'global_unique_id', 'regular_price', 'sale_price',
            'tax_status', 'tax_class', 'status', 'virtual', 'manage_stock', 'stock_quantity',
            'stock_status', 'weight', 'menu_order', 'backorders' );

        $result = array();
        foreach ( $fields as $f ) {
            if ( isset( $data[ $f ] ) ) $result[ $f ] = $data[ $f ];
        }

        // Sale dates
        if ( isset( $data['sale_price'] ) && $data['sale_price'] !== '' ) {
            if ( isset( $data['date_on_sale_from'] ) && $data['date_on_sale_from'] ) {
                $result['date_on_sale_from'] = $product_info->get_date_on_sale_from()->date( 'Y-m-d H:i:s' );
            } else { $result['date_on_sale_from'] = ''; }
            if ( isset( $data['date_on_sale_to'] ) && $data['date_on_sale_to'] ) {
                $result['date_on_sale_to'] = $product_info->get_date_on_sale_to()->date( 'Y-m-d H:i:s' );
            } else { $result['date_on_sale_to'] = ''; }
        }

        $result['shipping_class'] = $product_info->get_shipping_class();
        $result['dimensions'] = array(
            'length' => isset( $data['length'] ) ? $data['length'] : '',
            'width'  => isset( $data['width'] ) ? $data['width'] : '',
            'height' => isset( $data['height'] ) ? $data['height'] : '',
        );

        // Attributes
        if ( ! empty( $data['attributes'] ) ) {
            $attrs = array();
            foreach ( $data['attributes'] as $slug => $term_slug ) {
                $term = get_term_by( 'slug', $term_slug, $slug );
                $attrs[] = array(
                    'name'   => $slug,
                    'option' => $term ? $term->name : $term_slug,
                );
            }
            $result['attributes'] = $attrs;
        }

        // Image: il mapping con destination image ID viene gestito in sync()

        return $result;
    }

    private static function get_variation_meta_fields( $variation_id ) {
        global $wpdb;
        $excluded = self::get_excluded_meta_keys();
        $fields = array();
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type='product_variation' AND p.ID=%d",
            $variation_id
        ) );
        if ( $results ) {
            foreach ( $results as $r ) {
                if ( ! in_array( $r->meta_key, $excluded ) ) {
                    $fields[] = $r->meta_key;
                }
            }
        }
        return $fields;
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

    public static function get_excluded_meta_keys() {
        return array(
            '_wc_review_count', '_wc_rating_count', '_wc_average_rating', '_sku',
            '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to',
            'total_sales', '_tax_status', '_tax_class', '_manage_stock', '_backorders',
            '_weight', '_sold_individually', '_length', '_width', '_height',
            '_upsell_ids', '_crosssell_ids', '_purchase_note', '_default_attributes',
            '_virtual', '_downloadable', '_product_image_gallery', '_download_limit',
            '_download_expiry', '_stock', '_stock_status', '_downloadable_files',
            '_product_attributes', '_wpcom_is_markdown', '_thumbnail_id', '_edit_lock',
            '_price', '_children', '_product_url', '_button_text',
            'wc_api_mps_disable_auto_sync', '_product_version', '_wp_old_slug', 'mpsrel',
        );
    }
}
