import Model from "@/utils/model/Model";
import dayjs from "dayjs";
import Storage from "@/utils/Storage.js";
import dateShortcuts from "../../Modules/Reports/Utils/dateShortCuts";
import { presetRange, siteToday } from "../../Modules/Reports/Utils/datePresets";
import Rest from "@/utils/http/Rest";
import DateUtil from "@/utils/support/Date";
import useFilterState from "@/Bits/Components/FilterDropdown/FilterState";
import AppConfig from "@/utils/Config/AppConfig";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import translate from "@/utils/translator/Translator";

// A late or failed response must not overwrite the range of a newer request or route.
let compareRangeSeq = 0;

/** Value of the "All currencies, converted to the base currency" option */
export const CONVERTED_CURRENCY = '__all__';

/**
 * Report routes whose endpoints convert every order (ReportCurrency::ordersTable)
 * or subscription (ReportCurrency::subscriptionsTable) to the base currency.
 * Every other report reads one currency.
 */
export const CONVERTED_CURRENCY_ROUTES = [
    'reports_orders', 'reports_revenue', 'reports_refunds', 'reports_products', 'reports_sources', 'reports_coupons',
    'reports_customer', 'reports_payment_methods', 'reports_locations',
    'reports_products_compare',
    'subscriptions-retention', 'future_renewals', 'reports_installments',
];

/**
 * Reports that ignore the date range (snapshots over all time) list every
 * currency that has orders, not only those inside the range.
 */
export const ALL_TIME_CURRENCY_ROUTES = ['reports_installments', 'subscriptions-cohorts', 'future_renewals'];

/**
 * Subscription pages: their own product selector (subscription variants only) replaces the Filters menu,
 * so a product picked on another report does not apply here unseen.
 */
export const SUBSCRIPTION_PRODUCT_ROUTES = ['reports_subscriptions', 'subscriptions-retention', 'subscriptions-cohorts', 'future_renewals'];

class ReportFilterModel extends Model {
    filterState = null;

    data = {
        orderTypes: ['all'],
        currencies: [],
        baseCurrency: '',
        convertedExcludedOrders: 0,
        currency: "",
        defaultCurrency: "",
        currencySign: "",
        dateRange: [
            DateUtil.withTimezone(siteToday().subtract(1, "month").format("YYYY-MM-DD 00:00:00")),
            DateUtil.withTimezone(siteToday().format("YYYY-MM-DD 23:59:59")),
        ],
        rangeKey: "",
        dateDiff: 0,
        minDate: "",
        variation_ids: [],
        subscriptionVariationIds: [],
        active_tab: "",
        firstOrderDate: "",
        compareDate: DateUtil.withTimezone(siteToday().format("YYYY-MM-DD 23:59:59")),
        compareType: "previous_period",
        subscriptionType: "",
        reportRoute: "",
        // True while a route switch reloads the currency list; ReportsView holds the page until then
        currencyScopeLoading: false,
        compareRange: null,
        compareRanges: null,
    };

    storageKey = "report-filter";
    currencyStorageKey = `${this.storageKey}_currencies`;
    subscriptionVariationsKey = `${this.storageKey}_subscription_variations`;

    beforeInit() {
        this.filterState = useFilterState();
        this.mergeWithStorage();
        const data = Storage.get(this.storageKey);
        const currencies = Storage.get(this.currencyStorageKey);
        if (!data || !currencies) {
            this.fetchReportMeta();
        }
    }

