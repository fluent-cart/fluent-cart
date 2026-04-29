import Model from "@/utils/model/Model";
import dayjs from "dayjs";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Storage from "@/utils/Storage";
import DateUtil from "@/utils/support/Date";
import {useRoute} from "vue-router";
import translate from "@/utils/translator/Translator";

class FilterState extends Model {
  data = {
    orderStatus: ['all'],
    orderTypes: ['all'],
    paymentStatus: ['all'],
    filterOptions: {},
    showDropdown: false,
    activeFilter: null,
    searchQuery: "",
    selectedFilters: {},
    onFilterChanged: null,
    dateRange: [
      DateUtil.withTimezone(dayjs().subtract(1, "month").format("YYYY-MM-DD 00:00:00")),
      DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
    ],
    currencies: [],
    currency: "",
    variation_ids: [],
    rangeKey: "",
    storageKey: "filter-state",
    reRenderDate: false,
    compareType: "previous_period",
    compareDate: DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
    subscriptionType: ""
  };

  get dropdownOpen() {
    return this.data.showDropdown;
  }

  toggleDropdown() {
    this.data.showDropdown = !this.data.showDropdown;
  }

  setFilterOption(filterOptions) {
    this.data.filterOptions = filterOptions;
    // this.data.selectedFilters = {};
  }

  get filters() {
    return this.data.filterOptions;
  }

  get filteredFilters() {
    const query = this.data.searchQuery?.toLowerCase().trim();

    return this.filters.filter((filter) => {
      if (!query) return true;

      return (
          filter.label.toLowerCase().includes(query) ||
          filter.value.toLowerCase().includes(query)
      );
    });
  }

  get visibleFilters() {
    return this.filteredFilters.filter((filterOption) =>
      this.shouldRenderOption(filterOption)
    );
  }

  selectActiveFilter(filterOption) {
    this.data.activeFilter = filterOption;
  }

  get activeFilterOptions() {
    return this.data.activeFilter || null;
  }

  selectFilterOption(filterOption) {
    const selectedFilter = this.activeFilterOptions;

    if (!selectedFilter) return;

    this.data.selectedFilters[selectedFilter.value] = {
      label: selectedFilter.label,
      value: filterOption.value,
      type: selectedFilter.type,
      options: selectedFilter.options,
    };

    this.data[selectedFilter.value] = filterOption.value;

    //this.data.activeFilter = null;
    this.triggerFilterChanged();
  }

  selectFilterOptionMulti(filterOption) {
    const selectedFilter = this.activeFilterOptions;

    if (!selectedFilter) return;

    if (!this.data.selectedFilters[selectedFilter.value]) {
      this.data.selectedFilters[selectedFilter.value] = {
        label: selectedFilter.label,
        value: [],
        type: selectedFilter.type,
        options: selectedFilter.options,
      };
    }

    let currentValues = this.data.selectedFilters[selectedFilter.value].value;

    if(filterOption.value === 'all'){
      // reset and only keep "all" as object
      this.data.selectedFilters[selectedFilter.value]['value'] = [
        {
          label: filterOption.label,
          value: filterOption.value
        }
      ];

      // update main data with just ["all"]
      this.data[selectedFilter.value] = ['all'];
    }else{
      // Normal toggle behavior
      const existingIndex = currentValues.findIndex(
          (item) => item.value === filterOption.value
      );

      if (existingIndex > -1) {
        // remove it
        currentValues.splice(existingIndex, 1);
      } else {
        // add it
        currentValues.push({
          label: filterOption.label,
          value: filterOption.value,
        });
      }

      // if "all" was selected before, remove it once user selects something else
      const allIndex = currentValues.findIndex((item) => item.value === 'all');
      if (allIndex > -1) {
        currentValues.splice(allIndex, 1);
      }

      // update main data with just raw values
      this.data[selectedFilter.value] = currentValues.map((item) => item.value);
    }

    this.triggerFilterChanged();
  }

  setDateRange(value) {
    const start = dayjs(value[0]);
    const end = dayjs(value[1]);

    this.data.selectedFilters['dateRange'] = {
      label: translate("Date"),
      value: [start, end],
      type: "date",
    };

    //this.data.activeFilter = null;
    this.triggerFilterChanged();
  }

