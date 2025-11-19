import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";

class LicenseTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "order_id";
    }

    getTabs() {
        return {
            all: translate("All"),
            active: translate("Active"),
            inactive: translate("Inactive"),
            expired: translate("Expired"),
            disabled: translate("Disabled"),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Order ID'),
                value: 'order_id'
            },
            {
                label: translate('Product'),
                value: 'product'
            },
            {
                label: translate('Customer'),
                value: 'customer'
            },
            {
                label: translate('Date'),
                value: 'date'
            },
        ];
    }

    getSortableColumns() {
        return [
            {
                label: 'Order ID',
                value: 'order_id'
            },
            {
                label: 'Activation Count',
                value: 'activation_count'
            },
            {
                label: 'Expiration Date',
                value: 'expiration_date'
            },
            {
                label: 'Date',
                value: 'created_at'
            },
            {
                label: 'Status',
                value: 'status'
            },
        ];
    }

    getSearchHint() {
        return translate("by license key, order id, customer name/email or connected sites.")
    }

    getFetchUrl() {
        return 'licensing/licenses';
    }

    parseResponse(response) {
        return response.licenses;
    }

    getTableName() {
        return 'licenses';
    }

    getAdvanceFilterOptions() {
        return Arr.get(window, 'fluentCartAdminApp.filter_options.license_filter_options.advance');
    }

    getSearchGuideOptions() {
        return Arr.get(window, 'fluentCartAdminApp.filter_options.license_filter_options.guide');
    }

    with() {
        return [
            'customer',
            'product:ID,post_title',
            'productVariant:id,variation_title'
        ];
    }
}


/**
 * @return {LicenseTable}
 */
export default function useLicenseTable(data) {
    return LicenseTable.init(data);
}

