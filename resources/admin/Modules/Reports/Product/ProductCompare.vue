<script setup>
import {computed, h, nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import dayjs from "dayjs";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate, {translateNumber} from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import {fluentDayjsLocale, resolveDateFormat} from "@/utils/dateFormats";
import Theme from "@/utils/Theme";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import {formatNumber} from "@/Modules/Reports/Utils/formatNumber";
import ProductCompareChart from "@/Modules/Reports/Product/ProductCompareChart.vue";
import useFilterState from "@/Bits/Components/FilterDropdown/FilterState";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const MAX_SHOWN = 5;
const LISTENER_KEY = "product-compare";
const FETCH_DELAY_MS = 300;
const DEFAULT_SORT = {prop: "orders", order: "descending"};
const reportColors = Theme.colors.report;
const palette = [
  reportColors.royal_blue,
  reportColors.golden_rod,
  reportColors.sea_green,
  reportColors.hot_pink,
  reportColors.medium_slate_blue,
];
const previewColors = palette.slice(0, 3);

const isProActive = AppConfig.get("app_config.isProActive");
const METRICS = ["orders", "revenue", "units"];
const TOTAL_FIELDS = ["units", "revenue", "orders", "customers", "discount", "refunds"];
const metricLabels = {
  orders: translate("Orders"),
  revenue: translate("Revenue"),
  units: translate("Units"),
};

const groupBy = ref("product");
const metric = ref("orders");
const groupKey = ref("default");
const buckets = ref([]);
const series = ref({});
const loading = ref(false);
const loadError = ref("");
const pickedIds = ref([]);
const variationLimit = ref(0);
const entities = ref([]);
const totals = ref({});
const previousTotals = ref(null);
const compareRange = ref(null);
const compareRanges = ref(null);
const sortState = ref({...DEFAULT_SORT});
const colorByKey = ref({});
const expanded = ref(false);
const isDarkTheme = ref(Theme.isDark());

let latestRequestId = 0;
let fetchTimer = null;
let lastRequestKey = null;

// groupKey is sent to the server, so it belongs in the key; the client-side Group by does not.
const currentRequestKey = () => JSON.stringify({...props.reportFilter.applicableFilters.params, groupKey: groupKey.value});

const toIds = (ids) => [...new Set((Array.isArray(ids) ? ids : [])
    .map((id) => parseInt(id, 10))
    .filter((id) => id > 0))];

const fetchCompare = () => {
  const requestId = ++latestRequestId;
  const filters = props.reportFilter.applicableFilters;
  lastRequestKey = currentRequestKey();
  const ids = toIds(props.reportFilter.data.variation_ids);

  pickedIds.value = ids;
  loadError.value = "";

  if (!isProActive || !ids.length) {
    entities.value = [];
    totals.value = {};
    previousTotals.value = null;
    compareRange.value = null;
    compareRanges.value = null;
    buckets.value = [];
    series.value = {};
    loading.value = false;
    return;
  }

  loading.value = true;
  Rest.get("reports/product-compare", {params: {...filters.params, groupKey: groupKey.value}})
      .then((response) => {
        if (requestId !== latestRequestId) return;
        entities.value = Array.isArray(response?.entities) ? response.entities : [];
        totals.value = response?.totals || {};
        variationLimit.value = Number(response?.variation_limit) || 0;
        previousTotals.value = response?.previous_totals || null;
        compareRange.value = response?.compare_range || null;
        compareRanges.value = response?.compare_ranges || null;
        buckets.value = Array.isArray(response?.buckets) ? response.buckets : [];
        series.value = response?.series || {};
        // The server coarsens a grouping with too many buckets; show the one the buckets were built with
        if (groupKey.value !== "default" && response?.group_key && response.group_key !== groupKey.value) {
          groupKey.value = response.group_key;
          lastRequestKey = currentRequestKey();
        }
      })
      .catch((errors) => {
        if (requestId !== latestRequestId) return;
        lastRequestKey = null;
        entities.value = [];
        totals.value = {};
        previousTotals.value = null;
        compareRange.value = null;
        compareRanges.value = null;
        buckets.value = [];
        series.value = {};
        loadError.value = errors?.data?.message || translate("Failed to load the product comparison");
        Notify.error(loadError.value);
      })
      .finally(() => {
        if (requestId !== latestRequestId) return;
        loading.value = false;
      });
};

// Each tick in the product tree fires a filter change; wait for the picks to settle.
// The filter re-notifies with unchanged params after its own meta request; skip those.
// Otherwise bump the token now so an in-flight response for the old selection is dropped.
const scheduleFetch = () => {
  if (currentRequestKey() === lastRequestKey) return;
  lastRequestKey = null;
  latestRequestId++;
  clearTimeout(fetchTimer);
  fetchTimer = setTimeout(fetchCompare, FETCH_DELAY_MS);
};

const pickOrder = (variationId) => {
  const index = pickedIds.value.indexOf(Number(variationId));
  return index === -1 ? Number.MAX_SAFE_INTEGER : index;
};

const changeGroupKey = (value) => {
  if (groupKey.value === value) return;
  groupKey.value = value;
  clearTimeout(fetchTimer);
  fetchCompare();
};

const seriesOf = (variationId) => {
  const row = series.value[variationId] || {};
  return METRICS.reduce((values, key) => {
    values[key] = buckets.value.map((bucket, index) => Number(row[key]?.[index]) || 0);
    return values;
  }, {});
};

const hasComparison = computed(() => props.reportFilter.data.compareType !== "no_comparison" && previousTotals.value !== null);

const totalsOf = (row = {}) => TOTAL_FIELDS.reduce((values, name) => {
  values[name] = Number(row[name]) || 0;
  return values;
}, {});

const addTotals = (sum, values) => TOTAL_FIELDS.reduce((next, name) => {
  next[name] = sum[name] + values[name];
  return next;
}, {});

const previousOf = (variationId) => totalsOf(previousTotals.value?.[variationId]);

const variationRows = computed(() => entities.value.map((entity) => {
  const total = totals.value[entity.variation_id] || {};
  return {
    key: "v" + entity.variation_id,
    order: pickOrder(entity.variation_id),
    name: entity.product_title,
    sub: entity.variation_title,
    ...totalsOf(total),
    previous: previousOf(entity.variation_id),
    values: seriesOf(entity.variation_id),
  };
}));

const productRows = computed(() => {
  const products = {};
  entities.value.forEach((entity) => {
    const total = totals.value[entity.variation_id] || {};
    const key = "p" + entity.product_id;
    if (!products[key]) {
      products[key] = {
        key,
        order: Number.MAX_SAFE_INTEGER,
        name: entity.product_title,
        variations: 0,
        ...totalsOf(),
        previous: totalsOf(),
        values: seriesOf(null),
      };
    }
    const product = products[key];
    const previous = previousOf(entity.variation_id);
    const values = seriesOf(entity.variation_id);
    METRICS.forEach((name) => {
      product.values[name] = product.values[name].map((value, index) => value + values[name][index]);
    });
    product.order = Math.min(product.order, pickOrder(entity.variation_id));
    product.variations += 1;
    Object.assign(product, addTotals(product, totalsOf(total)));
    product.previous = addTotals(product.previous, previous);
  });

  return Object.values(products).map((product) => ({
    ...product,
    sub: product.variations === 1
        ? translate("1 variation")
        : translate("%s variations", product.variations),
  }));
});

// Share is the active metric over every selected row, so ranking by share is ranking by that metric.
const sortedRows = computed(() => {
  const rows = groupBy.value === "variation" ? variationRows.value : productRows.value;
  const {prop, order} = sortState.value;
  const field = prop === "share" ? metric.value : prop;
  const direction = order === "ascending" ? 1 : -1;
  return [...rows].sort((a, b) => ((a[field] - b[field]) * direction)
      || (b.revenue - a.revenue)
      || (a.order - b.order));
});

const topRows = computed(() => sortedRows.value.slice(0, MAX_SHOWN));

const hasMoreRows = computed(() => sortedRows.value.length > MAX_SHOWN);

const visibleRows = computed(() => (expanded.value ? sortedRows.value : topRows.value));

watch([() => pickedIds.value.join(","), groupBy], () => {
  expanded.value = false;
});

const selectedTotal = computed(() => sortedRows.value.reduce((sum, row) => addTotals(sum, row), totalsOf()));

const selectedPrevious = computed(() => sortedRows.value.reduce((sum, row) => addTotals(sum, row.previous), totalsOf()));

// Only the top rows get a colour, so the chart draws the same entities whether or not the table is expanded.
// An entity keeps its colour while it stays in the top rows; newcomers take the free colours in row order.
watch(() => topRows.value.map((row) => row.key), (keys) => {
  const next = {};
  keys.forEach((key) => {
    if (colorByKey.value[key]) next[key] = colorByKey.value[key];
  });
  const taken = Object.values(next);
  const free = palette.filter((color) => !taken.includes(color));
  keys.forEach((key) => {
    if (!next[key]) next[key] = free.shift();
  });
  colorByKey.value = next;
}, {immediate: true});

// colorByKey holds palette tokens so slots survive a theme switch; golden_rod is too light on white.
const lineColor = (color) => (!isDarkTheme.value && color === reportColors.golden_rod ? Theme.colors.warning['700'] : color);

const shownRows = computed(() => visibleRows.value.map((row) => ({
  ...row,
  color: lineColor(colorByKey.value[row.key]),
  share: selectedTotal.value[metric.value] ? row[metric.value] / selectedTotal.value[metric.value] : 0,
})));

const sortLabel = computed(() => ({
  orders: translate("Orders"),
  revenue: translate("Revenue"),
  units: translate("Units"),
  customers: translate("Customers"),
  discount: translate("Discounts"),
  refunds: translate("Refunds"),
  share: translate("Share"),
})[sortState.value.prop]);

const rowsNote = computed(() => (expanded.value
    ? translate("Showing all %s.", formatNumber(sortedRows.value.length))
    : translate(
        "Showing the top 5 of %1$s by %2$s. Narrow the product filter to compare specific %3$s.",
        formatNumber(sortedRows.value.length),
        sortLabel.value,
        groupBy.value === "variation" ? translate("variations") : translate("products")
    )));

// echarts keys legend toggling by series name, so a repeated title gets its id appended,
// and a name that still collides (a product literally titled "Widget (#12)") gets a counter.
const chartSeries = computed(() => {
  const rows = shownRows.value.filter((row) => row.color);
  const titles = rows.map((row) => (groupBy.value === "variation" ? row.name + " – " + row.sub : row.name));
  const used = new Set();
  return rows.map((row, index) => {
    const title = titles[index];
    const base = titles.indexOf(title) === titles.lastIndexOf(title) ? title : title + " (#" + row.key.slice(1) + ")";
    let name = base;
    for (let count = 2; used.has(name); count += 1) {
      name = base + " " + count;
    }
    used.add(name);
    return {
      key: row.key,
      name,
      color: row.color,
      data: row.values[metric.value],
    };
  });
});

const chartRef = ref(null);

const onRowEnter = (row) => {
  if (row.color && chartRef.value) chartRef.value.highlightSeries(row.key);
};

const onRowLeave = (row) => {
  if (row.color && chartRef.value) chartRef.value.downplaySeries(row.key);
};

const chartTitle = computed(() => {
  const label = metricLabels[metric.value];
  if (groupBy.value === "variation") {
    /* translators: %s - chart metric: Units, Revenue or Orders */
    return translate("%s by variation", label);
  }
  /* translators: %s - chart metric: Units, Revenue or Orders */
  return translate("%s by product", label);
});

const ghostText = computed(() => (pickedIds.value.length && !shownRows.value.length
    ? translate("No sales for the picked products in this date range.")
    : ""));

const filterState = useFilterState();
const openProductFilter = () => {
  const options = Array.isArray(filterState.filters) ? filterState.filters : [];
  const option = options.find((item) => item.value === "variation_ids");
  if (!option) return;
  filterState.selectActiveFilter(option);
  if (!filterState.dropdownOpen) filterState.toggleDropdown();
  nextTick(() => {
    const dropdown = document.querySelector(".fct-filter-dropdown");
    if (!dropdown) return;
    dropdown.scrollIntoView({block: "nearest"});
    dropdown.querySelector(".fct-product-tree input")?.focus();
  });
};

const shareTips = {
  units: translate("Share of the selected total units."),
  revenue: translate("Share of the selected total revenue."),
  orders: translate("Share of the selected total orders."),
};

const metricColumn = (name) => (metric.value === name ? "fct-product-compare__col--active" : "");

const onSortChange = ({prop, order}) => {
  sortState.value = prop && order ? {prop, order} : {...DEFAULT_SORT};
};

const tableRef = ref(null);

// A new metric re-ranks the table by it; the headers can re-sort afterwards.
watch(metric, (name) => {
  sortState.value = {prop: name, order: "descending"};
  if (tableRef.value) tableRef.value.sort(name, "descending");
});

// Fixed fraction digits from the report currency (JPY has none); the shared formatters drop trailing zeros.
const formatMoney = (value) => {
  const code = String(props.reportFilter.data.currency || AppConfig.get("shop.currency", "")).toUpperCase();
  const decimals = AppConfig.get("currency_decimals", {})[code];
  const digits = typeof decimals === "number" ? decimals : 2;
  const sign = props.reportFilter.currentCurrencySign || AppConfig.get("shop.currency_sign", "");
  const amount = translateNumber(new Intl.NumberFormat(undefined, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(Number(value) || 0));
  return AppConfig.get("shop.currency_position", "before") === "before" ? sign + amount : amount + sign;
};

const compactNumber = new Intl.NumberFormat(undefined, {notation: "compact", maximumFractionDigits: 1});
const decodeEntities = (html) => new DOMParser().parseFromString(html, "text/html").documentElement.textContent;

// The tooltip renders HTML; axis labels are canvas text, so their compact form decodes the sign's entities.
const formatChartValue = (value, compact = false) => {
  if (metric.value !== "revenue") return formatNumber(value);
  if (!compact) return formatMoney(value);
  const sign = decodeEntities(props.reportFilter.currentCurrencySign || AppConfig.get("shop.currency_sign", ""));
  const amount = translateNumber(compactNumber.format(Number(value) || 0));
  return AppConfig.get("shop.currency_position", "before") === "before" ? sign + amount : amount + sign;
};

// Table cells scale to K/M like the Sources report; the chart tooltip keeps the exact amount.
const Money = (moneyProps) => h("span", props.reportFilter.formatAmount(moneyProps.value));
Money.props = ["value"];

const limitNote = computed(() => (variationLimit.value && pickedIds.value.length > variationLimit.value
    ? translate("Comparing the first %1$s of the %2$s picked variations.", formatNumber(variationLimit.value), formatNumber(pickedIds.value.length))
    : ""));

const shareFormatter = new Intl.NumberFormat(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1});
const formatShare = (share) => translateNumber(shareFormatter.format(share * 100)) + "%";

// Previous revenue can be negative after refunds, so divide by its size; a zero base reads ±100% or 0% as in the other reports
function deltaOf(current, previous) {
  if (!previous) return current ? Math.sign(current) * 100 : 0;
  return Number(((current - previous) / Math.abs(previous) * 100).toFixed(1));
}

// compare_range is wall-clock time in the offset the request was sent with; a timezone shift would move the day
const formatRangeDay = (value) => translateNumber(
    dayjs(String(value).slice(0, 10)).locale(fluentDayjsLocale()).format(resolveDateFormat("date"))
);

watch(compareRange, (range) => {
  props.reportFilter.data.compareRange = range;
});

watch(compareRanges, (ranges) => {
  props.reportFilter.data.compareRanges = ranges;
});

const compareCaption = computed(() => {
  if (!hasComparison.value || !compareRange.value?.start || !compareRange.value?.end) return "";
  /* translators: %1$s - compare period start date, %2$s - compare period end date */
  return translate("Change compared with %1$s – %2$s", formatRangeDay(compareRange.value.start), formatRangeDay(compareRange.value.end));
});

const withFluctuation = (value, current, previous, hasCurrency = false, isLiability = false) => (hasComparison.value
    ? h("div", {class: "fct-product-compare__metric"}, [
      value,
      h(Fluctuation, {fluctuation: deltaOf(current, previous), amount: previous, hasCurrency, isLiability}),
    ])
    : value);

const summaryMethod = ({columns}) => columns.map((column, index) => {
  if (index === 0) {
    return translate("Selected total");
  }
  const total = selectedTotal.value;
  const previous = selectedPrevious.value;
  switch (column.property) {
    case "units":
      return withFluctuation(formatNumber(total.units), total.units, previous.units);
    case "revenue":
      return withFluctuation(h(Money, {value: total.revenue}), total.revenue, previous.revenue, true);
    case "orders":
      return withFluctuation(formatNumber(total.orders), total.orders, previous.orders);
    case "customers":
      return withFluctuation(formatNumber(total.customers), total.customers, previous.customers);
    case "discount":
      return withFluctuation(h(Money, {value: total.discount}), total.discount, previous.discount, true, true);
    case "refunds":
      return withFluctuation(h(Money, {value: total.refunds}), total.refunds, previous.refunds, true, true);
    case "share":
      return total[metric.value] ? formatShare(1) : formatShare(0);
    default:
      return "";
  }
});

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
};

