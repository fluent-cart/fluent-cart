import TableManager from "@/utils/table/TableManager";

/**
 * Class representing a grid layout.
 * @mixes TableManager
 */
export default class CanBeAttached {
    onSync = false;

    makeAttachment(tableManager, instance) {
        if (!tableManager instanceof TableManager) {
            throw new Error('The table you want to attach must be an TableManager instance');
        }
        instance.onSync = true;
        instance.accessor = tableManager.accessor;
        instance.disableSearching();
        instance.disablePagination();
        instance.disableFiltering();
        instance.disableSorting();


        tableManager.addOnSuccessCallback((response) => {
            instance.onSuccess(response);
            instance.isBusy = false;
        });

        tableManager.addOnFetchCallback(() => {
            instance.isBusy = true;
        });

        tableManager.addOnErrorCallback((error) => {
            instance.onError(error);
            instance.isBusy = false;
        });

    }
}