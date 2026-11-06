<?php
/**
 * Additive schema and background jobs.
 *
 * Zero-downtime rule: the live tables {prefix}shortlinks and {prefix}shortlink_clicks are
 * never altered. New features only add NEW tables with CREATE TABLE IF NOT EXISTS, which
 * takes no lock on the existing tables.
 *
 * URL index ({prefix}hydro_url_index)
 *   url_hash (sha1 of the exact target URL) -> shortlink_id.
 *   The `url` column of the links table is TEXT without an index, so "is there already a
 *   shortlink for this URL?" used to scan ~1M rows. The index turns that into a primary
 *   key lookup. Existing links are backfilled in small batches by a cron job; until the
 *   backfill is done, lookups fall back to the old query.
 *
 * Daily rollups ({prefix}hydro_stats_daily)
 *   One row per day / metric / dimension (total, hour, device, browser, os, ref, country, link).
 *   Filled by an hourly job (includes/report.php); analytics read these small rows instead
 *   of scanning the click table. Rollups also keep the statistics of clicks that the
 *   retention job deletes later.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

const HYDRO_SCHEMA_VERSION = 2;

add_action( 'plugins_loaded', 'hydro_schema_maybe_upgrade', 5 );
add_filter( 'cron_schedules', 'hydro_cron_schedules' );
add_action( 'hydro_url_index_event', 'hydro_url_index_backfill' );
add_action( 'hydro_retention_event', 'hydro_retention_run' );
add_action( 'init', 'hydro_schedule_jobs' );

function hydro_cron_schedules( array $s ): array {
    $s['hydro_five_minutes'] = [ 'interval' => 300, 'display' => 'Every 5 minutes (Hydro)' ];
    return $s;
}

function hydro_schema_maybe_upgrade(): void {
    if ( (int) get_option( 'hydro_schema_version', 0 ) >= HYDRO_SCHEMA_VERSION ) return;
    hydro_schema_upgrade();
}

/** Creates the additive tables. Safe to run any number of times. */
function hydro_schema_upgrade(): void {
    global $wpdb;
    $index   = hydro_table( 'index' );
    $daily   = hydro_table( 'daily' );
    $charset = $wpdb->get_charset_collate();

    // dbDelta() is only called for tables that do not exist yet, so it can only CREATE –
    // it never gets the chance to ALTER anything (zero-downtime rule).
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    if ( ! hydro_table_exists( $index ) ) {
        dbDelta( "CREATE TABLE {$index} (
  url_hash char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  shortlink_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (url_hash),
  KEY shortlink_id (shortlink_id)
) ENGINE=InnoDB;" );
    }
    if ( ! hydro_table_exists( $daily ) ) {
        dbDelta( "CREATE TABLE {$daily} (
  day date NOT NULL,
  metric varchar(12) NOT NULL,
  dim varchar(100) NOT NULL DEFAULT '',
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  uniques int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,metric,dim)
) {$charset};" );
    }

    if ( hydro_table_exists( $index ) && hydro_table_exists( $daily ) ) {
        update_option( 'hydro_schema_version', HYDRO_SCHEMA_VERSION );
        if ( get_option( 'hydro_url_index_max' ) === false ) {
            // Backfill covers every link that exists right now; newer links are indexed on creation.
            $max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', hydro_table( 'links' ) ) );
            update_option( 'hydro_url_index_max', $max, false );
            update_option( 'hydro_url_index_cursor', 0, false );
            update_option( 'hydro_url_index_done', $max === 0 ? '1' : '0', false );
        }
    }
}

/** True if a table exists (prepared SHOW TABLES). */
function hydro_table_exists( string $table ): bool {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
}

function hydro_schedule_jobs(): void {
    if ( get_option( 'hydro_url_index_done' ) === '0' && ! wp_next_scheduled( 'hydro_url_index_event' ) ) {
        wp_schedule_event( time() + 60, 'hydro_five_minutes', 'hydro_url_index_event' );
    }
    if ( (int) get_option( 'hydro_click_retention_days', 0 ) > 0 ) {
        if ( ! wp_next_scheduled( 'hydro_retention_event' ) ) {
            wp_schedule_event( time() + 600, 'daily', 'hydro_retention_event' );
        }
    } elseif ( wp_next_scheduled( 'hydro_retention_event' ) ) {
        wp_clear_scheduled_hook( 'hydro_retention_event' );
    }
    if ( ! wp_next_scheduled( 'hydro_rollup_event' ) && (int) get_option( 'hydro_schema_version', 0 ) >= 2 ) {
        wp_schedule_event( time() + 120, 'hourly', 'hydro_rollup_event' );
    }
    if ( ! wp_next_scheduled( 'hydro_geo_batch_event' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'hydro_geo_batch_event' );
    }
}

function hydro_url_hash( string $url ): string {
    return sha1( $url );
}

function hydro_url_index_ready(): bool {
    return (int) get_option( 'hydro_schema_version', 0 ) >= 1;
}

function hydro_url_index_complete(): bool {
    return hydro_url_index_ready() && get_option( 'hydro_url_index_done' ) === '1';
}

/** Adds one link to the URL index. The first link for a URL wins (INSERT IGNORE). */
function hydro_index_link( int $id, string $url ): void {
    if ( ! hydro_url_index_ready() || $id <= 0 || $url === '' ) return;
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        'INSERT IGNORE INTO %i (url_hash, shortlink_id) VALUES (%s, %d)',
        hydro_table( 'index' ), hydro_url_hash( $url ), $id
    ) );
}

