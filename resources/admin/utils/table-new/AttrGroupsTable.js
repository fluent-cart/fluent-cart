import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


class AttrGroupsTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "id";
    }

    getTabs() {
        return {
            all: translate("All"),
            button: translate("Button"),
            dropdown: translate("Dropdown"),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Title'),
                value: 'title'
            },
            {
                label: translate('Type'),
                value: 'type'
            },
            {
                label: translate('Styling'),
                value: 'styling'
            },
        ];
    }

    getSearchHint() {
        return translate("Search by id, title or slug.")
    }

    getFetchUrl() {
        return 'options/attr/groups';
    }

    parseResponse(response) {
        return response.groups;
    }

    getTableName() {
        return 'attr_groups_table';
    }

    getSearchGuideOptions() {
        return [];
    }


}

/**
 * @return {AttrGroupsTable}
 */
export default function useAttrGroupsTable() {
    return AttrGroupsTable.init();
}