  postSetDateRange() {
    this.data.dateRange = [
      reportFilter.data.dateRange[0],
      reportFilter.data.dateRange[1],
    ];

    this.data.selectedFilters["dateRange"] = {
      label: translate("Date"),
      value: [
        DateUtil.withTimezone(dayjs(this.data.dateRange[0])),
        DateUtil.withTimezone(dayjs(this.data.dateRange[1])),
      ],
      type: "date",
      rangeKey: this.data.rangeKey,
    };
    this.data.reRenderDate = true;
    this.triggerFilterChanged();
  }

  setSelectChange(value, selectedFilterItem = null) {
    const selectedFilter = selectedFilterItem || this.activeFilterOptions;

    if (!selectedFilter) return;

    this.data.selectedFilters[selectedFilter.value] = {
      label: selectedFilter.label,
      value: value,
      type: selectedFilter.type,
    };

    this.triggerFilterChanged();
  }

  setTreeSelectItem(checkedNodes) {
    // Only include leaf nodes (children without further children)
    const selectedItems = checkedNodes
      .filter((node) => !node.children || node.children.length === 0)
      .map((node) => ({
        id: node.value,
        label: node.label,
      }));

    this.handleTreeSelections(selectedItems)

    this.triggerFilterChanged();
  }

  removeTreeSelectItem(removedId) {
    const selectedItems =
      this.data.selectedFilters.variation_ids?.value || [];

    const updatedItems = selectedItems.filter((item) => item.id !== removedId);

    this.handleTreeSelections(updatedItems)

    // Sync the tree select model
    this.data.variation_ids = this.data.variation_ids.filter(
      (id) => id !== removedId
    );

    this.triggerFilterChanged();
  }

  handleTreeSelections(items) {
    if (!items.length) {
      delete this.data.selectedFilters.variation_ids;
    } else {
      if (!this.data.selectedFilters.variation_ids) {
        this.data.selectedFilters.variation_ids = {
          label: translate('Products'),
          type: 'tree-select',
        };
      }

      this.data.selectedFilters.variation_ids.value = items;
    }
  }

  clearTreeSelect() {
    // Remove filter entry
    delete this.data.selectedFilters.variation_ids;

    // Clear v-model of the tree select
    this.data.variation_ids = [];

    reportFilter.resetFilterToDefault('variation_ids');

    this.triggerFilterChanged();
  }

  isFilterOptionSelected(filterOption) {
    const selectedFilter = this.activeFilterOptions;

    if (!selectedFilter) return false;

    const currentFilter = this.data.selectedFilters[selectedFilter.value];

    // Case 1: user has explicitly selected options
    if (currentFilter) {
      return currentFilter.value === filterOption.value;
    }

    // Case 2: no selection yet, fallback to default value in data
    const defaultValue = this.data[selectedFilter.value];
    return defaultValue === filterOption.value;
  }
  isFilterOptionSelectedMulti(filterOption) {
    const selectedFilter = this.activeFilterOptions;
    const currentFilter = this.data.selectedFilters[selectedFilter?.value];

    if (!currentFilter) return false;

    const currentValues = currentFilter.value;

    return currentValues.some(
        (item) => item.value === filterOption.value
    );
  }

  setMultiSelectChange(filterItemKey, value) {
    if (!this.data.selectedFilters[filterItemKey]) {
      this.data.selectedFilters[filterItemKey] = {};
    }
    const hasAll = value.some(item => item.value === 'all');
    const prevValue = this.data.selectedFilters[filterItemKey].value || [];

    if (hasAll) {
      if (prevValue.length === 1 && prevValue[0].value === 'all' && value.length > 1) {
        // Case: "all" was already selected, then user picked another filter
        // => remove "all" and keep others
        this.data.selectedFilters[filterItemKey].value = value.filter(
            item => item.value !== 'all'
        );
      } else {
        // Case: user clicked "all" along with others
        // => reset to only "all"
        this.data.selectedFilters[filterItemKey].value = [
          value.find(item => item.value === 'all')
        ];
      }
    } else {
      // Normal case: no "all" present
      this.data.selectedFilters[filterItemKey].value = value;
    }

    this.triggerFilterChanged();
  }

  onFilterChanged(callback) {
    return (this.data.onFilterChanged = callback);
  }

  setCompareTypeChange(value) {
    this.data.compareType = value;
    this.data.selectedFilters['compareType'] = {
      value: value
    };

    if(value === 'custom') {
      return;
    }

    this.triggerFilterChanged();
  }

