<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php $fct_show_top_bar = apply_filters('fluent_cart/show_admin_top_bar', true); ?>
<div id="fct_admin_app_wrapper"<?php echo $fct_show_top_bar ? '' : ' class="fct-no-top-bar"'; ?>>
    <div id="fct_admin_menu_holder">
        <?php do_action('fluent_cart/admin_menu'); ?>
    </div>
    <div id="fluent_cart_plugin_app" class="warp fconnector_app">

    </div>
</div>
