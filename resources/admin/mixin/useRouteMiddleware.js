import Permission from "@/utils/permission/Permission";
import Arr from "@/utils/support/Arr";
import AppConfig from "@/utils/Config/AppConfig";

export function useRouteMiddleware(router) {
    router.beforeEach((to, from, next) => {

        const requiredPermission = Arr.get(to, 'meta.permission');
        if (!requiredPermission) {
            if (AppConfig.get('app_config.env') === 'dev') {
                console.warn(`[Permission] Route "${to.name || to.path}" is missing 'meta.permission'.`);
            }
            return next();
        }

        const hasAccess = Permission.resolve({
            permission: requiredPermission,
            permissionType: Arr.get(to, 'meta.permissionType')
        });

        return hasAccess ? next() : next({name: "unauthorized"});
    });
}