  setCompareDateChange(value) {
    this.data.compareDate = value;
    this.data.selectedFilters['compareDate'] = {
      value: DateUtil.withTimezone(dayjs(value)),
    };
    this.triggerFilterChanged();
  }

  get currencies() {
    return Object.entries(reportFilter.data.currencies).map(
      ([code, symbol]) => {
        return {
          label: code,
          value: code,
        };
      }
    );
  }

  // Helper method to determine if a filter option should render
  shouldRenderOption(filterOption) {
    const disableRoutes = filterOption.disableRoutes || [];
    const route = useRoute();

    if (disableRoutes.includes(route.name)) {
      return false;
    }

    if (filterOption.value === 'currency') {
      return this.data.currencies && this.data.currencies.length > 1;
    }

    return true;


    // const isSubscriptionReport = reportFilter.data.active_tab === "subscription-report";
    //
    // // switch (filterOption.value) {
    //   case "subscriptionType":
    //     return isSubscriptionReport;
    //   case "currency":
    //     return this.data.currencies && this.data.currencies.length > 1;
    //   case "variation_ids":
    //     return ['product-report', 'default-report'].includes(reportFilter.data.active_tab) || isSubscriptionReport;
    //   default:
    //     // hide all other filters on subscription-report
    //     if (isSubscriptionReport) {
    //       return false;
    //     }
    //     return true;
    // }

  }

  applyFilterChange() {
    this.triggerFilterChanged();
  }

  resetFilters() {
    this.data.selectedFilters = {};
    this.data.currency = "";
    this.data.variation_ids = [];
    this.data.dateRange = [
      DateUtil.withTimezone(
        dayjs().subtract(1, 'month').format("YYYY-MM-DD 00:00:00")
      ),
      DateUtil.withTimezone(dayjs().format("YYYY-MM-DD 23:59:59")),
    ];
    this.data.compareType = "previous_period";
    this.data.compareDate = DateUtil.withTimezone(dayjs().subtract(1, "month").format("YYYY-MM-DD 00:00:00"));
    reportFilter.resetFiltersToDefault();
    this.triggerFilterChanged();
  }

  removeFilter(key, id) {
    if (id != null) {
      this.removeFilterItem(key, id);
      reportFilter.resetFilterToDefault(key);
      this.triggerFilterChanged();
    } else {
      delete this.data.selectedFilters[key];
      reportFilter.resetFilterToDefault(key);
      this.triggerFilterChanged();
    }
  }

  removeFilterItem(key, id) {
    const filterEntry = this.data.selectedFilters[key];
    if (!filterEntry) return;

    // Remove the item with the matching id
    const updatedItems = filterEntry.value.filter((item) => item.id !== id);

    if (!updatedItems.length) {
      delete this.data.selectedFilters[key];
    } else {
      this.data.selectedFilters[key].value = updatedItems;
    }
    
    // If it's bound to a v-model (like variation_ids), update that too
    if (key === "variation_ids") {
      this.data.variation_ids = this.data.variation_ids.filter(
        (val) => val !== id
      );
    }

    this.triggerFilterChanged();
  }

  // Reusable method to call the callback
  triggerFilterChanged() {
    this.storeSelectedFilters();

    if (typeof this.data.onFilterChanged === "function") {
      this.data.onFilterChanged(this.data.selectedFilters);
    }
  }
  get activeFilters() {
    return this.data.selectedFilters;
  }

  applicableFilters() {
    // define only the keys you want to store
    const allowedKeys = [
      "orderStatus",
      "orderTypes",
      "compareDate",
      "currency",
      "compareType",
      "subscriptionType",
      "variation_ids",
      "paymentStatus",
      "rangeKey"
    ];

    // build new object from this.data using only allowed keys
    // e.g. { orderStatus: "all", orderTypes: ["all"], ... }
    return Object.fromEntries(
        allowedKeys.map(key => [key, this.data[key]])
    );
  }
  storeSelectedFilters() {
    const key = "report-filter";
    const existing = Storage.get(key) || {};
    const applicable = this.applicableFilters();

    // merge: keep existing keys, overwrite with applicable
    const updated = { ...existing, ...applicable };
    Storage.set(key, updated);

    Storage.set(this.data.storageKey, this.activeFilters);
  }

  retrieveSavedFilters() {
    return Storage.get(this.data.storageKey);
  }

  retrieveSavedReportFilters() {
    return Storage.get('report-filter');
  }
}

export default function useFilterState() {
  return FilterState.init();
}