    mergeWithStorage() {
        const data = Storage.get(this.storageKey);
        const currencies = Storage.get(this.currencyStorageKey);

        this.data.orderTypes = data.orderTypes ?? this.data.orderTypes;
        this.data.compareType = data.compareType ? data.compareType : this.data.compareType;
        this.data.compareDate = data.compareDate ?? this.data.compareDate;
        this.data.subscriptionType = data.subscriptionType ?? this.data.subscriptionType;
        // Keep the stored choice; fetchReportMeta() falls back to the currency
        // with the most orders when the stored one is empty or not in range.
        this.data.currency = data.currency || '';
        this.data.dateRange = [
            data.startDate ?? this.data.dateRange[0],
            data.endDate ?? this.data.dateRange[1],
        ];
        this.data.currencies = currencies ?? this.data.currencies;
        this.data.rangeKey = data.rangeKey;
        // Free filters saved before every range opened up hold this month's fixed dates with no key
        if (!this.data.rangeKey && !AppConfig.get('app_config.isProActive')) {
            this.data.rangeKey = 'this_month';
        }
        // A saved preset is relative to today, not to the day it was picked
        const preset = presetRange(this.data.rangeKey);
        if (preset) {
            this.data.dateRange = [
                DateUtil.withTimezone(preset[0].format("YYYY-MM-DD 00:00:00")),
                DateUtil.withTimezone(preset[1].format("YYYY-MM-DD 23:59:59")),
            ];
        }
        this.data.variation_ids = data.variation_ids ? data.variation_ids : this.data.variation_ids;
        this.data.subscriptionVariationIds = Storage.get(this.subscriptionVariationsKey) || [];
    }

    setSubscriptionVariations(ids) {
        this.data.subscriptionVariationIds = ids || [];
        Storage.set(this.subscriptionVariationsKey, this.data.subscriptionVariationIds);
        this.onFilterChange();
    }

    // Counters in an object: the Model proxy writes unknown props into data,
    // so a plain numeric field would never change when read back
    requests = {meta: 0, filterChange: 0};

    /**
     * Resolves once the effective currency is known; ReportsView renders the
     * report pages only then, so their first request carries it.
     */
    fetchReportMeta() {
        return this.loadMeta(true);
    }

    /**
     * Which currencies have orders depends on the date range, so this runs on
     * load and on every filter change. Only the latest request applies.
     *
     * @param {boolean} adoptMinDate the first load starts the range at the first
     *                               order in it; a filter change keeps the chosen dates
     */
    loadMeta(adoptMinDate) {
        const request = ++this.requests.meta;
        // The default belongs to a range: none is known until this one resolves
        this.data.defaultCurrency = '';

        const allTime = ALL_TIME_CURRENCY_ROUTES.includes(this.data.reportRoute);
        const filters = this.applicableFilters;
        const metaFilters = allTime
            // Rest drops null values; undefined would be sent as the string "undefined"
            ? {...filters, params: {...filters.params, startDate: null, endDate: null}}
            : filters;

        return Rest.get("reports/fetch-report-meta", metaFilters)
            .then((response) => {
                if (request !== this.requests.meta) {
                    return; // a newer range is loading
                }
                this.data.currencies = response.currencies || {};
                this.data.baseCurrency = response.base_currency || '';
                this.data.convertedExcludedOrders = response.converted_excluded_orders || 0;
                // An all-time list says nothing about the range the other reports use
                if (adoptMinDate && !allTime) {
                    this.data.dateRange[0] = response.min_date;
                }
                const codes = Object.keys(this.data.currencies);
                this.data.defaultCurrency = response.default_currency || codes[0] || AppConfig.get('shop.currency');
                // Every report is in exactly one currency: keep the selected one
                // while it has orders in range, else the one with the most orders.
                if (!this.data.currency || !codes.includes(this.data.currency)) {
                    this.data.currency = this.data.defaultCurrency;
                }
                this.normalizeConvertedCurrency();
                this.data.currencySign = this.data.currencies[this.data.currency] || '';
                this.syncCurrencyChip();
                this.data.firstOrderDate = dayjs(response.first_order_date || siteToday().startOf("month")).format(
                    "YYYY-MM-DD 00:00:00"
                );
                this.syncStorage();
            })
            .catch((errors) => {
                // handle errors if needed
            })
            .finally(() => {
                this.data.loading = false;
                // Only the newest currency list releases a page held by a route switch, whatever started it
                if (request === this.requests.meta) {
                    this.data.currencyScopeLoading = false;
                }
            });
    }

    /**
     * Whether the current report supports the "All currencies" mode.
     */
    supportsConvertedCurrency() {
        return CONVERTED_CURRENCY_ROUTES.includes(this.data.reportRoute);
    }

