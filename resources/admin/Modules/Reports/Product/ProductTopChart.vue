<template>
  <div class="fct-product-performance-chart">
    <Card.Container>
      <Card.Header
          :title="translate('Product Performance Comparison')"
          title_size="small"
          border_bottom
      />
      <Card.Body class="pt-6">
        <el-skeleton v-if="loading" animated :rows="8" />

        <template v-else>
          <template v-if="!isEmpty">
            <!-- Minimal Left-aligned Selection Interface -->
            <div
                class="mb-6 flex flex-col sm:flex-row gap-6 items-start sm:items-center"
            >
              <!-- Selection Container -->
              <div class="flex items-center gap-3 flex-wrap">
                <!-- Primary Selection -->
                <div class="selector-group">
                  <el-select
                      v-model="primaryYear"
                      :placeholder="translate('Year')"
                      size="small"
                      class="year-select"
                      popper-class="minimal-select"
                  >
                    <el-option
                        v-for="year in availableYears"
                        :key="year"
                        :label="year"
                        :value="year"
                    ></el-option>
                  </el-select>

                  <el-select
                      v-model="primaryMonth"
                      :placeholder="translate('Month')"
                      size="small"
                      clearable
                      class="month-select"
                      :disabled="!primaryYear"
                      popper-class="minimal-select"
                  >
                    <el-option
                        v-for="month in monthsForYear(primaryYear)"
                        :key="month.value"
                        :label="month.label"
                        :value="month.value"
                    ></el-option>
                  </el-select>
                </div>

                <!-- VS Pill -->
                <div
                    class="vs-pill inline-flex items-center justify-center px-2 h-6 bg-gray-100 rounded-[12px] font-xs font-medium text-uppercase dark:bg-primary-500"
                >
                  {{ translate("vs") }}
                </div>

                <!-- Comparison Selection -->
                <div class="selector-group">
                  <el-select
                      v-model="comparisonYear"
                      :placeholder="translate('Year')"
                      size="small"
                      class="year-select"
                      popper-class="minimal-select"
                  >
                    <el-option
                        v-for="year in availableYears"
                        :key="year"
                        :label="year"
                        :value="year"
                    ></el-option>
                  </el-select>

                  <el-select
                      v-model="comparisonMonth"
                      :placeholder="translate('Month')"
                      size="small"
                      clearable
                      class="month-select"
                      :disabled="!comparisonYear"
                      popper-class="minimal-select"
                  >
                    <el-option
                        v-for="month in monthsForYear(comparisonYear)"
                        :key="month.value"
                        :label="month.label"
                        :value="month.value"
                    ></el-option>
                  </el-select>
                </div>
              </div>

              <!-- Current Comparison Info Tag -->
              <div
                  v-if="canShowComparison"
                  class="comparison-mode ml-auto inline-flex items-center h-6 px-2.5 bg-gray-50 rounded-[12px] dark:bg-primary-500"
              >
                <span class="text-xs font-medium text-gray-500">
                  {{ translate('Comparing %s with %s', primaryLabel, comparisonLabel) }}
                </span>
              </div>

              <!-- Current Selection Info Tag (when no comparison) -->
              <div
                  v-else-if="primaryYear"
                  class="comparison-mode ml-auto inline-flex items-center h-6 px-2.5 bg-gray-50 rounded-[12px] dark:bg-primary-500"
              >
                <span class="text-xs font-medium text-gray-500">
                  {{ translate('Viewing %s', primaryLabel) }}
                </span>
              </div>
            </div>

            <!-- Comparison Results -->
            <div v-if="canShowComparison" class="comparison-results">
              <!-- Legend for the dual-color bar -->
              <div class="legend flex items-center gap-4 mb-4">
                <div class="flex items-center">
                  <div class="w-3 h-3 primary-legend rounded-sm mr-1.5"></div>
                  <span class="text-xs text-gray-600">{{ primaryLabel }}</span>
                </div>
                <div class="flex items-center">
                  <div class="w-3 h-3 comparison-legend rounded-sm mr-1.5"></div>
                  <span class="text-xs text-gray-600">{{ comparisonLabel }}</span>
                </div>
              </div>

              <div class="comparison-table">
                <table class="w-full">
                  <thead class="sticky top-0 z-10 bg-gray-50 dark:bg-primary-500">
                  <tr>
                    <th
                        class="w-[50%] text-left font-medium text-system-dark border-0 border-b-[1px] border-solid border-gray-divider dark:border-dark-400 px-2.5 py-2 text-uppercase dark:text-gray-50"
                    >
                      {{ translate("Product") }}
                    </th>
                    <th
                        class="w-[40%] text-left font-medium text-system-dark border-0 border-b-[1px] border-solid border-gray-divider dark:border-dark-400 px-2.5 py-2 text-uppercase dark:text-gray-50"
                    >
                      {{ translate("Sales") }}
                    </th>
                    <th
                        class="w-[10%] text-right font-medium text-system-dark border-0 border-b-[1px] border-solid border-gray-divider dark:border-dark-400 px-2.5 py-2 text-uppercase dark:text-gray-50"
                    >
                      {{ translate("Change") }}
                    </th>
                  </tr>
                  </thead>
                  <tbody>
                  <tr
                      v-for="(product, index) in comparisonData"
                      :key="index"
                      class="product-row border-0 border-b-[1px] border-solid border-gray-divider dark:border-dark-400"
                  >
                    <!-- Product Name -->

                    <td
                        class="py-3 product-name font-medium max-w-[200px] truncate text-sm text-system-dark dark:text-gray-50"
                    >
                      {{ product.post_title }}
                      <br>
                      {{ product.name }}
                    </td>

                    <!-- Enhanced Single Dual-Color Bar with Labels -->
                    <td class="py-3">
                      <div class="dual-bar-container">
                        <span class="bar-label">
                          {{ product.primaryValue }}{{ ' vs ' + product.comparisonValue }} {{ translate("Units") }}
                        </span>
                        <div class="dual-bar-wrapper">
                          <!-- Primary data point portion -->
                          <div
                                class="dual-bar-segment primary-segment"
                                :style="{
                                  width: `${
                                    getSegmentWidth(
                                      product.primaryValue,
                                      product.comparisonValue
                                    ).primaryWidth
                                  }%`,
                                }"
                                @mouseenter="showTooltip($event, getToolTip(product.primaryValue, primaryLabel))"
                                @mouseleave="hideTooltip"
                            />
                          
                          <!-- Comparison data point portion -->
                          <div
                            class="dual-bar-segment comparison-segment"
                            :style="{
                              width: `${
                                getSegmentWidth(
                                  product.primaryValue,
                                  product.comparisonValue
                                ).comparisonWidth
                              }%`,
                            }"
                            @mouseenter="showTooltip($event, getToolTip(product.comparisonValue, comparisonLabel))"
                            @mouseleave="hideTooltip"
                          />
                        </div>
                      </div>
                    </td>

                    <!-- Growth Rate -->
                    <td class="py-3 text-right">
                      <div
                          class="growth-pill"
                          :class="getGrowthClass(product.growthRate)"
                      >
                        {{
                          product.growthRate > 0 ? "+" : ""
                        }}{{ product.growthRate }}%
                      </div>
                    </td>
                  </tr>
                  <tr v-if="comparisonData.length === 0">
                    <td colspan="3" class="py-4 text-center text-gray-500">
                        <span class="text-sm">{{
                            translate("No comparison data available")
                          }}</span>
                    </td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Current Period Data (when comparison is not available) -->
            <div v-else-if="primaryYear && currentPeriodData.length > 0" class="current-period-data">
              <div class="legend flex items-center gap-4 mb-4">
                <div class="flex items-center">
                  <div class="w-3 h-3 primary-legend rounded-sm mr-1.5"></div>
                  <span class="text-xs text-gray-600">{{ primaryLabel }}</span>
                </div>
              </div>

              <div class="current-period-table">
                <table class="w-full">
                  <thead class="sticky top-0 z-10 bg-gray-50 dark:bg-primary-500">
                  <tr>
                    <th
                        class="w-[50%] text-left font-medium text-system-dark border-0 border-b-[1px] border-solid border-gray-divider dark:border-dark-400 px-2.5 py-2 text-uppercase dark:text-gray-50"
                    >
                      {{ translate("Product") }}
                    </th>
                    <th
                        class="w-[50%] text-left font-medium text-system-dark border-0 border-b-[1px] border-solid border-gray-divider dark:border-dark-400 px-2.5 py-2 text-uppercase dark:text-gray-50"
                    >
                      {{ translate("Sales") }}
                    </th>
                  </tr>
                  </thead>
                  <tbody>
                  <tr
                      v-for="(product, index) in currentPeriodData"
                      :key="index"
                      class="product-row border-0 border-b-[1px] border-solid border-gray-divider dark:border-dark-400"
                  >
                    <!-- Product Name -->
                    <td
                        class="py-3 product-name font-medium max-w-[200px] truncate text-sm text-system-dark dark:text-gray-50"
                    >
                      {{ product.post_title }}
                      <br>
                      {{ product.name }}
                    </td>

                    <!-- Sales Bar -->
                    <td class="py-3">
                      <div class="single-bar-container">
                        <span 
                          class="bar-label" 
                          @mouseenter="showTooltip($event, getToolTip(product.value, comparisonLabel))"
                          @mouseleave="hideTooltip"
                        >
                          {{ product.value }} {{ pluralizeTranslate("Unit", "Units", product.value) }}
                        </span>
                        <div class="single-bar-wrapper">
                          <!-- Primary data point bar -->
                          <div
                                class="single-bar-segment"
                                :style="{
                                  width: `${getSingleBarWidth(product.value)}%`,
                                }"
                                @mouseenter="showTooltip($event, getToolTip(product.value, comparisonLabel))"
                                @mouseleave="hideTooltip"
                            />
                        </div>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="currentPeriodData.length === 0">
                    <td colspan="2" class="py-4 text-center text-gray-500">
                        <span class="text-sm">{{
                            translate("No data available for this period")
                          }}</span>
                    </td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- No Data Available Message -->
            <div v-else class="no-comparison-message">
              <div
                  class="flex flex-col items-center justify-center text-center py-8"
              >
                <svg
                    class="w-10 h-10 text-gray-300 mb-3"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                  ></path>
                </svg>
                <p class="text-gray-500 text-sm">
                  {{ translate("Please select a time period to view sales data") }}
                </p>
              </div>
            </div>

            <!-- Single shared tooltip -->
            <el-tooltip
              ref="sharedTooltip"
              popper-class="fct-tooltip"
              v-model:visible="tooltipVisible"
              :content="currentTooltipContent"
              :virtual-ref="tooltipTarget"
              virtual-triggering
              placement="top"
            />
          </template>

          <Empty
            v-else
            icon="Empty/ListView"
            :has-dark="true"
            :text="translate('Currently there is no data!')"
            class="fct-report-empty-state"
          />
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>

