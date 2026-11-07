<?php
/**
 * WP-CLI: wp hydro <status|index|geo|prune|create|top>
 * Run as the site's PHP-FPM user, never as root.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( 'WP_CLI' ) ) return;

// Hydro works on its own tables – WordPress has no API for them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Hydro_CLI {

    /**
     * Shows totals, recent clicks, URL index and geo status.
     */
    public function status(): void {
        global $wpdb;
        delete_transient( 'hydro_admin_totals' );
        delete_transient( 'hydro_dash_kpis' );
        $t = hydro_admin_totals();
        $k = hydro_dashboard_kpis_cli();
        $rows = [
            [ 'key' => 'Links', 'value' => number_format( $t['links'] ) ],
            [ 'key' => 'Lifetime clicks', 'value' => number_format( $t['clicks'] ) ],
            [ 'key' => 'Clicks today', 'value' => number_format( $k['today'] ) ],
            [ 'key' => 'Clicks 7 days', 'value' => number_format( $k['d7'] ) ],
            [ 'key' => 'Expired links', 'value' => number_format( $t['expired'] ) ],
            [ 'key' => 'Tracking', 'value' => get_option( 'hydro_enable_tracking', '1' ) === '1' ? 'on' : 'off' ],
            [ 'key' => 'URL index', 'value' => hydro_url_index_progress() . ' %' ],
            [ 'key' => 'Daily statistics', 'value' => hydro_rollup_progress() . ' % (through ' . hydro_rollup_cursor() . ')' ],
            [ 'key' => 'Clicks without country', 'value' => number_format( hydro_geo_pending() ) ],
            [ 'key' => 'Modules', 'value' => implode( ', ', (array) get_option( 'hydro_enabled_modules', [] ) ) ],
        ];
        WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'value' ] );
    }

    /**
     * Builds the URL index completely.
     *
     * ## OPTIONS
     * [--batch=<n>]
     * : Rows per query. Default 10000.
     * [--reset]
     * : Start again from the first link.
     */
    public function index( array $args, array $assoc ): void {
        if ( isset( $assoc['reset'] ) ) hydro_url_index_reset();
        if ( ! hydro_url_index_ready() ) hydro_schema_upgrade();
        $batch = max( 500, (int) ( $assoc['batch'] ?? 10000 ) );
        $max   = (int) get_option( 'hydro_url_index_max', 0 );
        if ( hydro_url_index_complete() ) { WP_CLI::success( 'URL index is already complete.' ); return; }
        $bar = WP_CLI\Utils\make_progress_bar( 'Indexing', max( 1, $max - (int) get_option( 'hydro_url_index_cursor', 0 ) ) );
        do {
            $r = hydro_url_index_backfill( $batch, 5 );
            $bar->tick( $r['indexed'] );
        } while ( ! $r['done'] );
        $bar->finish();
        WP_CLI::success( 'URL index complete.' );
    }

    /**
     * Aggregates all finished days into the daily statistics table.
     *
     * ## OPTIONS
     * [--rebuild]
     * : Start again from the first click.
     */
    public function rollup( array $args, array $assoc ): void {
        if ( ! hydro_rollup_table_ready() ) hydro_schema_upgrade();
        if ( isset( $assoc['rebuild'] ) ) hydro_rollup_reset();
        do {
            $r = hydro_rollup_run( 30 );
            WP_CLI::log( sprintf( '%d days aggregated, through %s (%s %%)', $r['days'], hydro_rollup_cursor(), hydro_rollup_progress() ) );
        } while ( $r['days'] > 0 && hydro_rollup_progress() < 100 );
        WP_CLI::success( 'Daily statistics are up to date.' );
    }

    /**
     * Resolves countries for clicks without one (1,000 IPs per batch, local GeoIP database).
     *
     * ## OPTIONS
     * [--batches=<n>]
     * : Number of batches. Default 10.
     */
    public function geo( array $args, array $assoc ): void {
        $total = 0;
        $n     = max( 1, (int) ( $assoc['batches'] ?? 10 ) );
        for ( $i = 0; $i < $n; $i++ ) {
            $done = hydro_geo_batch( 1000 );
            $total += $done;
            WP_CLI::log( "Batch " . ( $i + 1 ) . ": {$done} IPs" );
            if ( $done < 1000 ) break;
        }
        WP_CLI::success( "{$total} IP addresses resolved." );
    }

    /**
     * Deletes clicks older than the retention period (Settings → Privacy & data).
     */
    public function prune(): void {
        if ( (int) get_option( 'hydro_click_retention_days', 0 ) <= 0 ) {
            WP_CLI::warning( 'No retention period set (Settings → Privacy & data).' );
            return;
        }
        WP_CLI::success( number_format( hydro_retention_run( 1000 ) ) . ' clicks deleted.' );
    }

    /**
     * Creates a shortlink or returns the existing one.
     *
     * ## OPTIONS
     * <url>
     * : Target URL.
     * [--slug=<slug>]
     * : Custom slug (max 10 characters).
     */
    public function create( array $args, array $assoc ): void {
        $url = esc_url_raw( $args[0] );
        if ( ! $url ) WP_CLI::error( 'Invalid URL.' );
        if ( empty( $assoc['slug'] ) && ( $l = hydro_find_link_by_url( $url ) ) ) {
            WP_CLI::log( hydro_short_url( $l ) . ' (existing)' );
            return;
        }
        $id = hydro_insert_link( $url, (string) ( $assoc['slug'] ?? '' ) );
        if ( is_wp_error( $id ) ) WP_CLI::error( $id->get_error_message() );
        WP_CLI::log( hydro_short_url( hydro_get_link( $id ) ) );
    }

    /**
     * Most clicked links.
     *
     * ## OPTIONS
     * [--days=<n>]
     * : Period in days. Default 7.
     * [--limit=<n>]
     * : Default 15.
     */
    public function top( array $args, array $assoc ): void {
        global $wpdb;
        $days  = max( 1, (int) ( $assoc['days'] ?? 7 ) );
        $limit = max( 1, min( 200, (int) ( $assoc['limit'] ?? 15 ) ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            'SELECT l.slug, l.url, COUNT(*) AS clicks FROM %i c JOIN %i l ON l.id = c.shortlink_id
             WHERE c.click_time >= %s GROUP BY c.shortlink_id ORDER BY clicks DESC LIMIT %d',
            hydro_table( 'clicks' ), hydro_table( 'links' ), gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ), $limit
        ), ARRAY_A );
        WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'clicks', 'url' ] );
    }
}

/** Small KPI query for the CLI (the dashboard file is admin-only). */
function hydro_dashboard_kpis_cli(): array {
    global $wpdb;
    $now = current_time( 'timestamp' );
    $row = $wpdb->get_row( $wpdb->prepare(
        'SELECT SUM(click_time >= %s) AS today, COUNT(*) AS d7 FROM %i WHERE click_time >= %s',
        gmdate( 'Y-m-d 00:00:00', $now ), hydro_table( 'clicks' ), gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS )
    ) );
    return [ 'today' => (int) $row->today, 'd7' => (int) $row->d7 ];
}

WP_CLI::add_command( 'hydro', 'Hydro_CLI' );
