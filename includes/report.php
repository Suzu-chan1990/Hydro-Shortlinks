<?php
/**
 * Analytics engine.
 *
 * Reports are built from daily rollups ({prefix}hydro_stats_daily, an additional table):
 * every finished day is aggregated once by a background job, so a 12-month report reads a
 * few thousand small rows instead of millions of click rows. Today (and any day the
 * rollup job has not reached yet) is aggregated live from the click table.
 * Reports filtered by link or country are always computed live – those use the
 * shortlink_id / country indexes and touch few rows.
 *
 * "Visitors" = unique (shortened) IP addresses per day, summed over the range.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

add_action( 'rest_api_init', 'hydro_report_rest_routes' );
add_action( 'hydro_rollup_event', 'hydro_rollup_run' );

const HYDRO_ROLLUP_LINKS_PER_DAY = 300;
const HYDRO_ROLLUP_REFS_PER_DAY  = 60;

/** Cache lifetime in seconds (setting kept under its old option name). */
function hydro_report_ttl(): int {
    return max( 1, (int) get_option( 'hydro_adv_cache_minutes', 5 ) ) * MINUTE_IN_SECONDS;
}

function hydro_today(): string {
    return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
}

function hydro_day_shift( string $day, int $n ): string {
    return gmdate( 'Y-m-d', strtotime( $day . ' 12:00:00' ) + $n * DAY_IN_SECONDS );
}

/* ================================================================================================
 * Live aggregation
 * ============================================================================================== */

/**
 * Aggregates clicks between two days (inclusive) into rows
 * [day, metric, dim, clicks, uniques]. Metrics: total, hour, device, browser, os, ref, country, link.
 *
 * One pass over the click rows of the range: every row is read once (in chunks of 20,000 by
 * primary key) and counted in PHP; user agents and referrers are classified in PHP (memoized,
 * most clicks share a few hundred distinct user agents). On a busy day this is ~5x faster than one GROUP BY query
 * per metric, because the expensive part is reading the rows, not counting them.
 * Link and country filters are optional: "(0 = 0 OR …)" is folded away by the optimizer,
 * so an unused filter costs nothing and the SQL stays a fixed, fully prepared string.
 *
 * @param string[] $metrics Subset to return (default: all).
 */
