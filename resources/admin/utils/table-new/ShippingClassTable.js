import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";

class ShippingClassTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "id";
        this.data.sorting.sortType = "DESC";
    }

    getTabs() {
        return null; // No tabs needed for shipping classes
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Cost'),
                value: 'cost'
            },
            {
                label: translate('Type'),
                value: 'type'
            }
        ];
    }

    getSortableColumns() {
        return [
            {
                label: translate('Class Name'),
                value: 'name'
            },
            {
                label: translate('Cost'),
                value: 'cost'
            }
        ]
    }

    getSearchHint() {
        return translate("Search by class name")
    }

    getFetchUrl() {
        return 'shipping/classes';
    }

    parseResponse(response) {
        return response.shipping_classes;
    }

    getTableName() {
        return 'shipping_class_table';
    }

    getAdvanceFilterOptions() {
        return null;
    }

    getSearchGuideOptions() {
        return [];
    }

    useFullWidthSearch() {
        return true;
    }
}

/**
 * @return {ShippingClassTable}
 */
export default function useShippingClassTable(data) {
    return ShippingClassTable.init(data);
}
