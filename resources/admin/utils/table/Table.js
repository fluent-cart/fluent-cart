import {getCurrentInstance, onMounted, ref} from 'vue';
import TableColumn from "@/utils/table/Columns/TableColumn";

/**
 * Custom composable function for handling table state, sorting, pagination, filtering, and data fetching.
 *
 * This function provides reactive properties and methods for managing a data table in a Vue component.
 * It handles the table's columns, visibility, pagination, sorting, filtering, and data fetching from a
 * specified controller endpoint. It also includes callbacks for custom behaviors during the fetch process.
 *
 * @param {string} accessor - The key used to access the data in the fetched response.
 * @param {Object} controller - The controller object responsible for making API calls, typically having methods to perform actions like fetching data.
 * @param {string} [endPoint='index'] - The default API endpoint to call for fetching the table data.
 *
 * @param name
 * @returns {Object} Table management object containing the following reactive properties and methods:
 *
 * Reactive Properties:
 * @property {Object} sort - Contains the current sort state:
 *   @property {string} sort.sortBy - The column to sort by (default: 'id').
 *   @property {string} sort.sortType - The sorting direction, either 'ASC' or 'DESC' (default: 'DESC').
 *
 * @property {Object} filters - Contains filter-related information:
 *   @property {Object} filters.applied - The currently applied filters.
 *   @property {Object} filters.option - The available filter options.
 *
 * @property {Object} paginate - Manages pagination state:
 *   @property {number} paginate.per_page - The number of items per page (default: 10).
 *   @property {number} paginate.current_page - The current page (default: 1).
 *   @property {number} paginate.total - Total number of items.
 *   @property {number} paginate.last_page - The last available page.
 *   @property {number} paginate.from - The first item index on the current page.
 *   @property {number} paginate.to - The last item index on the current page.
 *
 * @property {Array} data - The table data retrieved from the server.
 *
 * @property {Array} visibleCoulmns - List of visible column names.
 *
 * @property {Object} columns - Column definitions for the table.
 *
 * @property {string} tableName - The name of the table.
 *
 * @property {boolean} isBusy - Indicates if data fetching is currently in progress.
 *
 * @property {boolean} isReady - Indicates if the table is ready to be rendered.
 *
 * @property {number} skeletonRowCount - Number of skeleton rows to display while the data is loading.
 *
 * @property {string} search - The current search term applied to the table.
 *
 * Methods:
 *
 * @method applyFilter(appliedFilters) - Applies the specified filters and fetches the updated table data.
 *
 * @method removeFilter(name) - Removes a filter by name and fetches the updated table data.
 *
 * @method resetFilter() - Resets all applied filters to their default state and fetches the data.
 *
 * @method setFilterOptions(options) - Sets the available filter options for the table.
 *
 * @method applySort() - Triggers a data fetch based on the current sort order.
 *
 * @method fetch() - Fetches the table data from the server using the current sorting, filters, and pagination state.
 *
 * @method setColumns(cols) - Sets the table's column definitions.
 *
 * @method setVisible(columnsToSet) - Updates the visible columns by providing a list of column names.
 *
 * @method setTableName(name) - Sets the name of the table.
 *
 * @method setSort(sortBy = 'id', sortType = 'DESC') - Sets the sort criteria for the table.
 *
 * @method onCurrentPageChanged({page, currentPageCount}) - Handles page changes by updating the current page and fetching new data.
 *
 * @method onPerPageChanged(perPage) - Updates the number of items per page and triggers a new data fetch.
 *
 * @method isColumnVisible(columnName) - Checks if a specific column is currently visible.
 *
 * @method searchDebounce - Debounced version of the `fetch` method, used to delay data fetching when performing a search.
 *
 * Callback Management:
 *
 * @method setOnFetchCallback(callback) - Sets a custom callback to be executed before data fetching starts.
 *
 * @method setOnSuccessCallback(callback) - Sets a custom callback to be executed when data fetching succeeds.
 *
 * @method setOnErrorCallback(callback) - Sets a custom callback to be executed when an error occurs during data fetching.
 *
 * @method setBuildSearchQueryUsing(callback) - Sets a custom callback for building the search query string based on the current search term.
 *
 * @method setBuildQueryUsing(callback) - Sets a custom callback for building the query parameters to be sent in the API request.
 */