function hydro_aggregate( string $from, string $to, int $link_id = 0, string $country = '', array $metrics = [] ): array {
    global $wpdb;
    $c     = hydro_table( 'clicks' );
    $want  = static fn( string $m ): bool => ! $metrics || in_array( $m, $metrics, true );
    $start = $from . ' 00:00:00';
    $end   = $to . ' 23:59:59';

    // Click IDs grow with time: the click_time index gives the ID window of the range
    // (index-only), the rows are then read in primary-key order inside that window.
    $bounds = $wpdb->get_row( $wpdb->prepare(
        'SELECT MIN(id) AS lo, MAX(id) AS hi FROM %i WHERE click_time >= %s AND click_time <= %s',
        $c, $start, $end
    ) );
    if ( ! $bounds || $bounds->lo === null ) return [];
    $cursor = (int) $bounds->lo - 1;
    $hi     = (int) $bounds->hi;

    $tot = $hour = $dev = $br = $os = $ref = $cty = $lnk = [];
    $ip_day = $ip_cty = $ip_lnk = [];

    do {
        $chunk = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, DATE(click_time), HOUR(click_time), ip_address, shortlink_id, country, referrer, user_agent
             FROM %i
             WHERE id > %d AND id <= %d AND click_time >= %s AND click_time <= %s
               AND (%d = 0 OR shortlink_id = %d) AND (%s = '' OR country = %s)
             ORDER BY id ASC LIMIT 20000",
            $c, $cursor, $hi, $start, $end, $link_id, $link_id, $country, $country
        ), ARRAY_N );

        foreach ( $chunk as $r ) {
            [ $id, $d, $h, $ip, $sid, $cc, $referrer, $agent ] = $r;
            [ $dv, $b, $o ] = hydro_classify_ua( (string) $agent );
            $host = hydro_referrer_host( (string) $referrer );
            $cc = ( $cc === null || $cc === '--' ) ? '' : strtoupper( (string) $cc );
            $ip = (string) $ip;
            $tot[ $d ] = ( $tot[ $d ] ?? 0 ) + 1;
            $hour[ $d ][ (int) $h ] = ( $hour[ $d ][ (int) $h ] ?? 0 ) + 1;
            $dev[ $d ][ $dv ] = ( $dev[ $d ][ $dv ] ?? 0 ) + 1;
            $br[ $d ][ $b ]   = ( $br[ $d ][ $b ] ?? 0 ) + 1;
            $os[ $d ][ $o ]   = ( $os[ $d ][ $o ] ?? 0 ) + 1;
            $ref[ $d ][ (string) $host ] = ( $ref[ $d ][ (string) $host ] ?? 0 ) + 1;
            $cty[ $d ][ $cc ] = ( $cty[ $d ][ $cc ] ?? 0 ) + 1;
            $lnk[ $d ][ (int) $sid ] = ( $lnk[ $d ][ (int) $sid ] ?? 0 ) + 1;
            $ip_day[ $d ][ $ip ] = true;
            $ip_cty[ $d ][ $cc ][ $ip ] = true;
            $ip_lnk[ $d ][ (int) $sid ][ $ip ] = true;
            $cursor = (int) $id;
        }
    } while ( count( $chunk ) === 20000 );

    $need_ua = $want( 'device' ) || $want( 'browser' ) || $want( 'os' );
    $rows = [];
    foreach ( $tot as $d => $n ) {
        $d = (string) $d;
        if ( $want( 'total' ) ) $rows[] = [ $d, 'total', '', $n, count( $ip_day[ $d ] ) ];
        if ( $want( 'hour' ) ) foreach ( $hour[ $d ] as $h => $v ) $rows[] = [ $d, 'hour', (string) $h, $v, 0 ];
        if ( $need_ua ) {
            foreach ( [ 'device' => $dev, 'browser' => $br, 'os' => $os ] as $m => $set ) {
                if ( $want( $m ) ) foreach ( $set[ $d ] as $dim => $v ) $rows[] = [ $d, $m, (string) $dim, $v, 0 ];
            }
        }
        if ( $want( 'ref' ) ) {
            $labels = [];
            foreach ( $ref[ $d ] as $hst => $v ) {
                $l = mb_substr( hydro_referrer_label( (string) $hst ), 0, 100 );
                $labels[ $l ] = ( $labels[ $l ] ?? 0 ) + $v;
            }
            arsort( $labels );
            foreach ( array_slice( $labels, 0, HYDRO_ROLLUP_REFS_PER_DAY, true ) as $l => $v ) $rows[] = [ $d, 'ref', (string) $l, $v, 0 ];
            $other = array_sum( array_slice( $labels, HYDRO_ROLLUP_REFS_PER_DAY ) );
            if ( $other ) $rows[] = [ $d, 'ref', '(other)', $other, 0 ];
        }
        if ( $want( 'country' ) ) foreach ( $cty[ $d ] as $cc => $v ) $rows[] = [ $d, 'country', (string) $cc, $v, count( $ip_cty[ $d ][ $cc ] ) ];
        if ( $want( 'link' ) ) {
            arsort( $lnk[ $d ] );
            foreach ( array_slice( $lnk[ $d ], 0, HYDRO_ROLLUP_LINKS_PER_DAY, true ) as $sid => $v ) $rows[] = [ $d, 'link', (string) $sid, $v, count( $ip_lnk[ $d ][ $sid ] ) ];
        }
    }
    return $rows;
}

/* ================================================================================================
 * Rollups
 * ============================================================================================== */

/** Last day that is fully rolled up ('' = none yet). */
function hydro_rollup_cursor(): string {
    $c = get_option( 'hydro_rollup_cursor', false );
    if ( $c === false ) {
        global $wpdb;
        $first = $wpdb->get_var( $wpdb->prepare( 'SELECT DATE(MIN(click_time)) FROM %i', hydro_table( 'clicks' ) ) );
        $c     = $first ? hydro_day_shift( (string) $first, -1 ) : hydro_day_shift( hydro_today(), -1 );
        update_option( 'hydro_rollup_first', $first ? (string) $first : hydro_today(), false );
        update_option( 'hydro_rollup_cursor', $c, false );
    }
    return (string) $c;
}

