import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Arr from "@/utils/support/Arr";

class CouponTable extends Table {


    getTabs() {
        return {
            all: translate("All"),
            active: translate("Active"),
            expired: translate('Inactive'),
            //disabled: translate('Disabled'),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: 'Title',
                value: 'title'
            },
            {
                label: 'Stackable',
                value: 'stackable'
            },
            {
                label: 'Actions',
                value: 'actions'
            },
        ];
    }

    getSortableColumns() {
        return [
            {
                label: translate('ID'),
                value: 'id'
            },
            {
                label: translate('Title'),
                value: 'title'
            },
            {
                label: translate('Code'),
                value: 'code'
            },
            {
                label: translate('Amount'),
                value: 'amount'
            },
            {
                label: translate('Max Uses'),
                value: 'max_uses'
            },
            {
                label: translate('Stackable'),
                value: 'stackable'
            },
            {
                label: translate('Status'),
                value: 'status'
            },
            {
                label: translate('Expiry Date'),
                value: 'expiry_date'
            },

        ]
    }

    getSearchHint() {
        return translate("Search by id, title, amount, code.")
    }

    getFetchUrl() {
        return 'coupons';
    }

    parseResponse(response) {
        return response.coupons;
    }

    getTableName() {
        return 'coupon_table';
    }

    getAdvanceFilterOptions() {
        return null;
    }

    getSearchGuideOptions() {
        return [];
    }

    with() {
        return [
            'appliedCouponsCount',
        ];
    }
}


/**
 * @return {CouponTable}
 */
export default function useCouponTable(data) {
    return CouponTable.init(data);
}

