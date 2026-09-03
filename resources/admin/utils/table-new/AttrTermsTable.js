import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";


class AttrTermsTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "serial";
        this.data.sorting.sortType = "ASC";
    }

    setGroupId(groupId) {
        this.data.groupId = groupId;
    }

    getTabs() {
        return null;
    }

    getToggleableColumns() {
        return [];
    }

    getSearchHint() {
        return translate("Search by title or slug.")
    }

    getFetchUrl() {
        return 'options/attr/group/' + this.data.groupId + '/terms';
    }

    parseResponse(response) {
        return response.terms;
    }

    getTableName() {
        return 'attr_terms_table';
    }

    getSearchGuideOptions() {
        return [];
    }
}

/**
 * @return {AttrTermsTable}
 */
export default function useAttrTermsTable(data = {}) {
    return AttrTermsTable.init(data);
}
