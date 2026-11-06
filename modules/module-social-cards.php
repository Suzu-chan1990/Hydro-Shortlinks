<?php
/*
 * Module Name: Social Large Cards (Ultimate + MaxRes)
 * Description: Social preview cards for link crawlers (Facebook, Threads, X, Discord, Telegram, WhatsApp …) with automatic maxres/hq thumbnail; visitors are redirected immediately.
 * Version: 3.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Hydro works on its own tables (shortlinks, clicks, URL index, daily stats) – WordPress has no API for them.
// Expensive reads are cached with transients where it matters.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Returns the video title from cache, or loads it once.
 */
function hydro_social_cards_cached_title($yt_id) {
    $cache_key = 'yt_title_' . md5($yt_id);
    $title = get_transient($cache_key);
    if ($title !== false) {
        return $title;
    }

    $title = 'Watch on YouTube';
    global $wpdb;

    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id
         FROM %i
         WHERE meta_key = 'yunyu_video_id'
         AND meta_value = %s
         LIMIT 1",
        $wpdb->postmeta, $yt_id
    ));

    if ($post_id) {
        $post_title = get_the_title($post_id);
        if (!empty($post_title)) {
            $title = wp_strip_all_tags($post_title);
        }
    }

    set_transient($cache_key, $title, DAY_IN_SECONDS);
    return $title;
}

/**
 * Returns the best thumbnail from cache, or checks it once (maxresdefault vs hqdefault).
 */
function hydro_social_cards_best_thumbnail($yt_id) {
    $cache_key = 'yt_thumb_' . md5($yt_id);
    $cached_img = get_transient($cache_key);

    // Already checked before: use the cached result
    if ($cached_img !== false) {
        return $cached_img;
    }

    $maxres = "https://i.ytimg.com/vi/{$yt_id}/maxresdefault.jpg";
    $fallback = "https://i.ytimg.com/vi/{$yt_id}/hqdefault.jpg";

    // Check whether the high-resolution image exists on YouTube (3 second timeout)
    $response = wp_remote_head($maxres, ['timeout' => 3]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
        $best_image = $maxres; // maxres exists
    } else {
        $best_image = $fallback; // 480p fallback
    }

    // Cache the result for 7 days
    set_transient($cache_key, $best_image, 7 * DAY_IN_SECONDS);

    return $best_image;
}

add_filter('hydro_shortlink_redirect_url', 'hydro_social_cards_unified', 10, 2);

/**
 * True for link-preview crawlers of social networks and messengers.
 * Only these get the preview page; real visitors are redirected immediately
 * (they used to wait one second on the preview page).
 */
function hydro_social_cards_is_preview_bot( string $ua ): bool {
    return (bool) preg_match(
        '/facebookexternalhit|facebookcatalog|Facebot|Threads|meta-externalagent|Twitterbot|Discordbot|TelegramBot|WhatsApp|Slackbot|Slack-ImgProxy|LinkedInBot|Pinterestbot|redditbot|SkypeUriPreview|Iframely|Embedly|vkShare|Mastodon|Bluesky|Cardyb|Snap URL Preview|kakaotalk-scrap/i',
        $ua
    );
}

function hydro_social_cards_unified($url, $link) {

    if ( ! hydro_social_cards_is_preview_bot( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ) ) {
        return $url;
    }

    // Extract the YouTube ID
    $yt_id = '';
    if (preg_match('/(?:v=|youtu\.be\/)([^&\?]+)/', $url, $matches)) {
        $yt_id = sanitize_text_field($matches[1]);
    }

    if (!$yt_id) {
        return $url;
    }

    // Title and best available image
    $title = hydro_social_cards_cached_title($yt_id);
    $image = hydro_social_cards_best_thumbnail($yt_id);

    $desc = 'Watch "' . $title . '" on YouTube';
    $desc = mb_substr($desc, 0, 160);
    $short_url = home_url('/' . $link->slug);

    // Response headers
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    ?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="utf-8">
<title><?php echo esc_html($title); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo esc_attr($title); ?>">
<meta property="og:description" content="<?php echo esc_attr($desc); ?>">
<meta property="og:image" content="<?php echo esc_url($image); ?>">
<meta property="og:url" content="<?php echo esc_url($short_url); ?>">
<meta property="og:site_name" content="<?php echo esc_attr(get_bloginfo('name')); ?>">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo esc_attr($title); ?>">
<meta name="twitter:description" content="<?php echo esc_attr($desc); ?>">
<meta name="twitter:image" content="<?php echo esc_url($image); ?>">

<link rel="canonical" href="<?php echo esc_url($url); ?>">

<?php 
// Only the Threads/Facebook crawler is kept away from the redirect (required by the earlier Threads fix).
$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
$is_meta_bot = preg_match('/facebookexternalhit|Threads/i', $ua);

if ( ! $is_meta_bot ) : 
?>
<meta http-equiv="refresh" content="1;url=<?php echo esc_url($url); ?>">
<script>
window.setTimeout(function () {
    window.location.href = <?php echo wp_json_encode($url); ?>;
}, 1000);
</script>
<?php endif; ?>

<style>
html, body { background:#fff; margin:0; padding:0; font-family:sans-serif; }
</style>

</head>
<body>
</body>
</html>
<?php
    exit;
}
