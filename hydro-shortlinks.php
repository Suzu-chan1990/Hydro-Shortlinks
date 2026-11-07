<?php
/**
 * Plugin Name: Hydro Shortlinks
 * Description: Shortlink manager with dashboard, link management, analytics, QR codes and a module system.
 * Version: 3.1.0
 * Author: Saguya
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hydro-shortlinks
 * Requires at least: 7.1
 * Requires PHP: 8.4
 * Update URI: https://github.com/Suzu-chan1990/Hydro-Shortlinks
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

define( 'HYDRO_VERSION', '3.1.0' );
define( 'HYDRO_FILE', __FILE__ );
define( 'HYDRO_DIR', __DIR__ . '/' );
define( 'HYDRO_URL', plugin_dir_url( __FILE__ ) );

// =================================================================
// SETTINGS HELPER
// =================================================================
// Settings live in wp_options unless an optional {prefix}hydro_settings table exists.
// The table check runs once per request.

function hydro_settings_table(): ?string {
    static $table = false;
    if ( $table === false ) {
        global $wpdb;
        $name  = $wpdb->prefix . 'hydro_settings';
        $table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name ) ? $name : null;
    }
    return $table;
}

function hydro_get_setting( string $key, mixed $default = false ): mixed {
    $table = hydro_settings_table();
    if ( $table === null ) {
        return get_option( $key, $default );
    }
    global $wpdb;
    $value = $wpdb->get_var( $wpdb->prepare( 'SELECT setting_value FROM %i WHERE setting_key = %s', $table, $key ) );
    return ( $value !== null ) ? maybe_unserialize( $value ) : $default;
}

function hydro_update_setting( string $key, mixed $value ): bool {
    $table = hydro_settings_table();
    if ( $table === null ) {
        return update_option( $key, $value );
    }
    global $wpdb;
    $wpdb->replace( $table, [ 'setting_key' => $key, 'setting_value' => maybe_serialize( $value ) ], [ '%s', '%s' ] );
    return true;
}

/** Table names in one place. */
function hydro_table( string $which ): string {
    global $wpdb;
    return match ( $which ) {
        'links'  => $wpdb->prefix . 'shortlinks',
        'clicks' => $wpdb->prefix . 'shortlink_clicks',
        'index'  => $wpdb->prefix . 'hydro_url_index',
        'daily'  => $wpdb->prefix . 'hydro_stats_daily',
        default  => $wpdb->prefix . 'shortlinks',
    };
}

/** Configured slug length (minimum 4). */
function hydro_slug_length(): int {
    return max( 4, min( 10, (int) hydro_get_setting( 'default_slug_length', 4 ) ) );
}

/** Random slug without look-alike characters (no I, O, l, o). */
function hydro_generate_slug( int $len = 0 ): string {
    $len   = $len > 0 ? $len : hydro_slug_length();
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz0123456789';
    $max   = strlen( $chars ) - 1;
    $slug  = '';
    for ( $i = 0; $i < $len; $i++ ) {
        $slug .= $chars[ wp_rand( 0, $max ) ];
    }
    return $slug;
}

/** True if the slug can be served by the redirect handler (max 10 chars, no "/" or "."). */
function hydro_slug_is_valid( string $slug ): bool {
    return $slug !== '' && strlen( $slug ) <= 10 && (bool) preg_match( '/^[A-Za-z0-9_-]+$/', $slug );
}

/** Number of clicks still waiting for a country (uses the `country` index). */
function hydro_geo_pending(): int {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE country IS NULL', hydro_table( 'clicks' ) ) );
}

/**
 * Resolves countries for up to $limit distinct IPs per run with the local GeoIP database.
 *
 * Every selected IP gets a value in the same run: the country code, or '--' when the address is
 * invalid, private or unknown. No row is ever left behind, so the backlog cannot get stuck on
 * the same first rows. Without a database nothing is selected (no external lookups).
 */