function hydro_rollup_progress(): float {
    if ( ! hydro_rollup_table_ready() ) return 0.0;
    $first  = strtotime( (string) get_option( 'hydro_rollup_first', hydro_today() ) );
    $target = strtotime( hydro_day_shift( hydro_today(), -1 ) );
    $cursor = strtotime( hydro_rollup_cursor() );
    if ( $target <= $first ) return 100.0;
    return round( max( 0, min( 100, ( $cursor - $first + DAY_IN_SECONDS ) / ( $target - $first + DAY_IN_SECONDS ) * 100 ) ), 1 );
}

function hydro_rollup_table_ready(): bool {
    return (int) get_option( 'hydro_schema_version', 0 ) >= 2;
}

/** Re-aggregates one day into the rollup table (delete + insert in one transaction). */
function hydro_rollup_day( string $day ): int {
    global $wpdb;
    $rows  = hydro_aggregate( $day, $day );
    $table = hydro_table( 'daily' );
    $wpdb->query( 'START TRANSACTION' );
    $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day = %s', $table, $day ) );
    foreach ( array_chunk( $rows, 400 ) as $chunk ) {
        $args = [ $table ];
        foreach ( $chunk as $r ) array_push( $args, $r[0], $r[1], $r[2], $r[3], $r[4] );
        // One multi-row INSERT per 400 rows; every value goes through a placeholder.
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are generated with array_fill() to match the arguments.
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO %i (day, metric, dim, clicks, uniques) VALUES ' . implode( ',', array_fill( 0, count( $chunk ), '(%s,%s,%s,%d,%d)' ) ),
            $args
        ) );
    }
    $wpdb->query( 'COMMIT' );
    return count( $rows );
}

/**
 * Background job (hourly): rolls up all finished days not done yet, then refreshes the last
 * two days and recent days that still had clicks without a country (geo lookup runs later).
 */
function hydro_rollup_run( int $time_limit = 20 ): array {
    if ( ! hydro_rollup_table_ready() ) return [ 'days' => 0 ];
    global $wpdb;
    $start     = microtime( true );
    $yesterday = hydro_day_shift( hydro_today(), -1 );
    $cursor    = hydro_rollup_cursor();
    $done      = 0;

    while ( $cursor < $yesterday && ( microtime( true ) - $start ) < $time_limit ) {
        $cursor = hydro_day_shift( $cursor, 1 );
        hydro_rollup_day( $cursor );
        update_option( 'hydro_rollup_cursor', $cursor, false );
        $done++;
    }

    if ( $cursor >= $yesterday ) {
        // Late data: yesterday's last minutes and countries resolved after the first rollup.
        $refresh = [ $yesterday, hydro_day_shift( $yesterday, -1 ) ];
        $since   = hydro_day_shift( hydro_today(), -30 );
        $ret     = (int) get_option( 'hydro_click_retention_days', 0 );
        if ( $ret > 0 ) $since = max( $since, hydro_day_shift( hydro_today(), -( $ret - 2 ) ) );
        $pending = $wpdb->get_col( $wpdb->prepare(
            "SELECT day FROM %i WHERE metric = 'country' AND dim = '' AND day >= %s AND day < %s ORDER BY day DESC LIMIT 5",
            hydro_table( 'daily' ), $since, hydro_day_shift( $yesterday, -1 )
        ) );
        foreach ( array_unique( array_merge( $refresh, $pending ) ) as $d ) {
            if ( ( microtime( true ) - $start ) > $time_limit + 10 ) break;
            hydro_rollup_day( (string) $d );
        }
    }
    update_option( 'hydro_rollup_last_run', [ 'time' => time(), 'days' => $done ], false );
    return [ 'days' => $done, 'cursor' => $cursor ];
}

/** Rebuilds all rollups from the first click (runs in the background). */
function hydro_rollup_reset(): void {
    delete_option( 'hydro_rollup_cursor' );
    hydro_rollup_cursor();
}

