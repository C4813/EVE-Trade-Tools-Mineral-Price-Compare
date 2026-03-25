<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Frontend rendering: shortcodes, enqueuing, and AJAX handlers.
 *
 * Shortcodes:
 *   [ettmc_mineral_profile]  — EVE SSO character connect/disconnect card.
 *   [ettmc_mineral_compare]  — Full mineral price tables + extended trade ops.
 */
final class ETTMC_Render {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_shortcode( 'ettmc_mineral_profile', [ __CLASS__, 'shortcode_profile' ] );
		add_shortcode( 'ettmc_mineral_compare', [ __CLASS__, 'shortcode_compare' ] );

		// Cache bypass on pages with our shortcodes.
		add_action( 'wp', [ __CLASS__, 'maybe_bypass_cache' ], 1 );

		// AJAX: return best fees per hub for the current user's characters.
		add_action( 'wp_ajax_ettmc_best_fees', [ __CLASS__, 'ajax_best_fees' ] );
	}

	// ── Cache bypass ──────────────────────────────────────────────────────

	public static function maybe_bypass_cache(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['action'] ) && $_GET['action'] === 'ettmc_eve_callback' ) {
			self::nocache();
			return;
		}
		if ( ! is_singular() ) return;
		global $post;
		if ( ! ( $post instanceof WP_Post ) ) return;
		$c = (string) $post->post_content;
		if ( has_shortcode( $c, 'ettmc_mineral_profile' ) || has_shortcode( $c, 'ettmc_mineral_compare' ) ) {
			self::nocache();
		}
	}

	private static function nocache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) )  define( 'DONOTCACHEPAGE',  true );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		if ( ! defined( 'DONOTCACHEDB' ) )    define( 'DONOTCACHEDB',    true );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		if ( ! defined( 'LSCACHE_NO_CACHE' ) ) define( 'LSCACHE_NO_CACHE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		@header( 'X-LiteSpeed-Cache-Control: no-cache' );
		nocache_headers();
	}

	// ── Assets ────────────────────────────────────────────────────────────

	public static function enqueue_assets(): void {
		if ( is_admin() ) return;

		// Only load on pages that actually contain an ETTMC shortcode.
		// This prevents the JS from running on every page and avoids
		// conflicts with other plugins that may use similar element IDs.
		global $post;
		if ( ! $post ) return;
		$has_compare = has_shortcode( $post->post_content, 'ettmc_mineral_compare' );
		$has_profile = has_shortcode( $post->post_content, 'ettmc_mineral_profile' );
		if ( ! $has_compare && ! $has_profile ) return;

		$url     = ETTMC_URL . 'assets/';
		$path    = ETTMC_PATH . 'assets/';
		$css_ver = file_exists( $path . 'frontend.css' ) ? filemtime( $path . 'frontend.css' ) : ETTMC_VERSION;
		$js_ver  = file_exists( $path . 'frontend.js'  ) ? filemtime( $path . 'frontend.js'  ) : ETTMC_VERSION;

		wp_enqueue_style( 'ettmc-frontend', $url . 'frontend.css', [], $css_ver );
		wp_enqueue_script( 'ettmc-frontend', $url . 'frontend.js', [ 'jquery' ], $js_ver, true );

		wp_localize_script( 'ettmc-frontend', 'ETTMC', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'minerals' => ETTMC_ESI::minerals(),
			'hubs'     => ETTMC_ESI::hubs(),
			'fees'     => self::compute_best_fees(),
		] );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────

	/**
	 * Compute best broker fee and sales tax per hub for the current user.
	 * Used both server-side (embedded in localize) and as an AJAX fallback.
	 *
	 * Returns [ hub_key => [ 'broker_fee' => float%, 'sales_tax' => float% ] ]
	 */
	public static function compute_best_fees(): array {
		$best = [];
		foreach ( ETTMC_ESI::hubs() as $h ) {
			$best[ $h['key'] ] = [ 'broker_fee' => 3.000, 'sales_tax' => 8.000 ];
		}

		if ( ! is_user_logged_in() ) return $best;

		$characters = get_user_meta( get_current_user_id(), ETTMC_OAuth::META_KEY, true ) ?: [];
		foreach ( $characters as $char_id => $_ ) {
			$char_data = ETTMC_OAuth::get_character_data( (string) $char_id );
			if ( empty( $char_data['skill_levels'] ) ) continue;
			foreach ( ETTMC_ESI::hubs() as $h ) {
				$fees   = ETTMC_OAuth::calc_fees( $char_data, $h['name'] );
				$bf_pct = round( $fees['broker_fee'] * 100, 3 );
				$tx_pct = round( $fees['sales_tax']  * 100, 3 );
				if ( $bf_pct < $best[ $h['key'] ]['broker_fee'] ) $best[ $h['key'] ]['broker_fee'] = $bf_pct;
				if ( $tx_pct < $best[ $h['key'] ]['sales_tax']  ) $best[ $h['key'] ]['sales_tax']  = $tx_pct;
			}
		}
		return $best;
	}

	public static function ajax_best_fees(): void {
		if ( ! is_user_logged_in() ) { wp_send_json_success( [] ); return; }
		wp_send_json_success( self::compute_best_fees() );
	}

	// ── Shortcodes ────────────────────────────────────────────────────────

	/** [ettmc_mineral_profile] — character connect/disconnect card. */
	public static function shortcode_profile(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="ett-characters"><h3>Authenticated Characters (0)</h3>'
			     . '<p>You must be <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">logged in</a> to connect an EVE character.</p></div>';
		}
		$user_id    = get_current_user_id();
		$characters = get_user_meta( $user_id, ETTMC_OAuth::META_KEY, true ) ?: [];
		$count      = count( $characters );
		ob_start();
		include ETTMC_PATH . 'templates/frontend/mineral-profile.php';
		return ob_get_clean();
	}

	/** [ettmc_mineral_compare] — mineral price tables + extended trade ops. */
	public static function shortcode_compare(): string {
		// Pass extended trades data only when the shortcode is actually rendering.
		// This avoids loading large order-book arrays on every frontend page.
		wp_localize_script( 'ettmc-frontend', 'ETTMC_Extended', [
			'extendedTradesData' => ETTMC_ESI::load_extended_trades_data(),
		] );
		ob_start();
		include ETTMC_PATH . 'templates/frontend/mineral-compare.php';
		return ob_get_clean();
	}

	// ── Table rendering helpers ───────────────────────────────────────────

	/**
	 * Build an array of row data for a buy or sell price table.
	 *
	 * @param  string $type  'buy' or 'sell'
	 * @param  array  $prices  Full prices array from ETTMC_ESI::load_all_prices()
	 * @return array  [ [ 'mineral' => ..., 'cells' => [ ['value', 'class', 'trend_html'] ] ] ]
	 */
	public static function build_table_rows( string $type, array $prices ): array {
		$hubs     = ETTMC_ESI::hubs();
		$minerals = ETTMC_ESI::minerals();
		$eps      = 0.0001;
		$rows     = [];

		foreach ( $minerals as $type_id => $mineral_name ) {
			$vals = [];
			foreach ( $hubs as $hub ) {
				$entry  = $prices[ $hub['key'] ][ $type_id ] ?? [];
				$vals[] = isset( $entry[ $type ] ) && is_numeric( $entry[ $type ] ) ? (float) $entry[ $type ] : null;
			}

			// Rank top-3 unique values (buy: highest = best; sell: lowest = best).
			$sorted_vals = $vals;
			if ( $type === 'buy' ) arsort( $sorted_vals );
			else                   asort( $sorted_vals );
			$unique = [];
			foreach ( $sorted_vals as $v ) {
				if ( $v === null ) continue;
				$found = false;
				foreach ( $unique as $u ) if ( abs( $v - $u ) < $eps ) { $found = true; break; }
				if ( ! $found ) { $unique[] = $v; if ( count( $unique ) >= 3 ) break; }
			}

			// Collect trends alongside vals.
			$trends = [];
			foreach ( $hubs as $hub ) {
				$entry    = $prices[ $hub['key'] ][ $type_id ] ?? [];
				$trends[] = ( $entry['trend'] ?? null ) ? $entry['trend'][ $type ] ?? null : null;
			}

			$cells = [];
			foreach ( $vals as $idx => $val ) {
				$class = '';
				$disp  = $val === null ? 'N/A' : number_format( $val, 2 );
				if ( $val !== null ) {
					if ( isset( $unique[0] ) && abs( $val - $unique[0] ) < $eps )      $class = 'emc-rank-1';
					elseif ( isset( $unique[1] ) && abs( $val - $unique[1] ) < $eps )  $class = 'emc-rank-2';
					elseif ( isset( $unique[2] ) && abs( $val - $unique[2] ) < $eps )  $class = 'emc-rank-3';
				}
				$t          = $trends[ $idx ] ?? null;
				$trend_html = '';
				if ( is_array( $t ) && isset( $t['pct'] ) && $t['pct'] !== null ) {
					$dir        = $t['dir'] ?? 'flat';
					$arrow      = $dir === 'up' ? '▲' : ( $dir === 'down' ? '▼' : '◆' );
					$trend_html = '<div class="emc-trend-sub"><span class="emc-trend-' . $dir . '">'
					            . $arrow . ' ' . number_format( (float) $t['pct'], 2 ) . '%</span></div>';
				}
				$cells[] = [ 'value' => $disp, 'class' => $class, 'trend_html' => $trend_html ];
			}

			$rows[] = [ 'mineral' => $mineral_name, 'cells' => $cells ];
		}
		return $rows;
	}
}