    /**
     * Set before the report page requests data, so a report without the
     * converted mode never shows "All currencies" over single-currency numbers.
     */
    setReportRoute(name) {
        const wasAllTime = ALL_TIME_CURRENCY_ROUTES.includes(this.data.reportRoute);
        this.data.reportRoute = name || '';
        // Moving between a dated and an all-time report changes which currencies apply. Reload the list
        // before the page mounts, so it fetches once and in the right currency.
        if (this.requests.meta && wasAllTime !== ALL_TIME_CURRENCY_ROUTES.includes(this.data.reportRoute)) {
            this.data.currencyScopeLoading = true;
            this.loadMeta(false).then(() => this.syncStorage());
        }
        compareRangeSeq++;
        this.data.compareRange = null;
        this.data.compareRanges = null;
        if (this.normalizeConvertedCurrency()) {
            this.data.currencySign = this.data.currencies[this.data.currency] || '';
            this.syncCurrencyChip();
            this.syncStorage();
        }
    }

    trackCompareRange(request) {
        const seq = ++compareRangeSeq;
        request.then(
            (response) => {
                if (seq === compareRangeSeq) {
                    this.data.compareRange = response?.compare_range ?? null;
                    this.data.compareRanges = response?.compare_ranges ?? null;
                }
            },
            () => {
                if (seq === compareRangeSeq) {
                    this.data.compareRange = null;
                    this.data.compareRanges = null;
                }
            }
        );
        return request;
    }

    /**
     * "All currencies" on a report that cannot convert becomes the base
     * currency — what its endpoints read — or, without base orders in
     * range, the currency with the most orders.
     *
     * @return {boolean} whether the selection changed
     */
    normalizeConvertedCurrency() {
        if (this.data.currency !== CONVERTED_CURRENCY || this.supportsConvertedCurrency()) {
            return false;
        }
        const codes = Object.keys(this.data.currencies || {}).filter((code) => code !== CONVERTED_CURRENCY);
        const base = this.data.baseCurrency || AppConfig.get('shop.currency');
        this.data.currency = codes.includes(base) ? base : (codes[0] || base);
        return true;
    }

    /**
     * Keep the picker's chip on the currency the reports use.
     */
    syncCurrencyChip() {
        const state = this.filterState && this.filterState.data;
        if (!state) {
            return;
        }
        if (!state.selectedFilters) {
            state.selectedFilters = {};
        }
        if (state.selectedFilters.currency) {
            state.selectedFilters.currency.value = this.data.currency;
        } else if (this.data.currency) {
            state.selectedFilters.currency = {
                label: translate('Currency'),
                value: this.data.currency,
                type: 'currency',
            };
        }
        state.currency = this.data.currency;
    }

    get currentCurrencySign() {
        return this.data.currencies &&
        this.data.currencies.hasOwnProperty(this.data.currency)
            ? this.data.currencies[this.data.currency]
            : "";
    }

    get reportCurrency() {
        return this.data.currency === CONVERTED_CURRENCY
            ? (this.data.baseCurrency || AppConfig.get('shop.currency'))
            : this.data.currency;
    }

    formatAmount(amount, {cents = false, chart = false} = {}) {
        const currency = this.reportCurrency;
        if (chart) {
            return cents
                ? CurrencyFormatter.formatScaledChart(amount, currency)
                : CurrencyFormatter.scaledChart(amount, true, false, currency);
        }
        return cents
            ? CurrencyFormatter.formatScaled(amount, true, false, currency)
            : CurrencyFormatter.scaled(amount, true, false, currency);
    }

    listeners = {};

    addListener(key, listener) {
        this.listeners[key] = listener;
        this.data.active_tab = key;
    }

    async onFilterChange() {
        const change = ++this.requests.filterChange;
        // The currencies with orders depend on the range: resolve the selection
        // before any report requests data
        await this.loadMeta(false);
        if (change !== this.requests.filterChange) {
            return; // a newer change reloads the reports
        }
        this.syncStorage();
        this.setDateDiff();
        for (let key in this.listeners) {
            this.listeners[key](this.applicableFilters);
        }
    }

    syncStorage() {
        // The global filters, not the subscription pages' swapped ones
        Storage.set(this.storageKey, {
            ...this.applicableFilters.params,
            variation_ids: this.data.variation_ids,
            subscriptionType: this.data.subscriptionType,
            orderTypes: this.data.orderTypes,
        });
        Storage.set(this.currencyStorageKey, this.data.currencies);
    }