function hydro_geo_batch( int $limit = 1000 ): int {
    global $wpdb;
    if ( ! hydro_geoip_available() ) {
        update_option( 'hydro_geo_last_run', [ 'time' => time(), 'resolved' => 0, 'error' => 'No GeoIP database yet (Settings → Privacy & data).' ], false );
        return 0;
    }
    $ips = $wpdb->get_col( $wpdb->prepare(
        'SELECT DISTINCT ip_address FROM %i WHERE country IS NULL LIMIT %d',
        hydro_table( 'clicks' ), $limit
    ) );
    if ( empty( $ips ) ) {
        update_option( 'hydro_geo_last_run', [ 'time' => time(), 'resolved' => 0 ], false );
        return 0;
    }

    $done = 0;
    foreach ( $ips as $ip ) {
        // get_col() returns NULL for empty strings as well, so NULL stands for "no IP stored"
        $code = ( $ip === null || $ip === '' ) ? '--' : hydro_geo_country( (string) $ip );
        if ( $code === '' ) break; // database vanished during the run
        // Only rows that still have no country: uses the `country` index and leaves resolved rows untouched.
        if ( $ip === null || $ip === '' ) {
            $wpdb->query( $wpdb->prepare( "UPDATE %i SET country = %s WHERE country IS NULL AND ( ip_address IS NULL OR ip_address = '' )", hydro_table( 'clicks' ), $code ) );
        } else {
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET country = %s WHERE country IS NULL AND ip_address = %s', hydro_table( 'clicks' ), $code, (string) $ip ) );
        }
        $done++;
    }
    update_option( 'hydro_geo_last_run', [ 'time' => time(), 'resolved' => $done ], false );
    return $done;
}

/**
 * Shortens an IP address before it is stored: IPv4 to the /24 network (last octet 0),
 * IPv6 to the /64 network (interface identifier zeroed). Anything else is not stored.
 * $strong (CSV export option): IPv4 /16, IPv6 /48.
 */
function hydro_anonymize_ip( string $ip, bool $strong = false ): string {
    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
        return (string) preg_replace( $strong ? '/\.\d+\.\d+$/' : '/\.\d+$/', $strong ? '.0.0' : '.0', $ip );
    }
    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
        $bin = inet_pton( $ip );
        if ( $bin === false ) return '';
        $keep = $strong ? 6 : 8;
        $net  = inet_ntop( substr( $bin, 0, $keep ) . str_repeat( "\0", 16 - $keep ) );
        return $net === false ? '' : $net;
    }
    return '';
}

/**
 * Referrer as stored: only scheme and host ("https://example.com"). Paths and query strings can
 * hold personal data (search terms, tokens, IDs) and are not used by the statistics.
 */
function hydro_referrer_origin( string $referrer ): string {
    $referrer = trim( $referrer );
    if ( $referrer === '' ) return '';
    $parts = wp_parse_url( $referrer );
    if ( ! is_array( $parts ) || empty( $parts['host'] ) ) return '';
    $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
    return substr( $scheme . '://' . strtolower( (string) $parts['host'] ), 0, 255 );
}

/** True if a stored link password is a hash (password_hash()) and not an old plain-text value. */
function hydro_password_is_hash( string $stored ): bool {
    return $stored !== '' && password_get_info( $stored )['algo'] !== null;
}

/** Hashes a link password for storage. */
function hydro_password_hash( string $plain ): string {
    return password_hash( $plain, PASSWORD_DEFAULT );
}

/**
 * Checks the password given by a visitor. Old plain-text values (before 3.1.0) are still
 * accepted and replaced by a hash on the first correct use.
 */
function hydro_password_check( object $link, string $given ): bool {
    global $wpdb;
    $stored = (string) $link->password;
    // Passwords were stored after sanitize_text_field(); accept the raw and the sanitized form
    $candidates = array_unique( [ $given, sanitize_text_field( $given ) ] );

    if ( hydro_password_is_hash( $stored ) ) {
        foreach ( $candidates as $c ) {
            if ( password_verify( $c, $stored ) ) return true;
        }
        return false;
    }
    foreach ( $candidates as $c ) {
        if ( hash_equals( $stored, $c ) ) {
            $wpdb->update( hydro_table( 'links' ), [ 'password' => hydro_password_hash( $stored ) ], [ 'id' => (int) $link->id ] );
            return true;
        }
    }
    return false;
}

class Hydro_Shortlinks {

    private string $table_links;
    private string $table_clicks;
    private string $opt_enable_tracking = 'hydro_enable_tracking';

    public function __construct() {
        $this->table_links  = hydro_table( 'links' );
        $this->table_clicks = hydro_table( 'clicks' );

        register_activation_hook( HYDRO_FILE, [ $this, 'create_tables' ] );
        register_deactivation_hook( HYDRO_FILE, [ $this, 'deactivate_cron' ] );


        // Ajax endpoint kept under its old name (used by the admin UI).
        add_action( 'wp_ajax_hydro_ajax_create_shortlink', [ $this, 'ajax_create_shortlink' ] );

        // Redirect handling
        add_action( 'init', [ $this, 'handle_redirect' ], 1 );

        // Cron for batch geo-IP lookups
        add_action( 'hydro_geo_batch_event', [ $this, 'process_geo_batch' ] );
    }

