import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


class LicenseSiteTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "id";
    }

    getTabs() {
        return {}
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Active Licenses'),
                value: 'active_licenses'
            },
            {
                label: translate('Products'),
                value: 'products'
            },
            {
                label: translate('Customer'),
                value: 'customer'
            },
            {
                label: translate('Last Activity'),
                value: 'last_activity'
            },
            {
                label: translate('Created At'),
                value: 'created_at'
            },
        ];
    }

    getSearchHint() {
        return translate("by site URL")
    }

    getFetchUrl() {
        return 'licensing/sites';
    }

    parseResponse(response) {
        return response.sites;
    }

    getTableName() {
        return 'license_sites';
    }

    with() {
        return [];
    }
}


/**
 * @return {LicenseSiteTable}
 */
export default function useLicenseSiteTable(data) {
    return LicenseSiteTable.init(data);
}