/** Rows from the rollup table for a range. */
function hydro_rollup_rows( string $from, string $to, array $metrics = [] ): array {
    global $wpdb;
    $metrics = $metrics ?: [ 'total', 'hour', 'device', 'browser', 'os', 'ref', 'country', 'link' ];
    $out     = [];
    $result  = $wpdb->get_results( $wpdb->prepare(
        'SELECT day, metric, dim, clicks, uniques FROM %i WHERE day >= %s AND day <= %s AND metric IN (' . implode( ',', array_fill( 0, count( $metrics ), '%s' ) ) . ')',
        array_merge( [ hydro_table( 'daily' ), $from, $to ], array_values( $metrics ) )
    ), ARRAY_N );
    foreach ( $result as $r ) {
        $out[] = [ (string) $r[0], (string) $r[1], (string) $r[2], (int) $r[3], (int) $r[4] ];
    }
    return $out;
}

/**
 * Rows for a range: rollups where available, live aggregation for the rest.
 * Filtered requests (link / country) are always live.
 */
function hydro_range_rows( string $from, string $to, int $link_id, string $country, array $metrics = [] ): array {
    if ( $link_id > 0 || $country !== '' || ! hydro_rollup_table_ready() ) {
        return hydro_aggregate( $from, $to, $link_id, $country, $metrics );
    }
    $cursor = hydro_rollup_cursor();
    $rows   = [];
    if ( $from <= $cursor ) {
        $rows = hydro_rollup_rows( $from, min( $to, $cursor ), $metrics );
    }
    $live_from = max( $from, hydro_day_shift( $cursor, 1 ) );
    if ( $live_from <= $to ) {
        $rows = array_merge( $rows, hydro_aggregate( $live_from, $to, 0, '', $metrics ) );
    }
    return $rows;
}

/* ================================================================================================
 * Report
 * ============================================================================================== */

