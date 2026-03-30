<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Taxonomy_Sync {

    public static function destination_category_id( $api, $url, $category_id, $exclude_term_description ) {
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
                if ( $result->image && $image_id ) {
                    RPS_Image_Sync::set_destination_image_id( $url, $image_id, $result->image->id );
                }
            }

            $wc_api_mps[ $url ] = $dest_id;
            update_term_meta( $category_id, 'mpsrel', $wc_api_mps );
        }

        return $dest_id;
    }

    public static function destination_tag_id( $api, $url, $tag_id, $exclude_term_description ) {
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
            $api->updateTag( $data, $dest_id );
        } else {
            $result = $api->addTag( $data );
            if ( isset( $result->id ) ) $dest_id = $result->id;
        }

        $wc_api_mps[ $url ] = $dest_id;
        update_term_meta( $tag_id, 'mpsrel', $wc_api_mps );
        return $dest_id;
    }

    public static function destination_attribute_id( $api, $url, $attribute_slug, $attribute_data ) {
        $attribute_id = 0;
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

        if ( ! $attribute_id ) return array();

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
            $api->updateAttributeTerm( $data, $dest_id, $attribute_id );
        } else {
            $result = $api->addAttributeTerm( $data, $attribute_id );
            if ( isset( $result->id ) ) $dest_id = $result->id;
        }

        $wc_api_mps[ $url ] = $dest_id;
        update_term_meta( $term_id, 'mpsrel', $wc_api_mps );
        return $term->name;
    }

    public static function destination_brand_id( $api, $url, $brand_id, $exclude_term_description ) {
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
        } else {
            $result = $api->addBrand( $data );
            if ( isset( $result->id ) ) $dest_id = $result->id;
        }

        if ( isset( $result->id ) && $result->image && $image_id ) {
            RPS_Image_Sync::set_destination_image_id( $url, $image_id, $result->image->id );
        }

        $wc_api_mps[ $url ] = $dest_id;
        update_term_meta( $brand_id, 'mpsrel', $wc_api_mps );
        return $dest_id;
    }
}
