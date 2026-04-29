import TableColumn from "@/utils/table/Columns/TableColumn";
import Storage from "@/utils/Storage";
import HasAttributes from "@/utils/Contract/HasAttributes";
import Mixin from "@/utils/Mixin";
import CanBeAttached from "@/utils/table/Contract/CanBeAttached";
import Query from "@/Bits/Components/Table/BaseComponents/Query/Query";


/**
 * Class representing a grid layout.
 * @mixes HasAttributes
 * @mixes CanBeAttached
 */
class TableManager {
    static make(endPoint = 'index', name = '', urlParams = {}, accessor = 'data') {
        return new this(accessor, endPoint, name, urlParams);
    }

    constructor(accessor, endPoint = 'index', name = '', urlParams) {
        this.accessor = accessor;
        this.endPoint = endPoint;
        this.urlParams = urlParams;

        //this.searchDebounce = Utils.debounce(this.fetch, 500, this);
        this.searchDebounce = this.debounce(this.fetch, 500)
        this.dynamicQuery = null;

        this.tableName = name;


        this.setupPagination();

        this.data = [];
        //this.visibleColumns = ([]);
        this.columns = {};
        this.sortableColumns = {};

        this.isBusy = true;
        this.isReady = true;
        this.skeletonRowCount = (10 * 2);
        this.search = '';
        this.selections = [];
        this.sortCriteria = [];
        this.sort = ({
            sortBy: 'id',
            sortType: 'DESC'
        });
        this.filters = ({
            applied: {},
            option: {},
        });

        this.onFetchCallback = [];
        this.onSuccessCallback = [];
        this.onErrorCallback = [];

        this.buildSearchQueryUsing = null;
        this.modifyFilterUsing = null;
        this.buildQueryUsing = null;

        this._enablePagination = true;
        this._enableSearching = true;
        this._enableFiltering = true;
        this._enableSorting = true;
        this._enableColumnVisibility = true;
        this._enableColumnOrdering = false;

        Mixin.install(this, HasAttributes);
        Mixin.install(this, CanBeAttached);
        this.onSync = false;

        this.init();
    }

    setupPagination() {
        this.paginate = ({
            per_page: this.getFromStorage('per_page', 10),
            current_page: 1,
            total: 0,
            last_page: 1,
            from: 1,
            to: 10,
        });
    }


    columnOrdering(enableOrdering = true) {
        this._enableColumnOrdering = enableOrdering;
        return this;
    }

    shouldReorderColumn() {
        return this._enableColumnOrdering;
    }

    getColumnOrderFromStorage() {
        const orderColumns = Storage.get(`${this.tableName}_order`, {});
        return Array.isArray(orderColumns) ? {} : orderColumns;
    }

    setColumnOrderInStorage(columns) {
        if (!this.shouldManageStorage()) return;
        this.setStorage('order', columns)
        //Storage.set(`${this.tableName}_order`, columns);
    }

    disableSearching() {
        this._enableSearching = false;
        return this;
    }

    enableSearching() {
        this._enableSearching = true;
        return this;
    }

    shouldSearch() {
        return this._enableSearching;
    }

    disablePagination() {
        this._enablePagination = false;
        return this;
    }

    enablePagination() {
        this._enablePagination = true;
        return this;
    }

    shouldPaginate() {
        return this._enablePagination;
    }

    disableFiltering() {
        this._enableFiltering = false;
        return this;
    }

    enableFiltering() {
        this._enableFiltering = true;
        return this;
    }

    shouldFilter() {
        return this._enableFiltering;
    }


    disableSorting() {
        this._enableSorting = false;
        return this;
    }

    enableSorting() {
        this._enableSorting = true;
        return this;
    }

    shouldSort() {
        return this._enableSorting;
    }

    disableColumnVisibility() {
        this._enableColumnVisibility = false;
        return this;
    }

    enableColumnVisibility() {
        this._enableColumnVisibility = true;
        return this;
    }

    manageColumnVisivility() {
        return this._enableColumnVisibility;
    }

    enableDynamicQuery(query) {
        if (!query instanceof Query) {
            throw new Error('Query Builder must be an instance of Query');
        }
        this.dynamicQuery = query;
    }

