import Model from "@/utils/model/Model";
import {onBeforeMount, ref} from "vue";
import Storage from "@/utils/Storage";
import Rest from "@/utils/http/Rest";
import {useRoute} from "vue-router";
import Url from "@/utils/support/Url";
import Arr from "@/utils/support/Arr";
import translate from "@/utils/translator/Translator";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import Notify from "@/utils/Notify";
import Message from "@/utils/Message";
import AppConfig from "@/utils/Config/AppConfig";

/**
 * Class representing a table filtering functionality.
 * Extends Model and provides features like searching, sorting, pagination, and advanced filtering.
 */
export default class Table extends Model {
    constructor(data = {}) {
        super();
        onBeforeMount(() => {
            this.setupInitialData();
            dayjs.extend(utc);
            dayjs.extend(timezone);
            this.data.user_tz = dayjs.tz.guess();
            this.data.vueInstance = data.instance;
            if (data.fetch === false) {
                return;
            }
            this.fetch()
        })


    }

    /**
     * Set The OnData loaded callback
     */
    onDataLoaded(callback) {
        this.data.callbacks.onDataLoaded = callback;
    }

    /**
     * Reactive data properties for the table.
     */
    data = {
        callbacks: {
            onDataLoaded: null
        },

        tableData: [],
        search: '',
        advanceFilters: [
            []
        ],
        searching: false,
        selectedView: '',
        sorting: {
            sortBy: 'id',
            sortType: 'DESC'
        },
        paginate: {
            current_page: 1,
            per_page: 10,
            last_page: 1,
            total: 0,
            from: 1,
            to: 10,
        },
        filterType: 'simple',
        columns: [],
        loading: false,
        inputRef: ref(null),
        nextPageCount: 10,
        user_tz: null,
        showDeleteBulkAction: false,
        savedViews: [],
        activeSavedViewId: null,
        savedViewQueryParams: null,
        showSaveViewDialog: false
    }

    /**
     * Initializes table data, including selected view and stored columns.
     */
    setupInitialData() {
        this.setupInitialTab();
        this.setupInitialColumnVisibility();
        this.setupInitialFilterType();
        this.setupInitialAdvanceFilter();
        this.setupInitialSearch();
        this.setupInitialPagination();
        this.setupSavedViews();
    }

    setupInitialSearch() {
        if (this.isRouteSearchApplied()) {
            this.data.search = this.isRouteSearchApplied();
            this.data.searching = true;
        }
    }


    setupInitialPagination() {
        const per_page = Storage.get(
            this.getPerPageStorageName()
        );

        const current_page = Storage.get(
            this.getCurrentPageStorageName()
        );
        this.data.paginate.per_page = parseInt(per_page) || 10;
        this.data.paginate.current_page = parseInt(current_page) || 1;
    }

    setupInitialTab() {

        const route = useRoute();
        const routerActiveView = route.query.active_view?.toString();

        const tabs = this.getTabsCount() ? this.getTabs() : {};

        // URL always wins — supports custom links like ?active_view=draft
        if (routerActiveView) {
            // URL points to a static tab
            if (tabs.hasOwnProperty(routerActiveView)) {
                this.data.selectedView = routerActiveView;
                return;
            }
            // URL points to something else — could be a saved view (checked later in setupSavedViews)
            this.data.pendingViewFromUrl = routerActiveView;
            this.data.selectedView = this.getDefaultView();
            return;
        }

        // No URL param — fall back to localStorage
        const storageTab = Storage.get(this.getTabStorageName());

        if (typeof storageTab === 'string' && tabs.hasOwnProperty(storageTab)) {
            this.data.selectedView = storageTab;
            return;
        }

        // Could be a saved view slug in storage — defer to setupSavedViews
        if (typeof storageTab === 'string' && storageTab !== '') {
            this.data.pendingViewFromStorage = storageTab;
        }

        this.data.selectedView = this.getDefaultView();
    }

