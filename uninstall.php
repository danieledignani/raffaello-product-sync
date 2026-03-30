<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

$uninstall = get_option( 'wc_api_mps_uninstall' );
if ( $uninstall ) {
    global $wpdb;

    $wpdb->delete( $wpdb->prefix . 'postmeta', array( 'meta_key' => 'mpsrel' ) );
    $wpdb->delete( $wpdb->prefix . 'termmeta', array( 'meta_key' => 'mpsrel' ) );

    // Drop log table
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rps_sync_log" );

    // Delete options
    delete_option( 'wc_api_mps_stores' );
    delete_option( 'wc_api_mps_sync_type' );
    delete_option( 'wc_api_mps_authorization' );
    delete_option( 'wc_api_mps_old_products_sync_by' );
    delete_option( 'wc_api_mps_product_sync_type' );
    delete_option( 'wc_api_mps_stock_sync' );
    delete_option( 'wc_api_mps_product_delete' );
    delete_option( 'wc_api_mps_uninstall' );
    delete_option( 'wc_api_mps_email_notification' );
    delete_option( 'wc_api_mps_email_recipient' );
    delete_option( 'rps_db_version' );

    // Clean up batch options
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rps_batch_%'" );
}
