<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Image_Sync {

    public static function get_destination_image_id( $api, $url, $image_id ) {
        $destination_image_id = 0;
        $wc_api_mps = get_post_meta( $image_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) {
            $wc_api_mps = array();
        }

        if ( isset( $wc_api_mps[ $url ] ) ) {
            $destination_image_id = $wc_api_mps[ $url ];
        } else {
            $image_info = get_post( $image_id );
            if ( $image_info && $image_info->post_name ) {
                $media = $api->getMedias( $image_info->post_name );
                if ( $media && isset( $media[0]->id ) && $media[0]->slug == $image_info->post_name ) {
                    $destination_image_id = $media[0]->id;
                    self::set_destination_image_id( $url, $image_id, $destination_image_id );
                }
            }
        }

        return $destination_image_id;
    }

    public static function set_destination_image_id( $url, $image_id, $destination_image_id ) {
        $wc_api_mps = get_post_meta( $image_id, 'mpsrel', true );
        if ( ! is_array( $wc_api_mps ) ) {
            $wc_api_mps = array();
        }
        $wc_api_mps[ $url ] = $destination_image_id;
        update_post_meta( $image_id, 'mpsrel', $wc_api_mps );
    }

    /**
     * Mappa le immagini del prodotto per un dato store.
     */
    public static function map_product_images( $api, $url, $images ) {
        $mapped = array();
        foreach ( $images as $image_data ) {
            $image_id = $image_data['id'];
            unset( $image_data['id'] );
            $dest_id = self::get_destination_image_id( $api, $url, $image_id );
            if ( $dest_id ) {
                unset( $image_data['src'] );
                $image_data['id'] = $dest_id;
            }
            $mapped[] = $image_data;
        }
        return $mapped;
    }

    /**
     * Salva il mapping delle immagini dopo la creazione/aggiornamento del prodotto remoto.
     */
    public static function save_image_mapping( $url, $local_images, $remote_product ) {
        if ( ! $remote_product || ! isset( $remote_product->images ) || empty( $remote_product->images ) ) return;

        $dest_images = array();
        $pos = 0;
        foreach ( $remote_product->images as $dest_image ) {
            $dest_images[ $pos ] = $dest_image->id;
            $pos++;
        }

        if ( $local_images ) {
            foreach ( $local_images as $img ) {
                $dest_id = isset( $dest_images[ $img['position'] ] ) ? $dest_images[ $img['position'] ] : 0;
                if ( $dest_id ) {
                    self::set_destination_image_id( $url, $img['id'], $dest_id );
                }
            }
        }
    }
}
