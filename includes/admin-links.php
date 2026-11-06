<?php
/**
 * Links: list with search, filters, bulk delete, create with advanced options,
 * edit drawer with per-link stats, QR code, target check, CSV import/export.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

add_action( 'wp_ajax_hydro_link_get', 'hydro_ajax_link_get' );
add_action( 'wp_ajax_hydro_link_update', 'hydro_ajax_link_update' );
add_action( 'wp_ajax_hydro_link_delete', 'hydro_ajax_link_delete' );
add_action( 'wp_ajax_hydro_link_check', 'hydro_ajax_link_check' );
add_action( 'admin_post_hydro_links_export', 'hydro_links_export' );
add_action( 'admin_post_hydro_links_import', 'hydro_links_import' );

const HYDRO_FILTERS = [
    ''          => 'All links',
    'top'       => 'Most clicked (top 500)',
    'unused'    => 'Never clicked',
    'expired'   => 'Expired',
    'protected' => 'Password protected',
    'utm'       => 'With UTM tags',
];

/**
 * Links matching search + filter, newest first, IDs below $before_id.
 * One fixed SQL string: unused conditions ("'' <> 'unused' OR …") are folded away by the optimizer.
 */
function hydro_links_query( string $search, string $filter, int $limit, int $offset = 0, int $before_id = PHP_INT_MAX ): array {
    global $wpdb;
    $like = '%' . $wpdb->esc_like( $search ) . '%';
    return (array) $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM %i
         WHERE id < %d
           AND (%s = '' OR slug = %s OR slug LIKE %s OR url LIKE %s)
           AND (%s <> 'unused' OR clicks = 0)
           AND (%s <> 'expired' OR (expire IS NOT NULL AND expire < NOW()))
           AND (%s <> 'protected' OR (password IS NOT NULL AND password <> ''))
           AND (%s <> 'utm' OR (utm_source IS NOT NULL AND utm_source <> '') OR (utm_campaign IS NOT NULL AND utm_campaign <> ''))
         ORDER BY id DESC LIMIT %d OFFSET %d",
        hydro_table( 'links' ), $before_id, $search, $search, $like, $like, $filter, $filter, $filter, $filter, $limit, $offset
    ) );
}

/** Reads a GET parameter for read-only list views (search, filter, paging). */
function hydro_links_get_param( string $key ): string {
    // Read-only view parameters (no state change), so no nonce is involved.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return isset( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
}