    setupSavedViews() {
        // Load saved views from table config
        this.data.savedViews = Arr.get(window, `fluentCartAdminApp.table_config.${this.getTableName()}.saved_views`) || [];

        // Resolve pending view — URL takes priority over storage
        const pendingId = this.data.pendingViewFromUrl || this.data.pendingViewFromStorage;
        delete this.data.pendingViewFromUrl;
        delete this.data.pendingViewFromStorage;

        if (pendingId) {
            const view = this.data.savedViews.find(v => v.slug === pendingId || String(v.id) === String(pendingId));
            if (view) {
                this.data.activeSavedViewId = view.slug;
                this.data.savedViewQueryParams = view.query_params;
                this._resetLiveFilter();
                this.data.selectedView = this.getDefaultView();
            } else {
                this.data.selectedView = this.getDefaultView();
                Storage.set(this.getTabStorageName(), this.getDefaultView());
                Url.pushToVueUrl(null, {active_view: this.getDefaultView()});
            }
        }
    }

    /**
     * Handles tab changes, resets pagination, and fetches new data.
     * @param {string} currentView - The selected tab view.
     */
    handleTabChanged(currentView) {
        const wasOnSavedView = !!this.data.activeSavedViewId;

        if (wasOnSavedView) {
            this.data.activeSavedViewId = null;
            this.data.savedViewQueryParams = null;
            this._resetLiveFilter();
        }

        if (currentView === this.data.selectedView && !wasOnSavedView) {
            return;
        }

        this.data.selectedView = currentView;
        this.data.paginate.current_page = 1;
        this.setCurrentPage(1);

        Storage.set(this.getTabStorageName(), this.data.selectedView);
        Url.pushToVueUrl(null, {active_view: this.data.selectedView});
        this.fetch();
    }

    /**
     * Returns the selected view.
     * @returns {string}
     */
    getSelectedTab() {
        return this.data.selectedView;
    }

    setupInitialColumnVisibility() {
        let storageColumns = Storage.get(this.getColumnStorageName());

        if (Array.isArray(storageColumns)) {
            this.data.columns = storageColumns;
        } else {
            let columns = [];
            for (let column of this.getToggleableColumns()) {
                columns.push(column.value)
            }
            this.data.columns = columns;
        }
    }

    /**
     * Checks if searching mode is active.
     * @returns {boolean}
     */
    isSearching() {
        return this.data.searching !== false;
    }

    /**
     * Enables search mode and fetches new data.
     * If a saved view is active, pre-fill the filter UI with its stored params on first open.
     */
    openSearch() {
        if (
            this.data.activeSavedViewId &&
            this.data.savedViewQueryParams &&
            this.data.savedViewQueryParams.filter_type !== 'advanced'
        ) {
            this._prefillFiltersFromParams(this.data.savedViewQueryParams);
        }
        this.data.searching = !this.isUsingAdvanceFilter();

        Storage.set(
            this.getToggleSearchingStorageName(),
            this.data.searching
        )

        this.fetch();
    }

    /**
     * Disables search mode, clears the search query, and fetches new data.
     */
    closeSearch() {
        this.data.searching = false;
        this.data.search = "";

        Storage.set(
            this.getToggleSearchingStorageName(),
            this.data.searching
        )
        this.fetch();
    }

    showBulkDeleteAction(value = null) {
        this.data.showDeleteBulkAction = value;
        Storage.set(
            this.getDeleteBulkActionStorageName(),
            this.data.showDeleteBulkAction
        )
    }

    useFullWidthSearch() {
        return false;
    }


    /**
     * Fetches new data when sorting is changed.
     */
    handleSortChange() {
        this.fetch()
    }

    /**
     * Checks if the simple filter is enabled.
     * @returns {boolean}
     */
    isUsingSimpleFilter() {
        return this.data.filterType === 'simple';
    }

    setupInitialFilterType() {

        if (!this.isProActive()) {
            this.data.filterType = 'simple';
            return;
        }
        if (this.isRouteActiveViewApplied() || this.isRouteSearchApplied()) {
            this.data.filterType = 'simple';
            return;
        }

        const storageFilterType = Storage.get(
            this.getFilterTypeStorageName()
        )

        if (['simple', 'advanced'].includes(storageFilterType)) {
            if (storageFilterType === 'advanced' && !this.isAdvanceFilterEnabled()) {
                return;
            }
            this.data.filterType = storageFilterType;
        }

        const toggleSearching = Storage.get(
            this.getToggleSearchingStorageName()
        )

        if (toggleSearching) {
            this.data.searching = toggleSearching;
        }

        const getDeleteBulkActionStorage = Storage.get(
            this.getDeleteBulkActionStorageName()
        )

        if (getDeleteBulkActionStorage) {
            this.data.showDeleteBulkAction = getDeleteBulkActionStorage;
        }

    }


