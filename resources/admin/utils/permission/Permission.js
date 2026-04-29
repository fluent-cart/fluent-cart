import Arr from "@/utils/support/Arr";

class Permission {

    allPermissions = [];

    /**
     * Create a new Permission instance.
     * Fetches and stores all available permissions from the global app config.
     */
    constructor() {
        this.allPermissions = Arr.get(window, 'fluentCartAdminApp.app_config.permissions', []);
    }

    isSuperAdmin() {
        return this.allPermissions.includes('is_super_admin');
    }

    /**
     * Check if a specific permission exists.
     *
     * @param {string} permission - The permission key to check.
     * @returns {boolean} True if the permission exists, false otherwise.
     */
    has(permission) {

        if (this.isSuperAdmin()) {
            return true;
        }
        return this.allPermissions.includes(permission);
    }

    /**
     * Check if at least one of the given permissions exists.
     *
     * @param {string[]} permissions - Array of permission keys to check.
     * @returns {boolean} True if any of the permissions exist, false otherwise.
     */
    hasAny(permissions) {
        if (this.isSuperAdmin()) {
            return true;
        }
        return Array.isArray(permissions) && permissions.some(p => this.allPermissions.includes(p));
    }

    /**
     * Check if all the given permissions exist.
     *
     * @param {string[]} permissions - Array of permission keys to check.
     * @returns {boolean} True if all permissions exist, false otherwise.
     */
    hasAll(permissions) {
        if (this.isSuperAdmin()) {
            return true;
        }
        return Array.isArray(permissions) && permissions.every(p => this.allPermissions.includes(p));
    }


    resolve({permission, permissionType}) {

        const type = permissionType === "all" ? "all" : "any";

        const permissions = Array.isArray(permission)
            ? permission
            : typeof permission === "string"
                ? permission.split(",").map(p => p.trim())
                : [];

        return type === "all"
            ? this.hasAll(permissions)
            : this.hasAny(permissions);

    }
}


const permission = new Permission();
export default permission;