/** Removes index rows that point to a link (after delete or URL change). */
function hydro_unindex_link( int $id ): void {
    if ( ! hydro_url_index_ready() ) return;
    global $wpdb;
    $wpdb->delete( hydro_table( 'index' ), [ 'shortlink_id' => $id ], [ '%d' ] );
}

/**
 * Finds the existing link for an exact URL.
 * Uses the index; only while the backfill is still running it falls back to the slow query.
 */
function hydro_find_link_by_url( string $url, bool $fallback = true ): ?object {
    global $wpdb;

    if ( hydro_url_index_ready() ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT l.* FROM %i i JOIN %i l ON l.id = i.shortlink_id WHERE i.url_hash = %s LIMIT 1',
            hydro_table( 'index' ), hydro_table( 'links' ), hydro_url_hash( $url )
        ) );
        // Exact, case-sensitive comparison (YouTube IDs are case-sensitive).
        if ( $row && (string) $row->url === $url ) return $row;
        if ( hydro_url_index_complete() || ! $fallback ) return null;
    }

    // Fallback while the backfill is incomplete (old behaviour: full scan of the TEXT column).
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE url = %s LIMIT 1', hydro_table( 'links' ), $url ) );
    if ( $row && (string) $row->url === $url ) {
        hydro_index_link( (int) $row->id, $url );
        return $row;
    }
    return null;
}

/**
 * Backfills the URL index in batches by primary key. Each batch reads id+url only
 * (consistent read, no locks on the links table) and writes with INSERT IGNORE.
 *
 * @return array{done:bool, cursor:int, max:int, indexed:int}
 */
function hydro_url_index_backfill( int $batch = 10000, int $time_limit = 20 ): array {
    global $wpdb;
    if ( ! hydro_url_index_ready() ) hydro_schema_upgrade();

    $max    = (int) get_option( 'hydro_url_index_max', 0 );
    $cursor = (int) get_option( 'hydro_url_index_cursor', 0 );
    $start  = microtime( true );
    $count  = 0;

    while ( $cursor < $max && ( microtime( true ) - $start ) < $time_limit ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, url FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d',
            hydro_table( 'links' ), $cursor, $max, $batch
        ) );
        if ( empty( $rows ) ) { $cursor = $max; break; }

        foreach ( array_chunk( $rows, 500 ) as $chunk ) {
            $args = [ hydro_table( 'index' ) ];
            foreach ( $chunk as $r ) {
                $args[] = hydro_url_hash( (string) $r->url );
                $args[] = (int) $r->id;
            }
            // One multi-row INSERT per 500 links; every value goes through a placeholder.
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are generated with array_fill() to match the arguments.
            $wpdb->query( $wpdb->prepare(
                'INSERT IGNORE INTO %i (url_hash, shortlink_id) VALUES ' . implode( ',', array_fill( 0, count( $chunk ), '(%s,%d)' ) ),
                $args
            ) );
        }
        $count += count( $rows );
        $cursor = (int) end( $rows )->id;
        update_option( 'hydro_url_index_cursor', $cursor, false );
    }

    $done = $cursor >= $max;
    if ( $done ) {
        update_option( 'hydro_url_index_done', '1', false );
        wp_clear_scheduled_hook( 'hydro_url_index_event' );
    }
    return [ 'done' => $done, 'cursor' => $cursor, 'max' => $max, 'indexed' => $count ];
}

/** Restarts the backfill (e.g. after links were imported by another tool). */
function hydro_url_index_reset(): void {
    global $wpdb;
    $max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', hydro_table( 'links' ) ) );
    update_option( 'hydro_url_index_max', $max, false );
    update_option( 'hydro_url_index_cursor', 0, false );
    update_option( 'hydro_url_index_done', '0', false );
    if ( ! wp_next_scheduled( 'hydro_url_index_event' ) ) {
        wp_schedule_event( time() + 30, 'hydro_five_minutes', 'hydro_url_index_event' );
    }
}

/** Progress of the URL index backfill in percent. */
function hydro_url_index_progress(): float {
    if ( hydro_url_index_complete() ) return 100.0;
    $max = (int) get_option( 'hydro_url_index_max', 0 );
    if ( $max <= 0 ) return 0.0;
    return round( min( 100, (int) get_option( 'hydro_url_index_cursor', 0 ) / $max * 100 ), 1 );
}

/**
 * Click retention: deletes clicks older than N days in small batches via the click_time
 * index, so the table is never locked for long. The lifetime counter in the links table
 * (`clicks`) is not touched.
 */
function hydro_retention_run( int $max_batches = 40 ): int {
    $days = (int) get_option( 'hydro_click_retention_days', 0 );
    if ( $days <= 0 ) return 0;
    global $wpdb;
    $cutoff = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    $total  = 0;
    for ( $i = 0; $i < $max_batches; $i++ ) {
        $n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE click_time < %s LIMIT 5000', hydro_table( 'clicks' ), $cutoff ) );
        $total += $n;
        if ( $n < 5000 ) break;
    }
    update_option( 'hydro_retention_last_run', [ 'time' => time(), 'deleted' => $total ], false );
    return $total;
}
