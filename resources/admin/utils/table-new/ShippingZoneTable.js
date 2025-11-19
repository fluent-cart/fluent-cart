import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";

class ShippingZoneTable extends Table {

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

    getSortableColumns() {
        return [
            {
                label: translate('Zone Name'),
                value: 'name'
            },
            {
                label: translate('Order'),
                value: 'order'
            }
        ]
    }

    getSearchHint() {
        return translate("Search by zone name")
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

    with() {
        return [
            'methodsCount'
        ];
    }
}

/**
 * @return {ShippingZoneTable}
 */
export default function useShippingZoneTable(data) {
    return ShippingZoneTable.init(data);
}
