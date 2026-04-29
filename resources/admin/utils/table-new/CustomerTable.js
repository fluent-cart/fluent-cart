import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


class CustomerTable extends Table {


    getTabs() {
        return null;
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Lifetime Value (LTV)'),
                value: 'ltv'
            },
            {
                label: translate('Customer Since'),
                value: 'customer_since'
            },
            {
                label: translate('Last Purchase Date'),
                value: 'last_purchase_date'
            }
        ];
    }

    getSortableColumns() {
        return [
            {
                label: translate('Customer ID'),
                value: 'id'
            },
            {
                label: translate('Name'),
                value: 'first_name'
            },
            {
                label: translate('Purchases'),
                value: 'purchase_count'
            },
            {
                label: translate('Lifetime Value (LTV)'),
                value: 'ltv'
            },
            {
                label: translate('Last Purchase Date'),
                value: 'last_purchase_date'
            },
            {
                label: translate('Customer Since'),
                value: 'created_at'
            }
        ]
    }

    getSearchHint() {
        return translate("Search by, #ID, First Name, Last Name and Email")
    }

    getFetchUrl() {
        return 'customers';
    }

    parseResponse(response) {
        return response.customers;
    }

    getTableName() {
        return 'customers';
    }

    useFullWidthSearch() {
        return true;
    }
}


/**
 * @return {CustomerTable}
 */
export default function useCustomerTable(data) {
    return CustomerTable.init(data);
}

