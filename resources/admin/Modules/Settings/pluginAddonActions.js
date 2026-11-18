import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ClipBoard from "@/utils/Clipboard";
import Url from "@/utils/support/Url";
import translate from "@/utils/translator/Translator";

// Install and activate for an entry of the plugin add-on list, shared by Settings → Features & addon and the
// dashboard recommendations. Each returns a promise that settles with the request, for loading states.

// redirect_url: admin route or full URL; settings_route is the older, route-only key.
export const addonTarget = (addon) => addon.redirect_url || addon.settings_route || '';

export const installPluginAddon = (addon, hasPro) => {
    if (!hasPro) {
        window.open(addon.repo_link, '_blank', 'noopener,noreferrer');
        return Promise.resolve();
    }

    return Rest.post('settings/modules/plugin-addons/install', {
        plugin_slug: addon.plugin_slug,
        source_type: addon.source_type || 'wordpress',
        source_link: addon.source_link || '',
        asset_path: addon.asset_path || ''
    })
        .then((response) => {
            if (response.action === 'copy' && response.url) {
                ClipBoard.copy(response.url, {
                    successMessage: translate('Download link copied to your clipboard')
                });
                window.open(response.url, '_blank', 'noopener,noreferrer');
            } else if (response.action === 'reload') {
                Notify.success(response.message || translate('Addon installed successfully'));
                setTimeout(() => Url.reloadAt(addonTarget(addon)), 500);
            } else {
                Notify.success(response.message || translate('Addon installed successfully'));
            }
        })
        .catch((errors) => {
            Notify.error(errors);
        });
};

export const activatePluginAddon = (addon) => {
    return Rest.post('settings/modules/plugin-addons/activate', {
        plugin_file: addon.plugin_file
    })
        .then((response) => {
            Notify.success(response.message || translate('Addon activated successfully'));
            setTimeout(() => Url.reloadAt(addonTarget(addon)), 500);
        })
        .catch((errors) => {
            Notify.error(errors);
        });
};