/** Normalises report arguments. */
function hydro_report_args( array $in ): array {
    $today = hydro_today();
    $to    = isset( $in['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['to'] ) ? (string) $in['to'] : $today;
    $from  = isset( $in['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['from'] ) ? (string) $in['from'] : hydro_day_shift( $to, -29 );
    if ( $from > $to ) [ $from, $to ] = [ $to, $from ];
    if ( ( strtotime( $to ) - strtotime( $from ) ) > 400 * DAY_IN_SECONDS ) $from = hydro_day_shift( $to, -400 );
    $days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;

    return [
        'from'    => $from,
        'to'      => $to,
        'days'    => $days,
        'agg'     => $days <= 2 ? 'hour' : 'day',
        'link_id' => isset( $in['link_id'] ) ? max( 0, (int) $in['link_id'] ) : 0,
        'country' => isset( $in['country'] ) && preg_match( '/^[A-Za-z]{2}$/', (string) $in['country'] ) ? strtoupper( (string) $in['country'] ) : '',
    ];
}

function hydro_report_cache_key( array $a ): string {
    return 'hydro_rep_' . md5( wp_json_encode( $a ) . '|' . hydro_rollup_cursor() );
}

/** Builds a full report. */
function hydro_report( array $in ): array {
    $a   = hydro_report_args( $in );
    $key = hydro_report_cache_key( $a );
    $hit = get_transient( $key );
    if ( is_array( $hit ) ) { $hit['cached'] = true; return $hit; }

    $t0   = microtime( true );
    $rows = hydro_range_rows( $a['from'], $a['to'], $a['link_id'], $a['country'] );

    $prev_to   = hydro_day_shift( $a['from'], -1 );
    $prev_from = hydro_day_shift( $prev_to, -( $a['days'] - 1 ) );
    $prows     = hydro_range_rows( $prev_from, $prev_to, $a['link_id'], $a['country'], $a['agg'] === 'hour' ? [ 'total', 'hour' ] : [ 'total' ] );

    $r = hydro_report_build( $a, $rows );
    $p = hydro_report_build( array_merge( $a, [ 'from' => $prev_from, 'to' => $prev_to ] ), $prows );

    $report = $r + [
        'args'       => $a,
        'prev'       => [ 'from' => $prev_from, 'to' => $prev_to, 'clicks' => $p['kpi']['clicks'], 'visitors' => $p['kpi']['visitors'] ],
        'prevSeries' => $p['series'],
        'rollup'     => hydro_rollup_progress(),
        'generated'  => current_time( 'mysql' ),
        'took_ms'    => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
        'cached'     => false,
    ];
    set_transient( $key, $report, hydro_report_ttl() );
    return $report;
}

/** Turns aggregate rows into report sections. */
function hydro_report_build( array $a, array $rows ): array {
    $sum = [ 'total' => [], 'hour' => [], 'device' => [], 'browser' => [], 'os' => [], 'ref' => [], 'country' => [], 'link' => [] ];
    $uq  = [ 'country' => [], 'link' => [] ];
    $day_clicks = [];
    $day_hour   = [];
    $clicks = $visitors = 0;
    $heat   = array_fill( 0, 7, array_fill( 0, 24, 0 ) );

    foreach ( $rows as [ $day, $m, $dim, $c, $u ] ) {
        switch ( $m ) {
            case 'total':
                $clicks += $c; $visitors += $u;
                $day_clicks[ $day ] = ( $day_clicks[ $day ] ?? 0 ) + $c;
                break;
            case 'hour':
                $h = (int) $dim;
                $day_hour[ sprintf( '%s %02d:00', $day, $h ) ] = $c;
                $heat[ (int) gmdate( 'N', strtotime( $day . ' 12:00:00' ) ) - 1 ][ $h ] += $c;
                break;
            default:
                $sum[ $m ][ $dim ] = ( $sum[ $m ][ $dim ] ?? 0 ) + $c;
                if ( isset( $uq[ $m ] ) ) $uq[ $m ][ $dim ] = ( $uq[ $m ][ $dim ] ?? 0 ) + $u;
        }
    }

    // Zero-filled series
    $labels = $data = [];
    if ( $a['agg'] === 'hour' ) {
        for ( $d = $a['from']; $d <= $a['to']; $d = hydro_day_shift( $d, 1 ) ) {
            for ( $h = 0; $h < 24; $h++ ) {
                $k = sprintf( '%s %02d:00', $d, $h );
                $labels[] = $k; $data[] = $day_hour[ $k ] ?? 0;
            }
        }
    } else {
        for ( $d = $a['from']; $d <= $a['to']; $d = hydro_day_shift( $d, 1 ) ) {
            $labels[] = $d; $data[] = $day_clicks[ $d ] ?? 0;
        }
    }
    $peak = [ 'label' => '', 'value' => 0 ];
    foreach ( $data as $i => $v ) if ( $v > $peak['value'] ) $peak = [ 'label' => $labels[ $i ], 'value' => $v ];

    foreach ( [ 'device', 'browser', 'os', 'ref' ] as $m ) arsort( $sum[ $m ] );

    // Countries
    $unresolved = (int) ( $sum['country'][''] ?? 0 );
    unset( $sum['country'][''] );
    arsort( $sum['country'] );
    $countries = [];
    foreach ( array_slice( $sum['country'], 0, 40, true ) as $code => $n ) {
        $countries[] = hydro_country_label( (string) $code ) + [ 'clicks' => $n, 'unique' => (int) ( $uq['country'][ $code ] ?? 0 ) ];
    }

    // Top links (+ meta in one query)
    arsort( $sum['link'] );
    $top = [];
    $ids = array_map( 'intval', array_keys( array_slice( $sum['link'], 0, 15, true ) ) );
    if ( $ids ) {
        global $wpdb;
        $meta = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, slug, url FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
            array_merge( [ hydro_table( 'links' ) ], $ids )
        ), OBJECT_K );
        foreach ( $ids as $id ) {
            $m = $meta[ $id ] ?? null;
            $top[] = [
                'id'     => $id,
                'slug'   => $m ? (string) $m->slug : '(deleted)',
                'url'    => $m ? (string) $m->url : '',
                'title'  => $m ? hydro_link_title( (string) $m->url ) : '',
                'clicks' => (int) $sum['link'][ $id ],
                'unique' => (int) ( $uq['link'][ $id ] ?? 0 ),
            ];
        }
    }

    $mobile = ( $sum['device']['Mobile'] ?? 0 ) + ( $sum['device']['Tablet'] ?? 0 );
    return [
        'kpi'        => [
            'clicks'   => $clicks,
            'visitors' => $visitors,
            'per_day'  => $a['days'] > 0 ? round( $clicks / $a['days'], 1 ) : 0,
            'direct'   => (int) ( $sum['ref']['(direct)'] ?? 0 ),
            'mobile'   => $mobile,
            'links'    => count( $sum['link'] ),
        ],
        'peak'       => $peak,
        'series'     => [ 'labels' => $labels, 'data' => $data ],
        'heatmap'    => $heat,
        'devices'    => $sum['device'],
        'browsers'   => $sum['browser'],
        'os'         => $sum['os'],
        'referrers'  => array_slice( $sum['ref'], 0, 15, true ),
        'countries'  => $countries,
        'unresolved' => $unresolved,
        'top'        => $top,
    ];
}

/** Stats for a single link (drawer in the links list). */
function hydro_link_stats( int $id ): array {
    $today = hydro_today();
    $r     = hydro_report( [ 'link_id' => $id, 'from' => hydro_day_shift( $today, -29 ), 'to' => $today ] );
    global $wpdb;
    // Newest click via the (shortlink_id, id) index – no scan over all clicks of the link.
    $last = $wpdb->get_var( $wpdb->prepare( 'SELECT click_time FROM %i WHERE shortlink_id = %d ORDER BY id DESC LIMIT 1', hydro_table( 'clicks' ), $id ) );
    return [
        'kpi'       => $r['kpi'],
        'prev'      => $r['prev'],
        'series'    => $r['series'],
        'referrers' => array_slice( $r['referrers'], 0, 6, true ),
        'countries' => array_slice( $r['countries'], 0, 6 ),
        'devices'   => $r['devices'],
        'last'      => $last ? (string) $last : '',
    ];
}

/* ------------------------------------------------------------------------------------------------
 * REST (admin only). /hydro/v1/analytics/<id> is kept for compatibility with the old module.
 * ---------------------------------------------------------------------------------------------- */
function hydro_report_rest_routes(): void {
    $perm = static fn(): bool => current_user_can( 'manage_options' );
    register_rest_route( 'hydro/v1', '/report', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => static fn( WP_REST_Request $r ) => rest_ensure_response( hydro_report( $r->get_params() ) ),
    ] );
    register_rest_route( 'hydro/v1', '/analytics/(?P<link_id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => static function ( WP_REST_Request $r ) {
            return rest_ensure_response( hydro_report( [ 'link_id' => (int) $r['link_id'], 'from' => $r->get_param( 'from' ), 'to' => $r->get_param( 'to' ) ] ) );
        },
    ] );
}