onMounted(() => {
  props.reportFilter.addListener(LISTENER_KEY, scheduleFetch);
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  fetchCompare();
});

onUnmounted(() => {
  clearTimeout(fetchTimer);
  latestRequestId++;
  props.reportFilter.removeListener(LISTENER_KEY);
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange);
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-revenue-report-page fct-product-compare">
      <PageHeading :title="translate('Compare products')"/>
      <p class="fct-product-compare__intro">
        {{ translate('Each product you pick in the Product filter gets its own row here.') }}
      </p>

      <Card.Container v-if="!isProActive">
        <ProFeatureNotice
            class="py-7.5"
            placement="feature_lock_report_advanced_filtering"
            :title="translate('Upgrade to Pro to compare products')"
            :text="translate('This feature is only available in FluentCart Pro.')"
        />
      </Card.Container>

      <ProductCompareChart
          v-if="isProActive && !loadError"
          ref="chartRef"
          :report-filter="reportFilter"
          :title="chartTitle"
          :buckets="buckets"
          :series="chartSeries"
          :metric="metric"
          :format-value="formatChartValue"
          :group-key="groupKey"
          :ghost-text="loading ? '' : ghostText"
          :resting="!pickedIds.length"
          :preview-colors="previewColors"
          :show-actions="pickedIds.length > 0"
          :loading="loading"
          @update:group-key="changeGroupKey"
          @open-filter="openProductFilter"
      >
        <template #actions>
          <el-radio-group v-model="metric" size="small" :aria-label="translate('Metric')">
            <el-radio-button v-for="name in METRICS" :key="name" :value="name">{{ metricLabels[name] }}</el-radio-button>
          </el-radio-group>
        </template>
      </ProductCompareChart>

      <Card.Container v-if="isProActive && (loading || loadError || shownRows.length)" class="overflow-hidden">
        <Card.Header :title="translate('Totals')" title_size="small" border_bottom>
          <template #action>
            <el-radio-group v-model="groupBy" size="small" :aria-label="translate('Group by')">
              <el-radio-button value="product">{{ translate('Product') }}</el-radio-button>
              <el-radio-button value="variation">{{ translate('Variation') }}</el-radio-button>
            </el-radio-group>
          </template>
        </Card.Header>

        <Card.Body class="p-0">
          <el-skeleton v-if="loading" class="p-5" :rows="5" animated/>

          <div v-else-if="loadError" class="fct-product-compare__error" role="alert">
            <p>{{ loadError }}</p>
            <el-button size="small" @click="fetchCompare">{{ translate('Try again') }}</el-button>
          </div>

          <template v-else>
            <p v-if="limitNote" class="fct-product-compare__hint" role="status">{{ limitNote }}</p>
            <p v-if="sortedRows.length === 1" class="fct-product-compare__hint">
              {{ translate('Pick one more to compare.') }}
            </p>

            <p v-if="compareCaption" class="fct-product-compare__hint">{{ compareCaption }}</p>

            <el-table
                ref="tableRef"
                :data="shownRows"
                row-key="key"
                class="w-full fct-product-compare__table"
                :default-sort="sortState"
                show-summary
                :summary-method="summaryMethod"
                @sort-change="onSortChange"
                @cell-mouse-enter="onRowEnter"
                @cell-mouse-leave="onRowLeave"
            >
              <el-table-column :label="translate('Entity')" min-width="175">
                <template #default="{row}">
                  <div class="fct-product-compare__entity">
                    <span class="fct-product-compare__dot" :style="{backgroundColor: row.color}" aria-hidden="true"/>
                    <div class="fct-product-compare__entity-text">
                      <span class="fct-product-compare__name">{{ row.name }}</span>
                      <span class="fct-product-compare__sub">{{ row.sub }}</span>
                    </div>
                  </div>
                </template>
              </el-table-column>

              <el-table-column prop="orders" :label="translate('Orders')" sortable="custom" :sort-orders="['descending', 'ascending']" min-width="110" :class-name="metricColumn('orders')" :label-class-name="metricColumn('orders')">
                <template #header>
                  <span class="fct-product-compare__header">
                    {{ translate('Orders') }}
                    <el-tooltip
                        v-if="groupBy === 'product'"
                        :content="translate('Sum of variation orders. An order holding two variations of one product counts twice.')"
                        placement="top"
                        popper-class="fct-tooltip"
                    >
                      <button
                          type="button"
                          class="fct-product-compare__info"
                          :aria-label="translate('Sum of variation orders. An order holding two variations of one product counts twice.')"
                          @click.stop
                      >
                        <DynamicIcon name="InformationFill"/>
                      </button>
                    </el-tooltip>
                  </span>
                </template>
                <template #default="{row}">
                  <div class="fct-product-compare__metric">
                    {{ formatNumber(row.orders) }}
                    <Fluctuation v-if="hasComparison" :fluctuation="deltaOf(row.orders, row.previous.orders)" :amount="row.previous.orders" :has-currency="false"/>
                  </div>
                </template>
              </el-table-column>

              <el-table-column prop="revenue" :label="translate('Revenue')" sortable="custom" :sort-orders="['descending', 'ascending']" min-width="110" :class-name="metricColumn('revenue')" :label-class-name="metricColumn('revenue')">
                <template #default="{row}">
                  <div class="fct-product-compare__metric">
                    <Money :value="row.revenue"/>
                    <Fluctuation v-if="hasComparison" :fluctuation="deltaOf(row.revenue, row.previous.revenue)" :amount="row.previous.revenue" :has-currency="true"/>
                  </div>
                </template>
              </el-table-column>

              <el-table-column prop="units" :label="translate('Units')" sortable="custom" :sort-orders="['descending', 'ascending']" min-width="80" :class-name="metricColumn('units')" :label-class-name="metricColumn('units')">
                <template #default="{row}">
                  <div class="fct-product-compare__metric">
                    {{ formatNumber(row.units) }}
                    <Fluctuation v-if="hasComparison" :fluctuation="deltaOf(row.units, row.previous.units)" :amount="row.previous.units" :has-currency="false"/>
                  </div>
                </template>
              </el-table-column>

              <el-table-column prop="customers" :label="translate('Customers')" sortable="custom" :sort-orders="['descending', 'ascending']" min-width="135">
                <template #header>
                  <span class="fct-product-compare__header">
                    {{ translate('Customers') }}
                    <el-tooltip
                        v-if="groupBy === 'product'"
                        :content="translate('Sum of variation customers. A customer who bought two variations of one product counts twice.')"
                        placement="top"
                        popper-class="fct-tooltip"
                    >
                      <button
                          type="button"
                          class="fct-product-compare__info"
                          :aria-label="translate('Sum of variation customers. A customer who bought two variations of one product counts twice.')"
                          @click.stop
                      >
                        <DynamicIcon name="InformationFill"/>
                      </button>
                    </el-tooltip>
                  </span>
                </template>
                <template #default="{row}">
                  <div class="fct-product-compare__metric">
                    {{ formatNumber(row.customers) }}
                    <Fluctuation v-if="hasComparison" :fluctuation="deltaOf(row.customers, row.previous.customers)" :amount="row.previous.customers" :has-currency="false"/>
                  </div>
                </template>
              </el-table-column>

              <el-table-column prop="discount" :label="translate('Discounts')" sortable="custom" :sort-orders="['descending', 'ascending']" min-width="110">
                <template #default="{row}">
                  <div class="fct-product-compare__metric">
                    <Money :value="row.discount"/>
                    <Fluctuation v-if="hasComparison" :fluctuation="deltaOf(row.discount, row.previous.discount)" :amount="row.previous.discount" :has-currency="true" :is-liability="true"/>
                  </div>
                </template>
              </el-table-column>

              <el-table-column prop="refunds" :label="translate('Refunds')" sortable="custom" :sort-orders="['descending', 'ascending']" min-width="120">
                <template #header>
                  <span class="fct-product-compare__header">
                    {{ translate('Refunds') }}
                    <el-tooltip
                        :content="translate('Refunds on orders placed in this date range, counted by order date, not by refund date.')"
                        placement="top"
                        popper-class="fct-tooltip"
                    >
                      <button
                          type="button"
                          class="fct-product-compare__info"
                          :aria-label="translate('Refunds on orders placed in this date range, counted by order date, not by refund date.')"
                          @click.stop
                      >
                        <DynamicIcon name="InformationFill"/>
                      </button>
                    </el-tooltip>
                  </span>
                </template>
                <template #default="{row}">
                  <div class="fct-product-compare__metric">
                    <Money :value="row.refunds"/>
                    <Fluctuation v-if="hasComparison" :fluctuation="deltaOf(row.refunds, row.previous.refunds)" :amount="row.previous.refunds" :has-currency="true" :is-liability="true"/>
                  </div>
                </template>
              </el-table-column>

              <el-table-column prop="share" :label="translate('Share')" sortable="custom" :sort-orders="['descending', 'ascending']" min-width="115">
                <template #header>
                  <span class="fct-product-compare__header">
                    {{ translate('Share') }}
                    <el-tooltip
                        :content="shareTips[metric]"
                        placement="top"
                        popper-class="fct-tooltip"
                    >
                      <button
                          type="button"
                          class="fct-product-compare__info"
                          :aria-label="shareTips[metric]"
                          @click.stop
                      >
                        <DynamicIcon name="InformationFill"/>
                      </button>
                    </el-tooltip>
                  </span>
                </template>
                <template #default="{row}">{{ formatShare(row.share) }}</template>
              </el-table-column>
            </el-table>

            <p v-if="hasMoreRows" class="fct-product-compare__note">
              {{ rowsNote }}
              <el-button
                  link
                  type="primary"
                  class="fct-product-compare__toggle"
                  :aria-expanded="expanded ? 'true' : 'false'"
                  @click="expanded = !expanded"
              >
                {{ expanded ? translate('Show top 5') : translate('Show all %s', formatNumber(sortedRows.length)) }}
              </el-button>
            </p>
          </template>
        </Card.Body>
      </Card.Container>
    </div>
  </UserCan>
</template>
