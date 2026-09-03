import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


class SubscriptionTable extends Table {


    getTabs() {
        return {
            all: translate('All'),
            active: translate('Active'),
            trialing: translate('Trialing'),
            pending: translate('Pending'),
            intended: translate('Intended'),
            expired: translate('Expired'),
            canceled: translate('Canceled'),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Order ID'),
                value: 'order_id'
            },
            {
                label: translate('Next Billing Date'),
                value: 'next_billing_date'
            },
            {
                label: translate('Collection Method'),
                value: 'collection_method'
            },
            {
                label: translate('Bills Count'),
                value: 'bills_count'
            },
            {
                label: translate('Customer'),
                value: 'customer'
            },
            {
                label: translate('Payment Method'),
                value: 'payment_method'
            },
        ];
    }

    getSearchHint() {
        return translate("by id, order id, status, payment method, customer name, or product.")
    }

    getFetchUrl() {
        return 'subscriptions';
    }

    parseResponse(response) {
        return response.data;
    }

    getTableName() {
        return 'subscriptions';
    }

    // A SCREEN key, not a relation name — SubscriptionFilter::allowedWiths()
    // owns the relation and its customers/view check.
    with() {
        return [
            'admin_subscription_list'
        ];
    }
}


/**
 * @return {SubscriptionTable}
 */
export default function useSubscriptionTable(data) {
    return SubscriptionTable.init(data);
}

