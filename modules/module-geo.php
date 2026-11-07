<?php
/*
 * Module Name: Geo Targeting
 * Module Slug: geo
 * Description: Example module: geo targeting (template). Uses the local GeoIP database, no external requests.
 * Version: 1.1
 *
 * Note: this is a template. It shows how to use apply_filters('hydro_shortlink_redirect_url', $url, $link).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'hydro_geo_module_init' );

function hydro_geo_module_init() {
    add_filter( 'hydro_shortlink_redirect_url', 'hydro_geo_maybe_override_url', 10, 2 );
}

/**
 * Override redirect URL for demo.
 * Requires you to set special meta on a link to actually redirect; here we only act if slug contains "geo-"
 * (this is safe default behaviour).
 */
function hydro_geo_maybe_override_url( $url, $link_row ) {
    if ( empty( $link_row ) ) return $url;

    // safe demo: only act on slugs that start with "geo-"
    if ( strpos( $link_row->slug, 'geo-' ) !== 0 ) return $url;

    // get client's IP
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    if ( ! $ip ) return $url;

    // Local lookup with the shortened IP (country level is enough), nothing leaves the server
    $country = hydro_geo_country( hydro_anonymize_ip( $ip ) );
    if ( $country === '--' ) $country = '';

    if ( ! $country ) return $url;

    // demo rule: if DE -> append ?region=de, if US -> ?region=us
    if ( $country === 'DE' ) {
        $url = add_query_arg( 'region', 'de', $url );
    } elseif ( $country === 'US' ) {
        $url = add_query_arg( 'region', 'us', $url );
    }

    return $url;
}
