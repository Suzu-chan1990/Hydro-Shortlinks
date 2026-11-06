<?php
/**
 * Settings and maintenance.
 * Option names are unchanged from v2 (hydro_enable_tracking, default_slug_length,
 * hydro_analytics_bot_filter, hydro_analytics_anonymize_ip, hydro_adv_cache_minutes).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

add_action( 'admin_post_hydro_settings_save', 'hydro_settings_save' );
add_action( 'admin_post_hydro_maintenance', 'hydro_maintenance_action' );

function hydro_settings_save(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    check_admin_referer( 'hydro_settings_save' );

    // Nonce checked above with check_admin_referer(); numbers are cast with absint().
    $tracking = isset( $_POST['enable_tracking'] ) ? '1' : '0';
    hydro_update_setting( 'hydro_enable_tracking', $tracking );
    update_option( 'hydro_enable_tracking', $tracking ); // the redirect handler reads wp_options

    hydro_update_setting( 'default_slug_length', max( 4, min( 10, ( isset( $_POST['default_slug_length'] ) ? absint( $_POST['default_slug_length'] ) : 4 ) ) ) );
    update_option( 'hydro_analytics_anonymize_ip', isset( $_POST['anonymize_ip'] ) ? '1' : '0' );
    update_option( 'hydro_adv_cache_minutes', max( 1, min( 60, ( isset( $_POST['cache_minutes'] ) ? absint( $_POST['cache_minutes'] ) : 5 ) ) ) );
    $ret = max( 0, min( 3650, ( isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0 ) ) );
    if ( $ret > 0 && $ret < 35 ) $ret = 35; // rollups refresh the last 30 days; keep their raw clicks
    update_option( 'hydro_click_retention_days', $ret, false );

    hydro_flush_admin_cache();
    hydro_ui_flash( 'Settings saved.' );
    wp_safe_redirect( admin_url( 'admin.php?page=hydro-settings' ) );
    exit;
}

function hydro_maintenance_action(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    check_admin_referer( 'hydro_maintenance' );
    global $wpdb;
    $task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
    wp_raise_memory_limit( 'admin' );

    switch ( $task ) {
        case 'index_batch':
            $r = hydro_url_index_backfill( 10000, 25 );
            hydro_ui_flash( sprintf( 'URL index: %s links indexed in this run – %s %%.', number_format_i18n( $r['indexed'] ), hydro_url_index_progress() ) );
            break;
        case 'index_reset':
            hydro_url_index_reset();
            hydro_ui_flash( 'URL index rebuild scheduled. It runs in the background every 5 minutes.' );
            break;
        case 'geo_batch':
            $total = 0;
            for ( $i = 0; $i < 5; $i++ ) {
                $n = hydro_geo_batch();
                $total += $n;
                if ( $n < 100 ) break;
                sleep( 1 ); // ip-api batch limit: 15 requests per minute
            }
            hydro_ui_flash( sprintf( 'Geo lookup: %d IP addresses resolved.', $total ) );
            break;
        case 'rollup':
            $r = hydro_rollup_run( 40 );
            hydro_ui_flash( sprintf( 'Statistics: %d days aggregated – %s %%.', (int) $r['days'], hydro_rollup_progress() ) );
            break;
        case 'rollup_reset':
            hydro_rollup_reset();
            hydro_ui_flash( 'Statistics will be rebuilt from the first click in the background.' );
            break;
        case 'retention':
            $n = hydro_retention_run();
            hydro_ui_flash( sprintf( 'Retention: %s old clicks deleted.', number_format_i18n( $n ) ) );
            break;
        case 'cache':
            $wpdb->query( $wpdb->prepare(
                'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
                $wpdb->options, $wpdb->esc_like( '_transient_hydro_' ) . '%', $wpdb->esc_like( '_transient_timeout_hydro_' ) . '%'
            ) );
            if ( function_exists( 'wp_cache_flush_group' ) ) wp_cache_flush_group( 'hydro' );
            hydro_ui_flash( 'Hydro caches cleared.' );
            break;
    }
    wp_safe_redirect( admin_url( 'admin.php?page=hydro-settings#maintenance' ) );
    exit;
}

function hydro_db_table_info(): array {
    global $wpdb;
    $names = [ hydro_table( 'links' ), hydro_table( 'clicks' ), hydro_table( 'index' ), hydro_table( 'daily' ) ];
    $rows  = $wpdb->get_results( $wpdb->prepare(
        'SELECT TABLE_NAME AS n, TABLE_ROWS AS r, DATA_LENGTH + INDEX_LENGTH AS s FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s)',
        $names[0], $names[1], $names[2], $names[3]
    ), OBJECT_K );
    $out = [];
    foreach ( $names as $n ) $out[ $n ] = [ 'rows' => (int) ( $rows[ $n ]->r ?? 0 ), 'size' => (int) ( $rows[ $n ]->s ?? 0 ), 'exists' => isset( $rows[ $n ] ) ];
    return $out;
}

function hydro_admin_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    global $wpdb;

    $tracking   = get_option( 'hydro_enable_tracking', '1' ) === '1';
    $len        = hydro_slug_length();
    $anon       = get_option( 'hydro_analytics_anonymize_ip', '0' ) === '1';
    $cache_min  = (int) get_option( 'hydro_adv_cache_minutes', 5 );
    $retention  = (int) get_option( 'hydro_click_retention_days', 0 );
    $total      = hydro_admin_totals()['links'];
    $geo_open   = hydro_geo_pending();
    $geo_last   = (array) get_option( 'hydro_geo_last_run', [] );
    $ret_last   = (array) get_option( 'hydro_retention_last_run', [] );
    $tables     = hydro_db_table_info();
    $next       = static fn( string $hook ): string => ( $t = wp_next_scheduled( $hook ) ) ? human_time_diff( time(), $t ) : '–';

    hydro_ui_open( 'hydro-settings', 'Settings' );
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="hydro_settings_save">
        <?php wp_nonce_field( 'hydro_settings_save' ); ?>
        <div class="hy-section-head">
            <div><h2>Settings</h2><p class="hy-muted">Changes apply immediately – nothing here touches the structure of the link or click tables.</p></div>
            <button class="hy-btn hy-btn-primary" type="submit"><span class="dashicons dashicons-saved"></span>Save settings</button>
        </div>
        <div class="hy-grid hy-grid-3">
            <?php hydro_ui_card_open( 'Links', 'dashicons-admin-links' ); ?>
                <?php hydro_ui_switch( 'enable_tracking', $tracking, 'Track clicks', 'Stores time, country, referrer and browser of every click (IP shortened to /24 at once).' ); ?>
                <div class="hy-field">
                    <label class="hy-switch-label" for="hy-slen">Slug length for new links</label>
                    <input class="hy-input hy-input-num" id="hy-slen" type="number" name="default_slug_length" min="4" max="10" value="<?php echo esc_attr( (string) $len ); ?>">
                    <?php
                    foreach ( [ $len, $len + 1 ] as $i => $l ) {
                        if ( $l > 10 ) break;
                        $cap = pow( 36, $l );
                        $pct = $total / $cap * 100;
                        ?>
                        <div class="hy-capacity<?php echo $i ? ' hy-capacity-alt' : ''; ?>">
                            <div class="hy-capacity-head"><span><?php echo (int) $l; ?> characters<?php echo $i ? ' (if raised)' : ''; ?></span><span><?php echo esc_html( number_format_i18n( $pct, $pct < 0.01 ? 4 : 2 ) ); ?> %</span></div>
                            <div class="hy-progress"><span style="width:<?php echo esc_attr( (string) min( 100, max( 0.5, $pct ) ) ); ?>%"></span></div>
                            <div class="hy-desc"><?php echo esc_html( number_format_i18n( $total ) . ' of ' . number_format_i18n( $cap ) . ' combinations' ); ?></div>
                        </div>
                    <?php } ?>
                    <div class="hy-desc">Existing links keep their slugs. Recommended above 1 million links: 5.</div>
                </div>
            <?php hydro_ui_card_close(); ?>

            <?php hydro_ui_card_open( 'Privacy & data', 'dashicons-shield' ); ?>
                <?php hydro_ui_switch( 'anonymize_ip', $anon, 'Shorten IPs further in CSV exports', 'IPv4 → x.x.0.0, IPv6 → last two blocks zeroed.' ); ?>
                <div class="hy-field">
                    <label class="hy-switch-label" for="hy-ret">Delete clicks older than</label>
                    <div class="hy-inline"><input class="hy-input hy-input-num" id="hy-ret" type="number" name="retention_days" min="0" max="3650" value="<?php echo esc_attr( (string) $retention ); ?>"><span>days</span></div>
                    <div class="hy-desc">0 = keep forever, minimum 35. Runs daily in batches of 5,000 rows. Daily statistics and the lifetime click counter of each link stay – only the raw click rows (IP, browser, referrer) are removed.</div>
                </div>
            <?php hydro_ui_card_close(); ?>

            <?php hydro_ui_card_open( 'Reports', 'dashicons-chart-area' ); ?>
                <div class="hy-field">
                    <label class="hy-switch-label" for="hy-cache">Cache reports for</label>
                    <div class="hy-inline"><input class="hy-input hy-input-num" id="hy-cache" type="number" name="cache_minutes" min="1" max="60" value="<?php echo esc_attr( (string) $cache_min ); ?>"><span>minutes</span></div>
                    <div class="hy-desc">Reports over large ranges read many click rows; caching keeps the admin fast. "Refresh" on the analytics page always loads fresh data.</div>
                </div>
                <div class="hy-desc">Bots and crawlers are never counted (filtered when the click happens). Countries come from ip-api.com (batch endpoint, 100 IPs per hourly run, free, no key).</div>
            <?php hydro_ui_card_close(); ?>
        </div>
    </form>

    <div class="hy-section-head" id="maintenance">
        <div><h2>Maintenance</h2><p class="hy-muted">Background jobs and database status.</p></div>
    </div>
    <div class="hy-grid hy-grid-3">
        <?php hydro_ui_card_open( 'URL index', 'dashicons-database' ); ?>
            <p class="hy-desc">Makes "does this URL already have a shortlink?" a primary-key lookup instead of a scan over all links (used by the Public API, Bunrui and the themes). Built in an additional table – the link table is not changed.</p>
            <div class="hy-capacity">
                <div class="hy-capacity-head"><span><?php echo hydro_url_index_complete() ? 'Complete' : 'Building'; ?></span><span><?php echo esc_html( (string) hydro_url_index_progress() ); ?> %</span></div>
                <div class="hy-progress"><span style="width:<?php echo esc_attr( (string) hydro_url_index_progress() ); ?>%"></span></div>
                <div class="hy-desc"><?php echo hydro_url_index_complete() ? 'Kept up to date automatically.' : 'Next background batch in ' . esc_html( $next( 'hydro_url_index_event' ) ) . '.'; ?></div>
            </div>
            <div class="hy-actions">
                <?php if ( ! hydro_url_index_complete() ) hydro_maintenance_button( 'index_batch', 'Run a batch now', 'dashicons-controls-play' ); ?>
                <?php hydro_maintenance_button( 'index_reset', 'Rebuild', 'dashicons-update', 'Re-check all links? The index stays usable meanwhile.' ); ?>
            </div>
        <?php hydro_ui_card_close(); ?>

        <?php hydro_ui_card_open( 'Daily statistics', 'dashicons-chart-bar' ); ?>
            <p class="hy-desc">Finished days are aggregated once per hour into a small extra table, so reports over months stay fast. Today is always counted live.</p>
            <div class="hy-capacity">
                <div class="hy-capacity-head"><span><?php echo hydro_rollup_progress() >= 100 ? 'Up to date' : 'Preparing'; ?></span><span><?php echo esc_html( (string) hydro_rollup_progress() ); ?> %</span></div>
                <div class="hy-progress"><span style="width:<?php echo esc_attr( (string) hydro_rollup_progress() ); ?>%"></span></div>
                <div class="hy-desc">Aggregated through <?php echo esc_html( hydro_rollup_cursor() ); ?> · next run in <?php echo esc_html( $next( 'hydro_rollup_event' ) ); ?>.</div>
            </div>
            <div class="hy-actions">
                <?php if ( hydro_rollup_progress() < 100 ) hydro_maintenance_button( 'rollup', 'Continue now', 'dashicons-controls-play' ); ?>
                <?php hydro_maintenance_button( 'rollup_reset', 'Rebuild', 'dashicons-update', 'Rebuild all daily statistics from the click table? Days whose clicks were already deleted by the retention cannot be rebuilt.' ); ?>
            </div>
        <?php hydro_ui_card_close(); ?>

        <?php hydro_ui_card_open( 'Geo lookup', 'dashicons-admin-site-alt3' ); ?>
            <div class="hy-kv">
                <span>Clicks without country</span><strong><?php echo esc_html( hydro_ui_num( $geo_open ) ); ?></strong>
                <span>Last run</span><strong><?php echo ! empty( $geo_last['time'] ) ? esc_html( human_time_diff( (int) $geo_last['time'] ) . ' ago · ' . (int) ( $geo_last['resolved'] ?? 0 ) . ' IPs' ) : '–'; ?></strong>
                <span>Next run</span><strong><?php echo esc_html( $next( 'hydro_geo_batch_event' ) ); ?></strong>
            </div>
            <?php if ( ! empty( $geo_last['error'] ) ) : ?><p class="hy-desc hy-text-danger"><?php echo esc_html( (string) $geo_last['error'] ); ?></p><?php endif; ?>
            <div class="hy-actions"><?php hydro_maintenance_button( 'geo_batch', 'Resolve up to 500 IPs now', 'dashicons-location' ); ?></div>
        <?php hydro_ui_card_close(); ?>

        <?php hydro_ui_card_open( 'Database', 'dashicons-editor-table' ); ?>
            <table class="hy-table hy-table-compact">
                <thead><tr><th>Table</th><th class="hy-col-num">Rows ≈</th><th class="hy-col-num">Size</th></tr></thead>
                <tbody>
                <?php foreach ( $tables as $name => $t ) : ?>
                    <tr><td><code><?php echo esc_html( $name ); ?></code></td><td class="hy-col-num"><?php echo $t['exists'] ? esc_html( hydro_ui_num( $t['rows'] ) ) : '–'; ?></td><td class="hy-col-num"><?php echo $t['exists'] ? esc_html( size_format( $t['size'], 1 ) ) : 'missing'; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="hy-kv hy-mt">
                <span>Retention last run</span><strong><?php echo ! empty( $ret_last['time'] ) ? esc_html( human_time_diff( (int) $ret_last['time'] ) . ' ago · ' . hydro_ui_num( (int) ( $ret_last['deleted'] ?? 0 ) ) . ' rows' ) : '–'; ?></strong>
            </div>
            <div class="hy-actions">
                <?php if ( $retention > 0 ) hydro_maintenance_button( 'retention', 'Run retention now', 'dashicons-trash' ); ?>
                <?php hydro_maintenance_button( 'cache', 'Clear caches', 'dashicons-image-rotate' ); ?>
            </div>
        <?php hydro_ui_card_close(); ?>
    </div>

    <div class="hy-grid hy-grid-1">
        <?php hydro_ui_card_open( 'WP-CLI', 'dashicons-editor-code' ); ?>
            <p class="hy-desc">Run as the site's PHP-FPM user, never as root, e.g. <code>sudo -u &lt;fpm-user&gt; wp hydro status</code>.</p>
            <div class="hy-cli">
                <code>wp hydro status</code><span>Totals, clicks today / 7 days, index and geo status</span>
                <code>wp hydro index</code><span>Builds the complete URL index in one go</span>
                <code>wp hydro rollup [--rebuild]</code><span>Aggregates all finished days for the analytics</span>
                <code>wp hydro geo [--batches=10]</code><span>Resolves countries for pending clicks</span>
                <code>wp hydro prune</code><span>Applies the click retention now</span>
                <code>wp hydro create &lt;url&gt; [--slug=…]</code><span>Creates (or returns) a shortlink</span>
                <code>wp hydro top [--days=7]</code><span>Most clicked links</span>
            </div>
        <?php hydro_ui_card_close(); ?>
    </div>
    <?php
    hydro_ui_close();
}

function hydro_maintenance_button( string $task, string $label, string $icon, string $confirm = '' ): void {
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $confirm !== '' ? ' data-hy-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
        <input type="hidden" name="action" value="hydro_maintenance">
        <input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
        <?php wp_nonce_field( 'hydro_maintenance' ); ?>
        <button class="hy-btn" type="submit"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span><?php echo esc_html( $label ); ?></button>
    </form>
    <?php
}