    get isDynamicQueryEnabled() {
        return this.dynamicQuery instanceof Query;
    }


    addOnFetchCallback(callback) {
        this.onFetchCallback.push(callback);
    }

    addOnSuccessCallback(callback) {
        this.onSuccessCallback.push(callback);
    }

    addOnErrorCallback(callback) {
        this.onErrorCallback.push(callback);
    }

    setBuildSearchQueryUsing(callback) {
        this.buildSearchQueryUsing = callback;
    }

    setModifyFilterUsing(callback) {
        this.modifyFilterUsing = callback;
    }

    setBuildQueryUsing(callback) {
        this.buildQueryUsing = callback;
    }

    buildColumnsFromObject(cols) {
        Object.keys(cols).forEach((key) => {
            const column = cols[key];
            this.columns[key] = column instanceof TableColumn ? column.build() : cols[key];
        });
    }

    buildColumnsFromArray(cols) {
        cols.forEach((col) => {
            const build = col instanceof TableColumn ? col.build() : col;
            this.columns[build.key] = build;
        });
    }

    setColumns(cols) {
        this.columns = {};

        if (Array.isArray(cols)) {
            this.buildColumnsFromArray(cols);
        } else if (typeof cols === 'object') {
            this.buildColumnsFromObject(cols);
        }

        this.isReady = true;
        this.mergeVisible();
        this.buildSortableColumns();
    }

    buildSortableColumns() {
        this.sortableColumns = [];
        Object.values(this.columns).forEach((column) => {
            if (column.sortable === true) {
                this.sortableColumns.push({
                    label: column.title,
                    value: column.sortableKey
                },)
            }
        });
    }

    shouldManageStorage() {
        return !(this.tableName.length === 0);
    }

    mergeVisible() {
        if (!this.shouldManageStorage()) return;

        let storageColumns = this.getFromStorage('columns');
        if (Array.isArray(storageColumns)) {
            storageColumns = {};
        }
        Object.keys(this.columns).forEach((column) => {
            if (typeof this.columns[column].visible !== "function") {
                this.columns[column].visible = !(storageColumns[column] === false);
                storageColumns[column] = this.columns[column].visible;
            } else {
                delete storageColumns[column];
            }

            if (column.toggleable === false) {
                delete storageColumns[column];
            }

        });
        this.setStorage('columns', storageColumns);
    }

    getFromStorage(accessor, defaultValue = {}) {
        return Storage.get(`${this.tableName}_table_${accessor}`, defaultValue);
    }

    setStorage(key, data) {
        if (!this.shouldManageStorage()) return;
        Storage.set(`${this.tableName}_table_${key}`, data);
    }

    setVisible(columnsToSet) {
        this.visibleColumns = columnsToSet;
    }

    setTableName(name) {
        this.tableName = name;
    }

    onColumnVisibilityChange(column, visible) {
        if (!this.shouldManageStorage()) return;
        let storageColumns = this.getFromStorage('columns');
        if (Array.isArray(storageColumns)) {
            storageColumns = {};
        }
        storageColumns[column] = !!visible;
        this.setStorage('columns', storageColumns);
    }

    setSort(sortBy = 'id', sortType = 'DESC') {
        this.sort.sortBy = sortBy;
        this.sort.sortType = sortType;
    }

    resetFilter() {
        this.filters.applied = {};
        this.paginate.current_page = 1;
        this.fetch();
    }

    applyFilter(appliedFilters) {
        this.filters.applied = appliedFilters;
        this.paginate.current_page = 1;
        this.fetch();
    }

    removeFilter(name) {
        delete this.filters.applied[name];
        this.fetch();
    }

    setFilterOptions(options) {
        this.filters.option = options;
    }

    onError(error) {
        this.callback(this.onErrorCallback, error);
        //throw new Error(error);
    }

    onSuccess(response,) {
        const responseData = response[this.accessor];
        this.setData(responseData.data);
        Object.assign(this.paginate, {
            total: responseData.total,
            last_page: responseData.last_page,
            from: responseData.from,
            to: responseData.to,
        });
        this.callback(this.onSuccessCallback, response);
    }