    onFilterTypeChanged(filterType) {
        // Do not deactivate the saved view — it stays active as a backend scope
        // regardless of which filter mode the user switches to.

        if (filterType === 'advanced') {
            this.data.searching = false;
            this.data.search = '';
            // Pre-fill advanced filters from the saved view if it stored advanced params
            if (this.data.activeSavedViewId && this.data.savedViewQueryParams &&
                this.data.savedViewQueryParams.filter_type === 'advanced') {
                this._prefillFiltersFromParams(this.data.savedViewQueryParams);
            }
        }

        //If a user enables the simple filter, we don't need to refetch
        if (filterType === 'simple') {
            this.fetch();
        }

        Storage.set(
            this.getFilterTypeStorageName(),
            this.data.filterType
        )
    }

    setupInitialAdvanceFilter() {
        let advanceFilter = Storage.get(this.getAdvancedFilterStorageName());
        if (typeof advanceFilter === 'string') {
            advanceFilter = [[]];
        }
        this.data.advanceFilters = advanceFilter;
    }

    isProActive() {
        return AppConfig.get('app_config.isProActive');
    }

    applyAdvancedFilter(isRemoving = false) {

        if (!this.isProActive()) {
            //Open a modal here, maybe using elMessagebox?
            if (!isRemoving) {
                Message.showFeaturesCTA(
                    translate('Advanced Filter'),
                    translate('Advanced filter is only available in pro version'),
                    [],
                    this.data.vueInstance
                );
            }
            this.storeAdvanceFilter();
            return;
        }
        //this.data.paginate.current_page = 1;
        this.setCurrentPage(1);
        this.fetch();
        this.storeAdvanceFilter();
    }

    storeAdvanceFilter() {
        Storage.set(
            this.getAdvancedFilterStorageName(),
            this.data.advanceFilters
        );
    }

    /**
     * Checks if the advanced filter is enabled.
     * @returns {boolean}
     */
    isUsingAdvanceFilter() {
        return this.data.filterType === 'advanced';
    }

    /**
     * Adds a new group to advanced filters.
     */
    addAdvanceFilterGroup() {
        this.data.advanceFilters.push([])
    }

    /**
     * Removes an advanced filter group.
     * @param {number} index - Index of the group to remove.
     */
    removeAdvanceFilterGroup(index) {
        if (this.data.advanceFilters.length > 1) {
            this.data.advanceFilters.splice(index, 1);
        }
        this.storeAdvanceFilter();
    }

    /**
     * Clears all advanced filters and fetches new data.
     */
    clearAdvanceFilter() {
        this.data.advanceFilters = [[]];
        this.applyAdvancedFilter();
    }

    getAdvanceFilterOptions() {
        return Arr.get(window, `fluentCartAdminApp.table_config.${this.getTableName()}.filters.advance`);
    }

    getSearchGuideOptions() {
        return Arr.get(window, `fluentCartAdminApp.table_config.${this.getTableName()}.filters.guide`) || [];
    }

    isAdvanceFilterEnabled() {
        return this.getAdvanceFilterOptions() !== null;
    }

    /**
     * Builds query parameters for fetching data.
     * @returns {object}
     */
    buildQueryParams() {

        const params = {
            per_page: this.data.paginate.per_page,
            page: this.data.paginate.current_page,
            sort_by: this.data.sorting.sortBy,
            sort_type: this.data.sorting.sortType,
            with: this.with(),
            scopes: this.scopes()
        };

        if (this.data.activeSavedViewId) {
            params['active_view'] = this.data.activeSavedViewId;
        } else if (!this.isUsingAdvanceFilter()) {
            params['active_view'] = this.data.selectedView;
        }

        if (this.isUsingAdvanceFilter()) {
            params['filter_type'] = 'advanced';
            params['advanced_filters'] = JSON.stringify(this.data.advanceFilters);
        } else {
            params['filter_type'] = 'simple';
            params['search'] = this.data.search;
        }

        return params;
    }


    search() {
        Url.pushToVueUrl(null, {search: this.data.search});
        this.fetch();
    }

