<?php
/*
 * Module Name: Social Meta Previews
 * Description: Fügt OpenGraph- und Twitter-Metadaten für Shortlinks hinzu, inklusive Live-Vorschau und Auto-Fetch. (PHP 8.5 Optimized)
 * Version: 1.2.0
 * Author: Hydro Dev
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'hydro_social_meta_init' );
function hydro_social_meta_init(): void {
    add_action( 'add_meta_boxes', 'hydro_social_meta_add_box' );
    add_action( 'save_post', 'hydro_social_meta_save' );
    add_action( 'template_redirect', 'hydro_social_meta_output_tags', 1 );
}

function hydro_social_meta_add_box(): void {
    add_meta_box(
        'hydro_social_meta_box',
        __( 'Social Meta Preview', 'hydro-shortlinks' ),
        'hydro_social_meta_render_box',
        'hydro_shortlink',
        'normal',
        'high'
    );
}

function hydro_social_meta_render_box( object $post ): void {
    wp_nonce_field( 'hydro_social_meta_save', 'hydro_social_meta_nonce' );

    $title = (string) get_post_meta( $post->ID, '_hydro_meta_title', true );
    $desc  = (string) get_post_meta( $post->ID, '_hydro_meta_desc', true );
    $img   = (string) get_post_meta( $post->ID, '_hydro_meta_img', true );

    $css_path = plugin_dir_path( dirname(__FILE__) ) . 'modules/assets/social-meta.css';
    $js_path  = plugin_dir_path( dirname(__FILE__) ) . 'modules/assets/social-meta.js';

    wp_enqueue_style(
        'hydro-social-meta',
        plugins_url( 'modules/assets/social-meta.css', dirname(__FILE__) ),
        [],
        (string) ( file_exists($css_path) ? filemtime( $css_path ) : '1.0' )
    );

    wp_enqueue_script(
        'hydro-social-meta',
        plugins_url( 'modules/assets/social-meta.js', dirname(__FILE__) ),
        [ 'jquery' ],
        (string) ( file_exists($js_path) ? filemtime( $js_path ) : '1.0' ),
        true
    );

    wp_localize_script( 'hydro-social-meta', 'hydroMetaAjax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'hydro_meta_fetch_nonce' )
    ] );
    ?>
    <div class="hydro-meta-wrap">
        <label><strong>Social Title</strong></label>
        <input type="text" name="hydro_meta_title" value="<?php echo esc_attr( $title ); ?>" placeholder="z. B. Erlebe jetzt den neuen Hydro Shortlink!" />

        <label><strong>Social Description</strong></label>
        <textarea name="hydro_meta_desc" rows="3" placeholder="Kurze, prägnante Beschreibung..."><?php echo esc_textarea( $desc ); ?></textarea>

        <label><strong>Social Image URL</strong></label>
        <input type="text" name="hydro_meta_img" value="<?php echo esc_attr( $img ); ?>" placeholder="https://example.com/image.jpg" />

        <div class="hydro-meta-preview">
            <?php if ( $img !== '' ) echo '<img src="' . esc_url( $img ) . '" alt="">'; ?>
            <div class="overlay">
                <h4><?php echo $title !== '' ? esc_html( $title ) : 'Social Title Vorschau'; ?></h4>
                <p><?php echo $desc !== '' ? esc_html( $desc ) : 'Social Description Vorschau'; ?></p>
            </div>
        </div>

        <button type="button" id="hydro-auto-fetch" class="button button-secondary" style="margin-top:10px;">🔄 Auto-Fetch von Ziel-URL</button>
    </div>
    <?php
}

function hydro_social_meta_save( int $post_id ): void {
    if ( ! isset( $_POST['hydro_social_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hydro_social_meta_nonce'] ) ), 'hydro_social_meta_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    update_post_meta( $post_id, '_hydro_meta_title', sanitize_text_field( wp_unslash( $_POST['hydro_meta_title'] ?? '' ) ) );
    update_post_meta( $post_id, '_hydro_meta_desc', sanitize_textarea_field( wp_unslash( $_POST['hydro_meta_desc'] ?? '' ) ) );
    update_post_meta( $post_id, '_hydro_meta_img', esc_url_raw( wp_unslash( $_POST['hydro_meta_img'] ?? '' ) ) );
}

function hydro_social_meta_output_tags(): void {
    if ( ! is_singular( 'hydro_shortlink' ) ) return;

    global $post;
    if ( ! $post ) return;

    $title = (string) get_post_meta( $post->ID, '_hydro_meta_title', true );
    $desc  = (string) get_post_meta( $post->ID, '_hydro_meta_desc', true );
    $img   = (string) get_post_meta( $post->ID, '_hydro_meta_img', true );

    if ( $title === '' && $desc === '' && $img === '' ) return;

    echo "<!-- Hydro Social Meta -->\n";
    if ( $title !== '' ) {
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
    }
    if ( $desc !== '' ) {
        echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
    }
    if ( $img !== '' ) {
        echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url( $img ) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:locale" content="de_DE">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
}

add_action( 'wp_ajax_hydro_meta_fetch', 'hydro_meta_fetch' );
function hydro_meta_fetch(): void {
    check_ajax_referer( 'hydro_meta_fetch_nonce', 'nonce' );

    $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
    if ( $url === '' ) wp_send_json_error( [ 'message' => 'Keine URL übergeben.' ] );

    $response = wp_safe_remote_get( $url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $response ) ) wp_send_json_error( [ 'message' => 'Abruf fehlgeschlagen.' ] );

    $html = wp_remote_retrieve_body( $response );
    if ( $html === '' ) wp_send_json_error( [ 'message' => 'Keine Daten erhalten.' ] );

    $title = '';
    $desc  = '';
    $image = '';

    if ( preg_match( '/<meta property=["\']og:title["\'] content=["\']([^"\']+)["\']/', $html, $m ) ) $title = $m[1];
    if ( preg_match( '/<meta property=["\']og:description["\'] content=["\']([^"\']+)["\']/', $html, $m ) ) $desc = $m[1];
    if ( preg_match( '/<meta property=["\']og:image["\'] content=["\']([^"\']+)["\']/', $html, $m ) ) $image = $m[1];

    wp_send_json_success( [
        'title' => $title,
        'desc'  => $desc,
        'image' => $image
    ] );
}