<script setup>
import {ref, computed, watch, onMounted, nextTick} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import dayjs from "dayjs";
import Theme from "@/utils/Theme";
import translate, { pluralizeTranslate } from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import { tooltipSuffix } from '../Utils/decorator';
import Empty from "@/Bits/Components/Table/Empty.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const isDarkTheme = ref(Theme.isDark());
const loading = ref(true)

onMounted(() => {
  getTopChart();
  props.reportFilter.addListener("product-top-chart", () => {
    getTopChart()
  });

  window.addEventListener("onFluentCartThemeChange", function () {
    isDarkTheme.value = Theme.isDark();
  });
});

const productTopChart = ref({});
const chartData = ref({});
const getTopChart = () => {
  loading.value = true;

  const filters = props.reportFilter.applicableFilters;
  Rest.get(`reports/product-performance`, filters).then((response) => {
    chartData.value = response.productPerformance;
    setupAvailableYears();
    setupInitialSelections(true);
  }).finally(() => loading.value = false);
}

const isEmpty = computed(() => {
  const data = Array.isArray(chartData.value) ? chartData.value : Object.values(chartData.value);
  
  return !data.length;
});


// Primary data point selectors
const primaryYear = ref("");
const primaryMonth = ref("");

// Comparison data point selectors
const comparisonYear = ref("");
const comparisonMonth = ref("");

