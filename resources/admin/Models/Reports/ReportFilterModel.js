import Model from "@/utils/model/Model";
import dayjs from "dayjs";
import Storage from "@/utils/Storage.js";
import dateShortcuts from "../../Modules/Reports/Utils/dateShortCuts";
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
    'reports_sales', 'reports_orders', 'reports_revenue', 'reports_refunds', 'reports_products', 'reports_sources',
    'reports_products_compare',
    'subscriptions-retention', 'future_renewals',
];

class ReportFilterModel extends Model {
    filterState = null;

    data = {
        orderStatus: ['all'],
        orderTypes: ['all'],
        paymentStatus: ['all'],
        currencies: [],
        baseCurrency: '',
        convertedExcludedOrders: 0,
        currency: "",
        defaultCurrency: "",
        currencySign: "",
        dateRange: [
            DateUtil.withTimezone(dayjs().subtract(1, "month").format("YYYY-MM-DD 00:00:00")),
            DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
        ],
        rangeKey: "",
        dateDiff: 0,
        minDate: "",
        variation_ids: [],
        active_tab: "",
        filterMode: "",
        storeMode: "",
        firstOrderDate: "",
        compareDate: DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
        compareType: "previous_period",
        subscriptionType: "",
        reportRoute: "",
        compareRange: null,
        compareRanges: null,
    };

    storageKey = "report-filter";
    currencyStorageKey = `${this.storageKey}_currencies`;

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

        this.data.paymentStatus = data.paymentStatus ?? this.data.paymentStatus;
        this.data.orderStatus = data.orderStatus ?? this.data.orderStatus;
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
        this.data.variation_ids = data.variation_ids ? data.variation_ids : this.data.variation_ids;
        this.data.filterMode = data.filterMode ?? this.data.filterMode;
        this.data.storeMode = data.storeMode ?? this.data.storeMode;
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

        return Rest.get("reports/fetch-report-meta", this.applicableFilters)
            .then((response) => {
                if (request !== this.requests.meta) {
                    return; // a newer range is loading
                }
                this.data.currencies = response.currencies || {};
                this.data.baseCurrency = response.base_currency || '';
                this.data.convertedExcludedOrders = response.converted_excluded_orders || 0;
                if (adoptMinDate) {
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
                this.data.storeMode = response.storeMode;
                this.data.firstOrderDate = dayjs(response.first_order_date || dayjs().startOf("month")).format(
                    "YYYY-MM-DD 00:00:00"
                );
                this.syncStorage();
            })
            .catch((errors) => {
                // handle errors if needed
            })
            .finally(() => {
                this.data.loading = false;
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
        this.data.reportRoute = name || '';
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

    onModeChange(mode) {
        this.data.filterMode = mode;
        this.syncStorage();
    }

    syncStorage() {
        Storage.set(this.storageKey, this.applicableFilters.params);
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

        if (dateShortcut) {
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

        const params = {
            orderStatus: this.data.orderStatus,
            paymentStatus: this.data.paymentStatus,
            currency: this.data.currency,
            startDate: AppConfig.get('app_config.isProActive')
                ? startDate
                : DateUtil.withTimezone(dayjs().startOf("month").format("YYYY-MM-DD 00:00:00")),
            endDate: AppConfig.get('app_config.isProActive')
                ? endDate
                : DateUtil.withTimezone(dayjs().endOf("month").format("YYYY-MM-DD 23:59:59")),
            rangeKey: this.data.rangeKey,
            variation_ids: this.data.variation_ids,
            filterMode: this.data.filterMode,
            storeMode: this.data.storeMode,
            firstOrderDate: this.data.firstOrderDate,
            compareType: this.data.compareType,
            compareDate: this.data.compareDate,
            subscriptionType: this.data.subscriptionType,
        };

        let orderTypes = this.data.orderTypes;
        if (!orderTypes.includes('all')) {
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
        this.data.orderStatus = ['all'];
        this.data.orderTypes = ['all'];
        this.data.paymentStatus = ['all'];
        this.data.compareType = "previous_period";
        this.data.compareDate = DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59"));
        this.data.variation_ids = [];
        this.data.dateRange = [
            DateUtil.withTimezone(dayjs().subtract(1, "month").format("YYYY-MM-DD 00:00:00")),
            DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
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
            this.data.compareDate = DateUtil.withTimezone(dayjs().subtract(1, "month").format("YYYY-MM-DD 00:00:00"));
        }
        if (key === "subscriptionType") {
            this.data.subscriptionType = "";
        }
        if (key === "orderTypes") {
            this.data.orderTypes = ['all'];
        }
        if (key === "orderStatus" || key === "paymentStatus") {
            this.data[key] = ['all'];
        }
        if (key === "variation_ids") {
            this.data.variation_ids = [];
        }
        if (key === "dateRange") {
            this.data[key] = [
                DateUtil.withTimezone(dayjs().startOf("month").format("YYYY-MM-DD 00:00:00")),
                DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
            ];
        }
    }

    retrieveSavedFilters() {
        return Storage.get(this.data.storageKey);
    }
}

export default ReportFilterModel.init();