    /**
     * Fetches table data based on the built query parameters.
     */
    fetch() {
        this.data.loading = true;
        let queryParams = this.buildQueryParams();
        queryParams['user_tz'] = this.data.user_tz;
        Rest.get(this.getFetchUrl(), queryParams)
            .then(response => {
                const parsedResponse = this.parseResponse(response)
                this.data.tableData = parsedResponse.data;
                this.data.paginate.total = parsedResponse.total;
                this.data.paginate.last_page = parsedResponse.last_page;
                this.data.paginate.from = parsedResponse.from;
                this.data.paginate.to = parsedResponse.to;

                this.data.nextPageCount = this.guessNextPageCount(parsedResponse);


                if (typeof this.data.callbacks.onDataLoaded === 'function') {
                    this.data.callbacks.onDataLoaded(response);
                }
            })
            .catch((errors) => {
                //this.handleError(errors);
                if (errors.status_code == '422') {
                    Notify.validationErrors(errors);
                } else {
                    Notify.error(errors.data?.message);
                }
                this.data.tableData = [];
            })
            .finally(() => {
                this.data.loading = false;
            });
    }

    /**
     * Returns the data for table
     * @returns {object}
     */
    getTableData() {
        return this.data.tableData;
    }

    /**
     * Determines if the table is currently loading data.
     * @returns {boolean}
     */
    isLoading() {
        return this.data.loading === true;
    }

    /**
     * Retrieves the default view name.
     * @returns {string}
     */
    getDefaultView() {
        return 'all';
    }

    handleColumnVisibilityChange() {
        Storage.set(
            this.getColumnStorageName(),
            this.data.columns
        )
    }

    handlePerPageChange() {
        this.data.paginate.current_page = 1;
        this.setCurrentPage(1);
        Storage.set(
            this.getPerPageStorageName(),
            this.data.paginate.per_page
        )
        this.fetch();
    }

    handlePageChange() {
        this.fetch();
        this.setCurrentPage()
    }

    setCurrentPage(page) {
        Storage.set(
            this.getCurrentPageStorageName(),
            page || this.data.paginate.current_page
        )
    }

    getCurrentPage() {
        return Storage.get(
            this.getCurrentPageStorageName()
        )
    }

    getColumnStorageName() {
        return `${this.getTableName()}_columns`;
    }

    getTabStorageName() {
        return `${this.getTableName()}_tab`;
    }

    getFilterTypeStorageName() {
        return `${this.getTableName()}_filter_type`;
    }

    getAdvancedFilterStorageName() {
        return `${this.getTableName()}_advanced_filter`;
    }

    getPerPageStorageName() {
        return `${this.getTableName()}_per_page`;
    }

    getCurrentPageStorageName() {
        return `${this.getTableName()}_current_page`;
    }

    getToggleSearchingStorageName() {
        return `${this.getTableName()}_toggle_searching`;
    }

    getDeleteBulkActionStorageName() {
        return `${this.getTableName()}_delete_bulk_action`;
    }


    // ── Saved Views ─────────────────────────────────────

    getSavedViews() {
        return this.data.savedViews;
    }

    getActiveSavedViewId() {
        return this.data.activeSavedViewId;
    }

    getCurrentUserId() {
        return AppConfig.get('me.id');
    }

    isViewOwner(view) {
        return view.owner_id === this.getCurrentUserId();
    }

    promptAndSaveView() {
        if (!this.isProActive()) {
            Message.showFeaturesCTA(
                translate('Saved Views'),
                translate('Saved views is only available in pro version'),
                [],
                this.data.vueInstance
            );
            return;
        }
        this.data.showSaveViewDialog = true;
    }

    /**
     * Pre-fills the live filter state from saved view params so the user can see
     * what the view contains when they open the filter panel.
     * Uses the saved view's own filter_type so the correct panel is shown.
     */
    _resetLiveFilter() {
        this.data.search = '';
        this.data.searching = false;
        this.data.advanceFilters = [[]];
        this.data.filterType = 'simple';
        Storage.set(this.getFilterTypeStorageName(), 'simple');
    }

