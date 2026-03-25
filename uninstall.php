<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Variables here are local to the uninstall script, not true plugin globals.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// Delete plugin transients.
$prefixes = [ 'ettmc_char_data_v2_', 'ettmc_sso_state_', 'ettmc_cleanup_ran' ];
foreach ( $prefixes as $prefix ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $prefix . '%',
			'_transient_timeout_' . $prefix . '%'
		)
	);
}

// Remove character meta from all users.
$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->usermeta,
	[ 'meta_key' => 'ettmc_characters' ] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
);

// Drop the ettmc tables from the external DB.
if ( class_exists( 'ETT_ExternalDB' ) && ETT_ExternalDB::is_configured() ) {
	try {
		$pdo = ETT_ExternalDB::pdo(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$pdo->exec( 'DROP TABLE IF EXISTS ettmc_mineral_orders' );
		$pdo->exec( 'DROP TABLE IF EXISTS ettmc_mineral_trends' );
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ETTMC uninstall: could not drop ettmc tables: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