    removeListener(key) {
        delete this.listeners[key];
        this.data.active_tab = "";
    }

    get applicableFilters() {
        const dateShortcut = dateShortcuts.find(
            (shortcut) => shortcut.text === this.data.rangeKey
        );

        let startDate, endDate;
        const preset = presetRange(this.data.rangeKey);

        if (preset) {
            startDate = DateUtil.withTimezone(preset[0].format("YYYY-MM-DD 00:00:00"));
            endDate = DateUtil.withTimezone(preset[1].format("YYYY-MM-DD 23:59:59"));
        } else if (dateShortcut) {
            const [start, end] = dateShortcut.value();
            startDate =
                this.data.rangeKey === "All Time"
                    ? DateUtil.withTimezone(dayjs(this.data.dateRange[0]).format("YYYY-MM-DD 00:00:00"))
                    : DateUtil.withTimezone(dayjs(start).format("YYYY-MM-DD 00:00:00"));
            endDate = DateUtil.withTimezone(dayjs(end).format("YYYY-MM-DD 23:59:59"));
        } else {
            startDate = DateUtil.withTimezone(dayjs(this.data.dateRange[0]).format("YYYY-MM-DD 00:00:00"));
            endDate = DateUtil.withTimezone(dayjs(this.data.dateRange[1]).format("YYYY-MM-DD 23:59:59"));
        }

        // No order/payment status or live/test mode: every report uses the fixed report statuses
        const params = {
            currency: this.data.currency,
            startDate,
            endDate,
            rangeKey: this.data.rangeKey,
            variation_ids: this.data.variation_ids,
            firstOrderDate: this.data.firstOrderDate,
            compareType: this.data.compareType,
            compareDate: this.data.compareDate,
            subscriptionType: this.data.subscriptionType,
        };

        let orderTypes = this.data.orderTypes;
        if (SUBSCRIPTION_PRODUCT_ROUTES.includes(this.data.reportRoute)) {
            params.variation_ids = this.data.subscriptionVariationIds;
            params.subscriptionType = '';
        } else if (!orderTypes.includes('all')) {
            params['orderTypes'] = orderTypes;
        }

        return {
            params
        };
    }

    setDateDiff() {
        if (!this.data.dateRange || this.data.dateRange.length < 2) return 0;
        const [start, end] = this.data.dateRange;
        this.data.dateDiff = dayjs(end).diff(dayjs(start), "days");
    }

    resetFiltersToDefault() {
        // A kept preset key would override the reset dates
        this.data.rangeKey = "";
        this.data.orderTypes = ['all'];
        this.data.compareType = "previous_period";
        this.data.compareDate = DateUtil.withTimezone(siteToday().format("YYYY-MM-DD 23:59:59"));
        this.data.variation_ids = [];
        this.data.dateRange = [
            DateUtil.withTimezone(siteToday().subtract(1, "month").format("YYYY-MM-DD 00:00:00")),
            DateUtil.withTimezone(siteToday().format("YYYY-MM-DD 23:59:59")),
        ];
        this.data.subscriptionType = "";
        this.data.currency = "";
    }

    resetFilterToDefault(key) {
        // Empty until loadMeta() resolves it to the currency with the most orders
        if (key === "currency") {
            this.data.currency = this.data.defaultCurrency;
        }
        if (key === "compareType") {
            this.data.compareType = "previous_period";
        }
        if (key === "compareDate") {
            this.data.compareDate = DateUtil.withTimezone(siteToday().subtract(1, "month").format("YYYY-MM-DD 00:00:00"));
        }
        if (key === "subscriptionType") {
            this.data.subscriptionType = "";
        }
        if (key === "orderTypes") {
            this.data.orderTypes = ['all'];
        }
        if (key === "variation_ids") {
            this.data.variation_ids = [];
        }
        if (key === "dateRange") {
            this.data.rangeKey = "";
            this.data[key] = [
                DateUtil.withTimezone(siteToday().startOf("month").format("YYYY-MM-DD 00:00:00")),
                DateUtil.withTimezone(siteToday().format("YYYY-MM-DD 23:59:59")),
            ];
        }
    }

    retrieveSavedFilters() {
        return Storage.get(this.data.storageKey);
    }
}

export default ReportFilterModel.init();
