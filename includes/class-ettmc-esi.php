<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ETT Mineral Compare — Data layer.
 *
 * All prices come from ETT Price Helper's ett_prices table.
 * Trend data (today vs 30-day avg) comes from ettmc_mineral_trends,
 * populated by ETTMC_Hooks::on_history_results() during ETT PH history jobs.
 * Order book arrays come from ettmc_mineral_orders for extended trade simulation.
 *
 * No independent ESI calls are made here.
 */
final class ETTMC_ESI {

	const MAX_ORDERS_PER_SIDE = 150;

	/** Hub definitions — keys must match ETT Price Helper's hub_key values. */
	public static function hubs(): array {
		return [
			[ 'key' => 'jita',    'name' => 'Jita'    ],
			[ 'key' => 'amarr',   'name' => 'Amarr'   ],
			[ 'key' => 'rens',    'name' => 'Rens'    ],
			[ 'key' => 'hek',     'name' => 'Hek'     ],
			[ 'key' => 'dodixie', 'name' => 'Dodixie' ],
		];
	}

	public static function minerals(): array {
		return [
			34    => 'Tritanium',
			35    => 'Pyerite',
			36    => 'Mexallon',
			37    => 'Isogen',
			38    => 'Nocxium',
			39    => 'Zydrine',
			40    => 'Megacyte',
			11399 => 'Morphite',
		];
	}

	public static function init(): void {}

	// ── Price + trend loading ─────────────────────────────────────────────

	/**
	 * Load buy/sell prices and trend data for all minerals at all hubs.
	 * Returns [ hub_key => [ type_id => [ name, buy, sell, trend ] ] ]
	 * Trend is null if history job has not run yet.
	 */
	public static function load_all_prices(): array {
		$hub_keys = array_column( self::hubs(),    'key' );
		$type_ids = array_keys(   self::minerals() );

		$db_prices = ETTMC_ExtDB::get_hub_prices(  $hub_keys, $type_ids );
		$db_trends = ETTMC_ExtDB::get_all_trends(  $hub_keys, $type_ids );

		$out = [];
		foreach ( self::hubs() as $hub ) {
			$out[ $hub['key'] ] = [];
			foreach ( self::minerals() as $tid => $name ) {
				$row   = $db_prices[ $hub['key'] ][ $tid ] ?? [];
				$trend = $db_trends[ $hub['key'] ][ $tid ] ?? null;
				$out[ $hub['key'] ][ $tid ] = [
					'name'  => $name,
					'buy'   => $row['buy']  ?? null,
					'sell'  => $row['sell'] ?? null,
					'trend' => $trend ? [
						'buy'  => [ 'pct' => $trend['buy_pct'],  'dir' => self::dir( $trend['buy_pct']  ) ],
						'sell' => [ 'pct' => $trend['sell_pct'], 'dir' => self::dir( $trend['sell_pct'] ) ],
					] : null,
				];
			}
		}
		return $out;
	}

	private static function dir( ?float $pct ): string {
		if ( $pct === null ) return 'flat';
		return $pct > 0.0 ? 'up' : ( $pct < 0.0 ? 'down' : 'flat' );
	}

	// ── Extended trades data ──────────────────────────────────────────────

	/**
	 * Load data for the client-side trade simulation.
	 * { type_id: { name, hubs: { HubName: { buy, sell, buy_orders, sell_orders } } } }
	 */
	public static function load_extended_trades_data(): array {
		$minerals = self::minerals();
		$hubs     = self::hubs();
		$hub_keys = array_column( $hubs, 'key' );
		$type_ids = array_keys( $minerals );

		$db_prices = ETTMC_ExtDB::get_hub_prices( $hub_keys, $type_ids );
		$orders    = self::load_all_orders( $hub_keys, $type_ids );

		$out = [];
		foreach ( $minerals as $tid => $name ) {
			$out[ $tid ] = [ 'name' => $name, 'hubs' => [] ];
			foreach ( $hubs as $hub ) {
				$hk  = $hub['key'];
				$hn  = $hub['name'];
				$row = $db_prices[ $hk ][ $tid ] ?? [];
				$out[ $tid ]['hubs'][ $hn ] = [
					'buy'         => $row['buy']  ?? 'N/A',
					'sell'        => $row['sell'] ?? 'N/A',
					'buy_orders'  => $orders[ $hk ][ $tid ]['buy']  ?? [],
					'sell_orders' => $orders[ $hk ][ $tid ]['sell'] ?? [],
				];
			}
		}
		return $out;
	}

	private static function load_all_orders( array $hub_keys, array $type_ids ): array {
		$out = [];
		if ( ! ETTMC_ExtDB::is_configured() ) return $out;
		try {
			$pdo    = ETT_ExternalDB::pdo();
			$hk_ph  = implode( ',', array_fill( 0, count( $hub_keys ), '?' ) );
			$tid_ph = implode( ',', array_fill( 0, count( $type_ids  ), '?' ) );
			$stmt   = $pdo->prepare(
				"SELECT hub_key, type_id, is_buy, price, volume_remain
				 FROM ettmc_mineral_orders
				 WHERE hub_key IN ({$hk_ph}) AND type_id IN ({$tid_ph})
				 ORDER BY hub_key, type_id, is_buy,
				          CASE is_buy WHEN 1 THEN price END DESC,
				          CASE is_buy WHEN 0 THEN price END ASC
				 LIMIT 50000"
			);
			$stmt->execute( array_merge( $hub_keys, $type_ids ) );
			foreach ( $stmt->fetchAll() as $row ) {
				$hk   = (string) $row['hub_key'];
				$tid  = (int)    $row['type_id'];
				$side = (bool)   $row['is_buy'] ? 'buy' : 'sell';
				if ( ! isset( $out[ $hk ][ $tid ][ $side ] ) ) $out[ $hk ][ $tid ][ $side ] = [];
				if ( count( $out[ $hk ][ $tid ][ $side ] ) < self::MAX_ORDERS_PER_SIDE ) {
					$out[ $hk ][ $tid ][ $side ][] = [
						'price' => (float) $row['price'],
						'vol'   => (int)   $row['volume_remain'],
					];
				}
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ETTMC ESI load_all_orders failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return $out;
	}
}