    public function deactivate_cron(): void {
        wp_clear_scheduled_hook( 'hydro_geo_batch_event' );
        wp_clear_scheduled_hook( 'hydro_url_index_event' );
        wp_clear_scheduled_hook( 'hydro_retention_event' );
        wp_clear_scheduled_hook( 'hydro_rollup_event' );
        wp_clear_scheduled_hook( 'hydro_geoip_update_event' );
        wp_clear_scheduled_hook( 'hydro_privacy_cleanup_event' );
    }

    // Local lookups need no rate limit: several batches per run, at most ~20 seconds
    public function process_geo_batch(): void {
        $start = microtime( true );
        for ( $i = 0; $i < 20 && ( microtime( true ) - $start ) < 20; $i++ ) {
            if ( hydro_geo_batch( 1000 ) < 1000 ) break;
        }
    }

    /**
     * Creates the tables on a fresh install only.
     *
     * Existing tables are never passed to dbDelta(): with ~1M links and ~2M clicks any
     * schema difference would otherwise turn a plugin re-activation into an ALTER TABLE
     * on the live tables. New features only ever add new tables (see includes/schema.php).
     */
    public function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $exists  = static fn( string $t ): bool => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t;

        $sql_links = "CREATE TABLE {$this->table_links} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(10) NOT NULL,
            url TEXT NOT NULL,
            redirect_type SMALLINT(3) NOT NULL DEFAULT 302,
            clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            password VARCHAR(200) DEFAULT NULL,
            expire DATETIME DEFAULT NULL,
            utm_source VARCHAR(255) DEFAULT NULL,
            utm_medium VARCHAR(255) DEFAULT NULL,
            utm_campaign VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;";

        $sql_clicks = "CREATE TABLE {$this->table_clicks} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            shortlink_id BIGINT(20) UNSIGNED NOT NULL,
            click_time DATETIME NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            country VARCHAR(2) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            referrer TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY shortlink_id (shortlink_id),
            KEY click_time (click_time),
            KEY country (country)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if ( ! $exists( $this->table_links ) ) {
            dbDelta( $sql_links );
        }
        if ( ! $exists( $this->table_clicks ) ) {
            dbDelta( $sql_clicks );
        }

        if ( get_option( $this->opt_enable_tracking ) === false ) {
            update_option( $this->opt_enable_tracking, '1' );
        }

        if ( ! wp_next_scheduled( 'hydro_geo_batch_event' ) ) {
            wp_schedule_event( time(), 'hourly', 'hydro_geo_batch_event' );
        }

