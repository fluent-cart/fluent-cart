<?php
defined('ABSPATH') || exit;

use FluentCart\App\Vite;

$wp_head = !((isset($wp_head) && $wp_head === false));
$wp_footer = !((isset($wp_footer) && $wp_footer == false));
?>
<!DOCTYPE html>
<html>

<?php if (!empty($title)): ?>

    <title><?php echo esc_html($title) ?></title>
<?php endif; ?>
<?php


if (!is_page() && \FluentCart\App\App::request()->get('action') !== 'elementor' && $wp_head) {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    wp_head();
}

if (isset($styles) && is_array($styles) && !empty($enqueue_prefix)) {
    Vite::printAllStyles($styles, $enqueue_prefix.'_styles');
}
?>
<body>

<div style="width: 100%; padding: 32px; box-sizing: border-box">
    <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
</body>

<?php

if (function_exists('wp_enqueue_block_template_skip_link')) {
    wp_enqueue_block_template_skip_link();
}

if (!is_page() && \FluentCart\App\App::request()->get('action') !== 'elementor' && $wp_footer) {
    wp_footer();
}

if (isset($scripts) && is_array($scripts) && !empty($enqueue_prefix)) {
    Vite::printAllScripts($scripts, $enqueue_prefix.'_scripts');
}
?>
</html>
