
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


    // A SCREEN key, not a relation name — the order column select lives
    // server-side in TaxFilter::adminTaxReport().
    with() {
        return [
            'admin_tax_report'
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