    setData(data) {
        this.data = data;
    }

    always() {
        this.isBusy = false;
    }

    /**
     * Set if the table is busy.
     * @param {Boolean} busy - set if the table is busy.
     * @returns {TableManager}
     */
    busy(busy = true) {
        this.isBusy = busy;
        return this;
    }

    callback(callable, ...args) {
        if (typeof callable === 'function') {
            return callable(...args);
        } else if (Array.isArray(callable)) {
            callable.forEach((func) => {
                func(...args);
            })
        }
        return undefined;
    }

    callController(endPoint, query = null) {
        return ;
    }

    load() {
        this.fetch()
    }

    fetch(skipBusy = false) {

        if (this.onSync === true) {
            return;
        }

        this.callback(this.onFetchCallback);
        //return;

        if (!skipBusy) {
            this.isBusy = true;
        }


        const queryParams = {
            ...this.shouldPaginate() ? {
                per_page: this.paginate.per_page,
                page: this.paginate.current_page,
            } : {},

            ...this.shouldSort() ? {
                order_by: this.sort.sortBy,
                order_type: this.sort.sortType,
            } : {},

            ...this.shouldSearch() ? {
                search: this.callback(this.buildSearchQueryUsing, this.search) ?? this.search,
            } : {},

            ...this.shouldFilter() ? {
                filters: this.callback(this.modifyFilterUsing, {...this.filters.applied}) ?? this.filters.applied,
            } : {},

            ...this.isDynamicQueryEnabled ? {
                dynamic_filters: this.dynamicQuery.query,
            } : {},

            ...this.sortCriteria ? {
                sort_criteria: this.buildSortCriteria(),
            } : {}

        };

        const finalQuery = this.callback(this.buildQueryUsing, queryParams) ?? queryParams;
        this.callController(this.endPoint, finalQuery)
            .then(this.onSuccess.bind(this))
            .catch(this.onError.bind(this))
            .finally(this.always.bind(this));
    }

    buildSortCriteria() {
        return this.sortCriteria.map((criteria) => {
            return {
                key: criteria.sortableKey,
                order: criteria.order
            }
        })
    }

    onCurrentPageChanged({page, currentPageCount}) {
        this.skeletonRowCount = currentPageCount * 2;
        this.paginate.current_page = page;
        this.fetch();
    }

    onPerPageChanged(perPage) {
        this.skeletonRowCount = 20;
        this.paginate.current_page = 1;
        this.paginate.per_page = perPage;
        this.setStorage('per_page', perPage);
        this.fetch();
    }

    isColumnVisible(column) {
        if (typeof column.visible === "function") {
            return column.visible(column, this.data);
        }
        return column.visible;
    }

    init() {
        // Initialization logic (e.g., onMounted(fetch) can be uncommented to fetch data on mount)
    }


    debounce(callback, wait = 300) {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                callback.bind(this)(args)
            }, wait);
        };
    }


    toggleSort(column, order) {
        const key = column.sortableKey;
        const index = this.columnSortingIndex(key);
        if (index < 0) {
            this.applySort(column, order);
        } else {
            const isSorted = this.isColumnSorted(key, order);
            this.removeSort(index);
            if (!isSorted) {
                this.applySort(column, order);
            }
        }
        this.fetch(true);
    }

    isColumnSorted(key, order) {
        const index = this.columnSortingIndex(key);
        if (index === -1) {
            return false;
        }
        return this.sortCriteria[index].order === order;
    }

    columnSortingIndex(key) {
        return this.sortCriteria.findIndex((item) => item.key === key);
    }

    applySort(column, order) {
        const key = column.sortableKey;
        const title = column.title;
        this.sortCriteria.push({
            key,
            order,
            title,
            sortableKey: key,
            sortable: true
        });
    }

    applyLegacySort() {
        this.fetch();
    }

    removeSort(index) {
        this.sortCriteria.splice(index, 1);

    }


    getDefaultSort() {
        return this.sortCriteria.length ? this.sortCriteria[0] : {};
    }

    attach(tableManager) {
        this.makeAttachment(tableManager, this)
    }


}

export default TableManager;
