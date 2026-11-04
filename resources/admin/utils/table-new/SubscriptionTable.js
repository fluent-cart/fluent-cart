import Table from "@/utils/table-new/Table";
import translate, {_x} from "@/utils/translator/Translator";


class SubscriptionTable extends Table {


    getTabs() {
        return {
            all: translate('All'),
            active: _x('Active', 'Subscription status'),
            trialing: translate('Trialing'),
            pending: _x('Pending', 'Subscription status'),
            intended: translate('Intended'),
            expired: _x('Expired', 'Subscription status'),
            canceled: _x('Canceled', 'Subscription status'),
            completed: {
                title: _x('Completed', 'Subscription status'),
                description: translate('Installment plans paid in full')
            },
            installment: {
                title: translate('Installments'),
                description: translate('Plans with a fixed number of payments')
            },
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

