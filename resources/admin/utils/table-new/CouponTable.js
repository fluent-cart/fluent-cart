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

    // Nothing to load. The redemption count this used to request was never
    // rendered — `applied_coupons_count` appears nowhere in the admin; the
    // table prints the real `use_count` column instead.
    with() {
        return [];
    }
}


/**
 * @return {CouponTable}
 */
export default function useCouponTable(data) {
    return CouponTable.init(data);
}

