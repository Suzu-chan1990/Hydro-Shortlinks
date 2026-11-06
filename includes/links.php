<?php
/**
 * Link helpers shared by the admin UI, the public API module, the CSV import and WP-CLI.
 * They only use INSERT / UPDATE / DELETE on rows – the table structure is never changed.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

function hydro_get_link( int $id ): ?object {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', hydro_table( 'links' ), $id ) );
    return $row ?: null;
}

function hydro_get_link_by_slug( string $slug ): ?object {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s LIMIT 1', hydro_table( 'links' ), $slug ) );
    return $row ?: null;
}

function hydro_short_url( object|string $link ): string {
    return home_url( '/' . ( is_object( $link ) ? $link->slug : $link ) );
}

/**
 * Reads the optional link fields from an already unslashed request array.
 * Every value is sanitized here.
 * Returns ['redirect_type','expire','password','utm_source','utm_medium','utm_campaign'] or WP_Error.
 */
function hydro_link_fields_from_request( array $src ): array|WP_Error {
    $get = static fn( string $k ): string => isset( $src[ $k ] ) && is_scalar( $src[ $k ] ) ? trim( sanitize_text_field( (string) $src[ $k ] ) ) : '';

    $redirect = (int) ( $get( 'redirect_type' ) ?: 302 );
    if ( ! in_array( $redirect, [ 301, 302, 307, 308 ], true ) ) $redirect = 302;

    $expire = $get( 'expire' );
    if ( $expire !== '' ) {
        $ts = strtotime( str_replace( 'T', ' ', $expire ) );
        if ( ! $ts ) return new WP_Error( 'bad_expire', 'Invalid expiry date.' );
        $expire = gmdate( 'Y-m-d H:i:s', $ts );
    }

    $password = $get( 'password' );
    if ( strlen( $password ) > 200 ) return new WP_Error( 'bad_password', 'Password is too long.' );

    return [
        'redirect_type' => $redirect,
        'expire'        => $expire !== '' ? $expire : null,
        'password'      => $password !== '' ? sanitize_text_field( $password ) : null,
        'utm_source'    => $get( 'utm_source' ) !== '' ? sanitize_text_field( $get( 'utm_source' ) ) : null,
        'utm_medium'    => $get( 'utm_medium' ) !== '' ? sanitize_text_field( $get( 'utm_medium' ) ) : null,
        'utm_campaign'  => $get( 'utm_campaign' ) !== '' ? sanitize_text_field( $get( 'utm_campaign' ) ) : null,
    ];
}

/**
 * Inserts a link. Generates a slug when none is given.
 *
 * @return int|WP_Error New link ID.
 */
function hydro_insert_link( string $url, string $slug = '', array $fields = [] ): int|WP_Error {
    global $wpdb;
    $table = hydro_table( 'links' );

    if ( $slug !== '' ) {
        if ( ! hydro_slug_is_valid( $slug ) ) {
            return new WP_Error( 'bad_slug', 'Slug: 1–10 characters, only letters, digits, "-" and "_".' );
        }
        if ( hydro_get_link_by_slug( $slug ) ) {
            return new WP_Error( 'slug_exists', 'This slug is already taken.' );
        }
    }

    $data = array_merge( [
        'url'           => $url,
        'redirect_type' => 302,
        'created_at'    => current_time( 'mysql' ),
        'clicks'        => 0,
    ], array_filter( $fields, static fn( $v ) => $v !== null ) );

    $attempts = $slug !== '' ? 1 : 10;
    for ( $i = 0; $i < $attempts; $i++ ) {
        $data['slug'] = $slug !== '' ? $slug : hydro_generate_slug();
        // The UNIQUE key on slug makes the insert atomic; a collision simply fails and we retry.
        $wpdb->suppress_errors( true );
        $ok = $wpdb->insert( $table, $data );
        $wpdb->suppress_errors( false );
        if ( $ok ) {
            $id = (int) $wpdb->insert_id;
            hydro_index_link( $id, $url );
            hydro_flush_admin_cache();
            do_action( 'hydro_link_created', $id, $url );
            return $id;
        }
    }
    return new WP_Error( $slug !== '' ? 'slug_exists' : 'db_error', $slug !== '' ? 'This slug is already taken.' : 'Could not create the shortlink.' );
}

/** Updates editable fields of a link (url, redirect, expiry, password, UTM). The slug never changes. */
function hydro_update_link( int $id, string $url, array $fields ): bool|WP_Error {
    global $wpdb;
    $link = hydro_get_link( $id );
    if ( ! $link ) return new WP_Error( 'not_found', 'Link not found.' );

    $data = array_merge( [ 'url' => $url ], $fields );
    $ok   = $wpdb->update( hydro_table( 'links' ), $data, [ 'id' => $id ] );
    if ( $ok === false ) return new WP_Error( 'db_error', 'Could not save the link.' );

    if ( (string) $link->url !== $url ) {
        hydro_unindex_link( $id );
        hydro_index_link( $id, $url );
    }
    hydro_flush_admin_cache();
    do_action( 'hydro_link_updated', $id );
    return true;
}

/** Deletes links and their click rows. Click rows go in batches (shortlink_id index). */
function hydro_delete_links( array $ids ): int {
    global $wpdb;
    $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
    if ( ! $ids ) return 0;
    $deleted = 0;
    foreach ( $ids as $id ) {
        if ( $wpdb->delete( hydro_table( 'links' ), [ 'id' => $id ], [ '%d' ] ) ) {
            $deleted++;
            hydro_unindex_link( $id );
            do {
                $n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE shortlink_id = %d LIMIT 5000', hydro_table( 'clicks' ), $id ) );
            } while ( $n === 5000 );
            do_action( 'hydro_link_deleted', $id );
        }
    }
    hydro_flush_admin_cache();
    return $deleted;
}

function hydro_flush_admin_cache(): void {
    delete_transient( 'hydro_admin_totals' );
    delete_transient( 'hydro_dash_kpis' );
}

/** Lifetime totals (full table scans) – cached 5 minutes. */
function hydro_admin_totals(): array {
    $t = get_transient( 'hydro_admin_totals' );
    if ( is_array( $t ) && isset( $t['links'], $t['clicks'], $t['expired'], $t['protected'] ) ) return $t;
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS links, COALESCE(SUM(clicks),0) AS clicks,
        SUM(expire IS NOT NULL AND expire < NOW()) AS expired,
        SUM(password IS NOT NULL AND password <> '') AS protected,
        SUM(clicks = 0) AS unused FROM %i",
        hydro_table( 'links' )
    ) );
    $t = [
        'links'     => (int) $row->links,
        'clicks'    => (int) $row->clicks,
        'expired'   => (int) $row->expired,
        'protected' => (int) $row->protected,
        'unused'    => (int) $row->unused,
    ];
    set_transient( 'hydro_admin_totals', $t, 5 * MINUTE_IN_SECONDS );
    return $t;
}
