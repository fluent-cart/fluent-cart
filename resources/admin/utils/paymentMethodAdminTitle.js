import AppConfig from "@/utils/Config/AppConfig";

export const paymentMethodAdminTitle = (slug) => {
    if (!slug) {
        return '';
    }

    const route = (AppConfig.get('payment_routes') || []).find(route => route.path === slug);

    return route?.meta?.admin_name || slug;
};
