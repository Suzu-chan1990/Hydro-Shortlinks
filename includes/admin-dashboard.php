<?php
/**
 * Dashboard: status, KPIs, 30-day trend, top links, live click feed, quick create and health checks.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

add_action( 'wp_ajax_hydro_live', 'hydro_ajax_live' );

/**
 * Click KPIs for the dashboard. Ranges come from the report engine (rollups + live today),
 * the "today / 24 h" numbers are index-only counts. Cached 2 minutes.
 */
function hydro_dashboard_kpis(): array {
    $k = get_transient( 'hydro_dash_kpis' );
    if ( is_array( $k ) ) return $k;
    global $wpdb;
    $c     = hydro_table( 'clicks' );
    $now   = time() + (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ); // site-local timestamp
    $today = hydro_today();

    $live = $wpdb->get_row( $wpdb->prepare(
        "SELECT SUM(click_time >= %s) AS today, SUM(click_time < %s) AS yesterday_same FROM %i WHERE click_time >= %s",
        $today . ' 00:00:00', gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ), $c, hydro_day_shift( $today, -1 ) . ' 00:00:00'
    ) );
    $h24 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE click_time >= %s', $c, gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ) );
    $active_today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT shortlink_id) FROM %i WHERE click_time >= %s', $c, $today . ' 00:00:00' ) );

    $r7  = hydro_report( [ 'from' => hydro_day_shift( $today, -6 ), 'to' => $today ] );
    $r30 = hydro_report( [ 'from' => hydro_day_shift( $today, -29 ), 'to' => $today ] );

    $top = array_map( static fn( $t ) => [ 'id' => $t['id'], 'slug' => $t['slug'], 'url' => $t['url'], 'title' => $t['title'], 'n' => $t['clicks'] ], array_slice( $r7['top'], 0, 8 ) );

    $k = [
        'today'          => (int) $live->today,
        'yesterday_same' => (int) $live->yesterday_same,
        'h24'            => $h24,
        'd7'             => (int) $r7['kpi']['clicks'],
        'd7_prev'        => (int) $r7['prev']['clicks'],
        'd30'            => (int) $r30['kpi']['clicks'],
        'v7'             => (int) $r7['kpi']['visitors'],
        'active_today'   => $active_today,
        'series'         => $r30['series'],
        'top'            => $top,
    ];
    set_transient( 'hydro_dash_kpis', $k, 2 * MINUTE_IN_SECONDS );
    return $k;
}

/** Latest clicks (cheap: primary key order). */
function hydro_live_clicks( int $limit = 12 ): array {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT c.id, c.click_time, c.country, c.referrer, c.user_agent, l.slug, l.url, l.id AS link_id
         FROM %i c LEFT JOIN %i l ON l.id = c.shortlink_id
         ORDER BY c.id DESC LIMIT %d', hydro_table( 'clicks' ), hydro_table( 'links' ), $limit
    ) );
    $out = [];
    foreach ( $rows as $r ) {
        $host  = (string) wp_parse_url( (string) $r->referrer, PHP_URL_HOST );
        $ua    = strtolower( (string) $r->user_agent );
        $dev   = str_contains( $ua, 'ipad' ) || str_contains( $ua, 'tablet' ) ? 'tablet' : ( preg_match( '/mobi|iphone|android/', $ua ) ? 'smartphone' : 'desktop' );
        $cl    = $r->country && $r->country !== '--' ? hydro_country_label( (string) $r->country ) : null;
        $out[] = [
            'id'      => (int) $r->id,
            'ago'     => human_time_diff( strtotime( (string) $r->click_time ), current_time( 'timestamp' ) ),
            'time'    => (string) $r->click_time,
            'slug'    => (string) ( $r->slug ?? '(deleted)' ),
            'link_id' => (int) $r->link_id,
            'title'   => $r->url ? hydro_link_title( (string) $r->url ) : '',
            'ref'     => hydro_referrer_label( $host ),
            'flag'    => $cl['flag'] ?? '',
            'country' => $cl['name'] ?? '',
            'device'  => $dev,
        ];
    }
    return $out;
}