// Sort months chronologically
const sortMonths = (months) => {
  return months.sort((a, b) => dayjs(a.value).diff(dayjs(b.value)));
};

const availableYears = ref([]);
const setupAvailableYears = () => {
  const years = new Set();
  Object.keys(chartData.value).forEach((monthKey) => {
    const year = monthKey.split("-")[0];
    years.add(year);
  });
  availableYears.value = Array.from(years).sort();
}


// Function to get months for a specific year
const monthsForYear = (year) => {
  if (!year) return [];

  const months = [];
  Object.keys(chartData.value).forEach((monthKey) => {
    if (monthKey.startsWith(year)) {
      months.push({
        value: monthKey,
        label: dayjs(monthKey).format("MMMM"),
      });
    }
  });

  return sortMonths(months);
};

// Check if we can show comparison based on selections
const canShowComparison = computed(() => {
  // Basic requirement: years must be selected
  if (!primaryYear.value || !comparisonYear.value) {
    return false;
  }

  // Check for valid comparison scenarios
  const isPrimaryMonth = !!primaryMonth.value;
  const isComparisonMonth = !!comparisonMonth.value;

  // Case 1: Year vs Year - years must be different
  if (!isPrimaryMonth && !isComparisonMonth) {
    return primaryYear.value !== comparisonYear.value;
  }

  // Case 2: Month vs Month - must be different month-year combinations
  if (isPrimaryMonth && isComparisonMonth) {
    return primaryMonth.value !== comparisonMonth.value;
  }

  // Case 3 & 4: Year vs Month or Month vs Year - always valid
  return true;
});