/**
 * Device, browser and operating system of a user agent. Memoized per request.
 *
 * @return array{0:string,1:string,2:string}
 */
function hydro_classify_ua( string $ua ): array {
    static $memo = [];
    if ( isset( $memo[ $ua ] ) ) return $memo[ $ua ];
    if ( count( $memo ) > 20000 ) $memo = [];
    if ( $ua === '' ) return $memo[ $ua ] = [ 'Unknown', 'Unknown', 'Unknown' ];

    $has = static function ( string ...$needles ) use ( $ua ): bool {
        foreach ( $needles as $n ) if ( stripos( $ua, $n ) !== false ) return true;
        return false;
    };

    $device = match ( true ) {
        $has( 'bot', 'crawl', 'spider', 'preview' )                          => 'Bot',
        $has( 'ipad', 'tablet' ) || ( $has( 'android' ) && ! $has( 'mobile' ) ) => 'Tablet',
        $has( 'mobi', 'iphone', 'android' )                                  => 'Mobile',
        default                                                              => 'Desktop',
    };
    $browser = match ( true ) {
        $has( 'FBAN', 'FBAV', 'Instagram', ' Line/', 'Twitter', 'TikTok', 'musical_ly', 'Discord' ) => 'In-App',
        $has( 'Edg' )                  => 'Edge',
        $has( 'OPR/', 'Opera' )        => 'Opera',
        $has( 'SamsungBrowser' )       => 'Samsung',
        $has( 'Firefox', 'FxiOS' )     => 'Firefox',
        $has( 'Chrome', 'CriOS' )      => 'Chrome',
        $has( 'Safari' )               => 'Safari',
        default                        => 'Other',
    };
    $os = match ( true ) {
        $has( 'iphone', 'ipad', 'ipod' )   => 'iOS',
        $has( 'android' )                  => 'Android',
        $has( 'windows' )                  => 'Windows',
        $has( 'CrOS' )                     => 'ChromeOS',
        $has( 'mac os', 'macintosh' )      => 'macOS',
        $has( 'linux' )                    => 'Linux',
        default                            => 'Other',
    };
    return $memo[ $ua ] = [ $device, $browser, $os ];
}