function hydro_links_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    global $wpdb;

    $search = hydro_links_get_param( 's' );
    $filter = hydro_links_get_param( 'filter' );
    $filter = isset( HYDRO_FILTERS[ $filter ] ) ? $filter : '';
    $per    = in_array( (int) hydro_links_get_param( 'per' ), [ 20, 50, 100 ], true ) ? (int) hydro_links_get_param( 'per' ) : 20;
    $paged  = max( 1, (int) hydro_links_get_param( 'paged' ) );
    $offset = ( $paged - 1 ) * $per;

    // A pasted short URL ("https://site/abcd") is searched as its slug.
    $home = home_url( '/' );
    if ( $search !== '' && str_starts_with( $search, $home ) ) {
        $maybe = trim( substr( $search, strlen( $home ) ), '/' );
        if ( hydro_slug_is_valid( $maybe ) ) $search = $maybe;
    }

    $total    = null; // exact total only where it is cheap
    $has_next = false;
    if ( $filter === 'top' ) {
        // Sorting 1M rows by clicks is a full scan: the top 500 IDs are cached for 10 minutes.
        $ids = get_transient( 'hydro_top_ids' );
        if ( ! is_array( $ids ) ) {
            $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY clicks DESC LIMIT 500', hydro_table( 'links' ) ) ) );
            set_transient( 'hydro_top_ids', $ids, 10 * MINUTE_IN_SECONDS );
        }
        $total = count( $ids );
        $slice = array_slice( $ids, $offset, $per );
        $links = [];
        if ( $slice ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $slice ), '%d' ) ) . ')',
                array_merge( [ hydro_table( 'links' ) ], $slice )
            ), OBJECT_K );
            foreach ( $slice as $id ) if ( isset( $rows[ $id ] ) ) $links[] = $rows[ $id ];
        }
    } else {
        $links    = hydro_links_query( $search, $filter, $per + 1, $offset );
        $has_next = count( $links ) > $per;
        $links    = array_slice( $links, 0, $per );
        if ( $search === '' && $filter === '' ) $total = hydro_admin_totals()['links'];
    }

    $base = admin_url( 'admin.php?page=hydro-links' );
    $qs   = array_filter( [ 's' => $search, 'filter' => $filter, 'per' => $per !== 20 ? $per : null ] );
    $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=hydro_links_export&' . http_build_query( array_filter( [ 's' => $search, 'filter' => $filter ] ) ) ), 'hydro_links_export' );

    hydro_ui_open( 'hydro-links', 'Links' );
    ?>
    <section class="hy-card hy-create">
        <form class="hy-create-form" data-hy-create data-insert="#hy-rows">
            <div class="hy-create-main">
                <span class="dashicons dashicons-admin-links hy-create-icon"></span>
                <input class="hy-input hy-input-lg" type="url" name="url" placeholder="Paste a long URL to shorten…" required>
                <input class="hy-input hy-slug-input" type="text" name="slug" maxlength="10" placeholder="slug (optional)" pattern="[A-Za-z0-9_-]{1,10}">
                <button class="hy-btn hy-btn-primary" type="submit"><span class="dashicons dashicons-plus-alt2"></span>Create</button>
            </div>
            <details class="hy-advanced">
                <summary>Advanced options</summary>
                <?php hydro_link_fields_html(); ?>
            </details>
        </form>
    </section>

    <section class="hy-card hy-list-card">
        <form class="hy-toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="hydro-links">
            <div class="hy-search">
                <span class="dashicons dashicons-search"></span>
                <input class="hy-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Slug, URL or short URL">
            </div>
            <select class="hy-input hy-select" name="filter" onchange="this.form.submit()">
                <?php foreach ( HYDRO_FILTERS as $k => $label ) : ?>
                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter, $k ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="hy-input hy-select hy-select-sm" name="per" onchange="this.form.submit()" aria-label="Per page">
                <?php foreach ( [ 20, 50, 100 ] as $n ) : ?><option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $per, $n ); ?>><?php echo esc_html( (string) $n ); ?> / page</option><?php endforeach; ?>
            </select>
            <button class="hy-btn" type="submit">Filter</button>
            <span class="hy-spacer"></span>
            <button class="hy-btn hy-btn-danger" type="button" data-hy-bulk-delete disabled><span class="dashicons dashicons-trash"></span>Delete selected</button>
            <a class="hy-btn" href="<?php echo esc_url( $export_url ); ?>"><span class="dashicons dashicons-download"></span>Export CSV</a>
            <button class="hy-btn" type="button" data-hy-toggle="#hy-import"><span class="dashicons dashicons-upload"></span>Import</button>
        </form>

        <form id="hy-import" class="hy-import" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
            <input type="hidden" name="action" value="hydro_links_import">
            <?php wp_nonce_field( 'hydro_links_import' ); ?>
            <div>
                <strong>CSV import</strong> – columns: <code>url, slug, redirect_type, expire, password, utm_source, utm_medium, utm_campaign</code>.
                Only <code>url</code> is required, a header row is optional, up to 5,000 rows per file.
                URLs that already have a shortlink are skipped.
            </div>
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <button class="hy-btn hy-btn-primary" type="submit">Import</button>
        </form>

        <div class="hy-table-wrap">
            <table class="hy-table hy-links">
                <thead>
                    <tr>
                        <th class="hy-col-check"><input type="checkbox" data-hy-check-all aria-label="Select all"></th>
                        <th>Shortlink</th>
                        <th>Target</th>
                        <th class="hy-col-num">Clicks</th>
                        <th class="hy-col-date">Created</th>
                        <th class="hy-col-actions"><span class="screen-reader-text">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="hy-rows">
                    <?php
                    if ( ! $links ) {
                        echo '<tr class="hy-empty-row"><td colspan="6">' . ( $search !== '' || $filter !== '' ? 'No links match this filter.' : 'No shortlinks yet – create the first one above.' ) . '</td></tr>';
                    }
                    foreach ( $links as $l ) echo hydro_links_row_html( $l ); // phpcs:ignore -- escaped inside
                    ?>
                </tbody>
            </table>
        </div>

        <div class="hy-pager">
            <span class="hy-muted">
                <?php
                $from_n = $links ? $offset + 1 : 0;
                $to_n   = $offset + count( $links );
                if ( $total !== null ) {
                    printf( '%s–%s of %s', esc_html( hydro_ui_num( $from_n ) ), esc_html( hydro_ui_num( $to_n ) ), esc_html( hydro_ui_num( $total ) ) );
                } else {
                    printf( '%s–%s', esc_html( hydro_ui_num( $from_n ) ), esc_html( hydro_ui_num( $to_n ) ) );
                }
                ?>
            </span>
            <span class="hy-spacer"></span>
            <?php
            $pages = $total !== null ? (int) ceil( $total / $per ) : 0;
            $next  = $total !== null ? $paged < $pages : $has_next;
            if ( $paged > 1 ) {
                echo '<a class="hy-btn" href="' . esc_url( add_query_arg( $qs + [ 'paged' => 1 ], $base ) ) . '">«</a>';
                echo '<a class="hy-btn" href="' . esc_url( add_query_arg( $qs + [ 'paged' => $paged - 1 ], $base ) ) . '">‹ Prev</a>';
            }
            echo '<span class="hy-page-no">Page ' . esc_html( hydro_ui_num( $paged ) ) . ( $pages ? ' / ' . esc_html( hydro_ui_num( $pages ) ) : '' ) . '</span>';
            if ( $next ) {
                echo '<a class="hy-btn" href="' . esc_url( add_query_arg( $qs + [ 'paged' => $paged + 1 ], $base ) ) . '">Next ›</a>';
                if ( $pages ) echo '<a class="hy-btn" href="' . esc_url( add_query_arg( $qs + [ 'paged' => $pages ], $base ) ) . '">»</a>';
            }
            ?>
        </div>
    </section>

    <?php hydro_links_drawer_html(); ?>
    <?php
    hydro_ui_close();
}

