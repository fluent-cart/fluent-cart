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

    // The product placeholder illustration, shown wherever a product has no image.
    static productPlaceholder = () => {
        return Asset.getUrl('images/product-placeholder.webp')
    }
}
