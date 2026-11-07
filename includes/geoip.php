<?php
/**
 * Local country lookup (no external request per click or per IP).
 *
 * Database: "IP to Country Lite" by DB-IP (https://db-ip.com), CC BY 4.0, MaxMind DB format.
 * Read with the bundled MaxMind DB reader (lib/maxmind-db-reader, Apache-2.0).
 *
 * The file is stored in the uploads folder (wp-content/uploads/hydro-geoip/), not in the plugin
 * folder: WordPress deletes the plugin folder on every update. A daily job downloads a new
 * monthly edition when the local file is older than 30 days. The only external request is this
 * download from download.db-ip.com; IP addresses never leave the server.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'hydro_geoip_update_event', 'hydro_geoip_maybe_update' );

const HYDRO_GEOIP_FILE = 'dbip-country-lite.mmdb';
const HYDRO_GEOIP_MAX_AGE = 30 * 86400;

function hydro_geoip_dir(): string {
    $up = wp_upload_dir( null, false );
    return trailingslashit( (string) $up['basedir'] ) . 'hydro-geoip';
}

function hydro_geoip_path(): string {
    return hydro_geoip_dir() . '/' . HYDRO_GEOIP_FILE;
}

/** Loads the bundled reader classes (no global autoloader, so no clash with other plugins). */
function hydro_geoip_load_reader(): bool {
    if ( class_exists( '\MaxMind\Db\Reader', false ) ) return true;
    $base = HYDRO_DIR . 'lib/maxmind-db-reader/src/MaxMind/Db/';
    foreach ( [ 'Reader/InvalidDatabaseException.php', 'Reader/Util.php', 'Reader/Decoder.php', 'Reader/Metadata.php', 'Reader.php' ] as $f ) {
        if ( ! is_readable( $base . $f ) ) return false;
        require_once $base . $f;
    }
    return class_exists( '\MaxMind\Db\Reader', false );
}

/** Open reader for the local database, or null when there is none (cached per request). */
function hydro_geoip_reader(): ?\MaxMind\Db\Reader {
    static $reader = false;
    if ( $reader !== false ) return $reader;
    $reader = null;
    $file   = hydro_geoip_path();
    if ( is_readable( $file ) && hydro_geoip_load_reader() ) {
        try {
            $reader = new \MaxMind\Db\Reader( $file );
        } catch ( \Throwable $e ) {
            $reader = null;
        }
    }
    return $reader;
}

function hydro_geoip_available(): bool {
    return hydro_geoip_reader() !== null;
}

/**
 * Two-letter country code for an IP address, '--' when it is unknown or not a public address,
 * '' when there is no database. Works with shortened addresses (x.x.x.0, /64).
 */
function hydro_geo_country( string $ip ): string {
    $reader = hydro_geoip_reader();
    if ( ! $reader ) return '';
    if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) return '--';
    try {
        $rec = $reader->get( $ip );
    } catch ( \Throwable $e ) {
        return '--';
    }
    $code = is_array( $rec ) ? (string) ( $rec['country']['iso_code'] ?? '' ) : '';
    return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '--';
}

/** Info for the settings page: file date, edition, size. */
function hydro_geoip_info(): array {
    $file = hydro_geoip_path();
    $info = [ 'exists' => is_readable( $file ), 'path' => $file, 'mtime' => 0, 'built' => 0, 'size' => 0, 'type' => '' ];
    if ( $info['exists'] ) {
        $info['mtime'] = (int) filemtime( $file );
        $info['size']  = (int) filesize( $file );
        $reader        = hydro_geoip_reader();
        if ( $reader ) {
            $meta          = $reader->metadata();
            $info['built'] = (int) $meta->buildEpoch;
            $info['type']  = (string) $meta->databaseType;
        }
    }
    $info['last'] = get_option( 'hydro_geoip_last_update', [] );
    return $info;
}

/** Daily job: downloads a new edition when the local file is missing or older than 30 days. */
function hydro_geoip_maybe_update(): void {
    $file = hydro_geoip_path();
    if ( is_readable( $file ) && ( time() - (int) filemtime( $file ) ) < HYDRO_GEOIP_MAX_AGE ) return;
    hydro_geoip_update();
}

/**
 * Downloads the current monthly edition (falls back to the previous month at the start of a
 * month, before the new file is published), unpacks it, checks it and replaces the old file.
 *
 * @return true|WP_Error
 */
function hydro_geoip_update(): bool|WP_Error {
    if ( ! function_exists( 'download_url' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! hydro_geoip_load_reader() ) {
        return hydro_geoip_result( new WP_Error( 'reader', 'The MaxMind DB reader library is missing (lib/maxmind-db-reader).' ) );
    }
    $dir = hydro_geoip_dir();
    if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
        return hydro_geoip_result( new WP_Error( 'dir', 'Folder not writable: ' . $dir ) );
    }
    // The database is public data; still keep the folder out of directory listings
    if ( ! file_exists( $dir . '/index.php' ) ) {
        file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
    }

    $gz = null;
    $error = null;
    foreach ( [ gmdate( 'Y-m' ), gmdate( 'Y-m', strtotime( 'first day of last month' ) ) ] as $month ) {
        $url = 'https://download.db-ip.com/free/dbip-country-lite-' . $month . '.mmdb.gz';
        $tmp = download_url( $url, 120 );
        if ( ! is_wp_error( $tmp ) ) { $gz = $tmp; break; }
        $error = $tmp;
    }
    if ( $gz === null ) {
        return hydro_geoip_result( $error instanceof WP_Error ? $error : new WP_Error( 'download', 'Download failed.' ) );
    }

    // Unpack next to the target, check it, then swap it in atomically
    $new = $dir . '/' . HYDRO_GEOIP_FILE . '.new';
    $in  = gzopen( $gz, 'rb' );
    $out = $in ? fopen( $new, 'wb' ) : false;
    if ( ! $in || ! $out ) {
        if ( $in ) gzclose( $in );
        wp_delete_file( $gz );
        return hydro_geoip_result( new WP_Error( 'unpack', 'The download could not be unpacked.' ) );
    }
    while ( ! gzeof( $in ) ) {
        $chunk = gzread( $in, 1 << 20 );
        if ( $chunk === false ) break;
        fwrite( $out, $chunk );
    }
    gzclose( $in );
    fclose( $out );
    wp_delete_file( $gz );

    try {
        $test = new \MaxMind\Db\Reader( $new );
        $type = (string) $test->metadata()->databaseType;
        $test->close();
        if ( stripos( $type, 'country' ) === false ) {
            throw new \RuntimeException( 'Unexpected database type: ' . $type );
        }
    } catch ( \Throwable $e ) {
        wp_delete_file( $new );
        return hydro_geoip_result( new WP_Error( 'invalid', 'The downloaded file is not a valid country database (' . $e->getMessage() . ').' ) );
    }

    if ( ! rename( $new, hydro_geoip_path() ) ) {
        wp_delete_file( $new );
        return hydro_geoip_result( new WP_Error( 'rename', 'The database could not be replaced.' ) );
    }
    return hydro_geoip_result( true );
}

function hydro_geoip_result( bool|WP_Error $r ): bool|WP_Error {
    update_option( 'hydro_geoip_last_update', [
        'time'  => time(),
        'ok'    => $r === true,
        'error' => is_wp_error( $r ) ? $r->get_error_message() : '',
    ], false );
    return $r;
}
