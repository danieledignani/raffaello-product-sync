<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

class RPS_Admin_Pages {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
    }

    public function add_menu() {
        add_menu_page( 'Raffaello Product Sync', 'Product Sync', 'manage_options', 'wc_api_mps', array( $this, 'stores_page' ), 'dashicons-update' );
        add_submenu_page( 'wc_api_mps', 'Product Sync - Stores', 'Stores', 'manage_options', 'wc_api_mps', array( $this, 'stores_page' ) );
        add_submenu_page( 'wc_api_mps', 'Product Sync - Bulk Sync', 'Bulk Sync', 'manage_options', 'wc_api_mps_bulk_sync', array( $this, 'bulk_sync_page' ) );
        add_submenu_page( 'wc_api_mps', 'Product Sync - Force Sync', 'Force Sync', 'manage_options', 'wc_api_mps_force_sync', array( $this, 'force_sync_page' ) );
        add_submenu_page( 'wc_api_mps', 'Product Sync - Sync Log', 'Sync Log', 'manage_options', 'wc_api_mps_sync_log', array( $this, 'sync_log_page' ) );
        add_submenu_page( 'wc_api_mps', 'Product Sync - Settings', 'Settings', 'manage_options', 'wc_api_mps_settings', array( $this, 'settings_page' ) );
    }

    // ──── STORES PAGE ────
    public function stores_page() {
        $page_url = menu_page_url( 'wc_api_mps', 0 );

        if ( isset( $_POST['submit'] ) ) {
            check_admin_referer( 'rps_stores_action' );
            $stores = get_option( 'wc_api_mps_stores' );
            if ( ! is_array( $stores ) ) $stores = array();
            $stores[ esc_url_raw( $_POST['url'] ) ] = array(
                'store_name' => sanitize_text_field( $_POST['store_name'] ),
                'store_abbreviation' => sanitize_text_field( $_POST['store_abbreviation'] ),
                'acf_opt_value' => sanitize_text_field( $_POST['acf_opt_value'] ),
                'consumer_key' => sanitize_text_field( $_POST['consumer_key'] ),
                'consumer_secret' => sanitize_text_field( $_POST['consumer_secret'] ),
                'status' => 1,
                'exclude_categories_products' => array(),
                'exclude_brands_products' => array(),
                'exclude_tags_products' => array(),
                'exclude_meta_data' => '',
                'exclude_term_description' => 0,
                'price_adjustment' => 0,
                'price_adjustment_type' => '',
                'price_adjustment_operation' => '',
                'price_adjustment_amount' => '',
                'price_adjustment_amount_round' => 0,
            );
            $api = new WC_API_MPS( $_POST['url'], $_POST['consumer_key'], $_POST['consumer_secret'] );
            $auth = $api->authentication();
            if ( isset( $auth->code ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>Authentication failure.</p></div>';
            } else {
                update_option( 'wc_api_mps_stores', $stores );
                echo '<div class="notice notice-success is-dismissible"><p>Store added successfully.</p></div>';
            }
        } elseif ( isset( $_POST['update'] ) ) {
            check_admin_referer( 'rps_stores_action' );
            if ( ! isset( $_POST['exclude_categories_products'] ) ) $_POST['exclude_categories_products'] = array();
            if ( ! isset( $_POST['exclude_brands_products'] ) ) $_POST['exclude_brands_products'] = array();
            if ( ! isset( $_POST['exclude_tags_products'] ) ) $_POST['exclude_tags_products'] = array();
            $stores = get_option( 'wc_api_mps_stores' );
            $stores[ esc_url_raw( $_POST['url'] ) ] = array(
                'store_name' => sanitize_text_field( $_POST['store_name'] ),
                'store_abbreviation' => sanitize_text_field( $_POST['store_abbreviation'] ),
                'acf_opt_value' => sanitize_text_field( $_POST['acf_opt_value'] ),
                'consumer_key' => sanitize_text_field( $_POST['consumer_key'] ),
                'consumer_secret' => sanitize_text_field( $_POST['consumer_secret'] ),
                'status' => (int) $_POST['status'],
                'exclude_categories_products' => array_map( 'intval', $_POST['exclude_categories_products'] ),
                'exclude_brands_products' => array_map( 'intval', $_POST['exclude_brands_products'] ),
                'exclude_tags_products' => array_map( 'intval', $_POST['exclude_tags_products'] ),
                'exclude_meta_data' => sanitize_text_field( $_POST['exclude_meta_data'] ),
                'exclude_term_description' => (int) $_POST['exclude_term_description'],
                'price_adjustment' => (int) $_POST['price_adjustment'],
                'price_adjustment_type' => sanitize_text_field( $_POST['price_adjustment_type'] ),
                'price_adjustment_operation' => sanitize_text_field( $_POST['price_adjustment_operation'] ),
                'price_adjustment_amount' => sanitize_text_field( $_POST['price_adjustment_amount'] ),
                'price_adjustment_amount_round' => (int) $_POST['price_adjustment_amount_round'],
            );
            $api = new WC_API_MPS( $_POST['url'], $_POST['consumer_key'], $_POST['consumer_secret'] );
            $auth = $api->authentication();
            if ( isset( $auth->code ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>Authentication failure.</p></div>';
            } else {
                update_option( 'wc_api_mps_stores', $stores );
                echo '<div class="notice notice-success is-dismissible"><p>Store updated successfully.</p></div>';
            }
        } elseif ( isset( $_REQUEST['delete'] ) && isset( $_REQUEST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_REQUEST['_wpnonce'], 'rps_delete_store' ) ) {
                $stores = get_option( 'wc_api_mps_stores' );
                unset( $stores[ rawurldecode( $_REQUEST['delete'] ) ] );
                update_option( 'wc_api_mps_stores', $stores );
                echo '<div class="notice notice-success is-dismissible"><p>Store removed successfully.</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1>Stores</h1><hr>
            <?php if ( isset( $_REQUEST['edit'] ) ) : ?>
                <?php
                $product_sync_type = get_option( 'wc_api_mps_product_sync_type' ) ?: 'full_product';
                $stores = get_option( 'wc_api_mps_stores' );
                $store = $stores[ rawurldecode( $_REQUEST['edit'] ) ];
                $store['exclude_term_description'] = isset( $store['exclude_term_description'] ) ? $store['exclude_term_description'] : 0;
                ?>
                <h2>Edit store: <?php echo esc_url( rawurldecode( $_REQUEST['edit'] ) ); ?></h2>
                <form method="post" action="<?php echo esc_url( $page_url ); ?>">
                    <?php wp_nonce_field( 'rps_stores_action' ); ?>
                    <table class="form-table"><tbody>
                        <tr><th>Status</th><td><input type="hidden" name="status" value="0" /><input type="checkbox" name="status" value="1"<?php echo $store['status'] ? ' checked' : ''; ?> /></td></tr>
                        <tr><th>Store Name <span class="description">(required)</span></th><td><input type="text" name="store_name" value="<?php echo esc_attr( $store['store_name'] ); ?>" class="regular-text" required /></td></tr>
                        <tr><th>Store Abbreviation</th><td><input type="text" name="store_abbreviation" value="<?php echo esc_attr( $store['store_abbreviation'] ); ?>" class="regular-text" /></td></tr>
                        <tr><th>ACF Option Value Mapped <span class="description">(required)</span></th><td><input type="text" name="acf_opt_value" value="<?php echo esc_attr( $store['acf_opt_value'] ); ?>" class="regular-text" required /></td></tr>
                        <tr><th>Consumer Key <span class="description">(required)</span></th><td><input type="text" name="consumer_key" value="<?php echo esc_attr( $store['consumer_key'] ); ?>" class="regular-text" required /></td></tr>
                        <tr><th>Consumer Secret <span class="description">(required)</span></th><td><input type="text" name="consumer_secret" value="<?php echo esc_attr( $store['consumer_secret'] ); ?>" class="regular-text" required /></td></tr>
                        <tr style="<?php echo $product_sync_type != 'full_product' ? 'display:none;' : ''; ?>"><th>Exclude categories</th><td><?php
                            $cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
                            $exc = isset( $store['exclude_categories_products'] ) ? $store['exclude_categories_products'] : array();
                            if ( $cats ) foreach ( $cats as $c ) {
                                $chk = in_array( $c->term_id, $exc ) ? ' checked' : '';
                                echo '<label><input type="checkbox" name="exclude_categories_products[]" value="'.esc_attr($c->term_id).'"'.$chk.' /> '.esc_html($c->name).'</label>&nbsp;&nbsp;';
                            }
                        ?></td></tr>
                        <tr style="<?php echo $product_sync_type != 'full_product' ? 'display:none;' : ''; ?>"><th>Exclude brands</th><td><?php
                            $brands = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) );
                            $exc = isset( $store['exclude_brands_products'] ) ? $store['exclude_brands_products'] : array();
                            if ( $brands && ! is_wp_error( $brands ) ) foreach ( $brands as $b ) {
                                $chk = in_array( $b->term_id, $exc ) ? ' checked' : '';
                                echo '<label><input type="checkbox" name="exclude_brands_products[]" value="'.esc_attr($b->term_id).'"'.$chk.' /> '.esc_html($b->name).'</label>&nbsp;&nbsp;';
                            }
                        ?></td></tr>
                        <tr style="<?php echo $product_sync_type != 'full_product' ? 'display:none;' : ''; ?>"><th>Exclude tags</th><td><?php
                            $tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
                            $exc = isset( $store['exclude_tags_products'] ) ? $store['exclude_tags_products'] : array();
                            if ( $tags && ! is_wp_error( $tags ) ) foreach ( $tags as $t ) {
                                $chk = in_array( $t->term_id, $exc ) ? ' checked' : '';
                                echo '<label><input type="checkbox" name="exclude_tags_products[]" value="'.esc_attr($t->term_id).'"'.$chk.' /> '.esc_html($t->name).'</label>&nbsp;&nbsp;';
                            }
                        ?></td></tr>
                        <tr style="<?php echo $product_sync_type != 'full_product' ? 'display:none;' : ''; ?>"><th>Exclude Meta Data</th><td><input type="text" name="exclude_meta_data" value="<?php echo esc_attr( $store['exclude_meta_data'] ); ?>" class="regular-text" /><p class="description">Comma separated meta keys</p></td></tr>
                        <tr style="<?php echo $product_sync_type != 'full_product' ? 'display:none;' : ''; ?>"><th>Exclude Term Description</th><td><input type="hidden" name="exclude_term_description" value="0" /><input type="checkbox" name="exclude_term_description" value="1"<?php echo $store['exclude_term_description'] ? ' checked' : ''; ?> /></td></tr>
                        <tr style="<?php echo $product_sync_type == 'quantity' ? 'display:none;' : ''; ?>"><th>Price Adjustment</th><td><input type="hidden" name="price_adjustment" value="0" /><input type="checkbox" name="price_adjustment" value="1"<?php echo $store['price_adjustment'] ? ' checked' : ''; ?> /></td></tr>
                        <tr style="<?php echo $product_sync_type == 'quantity' ? 'display:none;' : ''; ?>"><th>Price Adjustment Type</th><td><input type="hidden" name="price_adjustment_type" value="" /><fieldset><label><input type="radio" name="price_adjustment_type" value="percentage"<?php echo $store['price_adjustment_type'] == 'percentage' ? ' checked' : ''; ?> /> Percentage</label><br><label><input type="radio" name="price_adjustment_type" value="fixed"<?php echo $store['price_adjustment_type'] == 'fixed' ? ' checked' : ''; ?> /> Fixed</label></fieldset></td></tr>
                        <tr style="<?php echo $product_sync_type == 'quantity' ? 'display:none;' : ''; ?>"><th>Price Adjustment Amount</th><td><select name="price_adjustment_operation"><option value="plus"<?php echo $store['price_adjustment_operation'] == 'plus' ? ' selected' : ''; ?>>+</option><option value="minus"<?php echo $store['price_adjustment_operation'] == 'minus' ? ' selected' : ''; ?>>-</option></select> <input type="number" name="price_adjustment_amount" value="<?php echo esc_attr( $store['price_adjustment_amount'] ); ?>" step="any" /></td></tr>
                        <tr style="<?php echo $product_sync_type == 'quantity' ? 'display:none;' : ''; ?>"><th>Round?</th><td><input type="hidden" name="price_adjustment_amount_round" value="0" /><input type="checkbox" name="price_adjustment_amount_round" value="1"<?php echo ! empty( $store['price_adjustment_amount_round'] ) ? ' checked' : ''; ?> /></td></tr>
                    </tbody></table>
                    <p><input type="hidden" name="url" value="<?php echo esc_url( rawurldecode( $_REQUEST['edit'] ) ); ?>" /><input type="submit" class="button-primary" name="update" value="Update store" /></p>
                </form>
            <?php else : ?>
                <h2>Add store</h2>
                <form method="post" action="<?php echo esc_url( $page_url ); ?>">
                    <?php wp_nonce_field( 'rps_stores_action' ); ?>
                    <table class="form-table"><tbody>
                        <tr><th>Store URL <span class="description">(required)</span></th><td><input type="url" name="url" class="regular-text" required /></td></tr>
                        <tr><th>Store Name <span class="description">(required)</span></th><td><input type="text" name="store_name" class="regular-text" required /></td></tr>
                        <tr><th>Store Abbreviation</th><td><input type="text" name="store_abbreviation" class="regular-text" /></td></tr>
                        <tr><th>ACF Option Value Mapped <span class="description">(required)</span></th><td><input type="text" name="acf_opt_value" class="regular-text" required /></td></tr>
                        <tr><th>Consumer Key <span class="description">(required)</span></th><td><input type="text" name="consumer_key" class="regular-text" required /></td></tr>
                        <tr><th>Consumer Secret <span class="description">(required)</span></th><td><input type="text" name="consumer_secret" class="regular-text" required /></td></tr>
                    </tbody></table>
                    <p><input type="submit" class="button-primary" name="submit" value="Add store" /></p>
                </form>
                <br><h2>Stores</h2>
                <table class="widefat striped"><thead><tr><th>Store URL</th><th>Status</th><th>ACF Opt Value</th><th>Action</th></tr></thead><tbody>
                <?php
                $stores = get_option( 'wc_api_mps_stores' );
                if ( $stores ) {
                    foreach ( $stores as $url => $d ) {
                        $icon = $d['status'] ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no"></span>';
                        echo "<tr><td>".esc_html($url)."</td><td>{$icon}</td><td>".esc_html($d['acf_opt_value'])."</td>";
                        $delete_url = wp_nonce_url( $page_url . '&delete=' . rawurlencode($url), 'rps_delete_store' );
                        echo '<td><a href="'.esc_url($page_url).'&edit='.rawurlencode($url).'"><span class="dashicons dashicons-edit"></span></a> ';
                        echo '<a href="'.esc_url($delete_url).'" onclick="return confirm(\'Eliminare questo store?\')"><span class="dashicons dashicons-trash"></span></a></td></tr>';
                    }
                } else {
                    echo '<tr><td colspan="4">No stores found.</td></tr>';
                }
                ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ──── BULK SYNC PAGE ────
    public function bulk_sync_page() {
        $stores = get_option( 'wc_api_mps_stores' );

        // Handle background sync submission
        if ( isset( $_POST['rps_bulk_sync_bg'] ) && isset( $_POST['records'] ) ) {
            check_admin_referer( 'rps_bulk_sync_action' );
            $records = array_map( 'intval', $_POST['records'] );
            $selected_stores = isset( $_POST['stores'] ) ? $_POST['stores'] : array();
            if ( ! empty( $records ) && ! empty( $selected_stores ) ) {
                $batch_id = RPS_Background_Sync::instance()->create_batch( $records, $selected_stores );
                echo '<div class="notice notice-info"><p>Bulk sync avviato in background! Batch ID: <strong>' . esc_html( $batch_id ) . '</strong>. Puoi monitorare il progresso qui sotto.</p></div>';
            }
        }

        $page_url = admin_url( '/admin.php?page=wc_api_mps_bulk_sync' );
        $product_cat = isset( $_REQUEST['product_cat'] ) ? (int) $_REQUEST['product_cat'] : 0;
        $product_brand = isset( $_REQUEST['product_brand'] ) ? (int) $_REQUEST['product_brand'] : 0;
        $product_tag = isset( $_REQUEST['product_tag'] ) ? (int) $_REQUEST['product_tag'] : 0;
        $status = isset( $_REQUEST['wc_api_mps_status'] ) ? sanitize_text_field( $_REQUEST['wc_api_mps_status'] ) : '';
        $store_filter = isset( $_REQUEST['wc_api_mps_store'] ) ? $_REQUEST['wc_api_mps_store'] : '';
        $s = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
        $record_per_page = isset( $_REQUEST['wc_api_mps_record_per_page'] ) ? (int) $_REQUEST['wc_api_mps_record_per_page'] : 10;
        ?>
        <div class="wrap">
            <h1>Bulk Sync</h1><hr>

            <!-- Progress area per batch attivi -->
            <div id="rps-batch-progress" style="display:none;" class="rps-progress-container">
                <h3>Sync in corso...</h3>
                <div class="rps-progress-bar-outer"><div class="rps-progress-bar-inner" id="rps-progress-bar" style="width:0%"></div></div>
                <p id="rps-progress-text">0 / 0 completati</p>
                <p id="rps-progress-errors" style="color:#dc3232;"></p>
                <button type="button" class="button" id="rps-cancel-batch">Annulla</button>
            </div>

            <!-- Filtri -->
            <form method="post">
                <table class="form-table"><tbody>
                    <tr><th>Search products</th><td><input type="text" name="s" value="<?php echo esc_attr( $s ); ?>" /></td></tr>
                    <tr><th>Categories</th><td><?php wp_dropdown_categories( array( 'show_option_none' => 'Select a category', 'option_none_value' => 0, 'orderby' => 'name', 'show_count' => 1, 'hierarchical' => 1, 'name' => 'product_cat', 'selected' => $product_cat, 'taxonomy' => 'product_cat' ) ); ?></td></tr>
                    <tr><th>Brands</th><td><?php wp_dropdown_categories( array( 'show_option_none' => 'Select a brand', 'option_none_value' => 0, 'orderby' => 'name', 'show_count' => 1, 'hierarchical' => 1, 'name' => 'product_brand', 'selected' => $product_brand, 'taxonomy' => 'product_brand' ) ); ?></td></tr>
                    <tr><th>Tags</th><td><?php wp_dropdown_categories( array( 'show_option_none' => 'Select a tag', 'option_none_value' => 0, 'orderby' => 'name', 'show_count' => 1, 'hierarchical' => 1, 'name' => 'product_tag', 'selected' => $product_tag, 'taxonomy' => 'product_tag' ) ); ?></td></tr>
                    <tr><th>Product Per Page</th><td><select name="wc_api_mps_record_per_page"><?php foreach ( array(5,10,25,50) as $n ) { $sel = $record_per_page == $n ? ' selected' : ''; echo "<option value=\"{$n}\"{$sel}>{$n}</option>"; } ?></select></td></tr>
                    <tr><th>Status</th><td>
                        <?php if ( $stores ) : ?><select name="wc_api_mps_store"><option value="">All Stores</option><?php foreach ( $stores as $su => $sd ) { if ( $sd['status'] ) { $sel = $store_filter == $su ? ' selected' : ''; echo '<option value="'.esc_url($su).'"'.$sel.'>'.esc_url($su).'</option>'; } } ?></select><br><br><?php endif; ?>
                        <fieldset><?php foreach ( array( '' => 'All', 'synced' => 'Synced', 'not-synced' => 'Not Synced' ) as $v => $l ) { $chk = $status == $v ? ' checked' : ''; echo "<label><input type=\"radio\" name=\"wc_api_mps_status\" value=\"{$v}\"{$chk}> {$l}</label>&nbsp;&nbsp;"; } ?></fieldset>
                    </td></tr>
                </tbody></table>
                <p class="submit"><input name="filter" class="button button-secondary" value="Filter" type="submit">&nbsp;&nbsp;<a class="button button-secondary" href="<?php echo esc_url( $page_url ); ?>">Clear</a></p>
            </form>

            <!-- Prodotti + Sync -->
            <form method="post">
                <?php wp_nonce_field( 'rps_bulk_sync_action' ); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><td class="manage-column column-cb check-column"><input type="checkbox"></td><th>Product</th></tr></thead>
                    <tbody>
                    <?php
                    $paged = isset( $_REQUEST['paged'] ) ? (int) $_REQUEST['paged'] : 1;
                    $args = array( 'posts_per_page' => $record_per_page, 'paged' => $paged, 'post_type' => 'product' );
                    if ( $s ) $args['s'] = $s;
                    if ( $product_cat ) $args['tax_query'][] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $product_cat );
                    if ( $product_brand ) $args['tax_query'][] = array( 'taxonomy' => 'product_brand', 'field' => 'term_id', 'terms' => $product_brand );
                    if ( $product_tag ) $args['tax_query'][] = array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $product_tag );

                    if ( $status == 'synced' ) {
                        if ( $store_filter ) { $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => $store_filter, 'compare' => 'LIKE' ); }
                        else { $args['meta_query'][] = array( 'key' => 'mpsrel', 'compare' => 'EXISTS' ); $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => 'a:0:{}', 'compare' => '!=' ); }
                    } elseif ( $status == 'not-synced' ) {
                        $args['meta_query']['relation'] = 'OR';
                        if ( $store_filter ) { $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => $store_filter, 'compare' => 'NOT LIKE' ); }
                        $args['meta_query'][] = array( 'key' => 'mpsrel', 'compare' => 'NOT EXISTS' );
                        $args['meta_query'][] = array( 'key' => 'mpsrel', 'value' => 'a:0:{}', 'compare' => '=' );
                    }

                    $records = new WP_Query( $args );
                    if ( $records->have_posts() ) {
                        while ( $records->have_posts() ) { $records->the_post(); $rid = get_the_ID();
                            echo '<tr><th class="check-column"><input type="checkbox" name="records[]" value="'.$rid.'"></th>';
                            echo '<td><strong><a href="'.esc_url(get_edit_post_link($rid)).'">'.esc_html(get_the_title()).'</a></strong>';
                            $mps = get_post_meta( $rid, 'mpsrel', true );
                            if ( $mps ) echo '<p><strong>Synced:</strong> '.implode(', ', array_keys($mps)).'</p>';
                            echo '</td></tr>';
                        }
                    } else {
                        echo '<tr class="no-items"><td colspan="2">No products found.</td></tr>';
                    }
                    ?>
                    </tbody>
                </table>
                <?php
                if ( $records->max_num_pages ) {
                    $add_args = array( 'wc_api_mps_record_per_page' => $record_per_page );
                    if ( $s ) $add_args['s'] = $s;
                    if ( $product_cat ) $add_args['product_cat'] = $product_cat;
                    if ( $product_brand ) $add_args['product_brand'] = $product_brand;
                    if ( $product_tag ) $add_args['product_tag'] = $product_tag;
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo paginate_links( array( 'base' => str_replace( 999999999, '%#%', admin_url('/admin.php?page=wc_api_mps_bulk_sync&paged=%#%') ), 'format' => '?paged=%#%', 'current' => max(1,$paged), 'total' => $records->max_num_pages, 'add_args' => $add_args ) );
                    echo '</div></div>';
                }
                wp_reset_postdata();
                ?>
                <table class="form-table"><tbody><tr><th>Destination Sites</th><td>
                    <label><input class="rps-check-all" type="checkbox" /> All</label><br><br>
                    <fieldset class="rps-sites"><?php
                        if ( $stores ) foreach ( $stores as $su => $sd ) {
                            if ( $sd['status'] ) echo '<p><label><input type="checkbox" name="stores[]" value="'.esc_url($su).'" /> '.esc_url($su).'</label></p>';
                        }
                    ?></fieldset>
                </td></tr></tbody></table>
                <p class="submit">
                    <input type="hidden" name="wc_api_mps_record_per_page" value="<?php echo $record_per_page; ?>" />
                    <input name="rps_bulk_sync_bg" class="button button-primary" value="Sync in Background" type="submit">
                </p>
            </form>
        </div>
        <?php
    }

    // ──── FORCE SYNC PAGE ────
    public function force_sync_page() {
        $stores = get_option( 'wc_api_mps_stores', array() );
        ?>
        <div class="wrap">
            <h1>Force Sync</h1><hr>
            <p>Sincronizza tutti i prodotti flaggati per uno store specifico o per tutti gli store configurati.</p>

            <!-- Progress area -->
            <div id="rps-batch-progress" style="display:none;" class="rps-progress-container">
                <h3>Sync in corso...</h3>
                <div class="rps-progress-bar-outer"><div class="rps-progress-bar-inner" id="rps-progress-bar" style="width:0%"></div></div>
                <p id="rps-progress-text">0 / 0 completati</p>
                <p id="rps-progress-errors" style="color:#dc3232;"></p>
                <button type="button" class="button" id="rps-cancel-batch">Annulla</button>
            </div>

            <table class="form-table"><tbody>
                <tr>
                    <th>Store</th>
                    <td>
                        <select id="rps-force-sync-store">
                            <option value="__all__">Tutti gli store</option>
                            <?php foreach ( $stores as $url => $data ) {
                                if ( $data['status'] ) {
                                    $name = ! empty( $data['store_name'] ) ? $data['store_name'] : $url;
                                    echo '<option value="' . esc_attr( $url ) . '">' . esc_html( $name ) . ' (' . esc_url( $url ) . ')</option>';
                                }
                            } ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Prodotti trovati</th>
                    <td>
                        <span id="rps-force-sync-count">-</span>
                        <button type="button" class="button" id="rps-force-sync-count-btn">Conta prodotti</button>
                    </td>
                </tr>
            </tbody></table>
            <p class="submit">
                <button type="button" class="button button-primary" id="rps-force-sync-start" disabled>Avvia Sync in Background</button>
            </p>
        </div>
        <?php
    }

    // ──── SYNC LOG PAGE ────
    public function sync_log_page() {
        ?>
        <div class="wrap">
            <h1>Sync Log</h1><hr>
            <div id="rps-log-filters">
                <select id="rps-log-level"><option value="">Tutti i livelli</option><option value="debug">Debug</option><option value="info">Info</option><option value="warning">Warning</option><option value="error">Error</option></select>
                <input type="text" id="rps-log-search" placeholder="Cerca nel messaggio..." />
                <input type="number" id="rps-log-product-id" placeholder="Product ID" style="width:100px" />
                <input type="date" id="rps-log-date-from" /> - <input type="date" id="rps-log-date-to" />
                <button class="button" id="rps-log-filter-btn">Filtra</button>
                <button class="button" id="rps-log-clear-btn" style="color:#dc3232;">Svuota Log</button>
                <a class="button" id="rps-log-export-btn" href="#">Esporta CSV</a>
            </div>
            <table class="wp-list-table widefat fixed striped" id="rps-log-table">
                <thead><tr><th style="width:150px">Timestamp</th><th style="width:70px">Level</th><th style="width:120px">Context</th><th>Message</th><th style="width:80px">Product</th><th style="width:200px">Store</th><th style="width:50px">User</th><th style="width:30px"></th></tr></thead>
                <tbody id="rps-log-tbody"><tr><td colspan="8">Caricamento...</td></tr></tbody>
            </table>
            <div id="rps-log-pagination" class="tablenav"><div class="tablenav-pages"></div></div>
        </div>
        <?php
    }

    // ──── SETTINGS PAGE ────
    public function settings_page() {
        if ( isset( $_POST['submit'] ) ) {
            check_admin_referer( 'rps_settings_action' );
            $fields = array( 'wc_api_mps_sync_type', 'wc_api_mps_authorization', 'wc_api_mps_old_products_sync_by', 'wc_api_mps_product_sync_type' );
            foreach ( $fields as $f ) { if ( isset( $_POST[$f] ) ) update_option( $f, sanitize_text_field( $_POST[$f] ) ); }
            $ints = array( 'wc_api_mps_stock_sync', 'wc_api_mps_product_delete', 'wc_api_mps_uninstall' );
            foreach ( $ints as $f ) { if ( isset( $_POST[$f] ) ) update_option( $f, (int) $_POST[$f] ); }
            if ( isset( $_POST['wc_api_mps_email_notification'] ) ) update_option( 'wc_api_mps_email_notification', (int) $_POST['wc_api_mps_email_notification'] );
            if ( isset( $_POST['wc_api_mps_email_recipient'] ) ) update_option( 'wc_api_mps_email_recipient', sanitize_email( $_POST['wc_api_mps_email_recipient'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        $sync_type = get_option( 'wc_api_mps_sync_type' );
        $auth = get_option( 'wc_api_mps_authorization' ) ?: 'header';
        $sync_by = get_option( 'wc_api_mps_old_products_sync_by' ) ?: 'slug';
        $pst = get_option( 'wc_api_mps_product_sync_type' ) ?: 'full_product';
        $stock = get_option( 'wc_api_mps_stock_sync' );
        $del = get_option( 'wc_api_mps_product_delete' );
        $uninst = get_option( 'wc_api_mps_uninstall' );
        $email_notif = get_option( 'wc_api_mps_email_notification', 0 );
        $email_recipient = get_option( 'wc_api_mps_email_recipient', get_option( 'admin_email' ) );
        ?>
        <div class="wrap"><h1>Settings</h1><hr>
            <form method="post"><?php wp_nonce_field( 'rps_settings_action' ); ?><table class="form-table"><tbody>
                <tr><th>Sync Type</th><td><fieldset><label><input type="radio" name="wc_api_mps_sync_type" value="auto"<?php echo $sync_type=='auto'?' checked':''; ?> /> Auto Sync</label><br><label><input type="radio" name="wc_api_mps_sync_type" value="manual"<?php echo $sync_type=='manual'?' checked':''; ?> /> Manual Sync</label></fieldset></td></tr>
                <tr><th>Authorization</th><td><fieldset><label><input type="radio" name="wc_api_mps_authorization" value="header"<?php echo $auth=='header'?' checked':''; ?> /> Header</label><br><label><input type="radio" name="wc_api_mps_authorization" value="query"<?php echo $auth=='query'?' checked':''; ?> /> Query String</label></fieldset></td></tr>
                <tr><th>Old Products Sync By</th><td><fieldset><label><input type="radio" name="wc_api_mps_old_products_sync_by" value="slug"<?php echo $sync_by=='slug'?' checked':''; ?> /> Slug</label><br><label><input type="radio" name="wc_api_mps_old_products_sync_by" value="sku"<?php echo $sync_by=='sku'?' checked':''; ?> /> SKU</label></fieldset></td></tr>
                <tr><th>Product Sync Type</th><td><fieldset><label><input type="radio" name="wc_api_mps_product_sync_type" value="full_product"<?php echo $pst=='full_product'?' checked':''; ?> /> Full Product</label><br><label><input type="radio" name="wc_api_mps_product_sync_type" value="price_and_quantity"<?php echo $pst=='price_and_quantity'?' checked':''; ?> /> Price and Quantity</label><br><label><input type="radio" name="wc_api_mps_product_sync_type" value="quantity"<?php echo $pst=='quantity'?' checked':''; ?> /> Quantity</label></fieldset></td></tr>
                <tr><th>Stock Sync?</th><td><input type="hidden" name="wc_api_mps_stock_sync" value="0" /><input type="checkbox" name="wc_api_mps_stock_sync" value="1"<?php echo $stock?' checked':''; ?> /></td></tr>
                <tr><th>Sync on product delete?</th><td><input type="hidden" name="wc_api_mps_product_delete" value="0" /><input type="checkbox" name="wc_api_mps_product_delete" value="1"<?php echo $del?' checked':''; ?> /></td></tr>
                <tr><th>Delete data on uninstall?</th><td><input type="hidden" name="wc_api_mps_uninstall" value="0" /><input type="checkbox" name="wc_api_mps_uninstall" value="1"<?php echo $uninst?' checked':''; ?> /></td></tr>
                <tr><th>Email al completamento bulk sync?</th><td><input type="hidden" name="wc_api_mps_email_notification" value="0" /><input type="checkbox" name="wc_api_mps_email_notification" value="1"<?php echo $email_notif?' checked':''; ?> /></td></tr>
                <tr><th>Email destinatario</th><td><input type="email" name="wc_api_mps_email_recipient" value="<?php echo esc_attr( $email_recipient ); ?>" class="regular-text" /><p class="description">Lascia vuoto per usare l'email admin</p></td></tr>
            </tbody></table>
            <p class="submit"><input type="submit" name="submit" class="button button-primary" value="Save Changes"></p>
            </form>
        </div>
        <?php
    }
}
