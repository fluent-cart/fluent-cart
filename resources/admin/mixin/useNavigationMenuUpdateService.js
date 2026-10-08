export function useNavigationMenuUpdateService(router) {
    const normalizePath = (path) => path.replace(/\/+$/, '') || '/';

    // Children of "Products" share one active_menu ('products') and "More" holds
    // unrelated pages, so menu entries are matched by route path. Longest link
    // wins, so /products/attributes picks "Attributes" over "All Products".
    const findItemByPath = (items, routePath) => {
        let activeItem = null;
        let matchedLength = 0;

        items.each(function () {
            const href = jQuery(this).find('a').first().attr('href') || '';
            const hashIndex = href.indexOf('#');
            if (hashIndex === -1) {
                return;
            }

            const linkPath = normalizePath(href.slice(hashIndex + 1));
            const isMatch = routePath === linkPath || routePath.startsWith(linkPath + '/');

            if (isMatch && linkPath.length > matchedLength) {
                activeItem = jQuery(this);
                matchedLength = linkPath.length;
            }
        });

        return activeItem;
    };

    const highlightTopMenu = (routePath, activeMenu) => {
        jQuery('.fct_menu li').removeClass('active_admin_menu');
        jQuery('.fct_menu li.fct_menu_item_' + activeMenu).addClass('active_admin_menu');

        const childItem = findItemByPath(jQuery('.fct_menu li.fct_menu_child_item'), routePath);
        if (childItem) {
            childItem.addClass('active_admin_menu');
            childItem.closest('li.fct_menu_item').addClass('active_admin_menu');
        }

        jQuery('.fct_admin_settings_btn').toggleClass('active_admin_menu', activeMenu === 'settings');
    };

    // The off-canvas menu is one flat list (children inlined), so only the row
    // itself lights up. Routes without their own link (e.g. /reports/sales)
    // fall back to the active_menu slug.
    const highlightOffcanvasMenu = (routePath, activeMenu) => {
        const items = jQuery('.fct-offcanvas-menu-item');
        items.removeClass('active');

        const pathItem = findItemByPath(items, routePath);
        if (pathItem) {
            pathItem.addClass('active');
        } else if (activeMenu) {
            items.filter('.fct-offcanvas-menu-item_' + activeMenu).addClass('active');
        }
    };

    // WordPress sidebar: one row is current. Path first, so "↳ Attributes"
    // wins over "Products"; otherwise the row named after active_menu.
    const highlightWpSubmenu = (routePath, activeMenu) => {
        const items = jQuery('#toplevel_page_fluent-cart .wp-submenu li');
        items.removeClass('current');

        const pathItem = findItemByPath(items, routePath);
        if (pathItem) {
            pathItem.addClass('current');
        } else if (activeMenu) {
            items.filter('.fluent_cart_' + activeMenu).addClass('current');
        }
    };

    router.afterEach((to, from) => {
        const activeMenu = to.meta.active_menu;

        const routePath = normalizePath(to.path);

        highlightTopMenu(routePath, activeMenu);
        highlightOffcanvasMenu(routePath, activeMenu);

        highlightWpSubmenu(routePath, activeMenu);

        if (to.meta.title) {
            jQuery('head title').text(to.meta.title + ' - FluentCart');
        }
    });
}
