<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Database access layer for ETT Mineral Compare.
 *
 * Reads mineral prices from ETT Price Helper's ett_prices table.
 * Manages ettmc_mineral_orders (order book for simulation) and
 * ettmc_mineral_trends (pre-computed trend percentages from history job).
 */
final class ETTMC_ExtDB {

	const MINERAL_IDS      = [ 34, 35, 36, 37, 38, 39, 40, 11399 ];
	const DEADLOCK_RETRIES  = 3;
	const DEADLOCK_SLEEP_US = 50000;

	public static function mineral_ids(): array {
		return self::MINERAL_IDS;
	}

	// ── Schema ────────────────────────────────────────────────────────────

	public static function ensure_schema(): void {
		$pdo = ETT_ExternalDB::pdo();

		$pdo->exec( "CREATE TABLE IF NOT EXISTS ettmc_mineral_orders (
			order_id      BIGINT UNSIGNED   NOT NULL,
			hub_key       VARCHAR(32)       NOT NULL,
			type_id       SMALLINT UNSIGNED NOT NULL,
			is_buy        TINYINT(1)        NOT NULL DEFAULT 0,
			price         DECIMAL(20,2)     NOT NULL,
			volume_remain INT UNSIGNED      NOT NULL,
			fetched_at    DATETIME          NOT NULL,
			PRIMARY KEY (order_id, hub_key),
			KEY idx_hub_type_side_price (hub_key, type_id, is_buy, price)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

		$pdo->exec( "CREATE TABLE IF NOT EXISTS ettmc_mineral_trends (
			hub_key    VARCHAR(32)       NOT NULL,
			type_id    SMALLINT UNSIGNED NOT NULL,
			buy_pct    DECIMAL(10,4)     NULL COMMENT 'today lowest vs 30-day avg lowest',
			sell_pct   DECIMAL(10,4)     NULL COMMENT 'today highest vs 30-day avg highest',
			updated_at DATETIME          NOT NULL,
			PRIMARY KEY (hub_key, type_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
	}

	// ── Price reading from ett_prices ─────────────────────────────────────

	/**
	 * Read sell_min and buy_max for all minerals at the given hubs
	 * from ETT Price Helper's ett_prices table.
	 *
	 * Returns [ hub_key => [ type_id => [ 'buy' => float|null, 'sell' => float|null ] ] ]
	 */
	public static function get_hub_prices( array $hub_keys, array $type_ids ): array {
		$out = [];
		foreach ( $hub_keys as $k ) $out[ $k ] = [];
		if ( ! self::is_configured() ) return $out;
		try {
			$pdo    = ETT_ExternalDB::pdo();
			$hk_ph  = implode( ',', array_fill( 0, count( $hub_keys ), '?' ) );
			$tid_ph = implode( ',', array_fill( 0, count( $type_ids  ), '?' ) );
			$stmt   = $pdo->prepare(
				"SELECT hub_key, type_id, buy_max, sell_min
				 FROM ett_prices
				 WHERE hub_key IN ({$hk_ph}) AND type_id IN ({$tid_ph})"
			);
			$stmt->execute( array_merge( $hub_keys, $type_ids ) );
			foreach ( $stmt->fetchAll() as $row ) {
				$hk  = (string) $row['hub_key'];
				$tid = (int)    $row['type_id'];
				if ( ! isset( $out[ $hk ] ) ) continue;
				$out[ $hk ][ $tid ] = [
					'buy'  => $row['buy_max']  !== null ? (float) $row['buy_max']  : null,
					'sell' => $row['sell_min'] !== null ? (float) $row['sell_min'] : null,
				];
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ETTMC ExtDB get_hub_prices failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return $out;
	}

	// ── Trend read/write ──────────────────────────────────────────────────

	/**
	 * Write pre-computed trend percentages for a batch of minerals at a hub.
	 * Called by ETTMC_Hooks::on_history_results() during ETT PH history jobs.
	 *
	 * @param array $rows  Each: [ 'type_id' => int, 'buy_pct' => float|null, 'sell_pct' => float|null ]
	 */
	public static function upsert_trends( string $hub_key, array $rows ): void {
		if ( empty( $rows ) ) return;
		try {
			$pdo = ETT_ExternalDB::pdo();
			$now = current_time( 'mysql' );
			foreach ( array_chunk( $rows, 100 ) as $chunk ) {
				$ph     = implode( ',', array_fill( 0, count( $chunk ), '(?,?,?,?,?)' ) );
				$params = [];
				foreach ( $chunk as $r ) {
					$params[] = (string) $hub_key;
					$params[] = (int)    $r['type_id'];
					$params[] = $r['buy_pct']  !== null ? (float) $r['buy_pct']  : null;
					$params[] = $r['sell_pct'] !== null ? (float) $r['sell_pct'] : null;
					$params[] = $now;
				}
				$pdo->prepare(
					"INSERT INTO ettmc_mineral_trends (hub_key, type_id, buy_pct, sell_pct, updated_at)
					 VALUES {$ph}
					 ON DUPLICATE KEY UPDATE
					 	buy_pct    = VALUES(buy_pct),
					 	sell_pct   = VALUES(sell_pct),
					 	updated_at = VALUES(updated_at)"
				)->execute( $params );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ETTMC ExtDB upsert_trends failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Load trend data for all minerals at all hubs.
	 * Returns [ hub_key => [ type_id => [ 'buy_pct' => float|null, 'sell_pct' => float|null ] ] ]
	 */
	public static function get_all_trends( array $hub_keys, array $type_ids ): array {
		$out = [];
		foreach ( $hub_keys as $k ) $out[ $k ] = [];
		if ( ! self::is_configured() ) return $out;
		try {
			$pdo    = ETT_ExternalDB::pdo();
			$hk_ph  = implode( ',', array_fill( 0, count( $hub_keys ), '?' ) );
			$tid_ph = implode( ',', array_fill( 0, count( $type_ids  ), '?' ) );
			$stmt   = $pdo->prepare(
				"SELECT hub_key, type_id, buy_pct, sell_pct
				 FROM ettmc_mineral_trends
				 WHERE hub_key IN ({$hk_ph}) AND type_id IN ({$tid_ph})"
			);
			$stmt->execute( array_merge( $hub_keys, $type_ids ) );
			foreach ( $stmt->fetchAll() as $row ) {
				$hk  = (string) $row['hub_key'];
				$tid = (int)    $row['type_id'];
				if ( ! isset( $out[ $hk ] ) ) continue;
				$out[ $hk ][ $tid ] = [
					'buy_pct'  => $row['buy_pct']  !== null ? (float) $row['buy_pct']  : null,
					'sell_pct' => $row['sell_pct'] !== null ? (float) $row['sell_pct'] : null,
				];
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ETTMC ExtDB get_all_trends failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return $out;
	}

	// ── Order book write ──────────────────────────────────────────────────

	public static function cleanup_stale( int $max_age_hours = 48 ): void {
		try {
			$pdo    = ETT_ExternalDB::pdo();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $max_age_hours * 3600 );
			$pdo->prepare( 'DELETE FROM ettmc_mineral_orders WHERE fetched_at < ?' )
			    ->execute( [ $cutoff ] );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ETTMC ExtDB cleanup_stale failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	public static function upsert_orders( string $hub_key, array $rows ): void {
		if ( empty( $rows ) ) return;
		try {
			$pdo = ETT_ExternalDB::pdo();
			$now = current_time( 'mysql' );
			foreach ( array_chunk( $rows, 100 ) as $chunk ) {
				self::upsert_chunk( $pdo, $hub_key, $chunk, $now );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ETTMC ExtDB upsert_orders failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	private static function upsert_chunk( \PDO $pdo, string $hub_key, array $chunk, string $now ): void {
		$ph     = implode( ',', array_fill( 0, count( $chunk ), '(?,?,?,?,?,?,?)' ) );
		$params = [];
		foreach ( $chunk as $r ) {
			$params[] = (int)    $r['order_id'];
			$params[] = (string) $hub_key;
			$params[] = (int)    $r['type_id'];
			$params[] = (int)    $r['is_buy'];
			$params[] = (float)  $r['price'];
			$params[] = (int)    $r['volume_remain'];
			$params[] = $now;
		}
		$sql   = "INSERT INTO ettmc_mineral_orders
			(order_id, hub_key, type_id, is_buy, price, volume_remain, fetched_at)
			VALUES {$ph}
			ON DUPLICATE KEY UPDATE
				price         = VALUES(price),
				volume_remain = VALUES(volume_remain),
				fetched_at    = VALUES(fetched_at)";
		$sleep = self::DEADLOCK_SLEEP_US;
		for ( $i = 0; $i <= self::DEADLOCK_RETRIES; $i++ ) {
			try {
				$pdo->prepare( $sql )->execute( $params );
				return;
			} catch ( \PDOException $e ) {
				if ( self::is_deadlock( $e ) && $i < self::DEADLOCK_RETRIES ) {
					usleep( $sleep ); $sleep *= 2; continue;
				}
				throw $e;
			}
		}
	}

	private static function is_deadlock( \PDOException $e ): bool {
		return $e->getCode() === '40001'
		    || strpos( $e->getMessage(), '1213' ) !== false
		    || strpos( $e->getMessage(), 'Deadlock' ) !== false;
	}

	// ── Order book read ───────────────────────────────────────────────────

	public static function hub_has_orders( string $hub_key ): bool {
		try {
			$pdo  = ETT_ExternalDB::pdo();
			$stmt = $pdo->prepare( 'SELECT 1 FROM ettmc_mineral_orders WHERE hub_key = ? LIMIT 1' );
			$stmt->execute( [ $hub_key ] );
			return (bool) $stmt->fetchColumn();
		} catch ( \Throwable $e ) { return false; }
	}

	public static function hub_last_updated( string $hub_key ): ?string {
		try {
			$pdo  = ETT_ExternalDB::pdo();
			$stmt = $pdo->prepare( 'SELECT MAX(fetched_at) FROM ettmc_mineral_orders WHERE hub_key = ?' );
			$stmt->execute( [ $hub_key ] );
			return $stmt->fetchColumn() ?: null;
		} catch ( \Throwable $e ) { return null; }
	}

	// ── Connection helpers ────────────────────────────────────────────────

	public static function is_configured(): bool {
		return class_exists( 'ETT_ExternalDB' ) && ETT_ExternalDB::is_configured();
	}

	public static function test_connection(): array {
		if ( ! class_exists( 'ETT_ExternalDB' ) ) {
			return [ 'ok' => false, 'message' => 'ETT Price Helper is not active.' ];
		}
		return ETT_ExternalDB::test_connection();
	}
}
