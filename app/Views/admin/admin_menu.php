<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Vite;

$darkLogo = Vite::getAssetUrl('images/logo/logo-full.svg');
$lightLogo = Vite::getAssetUrl('images/logo/logo-full-dark.svg');


?>
<div class="fct_admin_menu_wrap fct_global_menu_wrap">
    <div class="fct_admin_menu_row">
        <div class="fct_admin_logo_wrap">
            <a aria-label="<?php echo esc_html__('FluentCart Logo', 'fluent-cart'); ?>"
               title="<?php echo esc_html__('FluentCart Logo', 'fluent-cart'); ?>"
               href="<?php echo esc_url(admin_url('admin.php?page=fluent-cart#/')); ?>">
                <img class="block dark:hidden" src="<?php echo esc_url($lightLogo); ?>"
                     alt="<?php echo esc_html__('FluentCart Logo', 'fluent-cart'); ?>"/>
                <img class="hidden dark:block" src="<?php echo esc_url($darkLogo); ?>"
                     alt="<?php echo esc_html__('FluentCart Logo', 'fluent-cart'); ?>"/>
            </a>
        </div><!-- .fct_admin_logo_wrap -->

        <div class="fct_admin_menu">
            <ul class="fct_menu">
                <?php foreach ($menu_items as $itemSlug => $menu_item):
                    if (empty($menu_item['permission']) || PermissionManager::hasPermission($menu_item['permission'])):
                        ?>
                        <li class="fct_menu_item fct_menu_item_<?php echo esc_attr($itemSlug);
                        echo !empty($menu_item['children']) ? ' has-child' : ''; ?>">

                            <?php if (!empty($menu_item['children'])): ?>
                                <button aria-label="<?php echo esc_attr($menu_item['label']) ?>">
                                    <?php echo esc_html($menu_item['label']); ?>
                                    <span class="fct_menu_down_arrow">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                             fill="currentColor">
                                            <path fill="currentColor"
                                                  d="M10.1025513,12.7783485 L16.8106554,6.0794438 C17.0871744,5.80330401 17.5303978,5.80851813 17.8006227,6.09108986 C18.0708475,6.37366159 18.0657451,6.82658676 17.7892261,7.10272655 L10.5858152,14.2962587 C10.3114043,14.5702933 9.87226896,14.5675493 9.60115804,14.2901058 L2.2046872,6.72087106 C1.93149355,6.44129625 1.93181183,5.98834118 2.20539811,5.7091676 C2.47898439,5.42999401 2.92223711,5.43031926 3.19543076,5.70989407 L10.1025513,12.7783485 Z"></path>
                                        </svg>
                                    </span>
                                </button>
                            <?php else : ?>

                                <a type="button" aria-label="<?php echo esc_attr($menu_item['label']) ?>"
                                   href="<?php echo esc_url($menu_item['link']); ?>">
                                    <?php echo esc_html($menu_item['label']); ?>
                                </a>

                            <?php endif; ?>



                            <?php if (!empty($menu_item['children'])): ?>
                                <ul class="fct_menu_child">
                                    <?php foreach ($menu_item['children'] as $childSlug => $childItem):
                                        if (empty($childItem['permission']) || PermissionManager::hasPermission($childItem['permission'])):
                                            ?>
                                            <li class="fct_menu_child_item fct_menu_child_item_<?php echo esc_attr($childSlug); ?>">
                                                <a type="button"
                                                   aria-label="<?php echo esc_attr($childItem['label']) ?>"
                                                   href="<?php echo esc_url($childItem['link']); ?>">
                                                    <?php echo esc_html($childItem['label']); ?>
                                                </a>
                                            </li>
                                        <?php endif; endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endif; endforeach; ?>
            </ul>
        </div>

        <div class="fct_admin_menu_actions">
            <div id="fct_admin_menu_search"></div>
            <div class="fct_admin_menu_button_wrap">
                <div id="theme-button-container"></div>
                <?php if (PermissionManager::hasPermission(["store/settings", 'store/sensitive'])): ?>
                    <div class="fct_admin_settings_btn_wrap">
                        <a class="fct_admin_settings_btn"
                           href="<?php echo esc_url(admin_url('admin.php?page=fluent-cart#/settings/store-settings/')) ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 22 22"
                                 fill="none">
                                <path d="M20.3175 6.14139L19.8239 5.28479C19.4506 4.63696 19.264 4.31305 18.9464 4.18388C18.6288 4.05472 18.2696 4.15664 17.5513 4.36048L16.3311 4.70418C15.8725 4.80994 15.3913 4.74994 14.9726 4.53479L14.6357 4.34042C14.2766 4.11043 14.0004 3.77133 13.8475 3.37274L13.5136 2.37536C13.294 1.71534 13.1842 1.38533 12.9228 1.19657C12.6615 1.00781 12.3143 1.00781 11.6199 1.00781H10.5051C9.81078 1.00781 9.4636 1.00781 9.20223 1.19657C8.94085 1.38533 8.83106 1.71534 8.61149 2.37536L8.27753 3.37274C8.12465 3.77133 7.84845 4.11043 7.48937 4.34042L7.15249 4.53479C6.73374 4.74994 6.25259 4.80994 5.79398 4.70418L4.57375 4.36048C3.85541 4.15664 3.49625 4.05472 3.17867 4.18388C2.86109 4.31305 2.67445 4.63696 2.30115 5.28479L1.80757 6.14139C1.45766 6.74864 1.2827 7.05227 1.31666 7.37549C1.35061 7.69871 1.58483 7.95918 2.05326 8.48012L3.0843 9.63282C3.3363 9.95185 3.51521 10.5078 3.51521 11.0077C3.51521 11.5078 3.33636 12.0636 3.08433 12.3827L2.05326 13.5354C1.58483 14.0564 1.35062 14.3168 1.31666 14.6401C1.2827 14.9633 1.45766 15.2669 1.80757 15.8741L2.30114 16.7307C2.67443 17.3785 2.86109 17.7025 3.17867 17.8316C3.49625 17.9608 3.85542 17.8589 4.57377 17.655L5.79394 17.3113C6.25263 17.2055 6.73387 17.2656 7.15267 17.4808L7.4895 17.6752C7.84851 17.9052 8.12464 18.2442 8.2775 18.6428L8.61149 19.6403C8.83106 20.3003 8.94085 20.6303 9.20223 20.8191C9.4636 21.0078 9.81078 21.0078 10.5051 21.0078H11.6199C12.3143 21.0078 12.6615 21.0078 12.9228 20.8191C13.1842 20.6303 13.294 20.3003 13.5136 19.6403L13.8476 18.6428C14.0004 18.2442 14.2765 17.9052 14.6356 17.6752L14.9724 17.4808C15.3912 17.2656 15.8724 17.2055 16.3311 17.3113L17.5513 17.655C18.2696 17.8589 18.6288 17.9608 18.9464 17.8316C19.264 17.7025 19.4506 17.3785 19.8239 16.7307L20.3175 15.8741C20.6674 15.2669 20.8423 14.9633 20.8084 14.6401C20.7744 14.3168 20.5402 14.0564 20.0718 13.5354L19.0407 12.3827C18.7887 12.0636 18.6098 11.5078 18.6098 11.0077C18.6098 10.5078 18.7888 9.95185 19.0407 9.63282L20.0718 8.48012C20.5402 7.95918 20.7744 7.69871 20.8084 7.37549C20.8423 7.05227 20.6674 6.74864 20.3175 6.14139Z"
                                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M14.5195 11C14.5195 12.933 12.9525 14.5 11.0195 14.5C9.08653 14.5 7.51953 12.933 7.51953 11C7.51953 9.067 9.08653 7.5 11.0195 7.5C12.9525 7.5 14.5195 9.067 14.5195 11Z"
                                      stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </a>
                    </div>
                <?php endif; ?>
                <div class="fct-mobile-menu-container" id="mobile-menu-container">
                    <div class="fct-offcanvas-menu-overlay" data-fct-offcanvas-menu-overlay></div>

                    <div class="menu-toggle-button" data-fct-menu-toggle>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" class="">
                            <path d="M8.3335 4.16602L16.6668 4.16602" stroke="currentColor" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round"></path>
                            <path d="M3.3335 10L16.6668 10" stroke="currentColor" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round"></path>
                            <path d="M3.3335 15.832L11.6668 15.832" stroke="currentColor" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                    </div>

                    <div class="fct-offcanvas-menu" data-fct-offcanvas-menu>
                        <button class="fct-offcanvas-menu-close" data-fct-offcanvas-menu-close>
                            <span class="icon">
                                <svg class="cross" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M15.8337 4.1665L4.16699 15.8332M4.16699 4.1665L15.8337 15.8332"
                                          stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                          stroke-linejoin="round"></path>
                                </svg>
                            </span>
                        </button>
                        <div class="fct-offcanvas-menu-list">
                            <?php foreach ($menu_items as $itemSlug => $menu_item): ?>
                                <?php if (!empty($menu_item['children'])): ?>
                                    <?php foreach ($menu_item['children'] as $childSlug => $childItem): ?>
                                        <div class="fct-offcanvas-menu-item">
                                            <div class="fct-offcanvas-menu-label">
                                                <a href="<?php echo esc_url($childItem['link']); ?>"><?php echo esc_html($childItem['label']); ?></a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="fct-offcanvas-menu-item">
                                        <div class="fct-offcanvas-menu-label">
                                            <a href="<?php echo esc_url($menu_item['link']); ?>"><?php echo esc_html($menu_item['label']); ?></a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>