export function useTable(
    accessor,
    controller,
    endPoint = 'index',
    name = '',
    urlParams
) {

    let vueInstance = null;

    onMounted(() => {
        vueInstance = getCurrentInstance().ctx;
    })

    const paginate = ref({
        per_page: 10,
        current_page: 1,
        total: 0,
        last_page: 1,
        from: 1,
        to: 10,
    });

    const data = ref([]);
    const visibleCoulmns = ref([]);
    const columns = ref({});
    let tableName = ref(name);
    const isBusy = ref(true);
    const isReady = ref(true);
    let skeletonRowCount = ref(10 * 2);
    const search = ref('');
    const selections = ref([]);

    const sort = ref({
        sortBy: 'id',
        sortType: 'DESC'
    });

    let filters = ref({
        applied: {},
        option: {}

    });

    let onFetchCallback;
    const setOnFetchCallback = (callback) => {
        onFetchCallback = callback;
    }
    let onSuccessCallback;
    const setOnSuccessCallback = (callback) => {
        onSuccessCallback = callback;
    }

    let onErrorCallback;
    const setOnErrorCallback = (callback) => {
        onErrorCallback = callback;
    }

    let buildSearchQueryUsing;
    const setBuildSearchQueryUsing = (callback) => {
        buildSearchQueryUsing = callback;
    }

    let modifyFilterUsing;
    const setModifyFilterUsing = (callback) => {
        modifyFilterUsing = callback;
    }

    let buildQueryUsing;
    const setBuildQueryUsing = (callback) => {
        buildQueryUsing = callback;
    }


    const buildColumnsFromObject = (cols) => {
        Object.keys(cols).forEach((key, index) => {
            const column = cols[key];
            if (column instanceof TableColumn) {
                columns.value[key] = column.build.bind(column)();
            } else {
                columns.value[key] = cols[key];
            }
        })
    }

    const buildColumnsFromArray = (cols) => {
        cols.forEach((col) => {
            if (col instanceof TableColumn) {
                const build = col.build.bind(col)();
                columns.value[build.key] = build;
            } else {
                columns.value[col.key] = col;
            }
        })
    }


    const setColumns = (cols) => {
        columns.value = {};

        if (Array.isArray(cols)) {
            buildColumnsFromArray(cols)
        } else if (typeof cols === 'object') {
            buildColumnsFromObject(cols)
        }

        isReady.value = true;
        mergeVisible();
    }

    const shouldManageStorage = () => {
        return !(tableName.value.length === 0 || vueInstance === null);
    }


    const mergeVisible = () => {
        if (!shouldManageStorage()) {
            return;
        }

        const orderColumns = getFromStorage();


        Object.keys(columns.value).forEach((column, index) => {
            if (orderColumns.hasOwnProperty(column)) {
                columns.value[column].visible = !(orderColumns[column] === false);
            } else {
                orderColumns[column] = columns.value[column].visible;
            }
        })
        setStorage(orderColumns);
    }

    const getFromStorage = () => {
        const orderColumns = vueInstance.Storage().get(`${tableName.value}_table`, {});
        if (Array.isArray(orderColumns)) {
            return {};
        }
        return orderColumns;
    }

    const setStorage = (columns) => {
        if (!shouldManageStorage()) {
            return;
        }

        vueInstance.Storage().set(`${tableName.value}_table`, columns);
    }

    const setVisible = (columnsToSet) => {
        visibleCoulmns.value = columnsToSet;
    }

    const setTableName = (name) => {
        tableName.value = name;
    }


    const onColumnVisibilityChange = (column, visible) => {

        if (!shouldManageStorage()) {
            return;
        }

        let storageColumns = getFromStorage();


        if (!storageColumns.hasOwnProperty(column)) {
            storageColumns[column] = true;
        }

        storageColumns[column] = !!visible;


        setStorage(storageColumns);
    }

    const setSort = (sortBy = 'id', sortType = 'DESC') => {
        sort.value.sortBy = sortBy ?? 'id';
        sort.value.sortType = sortType ?? 'DESC';
        //setAttribute('sort', sort);
    }

    const applySort = () => {
        fetch();
    }

    const resetFilter = () => {
        filters.value.applied = {};
        fetch();
    }

    const applyFilter = (appliedFilters) => {
        filters.value.applied = appliedFilters;
        fetch();
    }

    const removeFilter = (name) => {
        //TODO
        delete filters.value.applied[name];
        fetch();
    }

    const setFilterOptions = (options) => {
        filters.value.option = options;
    }


    const onError = (error) => {
        callback(onErrorCallback, error);
        throw new Error(error);
    };


    const onSuccess = (response) => {
        data.value = response[accessor].data;
        paginate.value.total = response[accessor].total;
        paginate.value.last_page = response[accessor].last_page;
        paginate.value.from = response[accessor].from;
        paginate.value.to = response[accessor].to;
        callback(onSuccessCallback, response);
    };

    const always = () => {
        isBusy.value = false;
    };

    const setAttribute = (name, value) => {
        //model.setAttribute(`${tableName}.${name}`, value);
    };

    const callback = (callable, ...args) => {
        if (typeof callable === 'function') {
            return callable(...args);
        }
        return undefined;
    }

    const callController = (endPoint, query = null) => {
        if (typeof query === 'object') {
            return controller.withQuery(query)[endPoint](urlParams);
        } else {
            return controller[endPoint](urlParams);
        }
    };

    const fetch = () => {
        callback(onFetchCallback);
        isBusy.value = true;
        let queryParams = {
            "per_page": paginate.value.per_page,
            "page": paginate.value.current_page,
            "order_by": sort.value.sortBy,
            "order_type": sort.value.sortType
        };

        const searchAttributes = callback(buildSearchQueryUsing, search.value) ?? search.value;
        if (searchAttributes) {
            queryParams['search'] = searchAttributes;
        }

        const spreadFilters = {...filters.value.applied}
        const filterToApply = callback(modifyFilterUsing, spreadFilters) ?? spreadFilters;
        if (filterToApply) {
            queryParams['filters'] = filterToApply;
        }

        queryParams = callback(buildQueryUsing, filters.value.applied, queryParams) ?? queryParams;
        // if (filtersAttributes) {
        //     queryParams['filters'] = filtersAttributes;
        // }

        callController(endPoint, queryParams)
            .then(onSuccess)
            .catch(onError)
            .finally(always);
    };

    const onCurrentPageChanged = ({page, currentPageCount}) => {
        //setAttribute('skeletonRowCount', currentPageCount * 2);
        skeletonRowCount.value = currentPageCount * 2;
        paginate.value.current_page = page;
        //setAttribute('paginate.current_page', page);
        fetch();
    };

    const onPerPageChanged = (perPage) => {
        skeletonRowCount.value = 20;
        paginate.value.current_page = 1;
        paginate.value.per_page = perPage;
        fetch();
    };

    const isColumnVisible = (columnName) => {
        return visibleCoulmns.value.indexOf(columnName) !== -1;
    };

    // Initialization logic
    const init = () => {
        //onMounted(fetch); // Use onMounted to fetch data on component mount
    };

    init();

    const debounce = (callback, waitTime) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                callback(...args);
            }, waitTime);
        };
    }


    const searchDebounce = debounce(fetch, 500);


    // Expose the table state and methods
    return {
        sort,
        filters,
        setSort,
        applyFilter,
        removeFilter,
        resetFilter,
        setFilterOptions,
        applySort,
        skeletonRowCount,
        data,
        isBusy,
        isReady,
        columns,
        visibleCoulmns,
        tableName,
        setVisible,
        setTableName,
        setColumns,
        paginate,
        fetch,
        search,
        onCurrentPageChanged,
        onPerPageChanged,
        isColumnVisible,
        searchDebounce,
        onColumnVisibilityChange,
        selections,
        callbacks: {
            setOnFetchCallback,
            setOnSuccessCallback,
            setOnErrorCallback,
            setBuildSearchQueryUsing,
            setBuildQueryUsing,
            setModifyFilterUsing
        }
    };
}
