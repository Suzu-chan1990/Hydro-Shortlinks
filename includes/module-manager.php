<?php
/**
 * Hydro module manager.
 * Modules are files modules/module-<slug>.php with a "Module Name:" header.
 * Option `hydro_enabled_modules` (array of slugs) is unchanged from v2.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', 'hydro_modules_handle_save' );
add_action( 'plugins_loaded', 'hydro_load_active_modules', 25 );

/** Icons for the known modules. */
function hydro_module_icon( string $slug ): string {
    return [
        'api'          => 'dashicons-rest-api',
        'social-cards' => 'dashicons-format-image',
        'social-meta'  => 'dashicons-share',
        'geo'          => 'dashicons-location-alt',
        'device'       => 'dashicons-smartphone',
    ][ $slug ] ?? 'dashicons-admin-plugins';
}

function hydro_modules_handle_save(): void {
    if ( empty( $_POST['hydro_modules_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hydro_modules_nonce'] ) ), 'hydro_modules_save' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $known  = array_keys( hydro_scan_modules() );
    $posted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] )
        ? array_map( 'sanitize_text_field', wp_unslash( $_POST['modules'] ) )
        : [];
    $posted = array_values( array_intersect( $posted, $known ) );

    update_option( 'hydro_enabled_modules', $posted );
    if ( function_exists( 'hydro_ui_flash' ) ) hydro_ui_flash( 'Extensions saved.' );

    wp_safe_redirect( admin_url( 'admin.php?page=hydro-extensions' ) );
    exit;
}

/** Reads the module headers. Modules flagged "Merged Into Core: yes" are hidden. */
function hydro_scan_modules( bool $include_merged = false ): array {
    $dir = HYDRO_DIR . 'modules/';
    $out = [];
    $files = is_dir( $dir ) ? glob( $dir . 'module-*.php' ) : false;
    if ( ! $files ) return $out;

    foreach ( $files as $file ) {
        $head = (string) file_get_contents( $file, false, null, 0, 2048 );
        $slug = str_replace( [ 'module-', '.php' ], '', basename( $file ) );
        $meta = [ 'file' => basename( $file ), 'slug' => $slug, 'name' => $slug, 'description' => '', 'version' => '', 'merged' => false ];
        if ( preg_match( '/Module Name:\s*(.+)/i', $head, $m ) ) $meta['name'] = trim( $m[1] );
        if ( preg_match( '/Description:\s*(.+)/i', $head, $m ) ) $meta['description'] = trim( $m[1] );
        if ( preg_match( '/Version:\s*(.+)/i', $head, $m ) ) $meta['version'] = trim( $m[1] );
        if ( preg_match( '/Merged Into Core:\s*yes/i', $head ) ) $meta['merged'] = true;
        if ( $meta['merged'] && ! $include_merged ) continue;
        $out[ $slug ] = $meta;
    }
    ksort( $out );
    return $out;
}

function hydro_modules_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $modules = hydro_scan_modules();
    $enabled = (array) get_option( 'hydro_enabled_modules', [] );

    hydro_ui_open( 'hydro-extensions', 'Extensions' );
    ?>
    <form method="post">
        <?php wp_nonce_field( 'hydro_modules_save', 'hydro_modules_nonce' ); ?>
        <div class="hy-section-head">
            <div>
                <h2>Extensions</h2>
                <p class="hy-muted">Optional modules for Hydro. Each one lives in <code>modules/module-*.php</code> and only loads when it is switched on.</p>
            </div>
            <button class="hy-btn hy-btn-primary" type="submit"><span class="dashicons dashicons-saved"></span>Save</button>
        </div>
        <?php if ( ! $modules ) : ?>
            <div class="hy-card"><p class="hy-empty">No modules found.</p></div>
        <?php else : ?>
            <div class="hy-modules">
                <?php foreach ( $modules as $slug => $m ) :
                    $on = in_array( $slug, $enabled, true ); ?>
                    <label class="hy-module<?php echo $on ? ' is-on' : ''; ?>">
                        <span class="hy-module-icon"><span class="dashicons <?php echo esc_attr( hydro_module_icon( $slug ) ); ?>"></span></span>
                        <span class="hy-module-info">
                            <span class="hy-module-name"><?php echo esc_html( $m['name'] ); ?></span>
                            <span class="hy-module-desc"><?php echo esc_html( $m['description'] ); ?></span>
                            <span class="hy-module-meta"><code><?php echo esc_html( $m['file'] ); ?></code><?php if ( $m['version'] ) : ?> · v<?php echo esc_html( $m['version'] ); ?><?php endif; ?></span>
                        </span>
                        <span class="hy-switch"><input type="checkbox" name="modules[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $on ); ?>><span class="hy-slider"></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="hy-muted hy-small">Analytics and Advanced Analytics are part of the core since Hydro 3.0 and no longer appear here.</p>
    </form>
    <?php
    hydro_ui_close();
}

function hydro_load_active_modules(): void {
    $enabled = get_option( 'hydro_enabled_modules', [] );
    if ( ! is_array( $enabled ) || empty( $enabled ) ) return;

    $dir = HYDRO_DIR . 'modules/';
    foreach ( $enabled as $slug ) {
        $slug = preg_replace( '/[^a-z0-9_-]/i', '', (string) $slug );
        $file = $dir . 'module-' . $slug . '.php';
        if ( $slug !== '' && file_exists( $file ) ) {
            include_once $file;
        }
    }
}