// Generate labels for the selected data points
const primaryLabel = computed(() => {
  if (primaryMonth.value) {
    return dayjs(primaryMonth.value).format("MMM YYYY");
  } else {
    return primaryYear.value;
  }
});

const comparisonLabel = computed(() => {
  if (comparisonMonth.value) {
    return dayjs(comparisonMonth.value).format("MMM YYYY");
  } else {
    return comparisonYear.value;
  }
});

const setupInitialSelections = (refresh = false) => {
  const newYears = availableYears.value;
  if (newYears.length) {
    // Set primary to first year
    if (!primaryYear.value || refresh) {
      primaryYear.value = newYears[0]; // First year

      // Set primary month to first month of first year
      const primaryMonths = monthsForYear(primaryYear.value);
      if (primaryMonths.length) {
        primaryMonth.value = primaryMonths[0].value; // First month
      }
    }

    // Set comparison to last year
    if (!comparisonYear.value || refresh) {
      comparisonYear.value = newYears[newYears.length - 1]; // Last year

      // Set comparison month to last month of last year
      const comparisonMonths = monthsForYear(comparisonYear.value);
      if (comparisonMonths.length) {
        comparisonMonth.value =
            comparisonMonths[comparisonMonths.length - 1].value; // Last month
      }
    }
  }
}


// Get data for a specific month
const getMonthData = (monthKey) => {
  return chartData.value[monthKey] || [];
};

// Aggregate data by year
const aggregateDataByYear = (year) => {
  const yearData = {};

  // Collect all month data for the year
  Object.entries(chartData.value).forEach(([monthKey, products]) => {
    if (monthKey.startsWith(year)) {
      products.forEach((product) => {
        if (!yearData[product.name]) {
          yearData[product.variation_id] = {
            ...product,
            value: 0,
          };
        }
        yearData[product.variation_id].value += product.value;
      });
    }
  });

  const data = [];
  Object.values(yearData).forEach((product) => {
    data.push(product);
  });

  return data;
};

