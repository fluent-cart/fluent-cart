import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";


class OrderBumpTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "id";
    }

    getTabs() {
        return {
            all: translate("All"),
            active: translate("Active"),
            draft: translate("Draft"),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: 'Title',
                value: 'title'
            },
            {
                label: 'Status',
                value: 'status'
            },
            {
                label: 'Date',
                value: 'created_at'
            },
        ];
    }

    getSortableColumns() {
        return [
            {
                label: translate('Order Bump ID'),
                value: 'id'
            },
            {
                label: translate('Title'),
                value: 'title'
            },
            {
                label: translate('Created at'),
                value: 'created_at'
            }
        ]
    }

    getSearchHint() {
        return translate("Search title or description.")
    }

    getFetchUrl() {
        return 'order_bump';
    }

    parseResponse(response) {
        return response.order_bumps;
    }

    getTableName() {
        return 'order_bump_table';
    }
    //
    // getAdvanceFilterOptions() {
    //     return Arr.get(window, 'fluentCartAdminApp.filter_options.product_filter_options.advance');
    // }
    //
    // getSearchGuideOptions() {
    //     return Arr.get(window, 'fluentCartAdminApp.filter_options.product_filter_options.guide');
    // }

    getSearchGuideOptions() {
        return [];
    }

    with() {
        return [
            'product_variant'
        ];
    }
}

/**
 * @return {OrderBumpTable}
 */
export default function useOrderBumpTable(data) {
    return OrderBumpTable.init(data);
}
