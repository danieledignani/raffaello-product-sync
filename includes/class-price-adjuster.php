<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Price_Adjuster {

    /**
     * Applica l'aggiustamento prezzo ai dati del prodotto.
     * Elimina la duplicazione del codice prezzo presente 4 volte nell'originale.
     */
    public static function apply( &$data, $product_info_data, $store_config ) {
        if ( empty( $store_config['price_adjustment'] ) ) return;

        $type      = $store_config['price_adjustment_type'];
        $operation = $store_config['price_adjustment_operation'];
        $amount    = $store_config['price_adjustment_amount'];
        $round     = ! empty( $store_config['price_adjustment_amount_round'] );

        if ( ! $amount ) return;

        $fields = array( 'regular_price', 'sale_price' );
        foreach ( $fields as $field ) {
            if ( ! isset( $product_info_data[ $field ] ) || $product_info_data[ $field ] === '' ) continue;

            $original = (float) $product_info_data[ $field ];
            if ( ! $original ) continue;

            if ( $type == 'percentage' ) {
                $adjustment = ( $original * $amount ) / 100;
            } else {
                $adjustment = (float) $amount;
            }

            $data[ $field ] = ( $operation == 'plus' )
                ? $original + $adjustment
                : $original - $adjustment;

            if ( $round ) {
                $data[ $field ] = round( $data[ $field ] );
            }

            $data[ $field ] = '' . $data[ $field ] . '';
        }
    }
}
