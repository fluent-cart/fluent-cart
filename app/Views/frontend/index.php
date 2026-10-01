<?php
defined('ABSPATH') || exit;

use FluentCart\App\Vite;

// The is_page() guard below exists for the shortcode path, where this view
// renders INSIDE a real page whose wp_head() already ran. A route that takes
// over the request and renders a whole document is the opposite case: it needs
// wp_head()/wp_footer() precisely BECAUSE nothing else will print them — and
// on a site with a static front page, a route hanging off home_url() makes
// is_page() true for reasons that have nothing to do with this view. Passing
// true explicitly says "I am the whole document", and forces the call.
$force_wp_head = isset($wp_head) && $wp_head === true;
$force_wp_footer = isset($wp_footer) && $wp_footer === true;

$wp_head = !((isset($wp_head) && $wp_head === false));
$wp_footer = !((isset($wp_footer) && $wp_footer == false));
?>
<!DOCTYPE html>
<html>

<?php if (!empty($title)): ?>

    <title><?php echo esc_html($title) ?></title>
<?php endif; ?>
<?php


if (($force_wp_head || !is_page()) && \FluentCart\App\App::request()->get('action') !== 'elementor' && $wp_head) {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    wp_head();
}

if (isset($styles) && is_array($styles) && !empty($enqueue_prefix)) {
    Vite::printAllStyles($styles, $enqueue_prefix.'_styles');
}
?>
<body class="<?php echo esc_attr(implode(' ', \FluentCart\App\Services\Theme\FrontendTheme::bodyClasses([]))); ?>">

<div style="width: 100%; box-sizing: border-box">
    <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
</body>

<?php

if (function_exists('wp_enqueue_block_template_skip_link')) {
    wp_enqueue_block_template_skip_link();
}

if (($force_wp_footer || !is_page()) && \FluentCart\App\App::request()->get('action') !== 'elementor' && $wp_footer) {
    wp_footer();
}

if (isset($scripts) && is_array($scripts) && !empty($enqueue_prefix)) {
    Vite::printAllScripts($scripts, $enqueue_prefix.'_scripts');
}
?>
</html>
