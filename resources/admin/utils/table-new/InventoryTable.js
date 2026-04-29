import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";

class InventoryTable extends Table {

    getTabs() {
        return {
            all: translate("All"),
            low_stock: translate("Low Stock"),
            out_of_stock: translate("Out of Stock"),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('SKU'),
                value: 'sku'
            },
            {
                label: translate('Available'),
                value: 'available'
            },
            {
                label: translate('On hold'),
                value: 'on_hand'
            },
            {
                label: translate('Committed'),
                value: 'committed'
            }
        ];
    }

    getSortableColumns() {
        return [
            {
                label: translate('Product ID'),
                value: 'id'
            },
            {
                label: translate('Product Title'),
                value: 'post_title'
            }
        ]
    }

    getSearchHint() {
        return translate("Search by product name, variation name or SKU");
    }

    getFetchUrl() {
        return 'inventory';
    }

    parseResponse(response) {
        return response.products || [];
    }

    getTableName() {
        return 'inventory_table';
    }

    getAdvanceFilterOptions() {
        return window.fluentCartAdminApp?.filter_options?.product_filter_options?.advance || null;
    }

    getSearchGuideOptions() {
        return {};
    }

    getCustomColumns() {
        return {};
    }

    with() {
        return [
            'detail',
            'variants'
        ];
    }
}


/**
 * @return {InventoryTable}
 */
export default function useInventoryTable(data) {
    return InventoryTable.init(data);
}
