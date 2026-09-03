import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";
import Storage from "@/utils/Storage";
import Url from "@/utils/support/Url";

class ReviewTable extends Table {

    constructor(data = {}) {
        super(data);
        this.data.postId = data.postId || null;
    }

    buildQueryParams() {
        const params = super.buildQueryParams();
        if (this.data.postId) {
            params.post_id = this.data.postId;
        }
        return params;
    }

    getPostId() {
        return this.data.postId;
    }

    // A table scoped to one product (?post_id=) locks its filter controls —
    // search, advanced filter and saved views would all let the user filter
    // their way out of the product they navigated in to see. Single predicate
    // behind every override below.
    shouldDisableFilters() {
        return !!this.data.postId;
    }

    // Disables the search control. Extending the base method keeps
    // TableWrapper.vue's existing binding untouched.
    isUsingSimpleFilter() {
        return super.isUsingSimpleFilter() && !this.shouldDisableFilters();
    }

    // Hides the Advanced Filter toggle (this backs its v-if). Can't reuse
    // isUsingSimpleFilter() — on an unscoped table with advanced filter on
    // that is false, which would hide the only control able to switch back
    // to simple.
    isAdvanceFilterEnabled() {
        return super.isAdvanceFilterEnabled() && !this.shouldDisableFilters();
    }

    // Runs after the base setupInitial*()/setupSavedViews() calls, so it wins
    // over whatever they restored from storage, the route, or a saved view — a
    // product-scoped table must never issue its first fetch() with
    // filter_type=advanced or a search term.
    setupInitialData() {
        super.setupInitialData();
        if (!this.shouldDisableFilters()) {
            return;
        }

        this.data.filterType = 'simple';
        this.data.searching = false;
        this.data.search = '';
        this.data.advanceFilters = [[]];

        // A restored saved view carries its own search/advanced-filter
        // payload server-side via the active_view param (buildQueryParams()
        // sends activeSavedViewId ahead of the static tab) — the resets
        // above only touch the LIVE filter fields, so a saved view would
        // still leak past them onto the initial fetch. Only static status
        // tabs (all/approved/pending/...) stay put; they carry no filter
        // payload of their own.
        if (this.data.activeSavedViewId) {
            this.data.activeSavedViewId = null;
            this.data.savedViewQueryParams = null;
            this.data.selectedView = this.getDefaultView();

            // Without this, remounting on the next post_id change (the
            // :key on <router-view> in ReviewsRoute.vue) would read the
            // same saved-view slug back out of storage/the route and
            // restore it right away — undoing the reset above.
            Storage.set(this.getTabStorageName(), this.getDefaultView());
            Url.pushToVueUrl(null, {active_view: this.getDefaultView()});
        }
    }

    // setupInitialData() only clears a saved view restored on mount; the tabs
    // stay clickable after that, and selecting one reapplies its own
    // search/advanced-filter payload alongside post_id. Hiding them here drops
    // the entries entirely — static status tabs come from getTabs(), untouched.
    getSavedViews() {
        return this.shouldDisableFilters() ? [] : super.getSavedViews();
    }

    // Defense in depth: FilterTabs.vue is shared UI, so a stale reference could
    // still call this directly even with the tabs hidden.
    applySavedView(viewId) {
        if (this.shouldDisableFilters()) {
            return;
        }
        super.applySavedView(viewId);
    }

    getTabs() {
        return {
            all: translate("All"),
            approved: translate("Approved"),
            pending: translate("Pending"),
            spam: translate("Spam"),
            trash: translate("Trash"),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Product'),
                value: 'product'
            },
            {
                label: translate('Content'),
                value: 'content'
            },
            {
                label: translate('Date'),
                value: 'date'
            },
            {
                label: translate('Actions'),
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
                label: translate('Rating'),
                value: 'rating'
            },
            {
                label: translate('Reviewer'),
                value: 'reviewer_name'
            },
            {
                label: translate('Created At'),
                value: 'created_at'
            },
        ]
    }

    getSearchHint() {
        return translate("Search by title, content, reviewer name, email or product name.")
    }

    getFetchUrl() {
        return 'reviews';
    }

    parseResponse(response) {
        return response.reviews;
    }

    getTableName() {
        return 'review_table';
    }

    with() {
        return ['product', 'customer'];
    }
}


/**
 * @return {ReviewTable}
 */
export default function useReviewTable(data) {
    return ReviewTable.init(data);
}
