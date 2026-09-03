<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\App\App;
use FluentCart\App\Services\Permission\PermissionManager;

// Inline SVGs render immediately with the HTML — no network request, no load blink.
$logoDir      = App::isDevMode()
    ? FLUENTCART_PLUGIN_PATH . 'resources/images/logo/'
    : FLUENTCART_PLUGIN_PATH . 'assets/images/logo/';
$lightLogoSvg = file_get_contents($logoDir . 'logo-full-dark.svg');
$darkLogoSvg  = file_get_contents($logoDir . 'logo-full.svg');

?>
<div class="fct_admin_menu_wrap fct_global_menu_wrap">
    <div class="fct_admin_menu_row">
        <div class="fct_admin_logo_wrap">
            <a aria-label="<?php echo esc_html__('FluentCart Logo', 'fluent-cart'); ?>"
               title="<?php echo esc_html__('FluentCart Logo', 'fluent-cart'); ?>"
               href="<?php echo esc_url(admin_url('admin.php?page=fluent-cart#/')); ?>">
                <span class="logo-light"><?php echo $lightLogoSvg; ?></span>
                <span class="logo-dark"><?php echo $darkLogoSvg; ?></span>
            </a>
        </div><!-- .fct_admin_logo_wrap -->

        <div class="fct_admin_menu">
            <ul class="fct_menu">
                <?php foreach ($menu_items as $itemSlug => $menu_item):
                    if (empty($menu_item['permission']) || PermissionManager::hasPermission($menu_item['permission'])):
                        ?>
                        <li class="fct_menu_item fct_menu_item_<?php echo esc_attr($itemSlug);
                        echo !empty($menu_item['children']) ? ' has-child' : ''; ?>">

                            <?php if (!empty($menu_item['children']) && !empty($menu_item['link']) && $menu_item['link'] !== '#'): ?>
                                <a aria-label="<?php echo esc_attr($menu_item['label']) ?>"
                                   href="<?php echo esc_url($menu_item['link']); ?>">
                                    <?php echo esc_html($menu_item['label']); ?>
                                    <span class="fct_menu_down_arrow">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                             fill="currentColor">
                                            <path fill="currentColor"
                                                  d="M10.1025513,12.7783485 L16.8106554,6.0794438 C17.0871744,5.80330401 17.5303978,5.80851813 17.8006227,6.09108986 C18.0708475,6.37366159 18.0657451,6.82658676 17.7892261,7.10272655 L10.5858152,14.2962587 C10.3114043,14.5702933 9.87226896,14.5675493 9.60115804,14.2901058 L2.2046872,6.72087106 C1.93149355,6.44129625 1.93181183,5.98834118 2.20539811,5.7091676 C2.47898439,5.42999401 2.92223711,5.43031926 3.19543076,5.70989407 L10.1025513,12.7783485 Z"></path>
                                        </svg>
                                    </span>
                                </a>
                            <?php elseif (!empty($menu_item['children'])): ?>
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
            <!-- Pre-rendered so the search button is visible from first paint.
                 Click delegates to window.fluentCart.openSearch() once Vue mounts. -->
            <div id="fct_admin_menu_search">
                <div class="fct-global-search-input-wrap">
                    <button type="button" class="fct-setting-search-button" aria-label="<?php echo esc_attr__('Search', 'fluent-cart'); ?>"
                            onclick="window.fluentCart?.openSearch?.()">
                        <span class="fct-setting-search-icon">
                            <div class="icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
                                    <path d="M14.5833 14.5833L18.3333 18.3333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M16.6667 9.16666C16.6667 5.02452 13.3089 1.66666 9.16675 1.66666C5.02461 1.66666 1.66675 5.02452 1.66675 9.16666C1.66675 13.3088 5.02461 16.6667 9.16675 16.6667C13.3089 16.6667 16.6667 13.3088 16.6667 9.16666Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                </svg>
                            </div>
                        </span>
                        <span class="fct-global-search-button-keys">
                            <kbd class="fct-global-search-button-key">/</kbd>
                        </span>
                    </button>
                </div>
            </div>
            <div class="fct_admin_menu_button_wrap">
                <!-- Pre-rendered so the correct icon is visible from first paint.
                     data-fct-theme on <html> (set by the inline head script) drives
                     which icon span is visible via CSS. Vue teleport or vanilla JS
                     attach handlers after load without rebuilding the button. -->
                <div id="theme-button-container">
                    <div class="fct-theme-button-container">
                        <button class="theme-selected-button" type="button" aria-haspopup="true"
                                aria-label="<?php echo esc_attr__('Toggle color theme', 'fluent-cart'); ?>">
                            <span class="fct-theme-icon fct-theme-icon--light">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M10 14.5C8.80653 14.5 7.66193 14.0259 6.81802 13.182C5.97411 12.3381 5.5 11.1935 5.5 10C5.5 8.80653 5.97411 7.66193 6.81802 6.81802C7.66193 5.97411 8.80653 5.5 10 5.5C11.1935 5.5 12.3381 5.97411 13.182 6.81802C14.0259 7.66193 14.5 8.80653 14.5 10C14.5 11.1935 14.0259 12.3381 13.182 13.182C12.3381 14.0259 11.1935 14.5 10 14.5ZM10 13C10.7956 13 11.5587 12.6839 12.1213 12.1213C12.6839 11.5587 13 10.7956 13 10C13 9.20435 12.6839 8.44129 12.1213 7.87868C11.5587 7.31607 10.7956 7 10 7C9.20435 7 8.44129 7.31607 7.87868 7.87868C7.31607 8.44129 7 9.20435 7 10C7 10.7956 7.31607 11.5587 7.87868 12.1213C8.44129 12.6839 9.20435 13 10 13ZM9.25 1.75H10.75V4H9.25V1.75ZM9.25 16H10.75V18.25H9.25V16ZM3.63625 4.69675L4.69675 3.63625L6.2875 5.227L5.227 6.2875L3.63625 4.6975V4.69675ZM13.7125 14.773L14.773 13.7125L16.3638 15.3032L15.3032 16.3638L13.7125 14.773ZM15.3032 3.6355L16.3638 4.69675L14.773 6.2875L13.7125 5.227L15.3032 3.63625V3.6355ZM5.227 13.7125L6.2875 14.773L4.69675 16.3638L3.63625 15.3032L5.227 13.7125ZM18.25 9.25V10.75H16V9.25H18.25ZM4 9.25V10.75H1.75V9.25H4Z" fill="currentColor"/></svg>
                            </span>
                            <span class="fct-theme-icon fct-theme-icon--dark">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M8.5 6.25C8.49985 7.29298 8.81035 8.31237 9.39192 9.17816C9.97348 10.0439 10.7997 10.7169 11.7653 11.1112C12.7309 11.5055 13.7921 11.6032 14.8134 11.3919C15.8348 11.1807 16.7701 10.67 17.5 9.925V10C17.5 14.1423 14.1423 17.5 10 17.5C5.85775 17.5 2.5 14.1423 2.5 10C2.5 5.85775 5.85775 2.5 10 2.5H10.075C9.57553 2.98834 9.17886 3.57172 8.90836 4.21576C8.63786 4.8598 8.49902 5.55146 8.5 6.25ZM4 10C3.99945 11.3387 4.44665 12.6392 5.27042 13.6945C6.09419 14.7497 7.24723 15.4992 8.54606 15.8236C9.84489 16.148 11.2149 16.0287 12.4381 15.4847C13.6614 14.9407 14.6675 14.0033 15.2965 12.8215C14.1771 13.0852 13.0088 13.0586 11.9026 12.744C10.7964 12.4295 9.78888 11.8376 8.97566 11.0243C8.16244 10.2111 7.57048 9.20361 7.25596 8.09738C6.94144 6.99116 6.91477 5.82292 7.1785 4.7035C6.21818 5.2151 5.41509 5.97825 4.85519 6.91123C4.2953 7.84422 3.99968 8.91191 4 10Z" fill="currentColor"/></svg>
                            </span>
                            <span class="fct-theme-icon fct-theme-icon--system">
                                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.875 4.375V6.25H4.375V7.5H6.875V9.375H8.125V4.375H6.875ZM9.375 7.5H15.625V6.25H9.375V7.5ZM13.125 10.625V12.5H15.625V13.75H13.125V15.625H11.875V10.625H13.125ZM10.625 13.75H4.375V12.5H10.625V13.75Z" fill="currentColor"/></svg>
                            </span>
                        </button>
                    </div>
                </div>
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