// Get the appropriate data based on selection (year or month)
const getDataForSelection = (year, month) => {
  if (month) {
    return getMonthData(month);
  } else {
    const data = aggregateDataByYear(year);
    return aggregateDataByYear(year);
  }
};

// Current period data when no comparison is available
const currentPeriodData = computed(() => {
  if (!primaryYear.value) return [];

  // Get data for the current selection
  const data = getDataForSelection(primaryYear.value, primaryMonth.value);

  // Sort by value (highest first)
  return [...data].sort((a, b) => b.value - a.value);
});

// Find the maximum value for scaling single bars
const maxSingleValue = computed(() => {
  if (!currentPeriodData.value.length) return 100;
  return Math.max(...currentPeriodData.value.map(item => item.value)) * 1.1;
});

// Calculate width percentage for single bar display
const getSingleBarWidth = (value) => {
  if (value === 0 || maxSingleValue.value === 0) return 0;
  return (value / maxSingleValue.value) * 100;
};

// Comparison data for the selected time periods
const comparisonData = computed(() => {
  if (!canShowComparison.value) return [];

  // Get data based on selections
  const primaryData = getDataForSelection(
      primaryYear.value,
      primaryMonth.value
  );
  const comparisonData = getDataForSelection(
      comparisonYear.value,
      comparisonMonth.value
  );


  // Find all unique product names
  const variationIds = new Set();
  primaryData.forEach((item) => variationIds.add(item.variation_id));
  comparisonData.forEach((item) => variationIds.add(item.variation_id));

  const allIds = Array.from(variationIds);

  // Prepare data for comparison
  const data = allIds.map((variation_id) => {


    const primaryProduct = primaryData.find((p) => p.variation_id === variation_id);
    const primaryValue = primaryProduct?.value || 0;

    const comparisonProduct = comparisonData.find((p) => p.variation_id === variation_id);
    const comparisonValue = comparisonProduct?.value || 0;

    let growthRate = 0;
    if (primaryValue > 0) {
      growthRate = ((comparisonValue - primaryValue) / primaryValue) * 100;
    } else if (comparisonValue > 0) {
      growthRate = 100; // 100% growth from zero
    }

    return {
      name: primaryProduct?.name || comparisonProduct?.name || '',
      post_title: primaryProduct?.post_title || comparisonProduct?.post_title || '',
      primaryValue,
      comparisonValue,
      growthRate: parseFloat(growthRate.toFixed(1)),
    };
  });

  // Sort by absolute growth rate (most significant changes first)
  return data.sort((a, b) => Math.abs(b.growthRate) - Math.abs(a.growthRate));
});

// Function to calculate width percentages for the dual-color bar
const getSegmentWidth = (primaryValue, comparisonValue) => {
  // Handle cases where both values are 0
  if (primaryValue === 0 && comparisonValue === 0) {
    return {primaryWidth: 0, comparisonWidth: 0};
  }

  const total = primaryValue + comparisonValue;
  const primaryWidth = (primaryValue / total) * 100;
  const comparisonWidth = (comparisonValue / total) * 100;

  return {
    primaryWidth,
    comparisonWidth,
  };
};

// Function to get the appropriate class for growth rate
const getGrowthClass = (growthRate) => {
  if (growthRate > 0) return "positive";
  if (growthRate < 0) return "negative";
  return "neutral";
};

const primarySegmentColor = computed(() => {
  return isDarkTheme.value ? Theme.colors.report.dark_cyan_blue_16 : Theme.colors.report.light_gray;
});

const comparisonSegmentColor = computed(() => {
  return isDarkTheme.value ? Theme.colors.report.dark_cyan_blue_36 : Theme.colors.report.light_gray_cyan_blue;
});

