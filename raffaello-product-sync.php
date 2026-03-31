<?php
/**
 * Plugin Name: Raffaello Product Sync
 * Plugin URI: https://github.com/danieledignani/raffaello-product-sync
 * Description: Sincronizzazione prodotti WooCommerce tra più store tramite REST API. Fork personalizzato con logging avanzato, sync asincrono e aggiornamento automatico da GitHub.
 * Version: 4.0.0
 * Author: Daniele Dignani
 * Author URI: https://github.com/danieledignani
 * Text Domain: raffaello-product-sync
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * WC requires at least: 5.0
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

define( 'RPS_VERSION', '4.0.0' );
define( 'RPS_PLUGIN_FILE', __FILE__ );
define( 'RPS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'RPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RPS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Backward compat: vecchio codice usa WC_API_MPS_PLUGIN_PATH
define( 'WC_API_MPS_PLUGIN_PATH', RPS_PLUGIN_PATH );

// Aggiornamento automatico da GitHub
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$rpsUpdateChecker = PucFactory::buildUpdateChecker(
    'https://raw.githubusercontent.com/danieledignani/raffaello-product-sync/master/.github/update-metadata/raffaello-product-sync.json',
    __FILE__,
    'raffaello-product-sync'
);

// Carica le classi
require_once RPS_PLUGIN_PATH . 'includes/class-logger.php';
require_once RPS_PLUGIN_PATH . 'includes/class-api-client.php';
require_once RPS_PLUGIN_PATH . 'includes/class-price-adjuster.php';
require_once RPS_PLUGIN_PATH . 'includes/class-image-sync.php';
require_once RPS_PLUGIN_PATH . 'includes/class-taxonomy-sync.php';
require_once RPS_PLUGIN_PATH . 'includes/class-variation-sync.php';
require_once RPS_PLUGIN_PATH . 'includes/class-product-sync.php';
require_once RPS_PLUGIN_PATH . 'includes/class-background-sync.php';
require_once RPS_PLUGIN_PATH . 'includes/class-dashboard-widget.php';
require_once RPS_PLUGIN_PATH . 'includes/class-test-runner.php';
require_once RPS_PLUGIN_PATH . 'includes/class-plugin.php';
require_once RPS_PLUGIN_PATH . 'includes/admin/class-admin-pages.php';
require_once RPS_PLUGIN_PATH . 'includes/admin/class-metabox.php';
require_once RPS_PLUGIN_PATH . 'includes/admin/class-bulk-actions.php';
require_once RPS_PLUGIN_PATH . 'includes/admin/class-admin-columns.php';

// Inizializza il plugin
RPS_Plugin::instance();

// Attivazione
register_activation_hook( __FILE__, array( 'RPS_Plugin', 'activate' ) );
