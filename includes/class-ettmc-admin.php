<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers the "Mineral Compare" tab on ETT Price Helper's admin settings page.
 */
final class ETTMC_Admin {

	public static function init(): void {
		add_action( 'ett_admin_tabs',        [ __CLASS__, 'register_tab' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_ettmc_test_db', [ __CLASS__, 'ajax_test_db' ] );
	}

	public static function register_tab(): void {
		if ( ! class_exists( 'ETT_Admin' ) ) return;
		ETT_Admin::register_tab( 'mineral-compare', 'Mineral Compare', [ __CLASS__, 'render_tab' ] );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_ett-price-helper' ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
		if ( $tab !== 'mineral-compare' ) return;

		$url  = ETTMC_URL . 'assets/';
		$path = ETTMC_PATH . 'assets/';

		wp_enqueue_style(  'ettmc-admin', $url . 'admin.css', [], ETTMC_VERSION );
		wp_enqueue_script( 'ettmc-admin', $url . 'admin.js',  [ 'jquery' ], ETTMC_VERSION, true );

		wp_localize_script( 'ettmc-admin', 'ETTMC_Admin', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'ettmc_admin' ),
			'dbConfigured' => ETTMC_ExtDB::is_configured() ? 1 : 0,
			'callbackUrl' => ETTMC_OAuth::callback_url(),
		] );
	}

	public static function render_tab(): void {
		include ETTMC_PATH . 'templates/admin/settings-page.php';
	}

	/** AJAX: test the external DB connection. */
	public static function ajax_test_db(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json( [ 'ok' => false, 'message' => 'Forbidden' ], 403 );
		check_ajax_referer( 'ettmc_admin', 'nonce' );
		wp_send_json( ETTMC_ExtDB::test_connection() );
	}
}
