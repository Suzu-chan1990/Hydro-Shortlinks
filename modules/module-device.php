<?php
/*
 * Module Name: Device Routing
 * Module Slug: device
 * Description: Device-based routing (mobile/desktop) — Vorlage.
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function hydro_module_device_init() {
    add_filter( 'hydro_shortlink_redirect_url', 'hydro_device_maybe_override_url', 10, 2 );
}
add_action( 'init', 'hydro_module_device_init' );

function hydro_device_maybe_override_url( $url, $link_row ) {
    // Placeholder: detect mobile user agent and rewrite URL
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    if ( $ua !== '' && preg_match( '/Mobile|Android|iPhone|iPad/i', $ua ) ) {
        // Example override (only if link has some meta instructing it)
        // return $url . ( strpos( $url, '?' ) === false ? '?device=mobile' : '&device=mobile' );
    }
    return $url;
}
