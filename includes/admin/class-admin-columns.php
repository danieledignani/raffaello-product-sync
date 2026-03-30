<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Admin_Columns {

    public function __construct() {
        add_filter( 'ac/column/value', array( $this, 'render_mpsrel_links' ), 10, 3 );
        add_filter( 'ac/column/render', array( $this, 'render_v7_compat' ), 10, 4 );
        add_filter( 'ac/column/render/sanitize', array( $this, 'sanitize_v7_compat' ), 10, 4 );
    }

    public function render_mpsrel_links( $value, $id, $column ) {
        $meta_key = null;
        if ( is_object( $column ) && method_exists( $column, 'get_meta_key' ) ) {
            $meta_key = $column->get_meta_key();
        }
        if ( 'mpsrel' !== $meta_key ) return $value;

        $meta = get_post_meta( (int) $id, 'mpsrel', true );
        if ( ! is_array( $meta ) || empty( $meta ) ) return '';

        $stores = get_option( 'wc_api_mps_stores', array() );
        $links = array();
        foreach ( $meta as $url => $pid ) {
            if ( ! is_string( $url ) || ! $url || ! isset( $stores[ $url ] ) ) continue;
            $name = isset( $stores[ $url ]['store_abbreviation'] ) && $stores[ $url ]['store_abbreviation']
                ? $stores[ $url ]['store_abbreviation']
                : ( isset( $stores[ $url ]['store_name'] ) ? $stores[ $url ]['store_name'] : $url );
            $link = sprintf( '%s/wp-admin/post.php?post=%d&action=edit', esc_url( $url ), (int) $pid );
            $links[] = sprintf( '<a class="button wc-action-button" href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $link ), esc_html( $name ) );
        }
        return implode( ' ', $links );
    }

    public function render_v7_compat() {
        $args = func_get_args();
        $column = $id = null;
        $value = '';
        foreach ( $args as $arg ) {
            if ( null === $column && is_object( $arg ) && ( method_exists( $arg, 'get_meta_key' ) || method_exists( $arg, 'get_type' ) ) ) { $column = $arg; continue; }
            if ( null === $id && ( is_int( $arg ) || ( is_string( $arg ) && ctype_digit( $arg ) ) ) ) { $id = (int) $arg; continue; }
            if ( '' === $value && ( is_string( $arg ) || is_numeric( $arg ) ) ) { $value = (string) $arg; }
        }
        if ( null === $column || null === $id ) return isset( $args[0] ) ? $args[0] : '';
        return $this->render_mpsrel_links( $value, $id, $column );
    }

    public function sanitize_v7_compat() {
        $args = func_get_args();
        $sanitize = null; $column = null;
        foreach ( $args as $arg ) {
            if ( null === $sanitize && is_bool( $arg ) ) { $sanitize = $arg; continue; }
            if ( null === $column && is_object( $arg ) && ( method_exists( $arg, 'get_meta_key' ) || method_exists( $arg, 'get_type' ) ) ) { $column = $arg; }
        }
        if ( ! $column || ! method_exists( $column, 'get_meta_key' ) ) return null === $sanitize ? true : $sanitize;
        return 'mpsrel' === $column->get_meta_key() ? false : ( null === $sanitize ? true : $sanitize );
    }
}
