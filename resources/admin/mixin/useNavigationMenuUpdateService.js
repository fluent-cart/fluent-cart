export function useNavigationMenuUpdateService(router) {
    router.afterEach((to, from) => {
        const activeMenu = to.meta.active_menu;
        jQuery('.fct_menu li').removeClass('active_admin_menu');

        jQuery('.fct_menu li.fct_menu_item_' + activeMenu).addClass('active_admin_menu');

        jQuery('.toplevel_page_fluent-cart li').removeClass('current');
        jQuery('.toplevel_page_fluent-cart li.fluent_cart_' + activeMenu).addClass('current');

        if (to.meta.title) {
            jQuery('head title').text(to.meta.title + ' - FluentCart');
        }
    });
}
