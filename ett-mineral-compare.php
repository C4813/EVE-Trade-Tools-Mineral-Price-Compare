<?php
/*
Plugin Name: EVE Trade Tools Mineral Compare
Description: Mineral price comparison across EVE trade hubs with SSO character authentication, auto-calculated fees, extended trade simulation, and trend indicators.
Version: 0.2.8
Author: C4813
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: ett-price-helper
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ETTMC_VERSION', '0.2.8' );
define( 'ETTMC_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ETTMC_URL',     plugin_dir_url( __FILE__ ) );

/**
 * Block activation if ETT Price Helper is not active.
 * We check for its defining classes rather than using is_plugin_active()
 * because pluggable.php is not yet loaded at activation hook time.
 */
register_activation_hook( __FILE__, function () {
	if ( ! class_exists( 'ETT_Admin' ) || ! class_exists( 'ETT_ExternalDB' ) || ! class_exists( 'ETT_Crypto' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<p><strong>EVE Trade Tools Mineral Compare</strong> requires '
			. '<strong>EVE Trade Tools Price Helper</strong> to be installed and active before it can be activated.</p>'
			. '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a></p>',
			'Activation failed',
			[ 'back_link' => false ]
		);
	}

	// Ensure external DB schema includes our new table.
	try {
		ETT_ExternalDB::ensure_schema();
		ETTMC_ExtDB::ensure_schema(); // creates both ettmc_mineral_orders and ettmc_mineral_trends
	} catch ( \Throwable $e ) {
		// Non-fatal: table will be created on first use.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ETTMC: Schema creation on activation failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
} );

/**
 * Runtime notice if Price Helper is deactivated after we are already active.
 */
add_action( 'admin_notices', function () {
	if ( class_exists( 'ETT_Admin' ) ) return;
	if ( ! current_user_can( 'activate_plugins' ) ) return;
	echo '<div class="notice notice-error">'
	   . '<p><strong>EVE Trade Tools Mineral Compare</strong> requires '
	   . '<strong>EVE Trade Tools Price Helper</strong> to be installed and activated. '
	   . '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Manage plugins &rarr;</a>'
	   . '</p></div>';
} );

require_once ETTMC_PATH . 'includes/class-ettmc-extdb.php';
require_once ETTMC_PATH . 'includes/class-ettmc-oauth.php';
require_once ETTMC_PATH . 'includes/class-ettmc-esi.php';
require_once ETTMC_PATH . 'includes/class-ettmc-hooks.php';
require_once ETTMC_PATH . 'includes/class-ettmc-render.php';
require_once ETTMC_PATH . 'includes/class-ettmc-admin.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'ETT_Admin' ) ) return; // Price Helper not active; bail.

	ETTMC_OAuth::init();
	ETTMC_ESI::init();
	ETTMC_Hooks::init();
	ETTMC_Render::init();
	ETTMC_Admin::init();
} );
