const NOTICE_ICONS = {
    error: 'FailedCircle',
    warning: 'Warning',
    info: 'Information',
};

/**
 * The notices of a carrier payload, or [] when it has none.
 *
 * @param {Object|null} carrier describeCarrier() payload
 * @return {Array}
 */
export const carrierNotices = (carrier) => {
    const notices = carrier && Array.isArray(carrier.notices) ? carrier.notices : [];

    return notices.filter((notice) => notice && NOTICE_ICONS[notice.type] && notice.message);
};

/**
 * 'error' or 'warning' when the carrier needs the merchant's attention, else ''.
 *
 * @param {Object|null} carrier
 * @return {string}
 */
export const carrierAttentionLevel = (carrier) => {
    const types = carrierNotices(carrier).map((notice) => notice.type);

    if (types.includes('error')) {
        return 'error';
    }

    return types.includes('warning') ? 'warning' : '';
};

/**
 * @param {string} type
 * @return {string} DynamicIcon name
 */
export const noticeIcon = (type) => NOTICE_ICONS[type] || NOTICE_ICONS.info;

/**
 * An admin hash route ('settings/store-settings', '#/settings') as a router path.
 * Null when it does not resolve, so a carrier's stale route renders no dead link.
 *
 * @param {Object} router vue-router instance
 * @param {string} route
 * @return {string|null}
 */
export const noticeRoutePath = (router, route) => {
    if (typeof route !== 'string' || !route.trim() || !router) {
        return null;
    }

    const path = '/' + route.trim().replace(/^[#/]+/, '');

    try {
        const resolved = router.resolve(path);
        // The admin router's catch-all matches everything as NotFound.
        return resolved.matched.length && resolved.name !== 'NotFound' ? path : null;
    } catch (error) {
        return null;
    }
};