const barLabelColor = computed(() => {
  return isDarkTheme.value ? '#ffffff' : Theme.colors.system.mid;
});

const getToolTip = (value, date) => {
  return `${value} ${pluralizeTranslate('unit', 'units', value)} sold in ${date}`;
}

const tooltipVisible = ref(false)
const currentTooltipContent = ref('')
const tooltipTarget = ref(null)
let showTimer = null

function showTooltip(event, content) {
  tooltipTarget.value = event.currentTarget
  currentTooltipContent.value = content

  showTimer = setTimeout(() => {
    tooltipVisible.value = true
  }, 150)
}

function hideTooltip() {
  tooltipVisible.value = false
}
</script>

<style scoped>
.product-chart-table,
.comparison-table,
.current-period-table {
  max-height: 550px;
  overflow-x: hidden;
}

table {
  border-collapse: collapse;
  width: 100%;
}

.product-name {
  font-weight: 500;
  max-width: 200px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: #334155;
}

.selector-group {
  display: flex;
  gap: 8px;
  align-items: center;
}

.year-select {
  width: 80px;
}

.month-select {
  width: 120px;
}

/* Dual-color bar styles with minimal aesthetics */
.dual-bar-container, .single-bar-container {
  width: 100%;
  padding: 0 5px;
  position: relative;
}

.dual-bar-wrapper, .single-bar-wrapper {
  display: flex;
  width: 100%;
  height: 24px;
  border-radius: 4px;
  overflow: hidden;
  position: relative;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.single-bar-wrapper {
  background-color: v-bind("isDarkTheme ? '#1C2732' : '#F9FAFB'");
}

.dual-bar-segment, .single-bar-segment {
  height: 100%;
  transition: width 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}

.dual-bar-segment.primary-segment {
  background-color: v-bind("primarySegmentColor");
}

.dual-bar-segment.comparison-segment {
  background-color: v-bind("comparisonSegmentColor");
}

.single-bar-segment {
  background-color: v-bind("primarySegmentColor");
}

.primary-legend {
  background-color: v-bind("primarySegmentColor");
}

.comparison-legend {
  background-color: v-bind("comparisonSegmentColor");
}

.bar-label {
  color: white;
  color: v-bind("barLabelColor");
  /* font-weight: 600; */
  font-size: 0.75rem;
  text-shadow: 0 1px 1px rgba(0, 0, 0, 0.15);
  z-index: 2;
}

.primary-label {
  left: 8px;
}

.comparison-label {
  right: 8px;
}

.single-label {
  font-weight: 600;
  font-size: 0.75rem;
}

.no-comparison-message {
  border: 1px dashed #e2e8f0;
  border-radius: 0.375rem;
  background-color: #f8fafc;
}

.dark .no-comparison-message {
  background-color: #1c2633;
  border-color: #253241;
}

/* Growth pill styling */
.growth-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 8px;
  height: 22px;
  border-radius: 11px;
  font-size: 0.75rem;
  font-weight: 600;
}

.growth-pill.positive {
  background-color: rgba(34, 197, 94, 0.1);
  color: #16a34a;
}

.growth-pill.negative {
  background-color: rgba(239, 68, 68, 0.1);
  color: #ef4444;
}

.growth-pill.neutral {
  background-color: rgba(100, 116, 139, 0.1);
  color: #64748b;
}

/* Add subtle animations */
@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

/* Global element styles for minimal design */
:deep(.el-select) {
  --el-select-input-focus-border-color: #94a3b8;
}

:deep(.el-input__inner) {
  font-size: 0.875rem;
}

:deep(.el-select .el-input__wrapper) {
  box-shadow: 0 0 0 1px #e2e8f0;
}

:deep(.el-select .el-input__wrapper:hover) {
  box-shadow: 0 0 0 1px #cbd5e1;
}

:deep(.el-select .el-input__wrapper.is-focus) {
  box-shadow: 0 0 0 1px #94a3b8;
}
</style>