/** Advanced fields (used in the create form and in the edit drawer). */
function hydro_link_fields_html(): void {
    ?>
    <div class="hy-fields">
        <label><span>Redirect</span>
            <select class="hy-input" name="redirect_type">
                <option value="302">302 – temporary (default)</option>
                <option value="301">301 – permanent</option>
                <option value="307">307 – temporary, keeps method</option>
                <option value="308">308 – permanent, keeps method</option>
            </select>
        </label>
        <label><span>Expires</span><input class="hy-input" type="datetime-local" name="expire"></label>
        <label><span>Password</span><input class="hy-input" type="text" name="password" autocomplete="off" placeholder="visitors open /slug?pw=…"></label>
        <label><span>utm_source</span><input class="hy-input" type="text" name="utm_source" placeholder="e.g. x"></label>
        <label><span>utm_medium</span><input class="hy-input" type="text" name="utm_medium" placeholder="e.g. social"></label>
        <label><span>utm_campaign</span><input class="hy-input" type="text" name="utm_campaign" placeholder="e.g. release-2026"></label>
    </div>
    <?php
}

/** One table row. */
function hydro_links_row_html( object $l ): string {
    $short   = hydro_short_url( $l );
    $id      = (int) $l->id;
    $expired = $l->expire && current_time( 'mysql' ) > $l->expire;
    $flags   = '';
    if ( (int) $l->redirect_type !== 302 ) $flags .= '<span class="hy-tag">' . (int) $l->redirect_type . '</span>';
    if ( ! empty( $l->password ) ) $flags .= '<span class="hy-tag" title="Password protected"><span class="dashicons dashicons-lock"></span></span>';
    if ( $l->expire ) $flags .= '<span class="hy-tag ' . ( $expired ? 'hy-tag-danger' : '' ) . '" title="Expires ' . esc_attr( (string) $l->expire ) . '"><span class="dashicons dashicons-clock"></span>' . ( $expired ? 'expired' : esc_html( mysql2date( 'Y-m-d', (string) $l->expire ) ) ) . '</span>';
    if ( ! empty( $l->utm_source ) || ! empty( $l->utm_campaign ) ) $flags .= '<span class="hy-tag" title="UTM tags">UTM</span>';

    ob_start();
    ?>
    <tr data-id="<?php echo esc_attr( (string) $id ); ?>" data-short="<?php echo esc_attr( $short ); ?>" class="<?php echo esc_attr( $expired ? 'is-expired' : '' ); ?>">
        <td class="hy-col-check"><input type="checkbox" value="<?php echo esc_attr( (string) $id ); ?>" data-hy-check aria-label="Select"></td>
        <td class="hy-col-short">
            <div class="hy-short">
                <a href="<?php echo esc_url( $short ); ?>" target="_blank" rel="noopener"><code>/<?php echo esc_html( (string) $l->slug ); ?></code></a>
                <button type="button" class="hy-icon-btn" data-hy-copy="<?php echo esc_attr( $short ); ?>" title="Copy short URL"><span class="dashicons dashicons-admin-page"></span></button>
                <button type="button" class="hy-icon-btn" data-hy-qr="<?php echo esc_attr( $short ); ?>" title="QR code"><span class="dashicons dashicons-screenoptions"></span></button>
            </div>
            <?php if ( $flags !== '' ) echo '<div class="hy-flags">' . wp_kses( $flags, [ 'span' => [ 'class' => true, 'title' => true ] ] ) . '</div>'; ?>
        </td>
        <td class="hy-col-url">
            <div class="hy-url-title"><?php echo esc_html( hydro_link_title( (string) $l->url ) ); ?></div>
            <a class="hy-url" href="<?php echo esc_url( (string) $l->url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( (string) $l->url ); ?>"><?php echo esc_html( (string) $l->url ); ?></a>
        </td>
        <td class="hy-col-num"><strong><?php echo esc_html( hydro_ui_num( (int) $l->clicks ) ); ?></strong></td>
        <td class="hy-col-date"><time datetime="<?php echo esc_attr( (string) $l->created_at ); ?>"><?php echo esc_html( mysql2date( 'Y-m-d', (string) $l->created_at ) ); ?></time></td>
        <td class="hy-col-actions">
            <button type="button" class="hy-btn hy-btn-sm" data-hy-open="<?php echo esc_attr( (string) $id ); ?>"><span class="dashicons dashicons-chart-bar"></span>Details</button>
            <button type="button" class="hy-icon-btn hy-danger" data-hy-delete="<?php echo esc_attr( (string) $id ); ?>" title="Delete"><span class="dashicons dashicons-trash"></span></button>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

/** Side drawer (filled by JS). */
function hydro_links_drawer_html(): void {
    ?>
    <div class="hy-drawer-backdrop" data-hy-close hidden></div>
    <aside class="hy-drawer" id="hy-drawer" aria-hidden="true" aria-labelledby="hy-drawer-title" role="dialog">
        <header class="hy-drawer-head">
            <div>
                <div class="hy-kicker">Shortlink</div>
                <h2 id="hy-drawer-title"><code></code></h2>
            </div>
            <button type="button" class="hy-icon-btn" data-hy-close title="Close"><span class="dashicons dashicons-no-alt"></span></button>
        </header>
        <div class="hy-drawer-body">
            <div class="hy-drawer-kpis">
                <div><strong data-k="total">–</strong><span>lifetime</span></div>
                <div><strong data-k="clicks">–</strong><span>30 days <em data-k="delta"></em></span></div>
                <div><strong data-k="visitors">–</strong><span>visitors 30 d</span></div>
            </div>
            <div class="hy-chart-box hy-chart-sm"><canvas id="hy-drawer-chart"></canvas></div>
            <div class="hy-drawer-split">
                <div><h4>Sources</h4><ul class="hy-mini-list" data-k="refs"></ul></div>
                <div><h4>Countries</h4><ul class="hy-mini-list" data-k="countries"></ul></div>
            </div>
            <p class="hy-muted hy-small" data-k="last"></p>

            <form class="hy-edit-form" data-hy-edit>
                <h4>Edit</h4>
                <input type="hidden" name="id">
                <label class="hy-full"><span>Target URL</span><input class="hy-input" type="url" name="url" required></label>
                <?php hydro_link_fields_html(); ?>
                <div class="hy-edit-actions">
                    <button class="hy-btn hy-btn-primary" type="submit"><span class="dashicons dashicons-saved"></span>Save</button>
                    <button class="hy-btn" type="button" data-hy-check-target><span class="dashicons dashicons-yes-alt"></span>Check target</button>
                    <a class="hy-btn" data-k="analytics" href="#"><span class="dashicons dashicons-chart-area"></span>Analytics</a>
                    <span class="hy-check-result" data-k="check"></span>
                </div>
            </form>

            <div class="hy-qr-box">
                <h4>QR code</h4>
                <div class="hy-qr" data-k="qr"></div>
                <div class="hy-qr-actions">
                    <button type="button" class="hy-btn" data-hy-qr-download="png"><span class="dashicons dashicons-download"></span>PNG</button>
                    <button type="button" class="hy-btn" data-hy-qr-download="svg"><span class="dashicons dashicons-download"></span>SVG</button>
                </div>
            </div>
        </div>
    </aside>
    <?php
}

/* ------------------------------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------------------------------- */

function hydro_ajax_link_get(): void {
    check_ajax_referer( 'hydro_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
    $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $link = hydro_get_link( $id );
    if ( ! $link ) wp_send_json_error( 'Link not found.', 404 );

    $stats = hydro_link_stats( $id );
    wp_send_json_success( [
        'link'  => [
            'id'            => (int) $link->id,
            'slug'          => (string) $link->slug,
            'short'         => hydro_short_url( $link ),
            'url'           => (string) $link->url,
            'redirect_type' => (int) $link->redirect_type,
            'expire'        => $link->expire ? str_replace( ' ', 'T', substr( (string) $link->expire, 0, 16 ) ) : '',
            'password'      => (string) ( $link->password ?? '' ),
            'utm_source'    => (string) ( $link->utm_source ?? '' ),
            'utm_medium'    => (string) ( $link->utm_medium ?? '' ),
            'utm_campaign'  => (string) ( $link->utm_campaign ?? '' ),
            'clicks'        => (int) $link->clicks,
            'created'       => (string) $link->created_at,
        ],
        'stats' => $stats,
        'analytics' => admin_url( 'admin.php?page=hydro-analytics&link_id=' . $id ),
    ] );
}

function hydro_ajax_link_update(): void {
    check_ajax_referer( 'hydro_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
    $id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $url = isset( $_POST['url'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['url'] ) ) ) ) : '';
    if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) wp_send_json_error( 'Please enter a full URL (http:// or https://).', 400 );
    $fields = hydro_link_fields_from_request( wp_unslash( $_POST ) );
    if ( is_wp_error( $fields ) ) wp_send_json_error( $fields->get_error_message(), 400 );
    $ok = hydro_update_link( $id, $url, $fields );
    if ( is_wp_error( $ok ) ) wp_send_json_error( $ok->get_error_message(), 400 );
    wp_send_json_success( [ 'html' => hydro_links_row_html( hydro_get_link( $id ) ) ] );
}

