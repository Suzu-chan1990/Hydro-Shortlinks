<?php
/*
 * Module Name: Public API
 * Module Slug: api
 * Description: Provides hydro_create_or_get_shortlink() for other plugins and themes (Bunrui, Kakera, Kirameki …). Uses the URL index for fast, exact lookups.
 * Version: 2.0.0
 * Author: Hydro Dev
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'hydro_create_or_get_shortlink' ) ) {
    /**
     * Creates a shortlink for a URL or returns the existing one.
     *
     * @param string $target_url Target URL.
     * @return string|false Full short URL, or false on error.
     */
    function hydro_create_or_get_shortlink( string $target_url ): string|false {
        $url = esc_url_raw( $target_url );
        if ( empty( $url ) ) {
            return false;
        }

        // 1. Existing link? (URL index: primary key lookup instead of a scan over the TEXT column)
        $existing = hydro_find_link_by_url( $url );
        if ( $existing ) {
            return home_url( '/' . (string) $existing->slug );
        }

        // 2. Create it (atomic insert, retries on slug collision).
        $id = hydro_insert_link( $url );
        if ( is_wp_error( $id ) ) {
            return false;
        }
        $link = hydro_get_link( $id );
        return $link ? home_url( '/' . (string) $link->slug ) : false;
    }
}