/** Host part of a referrer ('' = direct / none). */
function hydro_referrer_host( string $referrer ): string {
    if ( $referrer === '' ) return '';
    $rest = str_contains( $referrer, '://' ) ? substr( $referrer, strpos( $referrer, '://' ) + 3 ) : $referrer;
    return preg_match( '~^([^/?#:]*)~', $rest, $m ) ? strtolower( $m[1] ) : '';
}

/** Friendly name for a referrer host. */
function hydro_referrer_label( string $host ): string {
    $host = strtolower( preg_replace( '/^(www\.|m\.|l\.|lm\.|mobile\.)/i', '', trim( $host ) ) );
    if ( $host === '' ) return '(direct)';
    $map = [
        't.co' => 'X / Twitter', 'twitter.com' => 'X / Twitter', 'x.com' => 'X / Twitter',
        'facebook.com' => 'Facebook', 'fb.me' => 'Facebook', 'instagram.com' => 'Instagram',
        'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'discord.com' => 'Discord', 'discordapp.com' => 'Discord',
        'reddit.com' => 'Reddit', 'out.reddit.com' => 'Reddit', 'threads.net' => 'Threads', 'bsky.app' => 'Bluesky',
        'tiktok.com' => 'TikTok', 'google.com' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo',
        'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'pinterest.com' => 'Pinterest', 'telegram.org' => 'Telegram',
        'web.telegram.org' => 'Telegram', 'line.me' => 'LINE', 'pixiv.net' => 'pixiv', 'nicovideo.jp' => 'niconico',
        'twitch.tv' => 'Twitch', 'xfolio.jp' => 'Xfolio',
    ];
    if ( isset( $map[ $host ] ) ) return $map[ $host ];
    if ( str_starts_with( $host, 'google.' ) ) return 'Google';
    if ( str_starts_with( $host, 'com.google.android' ) ) return 'Google (Android app)';
    $site = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( $site && preg_replace( '/^www\./', '', strtolower( (string) $site ) ) === $host ) return $host . ' (own site)';
    return $host;
}
/** Country name for an ISO code, with flag emoji. */
function hydro_country_label( string $code ): array {
    $code = strtoupper( $code );
    $flag = '';
    if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
        $flag = html_entity_decode( '&#' . ( 0x1F1E6 + ord( $code[0] ) - 65 ) . ';&#' . ( 0x1F1E6 + ord( $code[1] ) - 65 ) . ';', ENT_NOQUOTES, 'UTF-8' );
    }
    $name = $code;
    if ( class_exists( 'Locale' ) ) {
        $n = Locale::getDisplayRegion( '-' . $code, 'en' );
        if ( $n && $n !== $code ) $name = $n;
    }
    return [ 'code' => $code, 'name' => $name, 'flag' => $flag ];
}

/**
 * Human title for a target URL: the matching post title for own-site URLs or YouTube videos
 * (via the yunyu meta keys), otherwise the host + path. Cached per URL.
 */
function hydro_link_title( string $url ): string {
    if ( $url === '' ) return '';
    $key   = 'hydro_t_' . md5( $url );
    $cache = wp_cache_get( $key, 'hydro' );
    if ( is_string( $cache ) ) return $cache;

    $title = '';
    $home  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $host  = (string) wp_parse_url( $url, PHP_URL_HOST );
    if ( $host !== '' && $host === $home ) {
        $pid = url_to_postid( $url );
        if ( $pid ) $title = get_the_title( $pid );
    } elseif ( preg_match( '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
        $title = (string) get_transient( 'hydro_yt_' . $m[1] );
        if ( $title === '' ) {
            global $wpdb;
            $pid = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM %i WHERE meta_key IN ('yunyu_video_id','_yunyu_video_id') AND meta_value = %s LIMIT 1", $wpdb->postmeta, $m[1]
            ) );
            $title = $pid ? (string) get_the_title( (int) $pid ) : '▶ ' . $m[1];
            set_transient( 'hydro_yt_' . $m[1], $title, DAY_IN_SECONDS );
        }
    }
    if ( $title === '' ) {
        $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
        $title = preg_replace( '/^www\./', '', $host ) . ( $path !== '/' ? $path : '' );
    }
    $title = wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES ) );
    wp_cache_set( $key, $title, 'hydro', HOUR_IN_SECONDS );
    return $title;
}
