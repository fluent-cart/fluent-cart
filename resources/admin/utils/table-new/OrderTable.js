import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";

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

    getSortableColumns() {
        return [
            {
                label: translate('Order ID'),
                value: 'id'
            },
            {
                label: translate('Total'),
                value: 'total_amount'
            },
            {
                label: translate('Payment Status'),
                value: 'payment_status'
            },
            {
                label: translate('Order Status'),
                value: 'status'
            }
        ]
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

    getAdvanceFilterOptions() {
        return Arr.get(window, 'fluentCartAdminApp.filter_options.order_filter_options.advance');
    }

    getSearchGuideOptions() {
        return Arr.get(window, 'fluentCartAdminApp.filter_options.order_filter_options.guide');
    }

    getCustomColumns() {
        return Arr.get(window, 'fluentCartAdminApp.filter_options.order_filter_options.columns') || {};
    }

    with() {
        return [
            'customer.primary_billing_address', 'order_items'
        ];
    }
}


/**
 * @return {OrderTable}
 */
export default function useOrderTable(data) {
    return OrderTable.init(data);
}