    _prefillFiltersFromParams(params) {
        if (!params) return;

        if (params.filter_type === 'advanced' && params.advanced_filters) {
            let filters;
            try {
                filters = typeof params.advanced_filters === 'string'
                    ? JSON.parse(params.advanced_filters)
                    : params.advanced_filters;
            } catch (e) {
                filters = [[]];
            }
            this.data.advanceFilters = Array.isArray(filters) ? filters : [[]];
            this.data.search = '';
            this.data.searching = false;
            this.data.filterType = 'advanced';
        } else {
            this.data.search = params.search || '';
            this.data.advanceFilters = [[]];
            this.data.searching = !!(params.search);
            this.data.filterType = 'simple';
            if (params.active_view) {
                this.data.selectedView = params.active_view;
            }
        }

        Storage.set(this.getFilterTypeStorageName(), this.data.filterType);
    }

    _buildViewQueryParams() {
        const queryParams = {filter_type: this.data.filterType};
        if (this.data.filterType === 'advanced') {
            queryParams['advanced_filters'] = JSON.stringify(this.data.advanceFilters);
        } else {
            queryParams['search'] = this.data.search;
            queryParams['active_view'] = this.data.selectedView;
        }
        return queryParams;
    }

    saveCurrentView(name, description, isPublic) {
        if (this.data.savedViews.length >= 20) {
            Notify.error(translate('Maximum of 20 saved views reached'));
            return null;
        }

        const queryParams = this._buildViewQueryParams();

        return Rest.post('saved-views', {
            object_type: this.getTableName(),
            name: name,
            description: description || '',
            is_public: isPublic || false,
            query_params: queryParams,
        }).then(response => {
            const view = response.view;
            view.object_type = this.getTableName();
            this.data.savedViews.push(view);
            this._syncGlobalSavedViews();

            // Activate the new saved view as a tab; backend applies its filters
            this.data.activeSavedViewId = view.slug;
            this.data.savedViewQueryParams = view.query_params;
            this._resetLiveFilter();
            this.data.selectedView = this.getDefaultView();
            Storage.set(this.getTabStorageName(), view.slug);
            Url.pushToVueUrl(null, {active_view: view.slug});
        }).catch(errors => {
            Notify.error(errors.data?.message || translate('Failed to save view'));
        });
    }

    applySavedView(viewId) {
        const view = this.data.savedViews.find(v => v.slug === viewId);
        if (!view) return;

        this.data.activeSavedViewId = viewId;
        this.data.savedViewQueryParams = view.query_params;
        this._resetLiveFilter();
        this.data.selectedView = this.getDefaultView();

        this.data.paginate.current_page = 1;
        this.setCurrentPage(1);
        Storage.set(this.getTabStorageName(), viewId);
        Url.pushToVueUrl(null, {active_view: viewId});
        this.fetch();
    }

    updateSavedView(viewId) {
        const view = this.data.savedViews.find(v => v.slug === viewId);
        if (!view) return;

        const queryParams = this._buildViewQueryParams();

        Rest.put('saved-views/' + view.id, {
            query_params: queryParams,
        }).then(response => {
            view.query_params = queryParams;
        }).catch(errors => {
            Notify.error(errors.data?.message || translate('Failed to update view'));
        });
    }

    deleteSavedView(viewId) {
        const view = this.data.savedViews.find(v => v.slug === viewId);
        if (!view) return;

        Rest.delete('saved-views/' + view.id)
            .then(() => {
                const index = this.data.savedViews.findIndex(v => v.slug === viewId);
                if (index !== -1) {
                    this.data.savedViews.splice(index, 1);
                }
                this._syncGlobalSavedViews();

                if (this.data.activeSavedViewId === viewId) {
                    this.data.activeSavedViewId = null;
                    this.data.savedViewQueryParams = null;
                    this.data.selectedView = this.getDefaultView();
                    this.data.filterType = 'simple';
                    this.data.advanceFilters = [[]];
                    this.data.search = '';
                    this.data.searching = false;
                    Storage.set(this.getFilterTypeStorageName(), 'simple');
                    Storage.set(this.getTabStorageName(), this.getDefaultView());
                    Url.pushToVueUrl(null, {active_view: this.getDefaultView()});
                    this.fetch();
                }
            })
            .catch(errors => {
                Notify.error(errors.data?.message || translate('Failed to delete view'));
            });
    }

