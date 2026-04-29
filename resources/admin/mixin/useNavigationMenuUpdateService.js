export function useNavigationMenuUpdateService(router) {
    // Submenu grouping: map parent WP menu class → child classes that should toggle with it
    const groupedSubMenus = {
        'fluent_cart_products': [
            'fluent_cart_inventory'
        ]
    };

    const toggleSubmenus = (activeMenu) => {
        if (!activeMenu) return;

        const activeParentMenuClass = 'fluent_cart_' + activeMenu;

        Object.entries(groupedSubMenus).forEach(([parentMenuClass, childClasses]) => {
            const shouldShowChildren = activeParentMenuClass === parentMenuClass;

            childClasses.forEach((childClass) => {
                const menuItem = jQuery('.toplevel_page_fluent-cart li.' + childClass);

                if (shouldShowChildren) {
                    menuItem.show();
                } else {
                    menuItem.hide();
                }
            });
        });
    };

    router.afterEach((to, from) => {
        const activeMenu = to.meta.active_menu;
        jQuery('.fct_menu li').removeClass('active_admin_menu');

        jQuery('.fct_menu li.fct_menu_item_' + activeMenu).addClass('active_admin_menu');

        jQuery('.toplevel_page_fluent-cart li').removeClass('current');
        jQuery('.toplevel_page_fluent-cart li.fluent_cart_' + activeMenu).addClass('current');

        toggleSubmenus(activeMenu);

        if (to.meta.title) {
            jQuery('head title').text(to.meta.title + ' - FluentCart');
        }
    });
}