        // Additive tables (URL index) – CREATE TABLE IF NOT EXISTS only.
        if ( function_exists( 'hydro_schema_upgrade' ) ) {
            hydro_schema_upgrade();
        }
    }

    /**
     * Creates a shortlink from the admin UI.
     * Accepts url, slug, redirect_type, expire (Y-m-d\TH:i), password, utm_source/medium/campaign.
     */
    public function ajax_create_shortlink(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }
        if ( empty( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ajax_nonce'] ) ), 'hydro_ajax_nonce' ) ) {
            wp_send_json_error( 'bad nonce', 400 );
        }

        $url = isset( $_POST['url'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['url'] ) ) ) ) : '';
        if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) wp_send_json_error( 'Please enter a full URL (http:// or https://).', 400 );

        $fields = hydro_link_fields_from_request( wp_unslash( $_POST ) );
        if ( is_wp_error( $fields ) ) wp_send_json_error( $fields->get_error_message(), 400 );

        $slug = isset( $_POST['slug'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
        $result = hydro_insert_link( $url, $slug, $fields );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message(), $result->get_error_code() === 'slug_exists' ? 409 : 400 );
        }

        $link = hydro_get_link( $result );
        wp_send_json_success( [
            'id'    => $result,
            'slug'  => $link->slug,
            'short' => home_url( '/' . $link->slug ),
            'html'  => function_exists( 'hydro_links_row_html' ) ? hydro_links_row_html( $link ) : '',
        ] );
    }

    public function handle_redirect(): void {
        if ( is_admin() ) return;
        global $wpdb;

        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
        if ( $path === '' ) return;

        // A slug is at most 10 characters (VARCHAR(10)) and never contains "/" or ".".
        // Skipping everything else saves one database query on every normal page view.
        if ( strlen( $path ) > 10 || strpbrk( $path, '/.' ) !== false ) return;

        $link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s LIMIT 1', $this->table_links, $path ) );
        if ( ! $link ) return;

        if ( $link->expire && current_time( 'mysql' ) > $link->expire ) {
            wp_die( 'Link expired.', 'Link expired', [ 'response' => 410 ] );
        }
        // Public shortlink: visitors cannot carry a nonce. The value is only compared, never stored or output.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $pw = isset( $_GET['pw'] ) && is_string( $_GET['pw'] ) ? (string) wp_unslash( $_GET['pw'] ) : null;
        if ( $link->password && ( $pw === null || ! hydro_password_check( $link, $pw ) ) ) {
            wp_die( 'Password required.', 'Password required', [ 'response' => 403 ] );
        }

        $redirect_url = (string) $link->url;

        // UTM parameters are added first, so modules that output their own page (social cards)
        // forward to the same final URL as a normal redirect.
        $params = [];
        if ( ! empty( $link->utm_source ) ) $params['utm_source'] = $link->utm_source;
        if ( ! empty( $link->utm_medium ) ) $params['utm_medium'] = $link->utm_medium;
        if ( ! empty( $link->utm_campaign ) ) $params['utm_campaign'] = $link->utm_campaign;
        if ( ! empty( $params ) ) $redirect_url = add_query_arg( $params, $redirect_url );

        // IMPORTANT: tracking must happen before the module filter,
        // because modules (e.g. social cards) may end the request with exit.
        $tracking = get_option( $this->opt_enable_tracking, '1' ) === '1';
        $ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $is_bot   = preg_match( '/bot|crawl|slurp|spider|mediapartners/i', $ua );

        if ( $tracking && ! $is_bot ) {
            $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

            // GDPR: shorten the IP address before it is stored (IPv4 /24, IPv6 /64)
            $ip = hydro_anonymize_ip( $ip );

            $wpdb->insert( $this->table_clicks, [
                'shortlink_id' => $link->id,
                'click_time'   => current_time( 'mysql' ),
                'ip_address'   => $ip,
                'user_agent'   => $ua,
                // Domain only: full referrer URLs can carry personal data in path or query
                'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? hydro_referrer_origin( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '',
            ] );
            // Atomic increment: concurrent clicks never overwrite each other's count.
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET clicks = clicks + 1 WHERE id = %d', $this->table_links, (int) $link->id ) );
        }

        // Allow modules to alter the redirect URL (or render HTML and exit).
        $redirect_url = (string) apply_filters( 'hydro_shortlink_redirect_url', $redirect_url, $link );

        // A shortlink must be able to point to any external site, so wp_safe_redirect() (own host only) cannot be used.
        // The target URL can only be set by administrators (manage_options) and is stored with esc_url_raw().
        wp_redirect( $redirect_url, (int) $link->redirect_type ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }
}

require_once HYDRO_DIR . 'includes/geoip.php';
require_once HYDRO_DIR . 'includes/schema.php';
require_once HYDRO_DIR . 'includes/links.php';
require_once HYDRO_DIR . 'includes/report.php';
require_once HYDRO_DIR . 'includes/module-manager.php';

if ( is_admin() ) {
    require_once HYDRO_DIR . 'includes/admin-ui.php';
    require_once HYDRO_DIR . 'includes/admin-dashboard.php';
    require_once HYDRO_DIR . 'includes/admin-links.php';
    require_once HYDRO_DIR . 'includes/admin-analytics.php';
    require_once HYDRO_DIR . 'includes/admin-page-settings.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once HYDRO_DIR . 'includes/cli.php';
}

new Hydro_Shortlinks();

// =================================================================
// GITHUB UPDATER (Plugin Update Checker)
// =================================================================
// Updates come from the GitHub releases of Suzu-chan1990/Hydro-Shortlinks.
// Loaded in the admin, during WP-Cron (background update checks) and in WP-CLI –
// never on normal frontend requests, so redirects stay as lean as before.
add_action( 'init', 'hydro_github_updater' );

function hydro_github_updater(): void {
    // Build the checker only once – a second instance with the same slug is a fatal error in PUC.
    static $built = false;
    if ( $built ) {
        return;
    }
    if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return;
    }
    $puc = HYDRO_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $puc ) ) {
        return; // Library missing – the plugin keeps working without updates.
    }
    require_once $puc;
    $built = true;

    $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/Hydro-Shortlinks/',
        HYDRO_FILE,
        'hydro-shortlinks'
    );
    // Use the ZIP attached to a GitHub release (falls back to the tag's source ZIP).
    $checker->getVcsApi()->enableReleaseAssets();
}
