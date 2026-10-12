import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";

class PricingRuleTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "id";
    }

    getTabs() {
        return {
            all: translate("All"),
            active: translate("Active"),
            inactive: translate("Inactive"),
        };
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Status'),
                value: 'status'
            },
            {
                label: translate('What it does'),
                value: 'type'
            },
            {
                label: translate('Schedule'),
                value: 'date'
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
                label: translate('Name'),
                value: 'name'
            },
            {
                label: translate('Status'),
                value: 'status'
            },
            {
                label: translate('Date'),
                value: 'updated_at'
            },
        ];
    }

    getSearchHint() {
        return translate("Search rules by name.");
    }

    getSearchGuideOptions() {
        return [];
    }

    getTableName() {
        return 'pricing_rule_table';
    }

    getFetchUrl() {
        return 'pricing_rules';
    }

    parseResponse(response) {
        return response.pricing_rules;
    }
}

/**
 * @return {PricingRuleTable}
 */
export default function usePricingRuleTable(data) {
    return PricingRuleTable.init(data);
}
