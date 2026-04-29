import CheckoutHelper from "./CheckoutHelper";
import Url from "@/utils/support/Url";
import CartCheckoutHelper from "./CheckoutHelper";

export default class LocalizationService {


    static countryInfo = window.fluentcart_checkout_info.country_info || {};
    static countryCodeByTimeZone = {};


    static async getCountryInfo(countryCode, type = 'billing') {
        if (this.countryInfo[countryCode]) {
            return {
                ...this.countryInfo[countryCode],
                cached: true
            };
        }
        return await this.#_getCountryInfo(null, countryCode, type);
    }

    static getCountryCodeByTimeZone(timezone) {
        if (this.countryCodeByTimeZone[timezone]) {
            return this.countryCodeByTimeZone[timezone];
        }
        return null;
    }

    static async getCountryInfoByTimeZone(timezone, type = 'billing') {
        const countryCode = this.getCountryCodeByTimeZone(timezone);
        if (countryCode && this.countryInfo[countryCode]) {
            return this.countryInfo[countryCode];
        }
        return await this.#_getCountryInfo(timezone, null, type);
    }

    static async #_getCountryInfo(timezone, country_code, type = 'billing') {

        // return;
        const query = timezone ? {timezone} : {country_code};
        if (type) {
            query.type = type;
        }

        let url = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=get_country_info`).toString();
        url = Url.appendQueryParams(url, query);

        let res;
        await fetch(url, {
            headers: {
                'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
            },
            credentials: 'include'
        }).then(async (response) => {
            response = await response.json();
            res = {
                ...response
            };

            delete response['views'];

            this.countryInfo[response.country_info?.country_code] = response?.country_info;

            if (response?.fragments) {
                CheckoutHelper.handleFragments(response.fragments);
            }
        });

        return res?.country_info;
    }
}

window.fluent_cart_localization_service = {
    getCountryInfo: LocalizationService.getCountryInfo,
    getCountryCodeByTimeZone: LocalizationService.getCountryCodeByTimeZone,
    getCountryInfoByTimeZone: LocalizationService.getCountryInfoByTimeZone
};
