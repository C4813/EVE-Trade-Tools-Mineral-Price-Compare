<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks into ETT Price Helper's price and history jobs.
 *
 * PRICE JOB — order book capture:
 *   ett_prices_hub_start       — resets in-memory buffer for a hub.
 *   ett_prices_raw_orders_page — buffers mineral orders; written at PHP shutdown
 *                                to avoid deadlocking ETT PH's open PDO connection.
 *
 * HISTORY JOB — trend capture:
 *   ett_prices_history_results — filters mineral type IDs, computes today vs
 *                                30-day average, writes to ettmc_mineral_trends.
 *                                Written immediately (different table, no deadlock risk).
 */
final class ETTMC_Hooks {

	private static array $mineral_ids    = [];
	private static array $buffer         = [];
	private static bool  $shutdown_reg   = false;
	private static bool  $schema_ensured = false;

	public static function init(): void {
		self::$mineral_ids = ETTMC_ExtDB::mineral_ids();

		add_action( 'ett_prices_hub_start',        [ __CLASS__, 'on_hub_start'        ], 10, 3 );
		add_action( 'ett_prices_raw_orders_page',  [ __CLASS__, 'on_raw_orders_page'  ], 10, 6 );
		add_action( 'ett_prices_history_results',  [ __CLASS__, 'on_history_results'  ], 10, 3 );
	}

	// ── Price job hooks ───────────────────────────────────────────────────

	public static function on_hub_start( string $hub_key, int $_region_id, int $_station_id ): void {
		if ( ! ETTMC_ExtDB::is_configured() ) return;
		self::$buffer[ $hub_key ] = [];
		self::maybe_register_shutdown();
	}

	public static function on_raw_orders_page(
		string $hub_key, int $_region_id, int $station_id,
		int $_page, string $_source, array $orders
	): void {
		if ( ! ETTMC_ExtDB::is_configured() ) return;
		if ( empty( $orders ) ) return;

		$mineral_set = array_fill_keys( self::$mineral_ids, true );
		if ( ! isset( self::$buffer[ $hub_key ] ) ) self::$buffer[ $hub_key ] = [];

		foreach ( $orders as $o ) {
			$type_id = (int) ( $o['type_id'] ?? 0 );
			if ( ! isset( $mineral_set[ $type_id ] ) ) continue;

			$order_id = (int)   ( $o['order_id']     ?? 0 );
			$price    = (float) ( $o['price']         ?? 0 );
			$volrem   = (int)   ( $o['volume_remain'] ?? 0 );
			$is_buy   = (bool)  ( $o['is_buy_order']  ?? false );
			$loc_id   = (int)   ( $o['location_id']   ?? 0 );

			if ( $order_id <= 0 || $price <= 0 || $volrem <= 0 ) continue;

			if ( ! $is_buy ) {
				if ( $loc_id !== $station_id ) continue;
			} else {
				$range = $o['range'] ?? 'station';
				if ( $range === 'station' && $loc_id !== $station_id ) continue;
			}

			self::$buffer[ $hub_key ][] = [
				'order_id'      => $order_id,
				'type_id'       => $type_id,
				'is_buy'        => (int) $is_buy,
				'price'         => $price,
				'volume_remain' => $volrem,
			];
		}

		self::maybe_register_shutdown();
	}

	private static function maybe_register_shutdown(): void {
		if ( self::$shutdown_reg ) return;
		self::$shutdown_reg = true;
		register_shutdown_function( [ __CLASS__, 'flush_buffer' ] );
	}

	public static function flush_buffer(): void {
		if ( empty( self::$buffer ) ) return;
		if ( ! ETTMC_ExtDB::is_configured() ) return;

		if ( ! self::$schema_ensured ) {
			try {
				ETTMC_ExtDB::ensure_schema();
				self::$schema_ensured = true;
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'ETTMC Hooks flush_buffer: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return;
			}
		}

		foreach ( self::$buffer as $hub_key => $rows ) {
			if ( ! empty( $rows ) ) ETTMC_ExtDB::upsert_orders( $hub_key, $rows );
		}
		self::$buffer = [];

		if ( ! get_transient( 'ettmc_cleanup_ran' ) ) {
			ETTMC_ExtDB::cleanup_stale( 48 );
			set_transient( 'ettmc_cleanup_ran', true, DAY_IN_SECONDS );
		}
	}

	// ── History job hook ──────────────────────────────────────────────────

	/**
	 * Receives raw daily history data from ETT Price Helper's history job.
	 * Filters for mineral type IDs, computes today vs 30-day average for
	 * buy (lowest) and sell (highest) sides, and writes trend percentages
	 * to ettmc_mineral_trends.
	 *
	 * Written immediately (not deferred) — ettmc_mineral_trends is a
	 * separate table with no key overlap with ETT PH's tables, so there
	 * is no deadlock risk.
	 */
	public static function on_history_results( string $hub_key, int $_region_id, array $results ): void {
		if ( ! ETTMC_ExtDB::is_configured() ) return;
		if ( empty( $results ) ) return;

		$mineral_set = array_fill_keys( self::$mineral_ids, true );
		$rows        = [];

		foreach ( $results as $type_id => $result ) {
			$type_id = (int) $type_id;
			if ( ! isset( $mineral_set[ $type_id ] ) ) continue;
			if ( (int) ( $result['code'] ?? 0 ) !== 200 ) continue;

			$data = $result['data'] ?? [];
			if ( count( $data ) < 31 ) continue;

			// Ensure ascending date order (ESI returns oldest-first but be defensive).
			usort( $data, fn( $a, $b ) => strcmp( $a['date'] ?? '', $b['date'] ?? '' ) );

			$last31 = array_slice( $data, -31 );
			$today  = end( $last31 );
			$prev30 = array_slice( $last31, 0, 30 );

			$low_sum = $low_cnt = $high_sum = $high_cnt = 0;
			foreach ( $prev30 as $row ) {
				if ( isset( $row['lowest'] )  && is_numeric( $row['lowest'] )  && $row['lowest']  > 0 ) { $low_sum  += (float) $row['lowest'];  $low_cnt++; }
				if ( isset( $row['highest'] ) && is_numeric( $row['highest'] ) && $row['highest'] > 0 ) { $high_sum += (float) $row['highest']; $high_cnt++; }
			}

			$avg_low  = $low_cnt  > 0 ? $low_sum  / $low_cnt  : null;
			$avg_high = $high_cnt > 0 ? $high_sum / $high_cnt : null;

			$today_low  = ( isset( $today['lowest'] )  && is_numeric( $today['lowest'] ) )  ? (float) $today['lowest']  : null;
			$today_high = ( isset( $today['highest'] ) && is_numeric( $today['highest'] ) ) ? (float) $today['highest'] : null;

			$buy_pct  = ( $avg_low  && $today_low  !== null && $avg_low  != 0.0 )
				? round( ( $today_low  - $avg_low  ) / $avg_low  * 100.0, 2 ) : null;
			$sell_pct = ( $avg_high && $today_high !== null && $avg_high != 0.0 )
				? round( ( $today_high - $avg_high ) / $avg_high * 100.0, 2 ) : null;

			$rows[] = [ 'type_id' => $type_id, 'buy_pct' => $buy_pct, 'sell_pct' => $sell_pct ];
		}

		if ( empty( $rows ) ) return;

		try {
			ETTMC_ExtDB::ensure_schema(); // idempotent — creates trend table if needed
			ETTMC_ExtDB::upsert_trends( $hub_key, $rows );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ETTMC on_history_results: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
