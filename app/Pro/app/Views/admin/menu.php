<div id="<?php echo $slug; ?>-app" class="warp fconnector_app">
    <div class="fframe_app">
        <div class="fframe_main-menu-items">
            <div class="menu_logo_holder">
                <a href="<?php echo esc_url($baseUrl); ?>">
                    <img style="max-height: 40px;" src="<?php echo esc_url($logo); ?>" />
                    <span>beta</span>
                </a>
            </div>
            <div class="fframe_handheld"><span class="dashicons dashicons-menu-alt3"></span></div>

            <ul class="fframe_menu">
				<?php foreach ($menuItems as $item): ?>
					<?php $hasSubMenu = !empty($item['sub_items']); ?>
                    <li data-key="<?php echo esc_attr($item['key']); ?>" class="fframe_menu_item <?php echo ($hasSubMenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($item['key']); ?>">
                        <a class="fframe_menu_primary" href="<?php echo esc_url($item['permalink']); ?>">
							<?php echo sanitize_text_field($item['label']); ?>
							<?php if($hasSubMenu){ ?>
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
							<?php } ?></a>
						<?php if($hasSubMenu): ?>
                            <div class="fframe_submenu_items">
								<?php foreach ($item['sub_items'] as $sub_item): ?>
                                    <a href="<?php echo esc_url($sub_item['permalink']); ?>"><?php echo esc_attr($sub_item['label']); ?></a>
								<?php endforeach; ?>
                            </div>
						<?php endif; ?>
                    </li>
				<?php endforeach; ?>
            </ul>
        </div>
        <div class="fframe_body">
            <div id="fluent-framework-app" class="fs_route_wrapper"></div>
        </div>
    </div>
</div>
