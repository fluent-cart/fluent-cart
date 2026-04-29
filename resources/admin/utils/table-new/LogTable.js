import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";

class LogTable extends Table {


    getTabs() {
        return {
            all: translate("All"),
            success: translate("Success"),
            warning: translate('Warning'),
            error: translate('Error'),
            failed: translate('Failed'),
            info: translate('Info'),
            api: translate('API Only'),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: 'Title',
                value: 'title'
            },
            {
                label: 'Content',
                value: 'content'
            },
            {
                label: 'Date',
                value: 'date'
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
                label: translate('Created At'),
                value: 'created_at'
            },


        ]
    }

    getSearchHint() {
        return translate("Search by id, title, content or module.")
    }

    getFetchUrl() {
        return 'activity';
    }

    parseResponse(response) {
        return response.activities;
    }

    getTableName() {
        return 'log_table';
    }

    getAdvanceFilterOptions() {
        return null;
    }

    getSearchGuideOptions() {
        return [];
    }

}


/**
 * @return {LogTable}
 */
export default function useLogTable(data) {
    return LogTable.init(data);
}