function hydro_ajax_link_delete(): void {
    check_ajax_referer( 'hydro_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
    $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];
    $ids = array_slice( array_filter( $ids ), 0, 500 );
    if ( ! $ids ) wp_send_json_error( 'Nothing selected.', 400 );
    delete_transient( 'hydro_top_ids' );
    wp_send_json_success( [ 'deleted' => hydro_delete_links( $ids ), 'ids' => $ids ] );
}

/** Checks whether the target URL answers (HEAD, falls back to GET). */
function hydro_ajax_link_check(): void {
    check_ajax_referer( 'hydro_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
    $link = hydro_get_link( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    if ( ! $link ) wp_send_json_error( 'Link not found.', 404 );
    $args = [ 'timeout' => 8, 'redirection' => 5, 'user-agent' => 'Hydro-Link-Check/' . HYDRO_VERSION . '; ' . home_url() ];
    $res  = wp_safe_remote_head( (string) $link->url, $args );
    $code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
    if ( $code === 405 || $code === 403 || $code === 0 ) {
        $res  = wp_safe_remote_get( (string) $link->url, $args + [ 'limit_response_size' => 65536 ] );
        $code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
    }
    wp_send_json_success( [
        'code'  => $code,
        'ok'    => $code >= 200 && $code < 400,
        'error' => is_wp_error( $res ) ? $res->get_error_message() : '',
    ] );
}

/* ------------------------------------------------------------------------------------------------
 * CSV export / import
 * ---------------------------------------------------------------------------------------------- */

/** Streams all (or the filtered) links as CSV, 5,000 rows per query by primary key. */
function hydro_links_export(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    check_admin_referer( 'hydro_links_export' );
    $search = hydro_links_get_param( 's' );
    $filter = hydro_links_get_param( 'filter' );
    $filter = ( isset( HYDRO_FILTERS[ $filter ] ) && $filter !== 'top' ) ? $filter : '';

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="hydro-links-' . gmdate( 'Ymd-His' ) . '.csv"' );
    $out = new SplFileObject( 'php://output', 'w' );
    $out->fwrite( "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel
    $out->fputcsv( [ 'id', 'short_url', 'slug', 'url', 'redirect_type', 'clicks', 'created_at', 'expire', 'password', 'utm_source', 'utm_medium', 'utm_campaign' ] );

    $cursor = PHP_INT_MAX;
    do {
        $rows = hydro_links_query( $search, $filter, 5000, 0, $cursor );
        foreach ( $rows as $r ) {
            $out->fputcsv( [ $r->id, hydro_short_url( $r ), $r->slug, $r->url, $r->redirect_type, $r->clicks, $r->created_at, $r->expire, $r->password, $r->utm_source, $r->utm_medium, $r->utm_campaign ] );
            $cursor = (int) $r->id;
        }
        flush();
    } while ( count( $rows ) === 5000 );
    exit;
}

function hydro_links_import(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    check_admin_referer( 'hydro_links_import' );
    $back = admin_url( 'admin.php?page=hydro-links' );

    $tmp = isset( $_FILES['csv']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['csv']['tmp_name'] ) ) : '';
    if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
        hydro_ui_flash( 'No file uploaded.', 'error' );
        wp_safe_redirect( $back ); exit;
    }
    try {
        $fh = new SplFileObject( $tmp, 'r' );
    } catch ( RuntimeException $e ) {
        hydro_ui_flash( 'File could not be read.', 'error' ); wp_safe_redirect( $back ); exit;
    }

    $cols    = [ 'url', 'slug', 'redirect_type', 'expire', 'password', 'utm_source', 'utm_medium', 'utm_campaign' ];
    $map     = null;
    $n       = 0;
    $created = $skipped = $failed = 0;
    $first   = (string) $fh->fgets();
    $delim   = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
    $fh->rewind();

    while ( ! $fh->eof() && $n < 5000 ) {
        $row = $fh->fgetcsv( $delim );
        if ( ! is_array( $row ) || $row === [ null ] ) continue;
        $row = array_map( static fn( $v ) => trim( (string) $v, " \t\n\r\0\x0B\xEF\xBB\xBF" ), $row );
        if ( $map === null ) {
            $lower = array_map( 'strtolower', $row );
            if ( in_array( 'url', $lower, true ) ) { $map = array_flip( $lower ); continue; }
            $map = array_flip( $cols );
        }
        $n++;
        $get = static fn( string $k ) => isset( $map[ $k ], $row[ $map[ $k ] ] ) ? $row[ $map[ $k ] ] : '';
        $raw = $get( 'url' );
        $url = preg_match( '#^https?://[^\s/]+\.[^\s]+$#i', $raw ) ? esc_url_raw( $raw ) : '';
        if ( ! $url ) { $failed++; continue; }

        $slug = $get( 'slug' );
        if ( $slug === '' && hydro_find_link_by_url( $url, false ) ) { $skipped++; continue; }

        $fields = hydro_link_fields_from_request( [
            'redirect_type' => $get( 'redirect_type' ), 'expire' => $get( 'expire' ), 'password' => $get( 'password' ),
            'utm_source' => $get( 'utm_source' ), 'utm_medium' => $get( 'utm_medium' ), 'utm_campaign' => $get( 'utm_campaign' ),
        ] );
        if ( is_wp_error( $fields ) ) { $failed++; continue; }
        $id = hydro_insert_link( $url, $slug, $fields );
        is_wp_error( $id ) ? ( $id->get_error_code() === 'slug_exists' ? $skipped++ : $failed++ ) : $created++;
    }
    $fh = null;
    delete_transient( 'hydro_top_ids' );

    $msg = sprintf( 'Import finished: %d created, %d skipped (already existing), %d invalid.', $created, $skipped, $failed );
    if ( $n >= 5000 ) $msg .= ' The file had more than 5,000 rows – please import the rest in a second file.';
    hydro_ui_flash( $msg, $failed ? 'warning' : 'success' );
    wp_safe_redirect( $back );
    exit;
}
