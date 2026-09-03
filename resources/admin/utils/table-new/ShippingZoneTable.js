import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";

class ShippingZoneTable extends Table {

    constructor(data) {
        super(data);
        this.shippingClassId = data?.shipping_class_id ?? undefined;
    }

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "id";
        this.data.sorting.sortType = "DESC";
    }

    getTabs() {
        return null;
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Regions'),
                value: 'regions'
            },
            {
                label: translate('Shipping Methods'),
                value: 'methods_count'
            }
        ];
    }

    getSearchHint() {
        return translate("Search by zone name")
    }

    buildQueryParams() {
        const params = super.buildQueryParams();
        if (this.shippingClassId !== undefined) {
            params['shipping_class_id'] = this.shippingClassId;
        }
        return params;
    }

    getFetchUrl() {
        return 'shipping/zones';
    }

    parseResponse(response) {
        return response.shipping_zones;
    }

    getTableName() {
        return 'shipping_zone_table';
    }

    getAdvanceFilterOptions() {
        return Arr.get(window, 'fluentCartAdminApp.filter_options.shipping_zone_filter_options');
    }

    getSearchGuideOptions() {
        return [];
    }

    useFullWidthSearch() {
        return true;
    }

    // A SCREEN key, not a relation name — ShippingZoneFilter resolves it to a
    // withCount('methods'), which surfaces as the `methods_count` attribute.
    with() {
        return [
            'admin_shipping_zone_list'
        ];
    }
}

/**
 * @return {ShippingZoneTable}
 */
export default function useShippingZoneTable(data) {
    return ShippingZoneTable.init(data);
}
