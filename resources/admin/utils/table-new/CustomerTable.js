import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


class CustomerTable extends Table {


    getTabs() {
        return {};
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
}


/**
 * @return {CustomerTable}
 */
export default function useCustomerTable(data) {
    return CustomerTable.init(data);
}
