import AppConfig from '@/utils/Config/AppConfig';

// The product editor reads its Package list from the page-load config, so every
// successful package save writes the saved list back or the editor stays stale until reload.
// Stored as a copy so later unsaved edits on the saving page can't leak into it.
export const syncShippingPackagesConfig = (packages) => {
    if (!Array.isArray(packages)) {
        return;
    }
    AppConfig.mergeConfig({
        shop: Object.assign({}, AppConfig.get('shop', {}), {shipping_packages: JSON.parse(JSON.stringify(packages))})
    });
};
