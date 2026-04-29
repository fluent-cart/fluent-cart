import Model from "@/utils/model/Model";
import dayjs from "dayjs";
import Storage from "@/utils/Storage.js";
import dateShortcuts from "../../Modules/Reports/Utils/dateShortCuts";
import Rest from "@/utils/http/Rest";
import DateUtil from "@/utils/support/Date";
import useFilterState from "@/Bits/Components/FilterDropdown/FilterState";
import AppConfig from "@/utils/Config/AppConfig";


class ReportFilterModel extends Model {
    filterState = null;

    data = {
        orderStatus: ['all'],
        orderTypes: ['all'],
        paymentStatus: ['all'],
        currencies: [],
        currency: "",
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
        this.data.currency = data.currency ? this.data.currency : AppConfig.get('shop.currency');
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

    fetchReportMeta() {
        Rest.get("reports/fetch-report-meta", this.applicableFilters)
            .then((response) => {
                this.data.currencies = response.currencies;
                this.data.dateRange[0] = response.min_date;
                const [key, value] = Object.entries(response.currencies)[0];
                if (this.applicableFilters.params.currency === "") {
                    this.data.currency = key;
                }
                this.data.currencySign = value;
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

    get currentCurrencySign() {
        return this.data.currencies &&
        this.data.currencies.hasOwnProperty(this.data.currency)
            ? this.data.currencies[this.data.currency]
            : "";
    }

    listeners = {};

    addListener(key, listener) {
        this.listeners[key] = listener;
        this.data.active_tab = key;
    }

    async onFilterChange() {
        // this.fetchReportMeta();
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
    }

    resetFilterToDefault(key) {
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
