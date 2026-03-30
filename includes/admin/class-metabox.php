<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Metabox {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 20 );
    }

    public function add_meta_boxes() {
        $sync_type = get_option( 'wc_api_mps_sync_type' );
        if ( $sync_type == 'auto' ) {
            add_meta_box( 'wc_api_mps_disable_auto_sync', 'Raffaello Product Sync', array( $this, 'disable_auto_sync_callback' ), 'product', 'side' );
        } elseif ( $sync_type == 'manual' ) {
            add_meta_box( 'wc_api_mps_manual_sync', 'Raffaello Product Sync', array( $this, 'manual_sync_callback' ), 'product', 'normal' );
        }

        // Metabox "Link Siti"
        add_meta_box( 'link_ext_metabox', 'Link Siti', array( $this, 'link_ext_callback' ), 'product', 'side', 'high' );
    }

    public function disable_auto_sync_callback() {
        $post_id = isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0;
        if ( $post_id ) {
            $disabled = get_post_meta( $post_id, 'wc_api_mps_disable_auto_sync', true );
            ?>
            <input type="hidden" name="wc_api_mps_disable_auto_sync" value="0" />
            <label><input type="checkbox" name="wc_api_mps_disable_auto_sync" value="1"<?php echo $disabled ? ' checked' : ''; ?> /> Disable auto sync?</label>
            <?php
        }
    }

    public function manual_sync_callback() {
        $post_id = isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0;
        $stores = get_option( 'wc_api_mps_stores' );
        if ( ! $post_id || ! $stores ) {
            echo '<p>No stores found.</p>';
            return;
        }
        ?>
        <div id="wc_api_mps-message" style="margin-top:12px;"></div>
        <label>&nbsp;<input class="wc_api_mps-detail-check-uncheck" type="checkbox" /> All</label>
        <p class="description">&nbsp;Select/Deselect all stores.</p>
        <div id="wc_api_mps-stores">
            <?php foreach ( $stores as $url => $data ) {
                if ( $data['status'] ) echo '<p>&nbsp;<label><input type="checkbox" name="" value="'.esc_url($url).'" /> '.esc_url($url).'</label></p>';
            } ?>
        </div>
        <table><tr><td><button type="button" id="wc_api_mps_manual_sync_button" class="button-primary">Sync</button></td><td><span class="spinner wc_api_mps_spinner"></span></td></tr></table>
        <script>
            jQuery(function($) {
                $('.wc_api_mps-detail-check-uncheck').on('change', function() {
                    $('#wc_api_mps-stores input[type="checkbox"]').prop('checked', $(this).prop('checked'));
                });
                $('#wc_api_mps_manual_sync_button').on('click', function() {
                    var stores = [];
                    $('#wc_api_mps-stores input:checked').each(function() { stores.push($(this).val()); });
                    if (stores.length) {
                        $(this).prop('disabled', true);
                        $('.wc_api_mps_spinner').addClass('is-active');
                        $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            action: 'wc_api_mps_manual_sync', product_id: <?php echo $post_id; ?>, stores: stores
                        }, function() {
                            $('#wc_api_mps_manual_sync_button').prop('disabled', false);
                            $('.wc_api_mps_spinner').removeClass('is-active');
                            $('#wc_api_mps-message').html('<div class="notice notice-success is-dismissible"><p>Product successfully synced.</p></div>');
                        });
                    }
                });
            });
        </script>
        <?php
    }

    public function link_ext_callback( $post ) {
        $meta = get_post_meta( $post->ID, 'mpsrel', true );
        if ( ! is_array( $meta ) || empty( $meta ) ) return;

        $stores = get_option( 'wc_api_mps_stores', array() );
        foreach ( $meta as $url => $pid ) {
            if ( ! isset( $stores[ $url ] ) ) continue;
            $name = isset( $stores[ $url ]['store_name'] ) ? $stores[ $url ]['store_name'] : $url;
            $link = sprintf( '%s/wp-admin/post.php?post=%d&action=edit', esc_url( $url ), (int) $pid );
            echo sprintf( '<a class="button wc-action-button" href="%s" target="_blank" rel="noopener noreferrer">%s</a> ', esc_url( $link ), esc_html( $name ) );
        }
    }
}
