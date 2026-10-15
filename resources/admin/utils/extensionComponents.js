// Named components add-ons hand to core screens, e.g. Pro's `license_table`:
//   hooks.addFilter('fluent_cart_admin_components', 'my_addon', (components) => ({...components, my_name: MyComponent}));
export const EXTENSION_COMPONENTS_FILTER = 'fluent_cart_admin_components';

export function getExtensionComponent(name) {
    const hooks = window.fluent_cart_admin && window.fluent_cart_admin.hooks;
    if (!hooks || typeof hooks.applyFilters !== 'function') {
        return null;
    }

    const components = hooks.applyFilters(EXTENSION_COMPONENTS_FILTER, {});

    return (components && components[name]) || null;
}

export function hasExtensionComponent(name) {
    return !!getExtensionComponent(name);
}
