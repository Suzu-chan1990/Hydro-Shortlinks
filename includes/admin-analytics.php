<?php
/**
 * Analytics page: date presets, period comparison, trend with previous period,
 * weekday × hour heatmap, devices / browsers / OS, sources, countries, top links, CSV export.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

add_action( 'wp_ajax_hydro_report', 'hydro_ajax_report' );
add_action( 'admin_post_hydro_clicks_export', 'hydro_clicks_export' );

function hydro_analytics_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Read-only view parameter (no state change), so no nonce is involved.
    $link_id = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $link    = $link_id ? hydro_get_link( $link_id ) : null;
    $tz      = wp_timezone();
    $today   = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
    $from    = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );

    hydro_ui_open( 'hydro-analytics', 'Analytics' );
    ?>
    <div class="hy-ana" id="hy-ana" data-today="<?php echo esc_attr( $today ); ?>">
        <section class="hy-card hy-ana-bar">
            <div class="hy-presets" role="group" aria-label="Range">
                <button type="button" class="hy-preset" data-days="1">Today</button>
                <button type="button" class="hy-preset" data-days="2">48 h</button>
                <button type="button" class="hy-preset" data-days="7">7 days</button>
                <button type="button" class="hy-preset active" data-days="30">30 days</button>
                <button type="button" class="hy-preset" data-days="90">90 days</button>
                <button type="button" class="hy-preset" data-days="365">12 months</button>
            </div>
            <div class="hy-range">
                <input class="hy-input" type="date" name="from" value="<?php echo esc_attr( $from ); ?>" max="<?php echo esc_attr( $today ); ?>" aria-label="From">
                <span>→</span>
                <input class="hy-input" type="date" name="to" value="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $today ); ?>" aria-label="To">
            </div>
            <div class="hy-filter-pills">
                <label class="hy-pill-input" title="Filter by link (slug)">
                    <span class="dashicons dashicons-admin-links"></span>
                    <input type="text" name="slug" placeholder="all links" value="<?php echo esc_attr( $link ? (string) $link->slug : '' ); ?>" size="10">
                    <input type="hidden" name="link_id" value="<?php echo $link ? (int) $link->id : 0; ?>">
                </label>
                <label class="hy-pill-input" title="Filter by country code">
                    <span class="dashicons dashicons-location"></span>
                    <input type="text" name="country" placeholder="all countries" maxlength="2" size="6">
                </label>
            </div>
            <span class="hy-spacer"></span>
            <button type="button" class="hy-btn" data-hy-export><span class="dashicons dashicons-download"></span>Clicks CSV</button>
            <button type="button" class="hy-btn hy-btn-primary" data-hy-run><span class="dashicons dashicons-update"></span>Refresh</button>
        </section>

        <?php if ( hydro_rollup_progress() < 100 ) : ?>
            <div class="hy-alert hy-alert-info">Statistics are being prepared in the background (<?php echo esc_html( (string) hydro_rollup_progress() ); ?> %). Long ranges load slower until this is finished – or run <code>wp hydro rollup</code>.</div>
        <?php endif; ?>
        <?php if ( $link ) : ?>
            <div class="hy-alert hy-alert-info hy-link-focus">
                Showing <code>/<?php echo esc_html( (string) $link->slug ); ?></code> → <?php echo esc_html( hydro_link_title( (string) $link->url ) ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=hydro-analytics' ) ); ?>">show all links</a>
            </div>
        <?php endif; ?>

        <div class="hy-kpis hy-kpis-ana">
            <div class="hy-kpi"><span class="hy-kpi-icon dashicons dashicons-chart-bar"></span><div class="hy-kpi-num" data-kpi="clicks">–</div><div class="hy-kpi-label">Clicks <span data-delta="clicks"></span></div></div>
            <div class="hy-kpi"><span class="hy-kpi-icon dashicons dashicons-groups"></span><div class="hy-kpi-num" data-kpi="visitors">–</div><div class="hy-kpi-label">Visitors <span data-delta="visitors"></span></div></div>
            <div class="hy-kpi"><span class="hy-kpi-icon dashicons dashicons-performance"></span><div class="hy-kpi-num" data-kpi="per_day">–</div><div class="hy-kpi-label">Clicks per day</div></div>
            <div class="hy-kpi"><span class="hy-kpi-icon dashicons dashicons-smartphone"></span><div class="hy-kpi-num" data-kpi="mobile">–</div><div class="hy-kpi-label">Mobile share</div></div>
            <div class="hy-kpi"><span class="hy-kpi-icon dashicons dashicons-arrow-up-alt"></span><div class="hy-kpi-num" data-kpi="peak">–</div><div class="hy-kpi-label" data-kpi="peak_label">Peak</div></div>
            <div class="hy-kpi"><span class="hy-kpi-icon dashicons dashicons-randomize"></span><div class="hy-kpi-num" data-kpi="direct">–</div><div class="hy-kpi-label">Direct / no referrer</div></div>
        </div>

        <div class="hy-grid hy-grid-1">
            <?php hydro_ui_card_open( 'Trend', 'dashicons-chart-area', 'hy-chart-card', '<label class="hy-inline-toggle"><input type="checkbox" data-hy-compare checked> previous period</label>' ); ?>
                <div class="hy-chart-box hy-chart-lg"><canvas id="hy-trend"></canvas></div>
            <?php hydro_ui_card_close(); ?>
        </div>

        <div class="hy-grid hy-grid-2-1">
            <?php hydro_ui_card_open( 'When do people click?', 'dashicons-calendar-alt' ); ?>
                <div class="hy-heat" id="hy-heat" aria-label="Clicks by weekday and hour"></div>
                <p class="hy-muted hy-small">Weekday × hour in the site time zone (<?php echo esc_html( wp_timezone_string() ); ?>).</p>
            <?php hydro_ui_card_close(); ?>
            <?php hydro_ui_card_open( 'Devices', 'dashicons-smartphone' ); ?>
                <div class="hy-chart-box hy-chart-donut"><canvas id="hy-devices"></canvas></div>
            <?php hydro_ui_card_close(); ?>
        </div>

        <div class="hy-grid hy-grid-3">
            <?php hydro_ui_card_open( 'Sources', 'dashicons-share' ); ?><ol class="hy-bars" id="hy-refs"></ol><?php hydro_ui_card_close(); ?>
            <?php hydro_ui_card_open( 'Countries', 'dashicons-admin-site-alt3' ); ?><ol class="hy-bars" id="hy-countries"></ol><p class="hy-muted hy-small" id="hy-unresolved"></p><?php hydro_ui_card_close(); ?>
            <?php hydro_ui_card_open( 'Browsers & systems', 'dashicons-desktop' ); ?>
                <div class="hy-chart-box hy-chart-sm"><canvas id="hy-browsers"></canvas></div>
                <div class="hy-chart-box hy-chart-sm"><canvas id="hy-os"></canvas></div>
            <?php hydro_ui_card_close(); ?>
        </div>

        <div class="hy-grid hy-grid-1">
            <?php hydro_ui_card_open( 'Top links', 'dashicons-star-filled' ); ?>
                <div class="hy-table-wrap">
                    <table class="hy-table">
                        <thead><tr><th>#</th><th>Shortlink</th><th>Target</th><th class="hy-col-num">Clicks</th><th class="hy-col-num">Visitors</th><th class="hy-col-num">Share</th></tr></thead>
                        <tbody id="hy-top"></tbody>
                    </table>
                </div>
            <?php hydro_ui_card_close(); ?>
        </div>
        <p class="hy-muted hy-small hy-right" id="hy-meta"></p>
        <form id="hy-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
            <input type="hidden" name="action" value="hydro_clicks_export">
            <?php wp_nonce_field( 'hydro_clicks_export' ); ?>
            <input type="hidden" name="from"><input type="hidden" name="to"><input type="hidden" name="link_id"><input type="hidden" name="country">
        </form>
    </div>
    <?php
    hydro_ui_close();
}

function hydro_ajax_report(): void {
    check_ajax_referer( 'hydro_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
    $in = [
        'from'    => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
        'to'      => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
        'link_id' => isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0,
        'country' => isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '',
    ];
    // Slug typed into the filter → link id
    $slug = isset( $_POST['slug'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['slug'] ) ), "/ \t" ) : '';
    if ( $slug !== '' && ! $in['link_id'] ) {
        $l = hydro_get_link_by_slug( $slug );
        if ( ! $l ) wp_send_json_error( 'No shortlink with the slug "' . $slug . '".', 404 );
        $in['link_id'] = (int) $l->id;
    }
    if ( ! empty( $_POST['fresh'] ) ) {
        delete_transient( hydro_report_cache_key( hydro_report_args( $in ) ) );
    }
    wp_send_json_success( hydro_report( $in ) );
}

/** Raw click export (streamed in chunks of 5,000 rows, IPs optionally shortened further). */
function hydro_clicks_export(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    check_admin_referer( 'hydro_clicks_export' );
    global $wpdb;

    $a = hydro_report_args( [
        'from'    => sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ),
        'to'      => sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ),
        'link_id' => isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0,
        'country' => sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ),
    ] );
    $anon = get_option( 'hydro_analytics_anonymize_ip', '0' ) === '1' || get_option( 'hydro_adv_anonymize_export', '0' ) === '1';

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="hydro-clicks-' . $a['from'] . '_' . $a['to'] . '.csv"' );
    $out = new SplFileObject( 'php://output', 'w' );
    $out->fwrite( "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel
    $out->fputcsv( [ 'click_time', 'slug', 'target', 'country', 'ip', 'referrer', 'user_agent' ] );

    // Click IDs grow with time: walk the primary key inside the ID window of the range.
    $bounds = $wpdb->get_row( $wpdb->prepare(
        'SELECT MIN(id) AS lo, MAX(id) AS hi FROM %i WHERE click_time >= %s AND click_time <= %s',
        hydro_table( 'clicks' ), $a['from'] . ' 00:00:00', $a['to'] . ' 23:59:59'
    ) );
    $cursor = $bounds && $bounds->lo !== null ? (int) $bounds->lo - 1 : 0;
    $hi     = $bounds && $bounds->hi !== null ? (int) $bounds->hi : 0;
    while ( $cursor < $hi ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.click_time, c.country, c.ip_address, c.referrer, c.user_agent, l.slug, l.url
             FROM %i c LEFT JOIN %i l ON l.id = c.shortlink_id
             WHERE c.id > %d AND c.id <= %d AND c.click_time >= %s AND c.click_time <= %s
               AND (%d = 0 OR c.shortlink_id = %d) AND (%s = '' OR c.country = %s)
             ORDER BY c.id ASC LIMIT 5000",
            hydro_table( 'clicks' ), hydro_table( 'links' ), $cursor, $hi,
            $a['from'] . ' 00:00:00', $a['to'] . ' 23:59:59', $a['link_id'], $a['link_id'], $a['country'], $a['country']
        ) );
        foreach ( $rows as $r ) {
            $ip = (string) $r->ip_address;
            if ( $anon && $ip !== '' ) {
                $ip = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? preg_replace( '/\.\d+\.\d+$/', '.0.0', $ip ) : preg_replace( '/(:[0-9a-f]*){2}$/i', ':0000:0000', $ip );
            }
            $out->fputcsv( [ $r->click_time, $r->slug, $r->url, $r->country, $ip, $r->referrer, $r->user_agent ] );
            $cursor = (int) $r->id;
        }
        flush();
        if ( count( $rows ) < 5000 ) break;
    }
    exit;
}
