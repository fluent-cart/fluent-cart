import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";

class WithdrawalRequestTable extends Table {

    getTabs() {
        return {
            all: translate("All"),
            pending: translate("Pending"),
            accepted: translate("Accepted"),
            declined: translate("Declined"),
            unverified: translate("Unverified"),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Customer'),
                value: 'customer'
            },
            {
                label: translate('Order'),
                value: 'order'
            },
            {
                label: translate('Received'),
                value: 'received'
            },
        ];
    }

    getSortableColumns() {
        return [
            {
                label: translate('Received'),
                value: 'submitted_at'
            },
            {
                label: translate('Status'),
                value: 'status'
            },
            {
                label: translate('Name'),
                value: 'name'
            },
        ];
    }

    getSearchHint() {
        return translate("by name, email, order number or reference.")
    }

    getFetchUrl() {
        return 'withdrawal-requests';
    }

    parseResponse(response) {
        return response.withdrawal_requests;
    }

    getTableName() {
        return 'withdrawal_requests';
    }

    with() {
        return [];
    }
}

/**
 * @return {WithdrawalRequestTable}
 */
export default function useWithdrawalRequestTable(data) {
    return WithdrawalRequestTable.init(data);
}
