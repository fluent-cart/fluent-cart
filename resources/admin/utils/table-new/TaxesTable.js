import Arr from "@/utils/support/Arr";
import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";

class TaxesTable extends Table {


    getTabs() {
        return {
            all: translate("All"),
            filed: translate("Filed"),
            not_filed: translate('Not Filed'),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: 'ID',
                value: 'id'
            },
            {
                label: 'Order ID',
                value: 'order_id'
            },
            {
                label: 'Zip Code',
                value: 'postcode'
            },
            {
                label: 'Tax Rate',
                value: 'rate'
            },
            {
                label: 'Filed',
                value: 'filed_at'
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
                label: translate('Tax Country'),
                value: 'country'
            },


        ]
    }

    getSearchHint() {
        return translate("Search by id, title, content or module.")
    }

    getFetchUrl() {
        return 'taxes';
    }

    parseResponse(response) {
        return response.taxes;
    }

    getTableName() {
        return 'taxes_table';
    }

    getAdvanceFilterOptions() {
        return Arr.get(window, 'fluentCartAdminApp.filter_options.tax_filter_options.advance');
    }

    getSearchGuideOptions() {
        return [];
    }

    with() {
        return [
            'tax_rate'
        ];
    }

    scopes() {
        return [
            'validOrder'
        ];
    }
}


/**
 * @return {TaxesTable}
 */
export default function useTaxesTable(data) {
    return TaxesTable.init(data);
}

