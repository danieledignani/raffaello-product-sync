<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Taxonomy_Sync {

    /**
     * Genera la chiave per il transient cache.
     *
     * @param string $type   Tipo di tassonomia (category, tag, attribute, attribute_term, brand).
     * @param int    $local_id ID locale del termine.
     * @param string $url    URL dello store di destinazione.
     * @return string
     */
    private static function cache_key( $type, $local_id, $url ) {
        return 'rps_cache_' . $type . '_' . $local_id . '_' . md5( $url );
    }

    /**
     * Elimina tutti i transient di cache rps_cache_ (utile per debug).
     */
    public static function clear_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rps_cache_%' OR option_name LIKE '_transient_timeout_rps_cache_%'"
        );
    }

    public static function destination_category_id( $api, $url, $category_id, $exclude_term_description ) {
        // Controlla transient cache prima di qualsiasi chiamata API
        $cache_key = self::cache_key( 'category', $category_id, $url );
        $cached = get_transient( $cache_key );
        if ( false !== $cached && (int) $cached > 0 ) {
            return (int) $cached;
        }

        $category = get_term( $category_id );
        $wc_api_mps = get_term_meta( $category_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

        $dest_id = 0;
        if ( isset( $wc_api_mps[ $url ] ) && $wc_api_mps[ $url ] ) {
            $dest_id = $wc_api_mps[ $url ];
            $is_cat = $api->getCategory( $dest_id );
            if ( ! isset( $is_cat->id ) ) {
                $dest_id = 0;
                $cats = $api->getCategories( $category->slug );
                if ( is_array( $cats ) && ! empty( $cats ) && isset( $cats[0]->id ) ) {
                    $dest_id = $cats[0]->id;
                }
            }
        } else {
            $cats = $api->getCategories( $category->slug );
            if ( is_array( $cats ) && ! empty( $cats ) && isset( $cats[0]->id ) ) {
                $dest_id = $cats[0]->id;
            }
        }

        // CUSTOM RAFFAELLO: Crea categoria solo se non esiste
        if ( ! $dest_id ) {
            $data = array(
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
            );
            if ( $exclude_term_description ) unset( $data['description'] );

            if ( $category->parent ) {
                $data['parent'] = self::destination_category_id( $api, $url, $category->parent, $exclude_term_description );
            }

            $image_id = get_term_meta( $category_id, 'thumbnail_id', true );
            if ( $image_id ) {
                $dest_image_id = RPS_Image_Sync::get_destination_image_id( $api, $url, $image_id );
                if ( $dest_image_id ) {
                    $data['image']['id'] = $dest_image_id;
                } else {
                    $data['image']['src'] = wp_get_attachment_url( $image_id );
                }
            } else {
                $data['image'] = array();
            }

            $result = $api->addCategory( $data );
            if ( isset( $result->id ) ) {
                $dest_id = $result->id;
                if ( isset( $result->image ) && $result->image && $image_id ) {
                    RPS_Image_Sync::set_destination_image_id( $url, $image_id, $result->image->id );
                }
                RPS_Logger::instance()->info( 'category_sync', "Categoria '{$category->name}' creata -> remoto #{$dest_id}", array( 'store_url' => $url, 'request_data' => $data, 'response_data' => $result ) );
            }

            $wc_api_mps[ $url ] = $dest_id;
            update_term_meta( $category_id, 'mpsrel', $wc_api_mps );
        }

        // Salva in transient cache solo se l'ID e' valido
        if ( $dest_id ) {
            set_transient( $cache_key, $dest_id, HOUR_IN_SECONDS );
        }

        return $dest_id;
    }

    public static function destination_tag_id( $api, $url, $tag_id, $exclude_term_description ) {
        // Controlla transient cache prima di qualsiasi chiamata API
        $cache_key = self::cache_key( 'tag', $tag_id, $url );
        $cached = get_transient( $cache_key );
        if ( false !== $cached && (int) $cached > 0 ) {
            return (int) $cached;
        }

        $tag = get_term( $tag_id );
        $wc_api_mps = get_term_meta( $tag_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

        $dest_id = 0;
        if ( isset( $wc_api_mps[ $url ] ) && $wc_api_mps[ $url ] ) {
            $dest_id = $wc_api_mps[ $url ];
            $is_tag = $api->getTag( $dest_id );
            if ( ! isset( $is_tag->id ) ) {
                $dest_id = 0;
                $tags = $api->getTags( $tag->slug );
                if ( is_array( $tags ) && ! empty( $tags ) && isset( $tags[0]->id ) ) {
                    $dest_id = $tags[0]->id;
                }
            }
        } else {
            $tags = $api->getTags( $tag->slug );
            if ( is_array( $tags ) && ! empty( $tags ) && isset( $tags[0]->id ) ) {
                $dest_id = $tags[0]->id;
            }
        }

        $data = array(
            'name'        => $tag->name,
            'slug'        => $tag->slug,
            'description' => $tag->description,
        );
        if ( $exclude_term_description ) unset( $data['description'] );

        if ( $dest_id ) {
            $result = $api->updateTag( $data, $dest_id );
            RPS_Logger::instance()->info( 'tag_sync', "Tag '{$tag->name}' aggiornato -> remoto #{$dest_id}", array( 'store_url' => $url, 'request_data' => $data, 'response_data' => $result ) );
        } else {
            $result = $api->addTag( $data );
            if ( isset( $result->id ) ) {
                $dest_id = $result->id;
                RPS_Logger::instance()->info( 'tag_sync', "Tag '{$tag->name}' creato -> remoto #{$dest_id}", array( 'store_url' => $url, 'request_data' => $data, 'response_data' => $result ) );
            }
        }

        $wc_api_mps[ $url ] = $dest_id;
        update_term_meta( $tag_id, 'mpsrel', $wc_api_mps );

        // Salva in transient cache solo se l'ID e' valido
        if ( $dest_id ) {
            set_transient( $cache_key, $dest_id, HOUR_IN_SECONDS );
        }

        return $dest_id;
    }

    public static function destination_attribute_id( $api, $url, $attribute_slug, $attribute_data ) {
        // Controlla transient cache per l'ID dell'attributo remoto
        $cache_key = self::cache_key( 'attribute', crc32( $attribute_slug ), $url );
        $cached = get_transient( $cache_key );

        $attribute_id = 0;
        if ( false !== $cached && (int) $cached > 0 ) {
            $attribute_id = (int) $cached;
        }

        if ( ! $attribute_id ) {
        $attributes = $api->getAttributes();
        if ( $attributes ) {
            foreach ( $attributes as $attr ) {
                if ( $attr->slug == $attribute_slug ) {
                    $attribute_id = $attr->id;
                    break;
                }
            }
        }

        if ( ! $attribute_id ) {
            $taxonomy = get_taxonomy( $attribute_slug );
            if ( $taxonomy ) {
                $result = $api->addAttribute( array(
                    'name' => $taxonomy->labels->singular_name,
                    'slug' => $attribute_slug,
                ) );
                if ( isset( $result->id ) ) $attribute_id = $result->id;
            }
        }
        } // chiude if ( ! $attribute_id ) dal cache check

        if ( ! $attribute_id ) return array();

        // Salva in transient cache solo se l'ID e' valido
        set_transient( $cache_key, $attribute_id, HOUR_IN_SECONDS );

        $dest = array(
            'id'        => $attribute_id,
            'position'  => $attribute_data['position'],
            'visible'   => $attribute_data['visible'],
            'variation' => $attribute_data['variation'],
            'options'   => array(),
        );

        if ( ! empty( $attribute_data['options'] ) ) {
            foreach ( $attribute_data['options'] as $term_id ) {
                $dest['options'][] = self::destination_attribute_term_id( $api, $url, $term_id, $attribute_id );
            }
        }

        return $dest;
    }

    public static function destination_attribute_term_id( $api, $url, $term_id, $attribute_id ) {
        // Controlla transient cache prima di qualsiasi chiamata API
        // NB: questo metodo ritorna il nome del termine, non l'ID,
        // ma il cache serve per evitare le chiamate API di verifica/creazione
        $cache_key = self::cache_key( 'attribute_term', $term_id, $url );
        $cached = get_transient( $cache_key );
        if ( false !== $cached && (int) $cached > 0 ) {
            $term = get_term( $term_id );
            return $term->name;
        }

        $term = get_term( $term_id );
        $wc_api_mps = get_term_meta( $term_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

        $dest_id = 0;
        if ( isset( $wc_api_mps[ $url ] ) ) {
            $dest_id = $wc_api_mps[ $url ];
            $check = $api->getAttributeTerm( $dest_id, $attribute_id );
            if ( ! isset( $check->id ) ) $dest_id = 0;
        } else {
            $terms = $api->getAttributeTerms( $term->slug, $attribute_id );
            if ( $terms && isset( $terms[0]->id ) ) {
                $dest_id = $terms[0]->id;
            }
        }

        $data = array(
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
        );

        if ( $dest_id ) {
            $result = $api->updateAttributeTerm( $data, $dest_id, $attribute_id );
            RPS_Logger::instance()->info( 'attr_term_sync', "Attr term '{$term->name}' aggiornato -> remoto #{$dest_id}", array( 'store_url' => $url, 'request_data' => $data, 'response_data' => $result ) );
        } else {
            $result = $api->addAttributeTerm( $data, $attribute_id );
            if ( isset( $result->id ) ) {
                $dest_id = $result->id;
                RPS_Logger::instance()->info( 'attr_term_sync', "Attr term '{$term->name}' creato -> remoto #{$dest_id}", array( 'store_url' => $url, 'request_data' => $data, 'response_data' => $result ) );
            }
        }

        $wc_api_mps[ $url ] = $dest_id;
        update_term_meta( $term_id, 'mpsrel', $wc_api_mps );

        // Salva in transient cache solo se l'ID e' valido
        if ( $dest_id ) {
            set_transient( $cache_key, $dest_id, HOUR_IN_SECONDS );
        }

        return $term->name;
    }

    public static function destination_brand_id( $api, $url, $brand_id, $exclude_term_description ) {
        // Controlla transient cache prima di qualsiasi chiamata API
        $cache_key = self::cache_key( 'brand', $brand_id, $url );
        $cached = get_transient( $cache_key );
        if ( false !== $cached && (int) $cached > 0 ) {
            return (int) $cached;
        }

        $brand = get_term( $brand_id );
        $wc_api_mps = get_term_meta( $brand_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) $wc_api_mps = array();

        $dest_id = 0;
        if ( isset( $wc_api_mps[ $url ] ) && $wc_api_mps[ $url ] ) {
            $dest_id = $wc_api_mps[ $url ];
            $check = $api->getBrand( $dest_id );
            if ( ! isset( $check->id ) ) {
                $dest_id = 0;
                $brands = $api->getBrands( $brand->slug );
                if ( is_array( $brands ) && ! empty( $brands ) && isset( $brands[0]->id ) ) {
                    $dest_id = $brands[0]->id;
                }
            }
        } else {
            $brands = $api->getBrands( $brand->slug );
            if ( is_array( $brands ) && ! empty( $brands ) && isset( $brands[0]->id ) ) {
                $dest_id = $brands[0]->id;
            }
        }

        $data = array(
            'name'        => $brand->name,
            'slug'        => $brand->slug,
            'description' => $brand->description,
        );
        if ( $exclude_term_description ) unset( $data['description'] );

        if ( $brand->parent ) {
            $data['parent'] = self::destination_brand_id( $api, $url, $brand->parent, $exclude_term_description );
        } elseif ( $dest_id ) {
            $data['parent'] = 0;
        }

        $image_id = get_term_meta( $brand_id, 'thumbnail_id', true );
        if ( $image_id ) {
            $dest_image_id = RPS_Image_Sync::get_destination_image_id( $api, $url, $image_id );
            if ( $dest_image_id ) {
                $data['image']['id'] = $dest_image_id;
            } else {
                $data['image']['src'] = wp_get_attachment_url( $image_id );
            }
        } else {
            $data['image'] = array();
        }

        if ( $dest_id ) {
            $result = $api->updateBrand( $data, $dest_id );
            RPS_Logger::instance()->info( 'brand_sync', "Brand '{$brand->name}' aggiornato -> remoto #{$dest_id}", array( 'store_url' => $url, 'request_data' => $data, 'response_data' => $result ) );
        } else {
            $result = $api->addBrand( $data );
            if ( isset( $result->id ) ) {
                $dest_id = $result->id;
                RPS_Logger::instance()->info( 'brand_sync', "Brand '{$brand->name}' creato -> remoto #{$dest_id}", array( 'store_url' => $url, 'request_data' => $data, 'response_data' => $result ) );
            }
        }

        if ( isset( $result->id ) && isset( $result->image ) && $result->image && $image_id ) {
            RPS_Image_Sync::set_destination_image_id( $url, $image_id, $result->image->id );
        }

        $wc_api_mps[ $url ] = $dest_id;
        update_term_meta( $brand_id, 'mpsrel', $wc_api_mps );

        // Salva in transient cache solo se l'ID e' valido
        if ( $dest_id ) {
            set_transient( $cache_key, $dest_id, HOUR_IN_SECONDS );
        }

        return $dest_id;
    }
}
