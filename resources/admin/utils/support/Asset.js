import Arr from "@/utils/support/Arr";
import Str from "@/utils/support/Str";
import Url from "@/utils/support/Url";

export default class Asset {
    static url = () => {
        return Arr.get(window, 'fluentCartAdminApp.asset_url', '');
    }

    static getUrl = (url, params = {}) => {
        return Url.join(Asset.url(), url, params)
    }
}
