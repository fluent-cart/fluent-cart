import Model from "@/utils/model/Model";
import Storage from "@/utils/Storage";
import Arr from "@/utils/support/Arr";
import AppConfig from "@/utils/Config/AppConfig";
import Message from "@/utils/Message";
import translate from "@/utils/translator/Translator";

const TABLE_NAME = 'source_report';

/**
 * Advanced filter state for the Order Sources report.
 *
 * The Sources report renders a single aggregate response, not a paginated
 * table, so it cannot extend `utils/table-new/Table.js`. This model implements
 * only the surface `AdvancedFilter.vue` talks to — the same approach
 * `Models/BulkEditModel.js` takes — plus `getQueryParams()`, which returns the
 * two params (`filter_type`, `advanced_filters`) the report forwards to
 * `GET /reports/sources`, shaped exactly like `Table.buildQueryParams()`.
 *
 * Options accepted by `init()`:
 *   - instance: the Vue component instance, needed by the pro feature CTA modal
 *   - onApply:  callback invoked when the report should be re-fetched
 */
class SourceReportFilterModel extends Model {

    data = {};

    constructor(initOptions = {}) {
        super();
        this.initOptions = initOptions;
    }

    beforeInit() {
        this.data['advanceFilters'] = this.getStoredAdvanceFilters();
        this.data['filterType'] = this.getStoredFilterType();
        this.data['vueInstance'] = this.initOptions.instance || null;
        this.data['onApply'] = typeof this.initOptions.onApply === 'function'
            ? this.initOptions.onApply
            : null;
    }

    // ── Storage keys (mirrors Table.js) ─────────────────

    getTableName() {
        return TABLE_NAME;
    }

    getFilterTypeStorageName() {
        return `${this.getTableName()}_filter_type`;
    }

    getAdvancedFilterStorageName() {
        return `${this.getTableName()}_advanced_filter`;
    }

    getStoredAdvanceFilters() {
        const stored = Storage.get(this.getAdvancedFilterStorageName());

        if (!Array.isArray(stored) || !stored.length) {
            return [[]];
        }

        return stored;
    }

    getStoredFilterType() {
        if (!this.isProActive()) {
            return 'simple';
        }

        const stored = Storage.get(this.getFilterTypeStorageName());

        if (stored === 'advanced' && this.isAdvanceFilterEnabled()) {
            return 'advanced';
        }

        return 'simple';
    }

    storeAdvanceFilter() {
        Storage.set(
            this.getAdvancedFilterStorageName(),
            this.data.advanceFilters
        );
    }

    // ── Filter state ────────────────────────────────────

    isProActive() {
        return AppConfig.get('app_config.isProActive');
    }

    isUsingAdvanceFilter() {
        return this.data.filterType === 'advanced';
    }

    isUsingSimpleFilter() {
        return this.data.filterType === 'simple';
    }

    getAdvanceFilterOptions() {
        return Arr.get(window, `fluentCartAdminApp.table_config.${this.getTableName()}.filters.advance`);
    }

    isAdvanceFilterEnabled() {
        return this.getAdvanceFilterOptions() !== null;
    }

    onFilterTypeChanged(filterType) {
        // Switching back to simple drops the advanced params from the next
        // request, so the report has to be re-fetched. Switching to advanced
        // waits for Apply — the user still has to build the conditions.
        if (filterType === 'simple') {
            this.triggerApply();
        }

        Storage.set(
            this.getFilterTypeStorageName(),
            this.data.filterType
        );
    }

    applyAdvancedFilter(isRemoving = false) {
        if (!this.isProActive()) {
            if (!isRemoving) {
                Message.showFeaturesCTA(
                    translate('Advanced Filter'),
                    translate('Advanced filter is only available in pro version'),
                    [],
                    this.data.vueInstance,
                    'feature_lock_advanced_filtering'
                );
            }
            this.storeAdvanceFilter();
            return;
        }

        this.storeAdvanceFilter();
        this.triggerApply();
    }

    addAdvanceFilterGroup() {
        this.data.advanceFilters.push([]);
    }

    removeAdvanceFilterGroup(index) {
        if (this.data.advanceFilters.length > 1) {
            this.data.advanceFilters.splice(index, 1);
        }

        this.storeAdvanceFilter();
    }

    clearAdvanceFilter() {
        this.data.advanceFilters = [[]];
        // Resetting removes conditions, so no upgrade CTA for free users.
        this.applyAdvancedFilter(true);
    }

    // ── Report integration ──────────────────────────────

    /**
     * The advanced filter params for `GET /reports/sources`. Shaped exactly like
     * the `filter_type` / `advanced_filters` keys of `Table.buildQueryParams()`:
     * `advanced_filters` is only sent while the advanced filter is in use.
     *
     * @returns {object}
     */
    getQueryParams() {
        if (!this.isUsingAdvanceFilter()) {
            return {
                filter_type: 'simple'
            };
        }

        return {
            filter_type: 'advanced',
            advanced_filters: JSON.stringify(this.data.advanceFilters)
        };
    }

    triggerApply() {
        if (this.data.onApply) {
            this.data.onApply();
        }
    }
}

export default SourceReportFilterModel;
