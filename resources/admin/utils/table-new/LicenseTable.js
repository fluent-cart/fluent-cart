import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


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

    // A SCREEN key, not a relation name — the product/variant column selects
    // live server-side in LicenseFilter::adminLicenseList(), which also gates
    // the customer on customers/view and the catalogue on products/view.
    with() {
        return [
            'admin_license_list'
        ];
    }
}


/**
 * @return {LicenseTable}
 */
export default function useLicenseTable(data) {
    return LicenseTable.init(data);
}

