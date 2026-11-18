/**
 * Adds the add-on items from `addon_settings_sidebar` to the settings sidebar.
 *
 * An item may name a `parent` (a core item's `key`) to sit inside that group
 * instead of at the top level. The parent turns into a group on first use: its
 * own page becomes the first child, so it stays one click away. An unknown
 * parent falls back to the top level, as before.
 *
 * @param {Array} routes Core sidebar items; changed in place.
 * @param {Array} items  Add-on items.
 * @returns {Array} routes
 */
export function placeAddonSidebarItems(routes, items) {
    (Array.isArray(items) ? items : []).forEach(item => {
        const parent = item.parent && routes.find(route => route.key === item.parent);

        if (!parent) {
            routes.push(item);
            return;
        }

        if (!parent.child) {
            parent.child = [{
                name: parent.ownChildName || parent.name,
                url: parent.url,
                permission: parent.permission
            }];
        }

        parent.child.push({
            name: item.name,
            url: item.url,
            permission: item.permission
        });
    });

    return routes;
}