    renameSavedView(viewId, newName) {
        const view = this.data.savedViews.find(v => v.slug === viewId);
        if (!view) return;

        Rest.put('saved-views/' + view.id, {
            name: newName,
        }).then(response => {
            view.name = newName;
            this._syncGlobalSavedViews();
        }).catch(errors => {
            Notify.error(errors.data?.message || translate('Failed to rename view'));
        });
    }

    /**
     * Syncs the local saved views back to the global localized config
     * so other table instances pick up changes without a page reload.
     */
    _syncGlobalSavedViews() {
        if (window.fluentCartAdminApp && window.fluentCartAdminApp.table_config) {
            const tableName = this.getTableName();
            if (!window.fluentCartAdminApp.table_config[tableName]) {
                window.fluentCartAdminApp.table_config[tableName] = {};
            }
            window.fluentCartAdminApp.table_config[tableName].saved_views = this.data.savedViews;
        }
    }


    /**
     * Checks if a column is visible.
     * @param {string} column - Column name
     * @returns {boolean}
     */
    isColumnVisible(column) {
        return this.data.columns.includes(column);
    }

    /** Placeholder methods to be overridden */

    getSearchHint() {
        throw new Error("Override getSearchHint method");
    }

    getFetchUrl() {
        throw new Error("Override getFetchUrl method");
    }

    getTabs() {
        throw new Error("Override getTabs method");
    }

    getTabsCount() {
        const tabs = this.getTabs();
        return tabs ? Object.keys(tabs).length : 0;
    }

    getToggleableColumns() {
        throw new Error("Override getToggleableColumns method");
    }

    getSortableColumns() {
        throw new Error("Override getSortableColumns method");
    }

    getTableName() {
        throw new Error("Override getTableName method");
    }

    parseResponse(response) {
        throw new Error("Override parseResponse method");
    }

    with() {
        return [];
    }

    scopes() {
        return [];
    }

    isRouteActiveViewApplied() {
        const route = useRoute();
        return route.query.active_view || false;
    }

    isRouteSearchApplied() {
        const route = useRoute();
        return route.query.search || false;
    }


    guessNextPageCount(data) {
        const {total, to, per_page, current_page, last_page} = data;

        // If already on last page, guess 1 (minimum)
        if (current_page >= last_page) {
            return 1;
        }

        // Calculate remaining items
        const remaining = total - to;

        // Return the smaller of remaining or per_page, but at least 1
        return Math.max(1, Math.min(per_page, remaining));
    }

    get nextPageCount() {
        return this.data.nextPageCount;
    }

    guessTitle() {
        let tableName = this.getTableName();

        // Convert camelCase to snake_case for uniform handling
        tableName = tableName.replace(/([a-z])([A-Z])/g, '$1_$2').toLowerCase();

        // Remove or replace 'table' at the end
        tableName = tableName.replace(/_?table$/, ''); // Remove it
        // Or: tableName = tableName.replace(/_?table$/, '_records'); // Replace with records

        // Convert to Title Case
        return tableName
            .split('_')
            .map(word => word.charAt(0).toLowerCase() + word.slice(1))
            .join(' ');
    }

    isFiltering() {
        if (this.isUsingAdvanceFilter()) {
            return (this.data.advanceFilters || []).some(inner => Array.isArray(inner) && inner.length > 0) ?
                'advanced' : false;
        } else {
            if (this.data.search !== '') {
                return 'search';
            }
            if (this.data.selectedView !== 'all') {
                return 'tab';
            }
            return false;
        }
    }

    emptyMessageFiltered(filter) {
        if (filter === 'search' || filter === 'tab') {
            /* translators: %s - table name */
            return translate('No %s found based on your search', this.guessTitle());
        }
        /* translators: %s - table name */
        return translate('No %s found based on your filter', this.guessTitle());
    }

    getEmptyMessage() {
        /* translators: %s - table name */
        return translate('No %s found', this.guessTitle());
    }

    get emptyMessage() {
        const filter = this.isFiltering();
        if (filter) {
            return this.emptyMessageFiltered(filter);
        } else {
            return this.getEmptyMessage()
        }
    }

    getCustomColumns() {
        return Arr.get(window, `fluentCartAdminApp.table_config.${this.getTableName()}.filters.columns`) || {};
    }

}
