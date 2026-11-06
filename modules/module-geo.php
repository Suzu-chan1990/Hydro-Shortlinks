<?php
/*
 * Module Name: Geo Targeting
 * Module Slug: geo
 * Description: Beispiel-Modul: Geo-Targeting (Vorlage). Nutzt ip-api.com für Demo (cached).
 * Version: 1.0
 *
 * Hinweis: Dies ist eine Vorlage. Für Produktion: ersetze ip-api mit eigenem Provider/Key und achte auf
 * Rate-Limits. Das Modul zeigt, wie man apply_filters('hydro_shortlink_redirect_url', $url, $link) verwendet.
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

    $cache_key = 'hydro_geo_country_' . md5( $ip );
    $country = get_transient( $cache_key );

    if ( $country === false ) {
        $resp = wp_remote_get( "http://ip-api.com/json/" . rawurlencode( $ip ) . "?fields=status,countryCode", [ 'timeout' => 3 ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) == 200 ) {
            $body = wp_remote_retrieve_body( $resp );
            $json = json_decode( $body );
            if ( $json && isset( $json->status ) && $json->status == 'success' && isset( $json->countryCode ) ) {
                $country = sanitize_text_field( $json->countryCode );
            }
        }
        if ( $country ) set_transient( $cache_key, $country, DAY_IN_SECONDS * 7 );
    }

    if ( ! $country ) return $url;

    // demo rule: if DE -> append ?region=de, if US -> ?region=us
    if ( $country === 'DE' ) {
        $url = add_query_arg( 'region', 'de', $url );
    } elseif ( $country === 'US' ) {
        $url = add_query_arg( 'region', 'us', $url );
    }

    return $url;
}
