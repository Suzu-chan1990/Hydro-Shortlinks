<?php
/**
 * Admin shell in the Tegatai design: dark gradient header with version badge,
 * inner tab navigation, card grid. All Hydro pages are registered here.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'hydro_ui_menu', 9 );
add_action( 'admin_enqueue_scripts', 'hydro_ui_enqueue' );
add_filter( 'admin_body_class', 'hydro_ui_body_class' );

/** Pages: slug => [menu title, icon, callback]. */
function hydro_ui_pages(): array {
    return apply_filters( 'hydro_admin_pages', [
        'hydro-shortlinks' => [ 'Dashboard', 'dashicons-dashboard', 'hydro_dashboard_page' ],
        'hydro-links'      => [ 'Links', 'dashicons-admin-links', 'hydro_links_page' ],
        'hydro-analytics'  => [ 'Analytics', 'dashicons-chart-area', 'hydro_analytics_page' ],
        'hydro-extensions' => [ 'Extensions', 'dashicons-screenoptions', 'hydro_modules_admin_page' ],
        'hydro-settings'   => [ 'Settings', 'dashicons-admin-generic', 'hydro_admin_settings_page' ],
    ] );
}

function hydro_ui_menu(): void {
    add_menu_page( 'Hydro Shortlinks', 'Hydro Shortlinks', 'manage_options', 'hydro-shortlinks', 'hydro_dashboard_page', 'dashicons-admin-links', 60 );
    foreach ( hydro_ui_pages() as $slug => [ $title, , $cb ] ) {
        add_submenu_page( 'hydro-shortlinks', 'Hydro ' . $title, $title, 'manage_options', $slug, $cb );
    }
}

function hydro_ui_is_hydro_screen(): bool {
    // Only decides whether to load the Hydro assets – read-only, no nonce involved.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return $page !== '' && str_starts_with( $page, 'hydro' );
}

function hydro_ui_body_class( string $classes ): string {
    return hydro_ui_is_hydro_screen() ? $classes . ' hydro-screen' : $classes;
}

function hydro_ui_enqueue(): void {
    if ( ! hydro_ui_is_hydro_screen() ) return;
    $v = HYDRO_VERSION;
    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style( 'hydro-ui', HYDRO_URL . 'assets/css/hydro-ui.css', [], $v );
    wp_enqueue_script( 'hydro-chart', HYDRO_URL . 'assets/js/chart.min.js', [], '4.5.1', true );
    wp_enqueue_script( 'hydro-qrcode', HYDRO_URL . 'assets/js/qrcode.js', [], '1.4.4', true );
    wp_enqueue_script( 'hydro-ui', HYDRO_URL . 'assets/js/hydro-ui.js', [ 'hydro-chart', 'hydro-qrcode' ], $v, true );
    wp_localize_script( 'hydro-ui', 'HydroUI', [
        'ajax'     => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'hydro_ajax_nonce' ),
        'home'     => home_url( '/' ),
        'linksUrl' => admin_url( 'admin.php?page=hydro-links' ),
        'anaUrl'   => admin_url( 'admin.php?page=hydro-analytics' ),
        'site'     => get_bloginfo( 'name' ),
    ] );
}

/** Opens the page shell. */
function hydro_ui_open( string $active, string $subtitle = '', string $actions_html = '' ): void {
    $pages = hydro_ui_pages();
    ?>
    <div class="wrap hydro-wrap">
        <h1 class="screen-reader-text">Hydro Shortlinks</h1>
        <header class="hy-header hy-header-brand">
            <div class="hy-title hy-brand">
                <img class="hy-banner" src="<?php echo esc_url( HYDRO_URL . 'assets/banner-admin.png' ); ?>" width="638" height="280" alt="Hydro Shortlinks">
            </div>
            <div class="hy-head-right">
                <?php if ( $subtitle !== '' ) : ?><span class="hy-sub"><?php echo esc_html( $subtitle ); ?></span><?php endif; ?>
                <?php echo $actions_html; // phpcs:ignore -- built by the page with escaping ?>
                <span class="hy-badge">v<?php echo esc_html( HYDRO_VERSION ); ?></span>
            </div>
        </header>
        <nav class="hy-nav" aria-label="Hydro">
            <?php foreach ( $pages as $slug => [ $title, $icon ] ) : ?>
                <a class="hy-tab<?php echo $slug === $active ? ' active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"<?php echo $slug === $active ? ' aria-current="page"' : ''; ?>>
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span><?php echo esc_html( $title ); ?>
                </a>
            <?php endforeach; ?>
            <form class="hy-nav-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
                <input type="hidden" name="page" value="hydro-links">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <input type="search" name="s" placeholder="Find slug or URL…" aria-label="Find link">
            </form>
        </nav>
        <div class="hy-notices"><?php hydro_ui_flash_render(); ?></div>
    <?php
}

function hydro_ui_close(): void {
    echo '<div id="hy-toast" role="status" aria-live="polite"></div></div>';
}

/** Card helper. */
function hydro_ui_card_open( string $title, string $icon = '', string $class = '', string $extra = '' ): void {
    echo '<section class="hy-card ' . esc_attr( $class ) . '"><h3>';
    if ( $icon !== '' ) echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
    echo esc_html( $title ) . '</h3>' . $extra; // phpcs:ignore
}

function hydro_ui_card_close(): void {
    echo '</section>';
}

/** Switch (Tegatai style). */
function hydro_ui_switch( string $name, bool $checked, string $label, string $desc = '' ): void {
    ?>
    <div class="hy-switch-row">
        <div>
            <div class="hy-switch-label"><?php echo esc_html( $label ); ?></div>
            <?php if ( $desc !== '' ) : ?><div class="hy-desc"><?php echo wp_kses( $desc, [ 'code' => [], 'strong' => [], 'br' => [] ] ); ?></div><?php endif; ?>
        </div>
        <label class="hy-switch"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>><span class="hy-slider"></span></label>
    </div>
    <?php
}

/** One-shot notices across a redirect (POST → redirect → GET). */
function hydro_ui_flash( string $msg, string $type = 'success' ): void {
    set_transient( 'hydro_flash_' . get_current_user_id(), [ 'msg' => $msg, 'type' => $type ], 60 );
}

function hydro_ui_flash_render(): void {
    $key = 'hydro_flash_' . get_current_user_id();
    $f   = get_transient( $key );
    if ( ! is_array( $f ) ) return;
    delete_transient( $key );
    printf( '<div class="hy-alert hy-alert-%s">%s</div>', esc_attr( $f['type'] ), esc_html( $f['msg'] ) );
}

function hydro_ui_num( int|float $n ): string {
    return number_format_i18n( $n );
}

/** "+12.5 %" chip comparing two numbers. */
function hydro_ui_delta( int|float $now, int|float $before ): string {
    if ( $before <= 0 ) return $now > 0 ? '<span class="hy-delta up">new</span>' : '<span class="hy-delta">±0</span>';
    $p   = ( $now - $before ) / $before * 100;
    $cls = $p > 0.05 ? 'up' : ( $p < -0.05 ? 'down' : '' );
    return sprintf( '<span class="hy-delta %s">%s%s %%</span>', $cls, $p > 0 ? '+' : '', esc_html( number_format_i18n( $p, 1 ) ) );
}