function hydro_ajax_live(): void {
    check_ajax_referer( 'hydro_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
    wp_send_json_success( hydro_live_clicks() );
}

/** Health checks → list of [level, title, text, link]. */
function hydro_health_checks( array $totals ): array {
    global $wpdb;
    $out     = [];
    $enabled = (array) get_option( 'hydro_enabled_modules', [] );

    if ( get_option( 'hydro_enable_tracking', '1' ) !== '1' ) {
        $out[] = [ 'important', 'Tracking is off', 'Clicks are not recorded, so analytics stay empty.', 'hydro-settings' ];
    }
    if ( ! in_array( 'api', $enabled, true ) ) {
        $out[] = [ 'important', 'Public API module is off', 'Themes and plugins that call hydro_create_or_get_shortlink() (Kakera, Kirameki, Bunrui) cannot create shortlinks.', 'hydro-extensions' ];
    }
    if ( hydro_rollup_progress() < 100 ) {
        $out[] = [ 'normal', 'Statistics are being prepared (' . hydro_rollup_progress() . ' %)', 'Finished days are aggregated in the background every hour. Until then long analytics ranges load slower. WP-CLI: wp hydro rollup', 'hydro-settings' ];
    }
    if ( ! hydro_url_index_complete() ) {
        $out[] = [ 'normal', 'URL index is being built (' . hydro_url_index_progress() . ' %)', 'Until it is finished, duplicate checks for new links use the slow full-table lookup. Runs in the background every 5 minutes, or at once with WP-CLI: wp hydro index', 'hydro-settings' ];
    }
    $geo_open = hydro_geo_pending();
    if ( $geo_open > 5000 ) {
        $out[] = [ 'normal', number_format_i18n( $geo_open ) . ' clicks without country', 'The geo cron resolves 100 IPs per hour. You can run batches manually in Settings → Maintenance.', 'hydro-settings' ];
    }
    $len = hydro_slug_length();
    if ( $totals['links'] / pow( 36, $len ) > 0.05 ) {
        $out[] = [ 'normal', 'Slug space is filling up', sprintf( '%s of %s %d-character slugs are used. A longer slug length lowers collisions.', number_format_i18n( $totals['links'] ), number_format_i18n( pow( 36, $len ) ), $len ), 'hydro-settings' ];
    }
    if ( $totals['expired'] > 0 ) {
        $out[] = [ 'optional', number_format_i18n( $totals['expired'] ) . ' expired links', 'They answer with 410 Gone. Review or delete them in the links list.', 'hydro-links&filter=expired' ];
    }
    if ( (int) get_option( 'hydro_click_retention_days', 0 ) === 0 ) {
        $out[] = [ 'optional', 'No click retention', 'Click rows are kept forever. A retention period keeps the table small (lifetime counters stay).', 'hydro-settings' ];
    }
    return $out;
}

function hydro_dashboard_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $tot = hydro_admin_totals();
    $k   = hydro_dashboard_kpis();
    $tracking = get_option( 'hydro_enable_tracking', '1' ) === '1';
    $checks   = hydro_health_checks( $tot );

    hydro_ui_open( 'hydro-shortlinks', 'Dashboard' );
    ?>
    <div class="hy-hero">
        <section class="hy-status">
            <div class="hy-kicker">Today</div>
            <div class="hy-status-main">
                <div class="hy-ring"><strong><?php echo esc_html( hydro_ui_num( $k['today'] ) ); ?></strong><small>clicks</small></div>
                <div>
                    <div class="hy-status-name"><?php echo $tracking ? 'Tracking active' : 'Tracking paused'; ?></div>
                    <div class="hy-status-desc">
                        <?php echo hydro_ui_delta( $k['today'], $k['yesterday_same'] ); // phpcs:ignore ?> compared with yesterday at this time.
                        <?php echo esc_html( hydro_ui_num( $k['active_today'] ) ); ?> different links were clicked today.
                    </div>
                </div>
            </div>
            <div class="hy-chips">
                <span class="hy-chip"><span class="hy-dot <?php echo $tracking ? 'ok' : 'off'; ?>"></span>Tracking <?php echo $tracking ? 'on' : 'off'; ?></span>
                <span class="hy-chip"><span class="hy-dot <?php echo hydro_url_index_complete() ? 'ok' : 'warn'; ?>"></span>URL index <?php echo esc_html( (string) hydro_url_index_progress() ); ?> %</span>
                <span class="hy-chip"><span class="dashicons dashicons-admin-site-alt3"></span><?php echo count( array_filter( (array) get_option( 'hydro_enabled_modules', [] ) ) ); ?> modules</span>
            </div>
        </section>
        <section class="hy-quick">
            <div class="hy-kicker">Quick shortlink</div>
            <form class="hy-quick-form" data-hy-create>
                <input class="hy-input hy-input-lg" type="url" name="url" placeholder="https://www.youtube.com/watch?v=…" required>
                <div class="hy-quick-row">
                    <input class="hy-input" type="text" name="slug" maxlength="10" placeholder="Slug (optional)" pattern="[A-Za-z0-9_-]{1,10}">
                    <button class="hy-btn hy-btn-primary" type="submit"><span class="dashicons dashicons-plus-alt2"></span>Create</button>
                </div>
            </form>
            <div class="hy-quick-result" hidden>
                <div class="hy-quick-url"><a target="_blank" rel="noopener"></a></div>
                <div class="hy-quick-actions">
                    <button type="button" class="hy-btn" data-hy-copy><span class="dashicons dashicons-admin-page"></span>Copy</button>
                    <button type="button" class="hy-btn" data-hy-qr><span class="dashicons dashicons-screenoptions"></span>QR code</button>
                </div>
                <div class="hy-quick-note"></div>
            </div>
        </section>
    </div>

    <div class="hy-kpis">
        <?php
        $tiles = [
            [ 'Shortlinks', $tot['links'], 'dashicons-admin-links', '' ],
            [ 'Lifetime clicks', $tot['clicks'], 'dashicons-chart-bar', '' ],
            [ 'Clicks 24 h', $k['h24'], 'dashicons-clock', '' ],
            [ 'Clicks 7 days', $k['d7'], 'dashicons-calendar-alt', hydro_ui_delta( $k['d7'], $k['d7_prev'] ) ],
            [ 'Visitors 7 days', $k['v7'], 'dashicons-groups', '' ],
            [ 'Clicks 30 days', $k['d30'], 'dashicons-chart-line', '' ],
        ];
        foreach ( $tiles as [ $label, $num, $icon, $delta ] ) :
            ?>
            <div class="hy-kpi">
                <span class="hy-kpi-icon dashicons <?php echo esc_attr( $icon ); ?>"></span>
                <div class="hy-kpi-num"><?php echo esc_html( hydro_ui_num( $num ) ); ?></div>
                <div class="hy-kpi-label"><?php echo esc_html( $label ); ?> <?php echo $delta; // phpcs:ignore ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="hy-grid hy-grid-2-1">
        <?php hydro_ui_card_open( 'Clicks – last 30 days', 'dashicons-chart-area', 'hy-chart-card', '<a class="hy-card-link" href="' . esc_url( admin_url( 'admin.php?page=hydro-analytics' ) ) . '">Open analytics →</a>' ); ?>
            <div class="hy-chart-box"><canvas id="hy-dash-chart" data-series="<?php echo esc_attr( wp_json_encode( $k['series'] ) ); ?>"></canvas></div>
        <?php hydro_ui_card_close(); ?>

        <?php hydro_ui_card_open( 'Top links · 7 days', 'dashicons-star-filled' ); ?>
            <?php if ( ! $k['top'] ) : ?>
                <p class="hy-empty">No clicks in the last 7 days.</p>
            <?php else :
                $max = max( 1, $k['top'][0]['n'] ); ?>
                <ol class="hy-bars">
                    <?php foreach ( $k['top'] as $t ) : ?>
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=hydro-analytics&link_id=' . $t['id'] ) ); ?>" title="<?php echo esc_attr( $t['url'] ); ?>">
                                <span class="hy-bar-label"><code>/<?php echo esc_html( $t['slug'] ); ?></code> <?php echo esc_html( $t['title'] ); ?></span>
                                <span class="hy-bar-val"><?php echo esc_html( hydro_ui_num( $t['n'] ) ); ?></span>
                                <span class="hy-bar" style="--w:<?php echo esc_attr( (string) round( $t['n'] / $max * 100, 1 ) ); ?>%"></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        <?php hydro_ui_card_close(); ?>
    </div>

    <div class="hy-grid hy-grid-2-1">
        <?php hydro_ui_card_open( 'Live clicks', 'dashicons-controls-play', 'hy-live-card', '<span class="hy-live-dot" title="refreshes every 20 seconds"></span>' ); ?>
            <ul class="hy-live" id="hy-live" data-live>
                <?php foreach ( hydro_live_clicks() as $c ) hydro_live_row( $c ); ?>
            </ul>
        <?php hydro_ui_card_close(); ?>

        <?php hydro_ui_card_open( 'Health', 'dashicons-heart' ); ?>
            <?php if ( ! $checks ) : ?>
                <p class="hy-allgood"><span class="dashicons dashicons-yes-alt"></span> Everything looks good.</p>
            <?php else : ?>
                <div class="hy-recs">
                    <?php foreach ( $checks as [ $lvl, $title, $text, $page ] ) : ?>
                        <div class="hy-rec <?php echo esc_attr( $lvl ); ?>">
                            <span class="hy-rec-dot"></span>
                            <div>
                                <div class="hy-rec-title"><?php echo esc_html( $title ); ?> <span class="hy-rec-badge"><?php echo esc_html( $lvl === 'important' ? 'important' : ( $lvl === 'optional' ? 'optional' : 'recommended' ) ); ?></span></div>
                                <div class="hy-rec-desc"><?php echo esc_html( $text ); ?></div>
                            </div>
                            <a class="hy-rec-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>">Open →</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="hy-kv hy-mt">
                <span>Unused links (0 clicks)</span><strong><?php echo esc_html( hydro_ui_num( $tot['unused'] ) ); ?></strong>
                <span>Password protected</span><strong><?php echo esc_html( hydro_ui_num( $tot['protected'] ) ); ?></strong>
                <span>Expired</span><strong><?php echo esc_html( hydro_ui_num( $tot['expired'] ) ); ?></strong>
            </div>
        <?php hydro_ui_card_close(); ?>
    </div>
    <?php
    hydro_ui_close();
}

function hydro_live_row( array $c ): void {
    ?>
    <li data-id="<?php echo (int) $c['id']; ?>">
        <span class="dashicons dashicons-<?php echo esc_attr( $c['device'] ); ?>" title="<?php echo esc_attr( $c['device'] ); ?>"></span>
        <span class="hy-live-main">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=hydro-analytics&link_id=' . $c['link_id'] ) ); ?>"><code>/<?php echo esc_html( $c['slug'] ); ?></code></a>
            <span class="hy-live-title"><?php echo esc_html( $c['title'] ); ?></span>
        </span>
        <span class="hy-live-meta"><?php echo esc_html( trim( $c['flag'] . ' ' . $c['ref'] ) ); ?></span>
        <time datetime="<?php echo esc_attr( $c['time'] ); ?>"><?php echo esc_html( $c['ago'] ); ?></time>
    </li>
    <?php
}
