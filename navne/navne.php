<?php
/**
 * Plugin Name: Navne Entity Linker
 * Description: Automatically links named entities in posts to taxonomy archive pages.
 * Version:     1.4.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: navne
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NAVNE_VERSION', '1.4.0' );
define( 'NAVNE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NAVNE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NAVNE_PLUGIN_DIR . 'vendor/autoload.php';
require_once NAVNE_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( __FILE__, function () {
	\Navne\Storage\SuggestionsTable::create();
	\Navne\BulkIndex\RunsRepository::create_table();
	\Navne\BulkIndex\RunItemsRepository::create_table();
} );

// Uninstall is handled by uninstall.php — a closure here would blow up on
// activation because register_uninstall_hook() serializes the callback into
// wp_options, and closures can't be serialized.

add_action( 'plugins_loaded', [ 'Navne\\Plugin', 'init' ] );
