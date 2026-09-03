import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


class OrderTable extends Table {


    getTabs() {
        return {
            all: translate('All'),
            completed: translate('Completed'),
            processing: translate('Processing'),
            "on-hold": translate('On Hold'),
            paid: translate('Paid'),
            subscription: translate('Subscription'),
            renewal: translate('Renewal'),
            refunded: translate('Refunded'),
            partially_refunded: translate('Partially Refunded'),
            upgraded_from: {
                title: translate('Upgraded From'),
                description: translate('Orders upgraded from another order')
            },
            upgraded_to: {
                title: translate('Upgraded To'),
                description: translate('Orders upgraded to another order')
            }
            //unpaid: translate('Unpaid')
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Customer'),
                value: 'customer'
            },
            {
                label: translate('Items'),
                value: 'order_items'
            },
            {
                label: translate('Order Status'),
                value: 'status'
            },
            {
                label: translate('Order Type'),
                value: 'type'
            },
            {
                label: translate('Actions'),
                value: 'actions'
            },
        ];
    }

    getSearchHint() {
        return translate("Search by invoice no, customer name, or customer email.")
    }

    getFetchUrl() {
        return 'orders';
    }

    parseResponse(response) {
        return response.orders;
    }

    getTableName() {
        return 'order_table';
    }

    // A SCREEN key, not a relation name — OrderFilter::allowedWiths() owns
    // which relations, selects and permission checks it resolves to. One key
    // per screen: this one loads the line items and the customer popover, and
    // gates the customer half on customers/view server-side.
    with() {
        return [
            'admin_order_list'
        ];
    }
}


/**
 * @return {OrderTable}
 */
export default function useOrderTable(data) {
    return OrderTable.init(data);
}

