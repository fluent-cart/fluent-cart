<script setup>
import { ref, onMounted, onUnmounted, nextTick, computed, watch } from "vue";
import licenseReport from "@/Models/Reports/LicenseReportModel";
import * as echarts from "echarts";
import dayjs from "dayjs";
import Theme from "@/utils/Theme";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import translate from "../utils/translator/Translator";

// Define props
const props = defineProps({
  reportFilter: {
    type: Object,
  },
  activatedSites: String|Number
});

const colors = Theme.colors;
const baseColor = colors.blue;

const chartRef = ref(null);
const selectedGroupKey = ref("daily");
const chartType = ref("pie");
const dateRange = ref([dayjs().startOf("month"), dayjs()]);

// Track which legend items are selected/hidden
const legendStates = ref({});

const groupKeys = [
  { value: "daily", label: "Daily" },
  { value: "monthly", label: "Monthly" },
  { value: "yearly", label: "Yearly" },
];

// Computed property for custom legend data
const customLegendData = computed(() => {
  if (!licenseReport.data.pieChartData) return [];
  return licenseReport.data.pieChartData.map((item, index) => ({
    name: item.post_title,
    value: item.activation_count,
    percentage: item.percentage,
    color: getColorForIndex(index),
    visible: legendStates.value[item.post_title] !== false
  }));
});

// Generate colors for pie slices
const getColorForIndex = (index) => {
  const defaultColors = [
    '#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de',
    '#3ba272', '#fc8452', '#9a60b4', '#ea7ccc', '#ffb347'
  ];
  return defaultColors[index % defaultColors.length];
};

// Computed property for series data based on visibility
const visiblePieData = computed(() => {
  return licenseReport.data.pieChartData
      .filter(item => legendStates.value[item.post_title] !== false)
      .map((item, index) => ({
        value: item.activation_count,
        name: item.post_title,
        itemStyle: {
          color: getColorForIndex(licenseReport.data.pieChartData.findIndex(originalItem => originalItem.post_title === item.post_title))
        }
      }));
});

let chartInstance = null;

// Initialize the chart
const initChart = () => {
  if (chartRef.value && chartInstance === null) {
    chartInstance = echarts.init(chartRef.value);
    updateChart();
  }
};

// Update the chart with new options
const updateChart = () => {
  if (!chartInstance) return;

  const option = {
    title: {
      text: props.chartTitle ?? "",
      left: "center",
    },
    // Disable default legend since we're using custom
    legend: {
      show: false
    },
    tooltip: {
      trigger: "item",
      formatter: "{b}: {c} ({d}%)",
      borderRadius: 8,
      backgroundColor: Theme.isDark() ? "#38485a" : '#ffffff',
      textStyle: { color: Theme.isDark() ? colors.gray["25"] : colors.system["mid"] },
      borderColor: Theme.isDark() ? "#4a5a6b" : "#c0c4ca",
      borderWidth: 1,
    },
    series: [
      {
        type: "pie",
        radius: '55%',
        center: ['50%', '50%'], // Shift pie chart left to make room for custom legend
        data: visiblePieData.value,
        label: {
          show: true,
          color: Theme.isDark() ? colors.gray["25"] : colors.system["dark"]
        },
        emphasis: {
          itemStyle: {
            shadowBlur: 10,
            shadowOffsetX: 0,
            shadowColor: 'rgba(0, 0, 0, 0.5)'
          }
        },
        itemStyle: {
          emphasis: {
            shadowBlur: 10,
            shadowOffsetX: 0,
            shadowColor: "rgba(0, 0, 0, 0.5)",
          },
        },
      },
    ],
  };

  chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

// Handle custom legend item click
const handleLegendClick = (legendItem) => {
  // Toggle visibility
  legendStates.value[legendItem.name] = !legendItem.visible;

  // Update chart
  nextTick(() => {
    updateChart();
  });
};

// Handle custom legend item hover
const handleLegendHover = (legendItem, isEnter) => {
  if (!chartInstance) return;

  if (isEnter) {
    // Highlight corresponding pie slice
    chartInstance.dispatchAction({
      type: 'highlight',
      name: legendItem.name
    });
  } else {
    // Remove highlight
    chartInstance.dispatchAction({
      type: 'downplay',
      name: legendItem.name
    });
  }
};

const getData = () => {
  const params = {
    startDate: dateRange.value[0].toISOString(),
    endDate: dateRange.value[1].toISOString(),
  };
  licenseReport.getLicensePieChart({
    params: {
      ...params,
      groupKey: selectedGroupKey.value,
    },
  });
};

const handleDateChange = () => {
  getData();
};

const groupBy = () => {
  getData();
};

// Initialize legend states when data changes
const initializeLegendStates = () => {
  if (licenseReport.data.pieChartData) {
    licenseReport.data.pieChartData.forEach(item => {
      if (!(item.post_title in legendStates.value)) {
        legendStates.value[item.post_title] = true; // All visible by default
      }
    });
  }
};

// Watch for changes in chartData, chartType, or selectedGroupKey
watch(
    [() => licenseReport.chartData, chartType],
    () => {
      nextTick(updateChart);
    },
    { deep: true }
);

watch(
    () => licenseReport.data.pieChartData,
    () => {
      initializeLegendStates();
      nextTick(() => {
        updateChart();
      });
    },
    { deep: true }
);

const onThemeChanged = (event) => {
  updateChart();
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", onThemeChanged);
  getData();
  nextTick(initChart);
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", onThemeChanged);
});
</script>

<template>
  <div class="fct-usage-statistics-chart-wrap">
    <div class="fct-usage-statistics-info flex items-center justify-between">
      <div class="text-lg font-semibold text-system-dark dark:text-gray-50">
        {{ activatedSites }}
        <div class="text-xs font-medium text-system-mid dark:text-gray-300">{{ $t('Total Activated Sites') }}</div>
      </div>

      <el-popover
          placement="bottom-start"
          :width="226"
          trigger="click"
          popper-class="fct-license-statistics-popover"
      >
        <!-- Custom Legend -->
        <div class="fct-custom-legend" style="width: 100%;">
          <h4 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 600;">
            {{$t('License Distribution')}}
          </h4>
          <div class="legend-items">
            <div
                v-for="item in customLegendData"
                :key="item.name"
                class="legend-item"
                :class="{ 'legend-item-hidden': !item.visible }"
                @click="handleLegendClick(item)"
                @mouseenter="handleLegendHover(item, true)"
                @mouseleave="handleLegendHover(item, false)"
                :style="{
                opacity: item.visible ? 1 : 0.5
              }"
            >
              <!-- Color indicator -->
              <div
                  class="legend-color"
                  :style="{ backgroundColor: item.color }"
              ></div>

              <!-- Legend content -->
              <div style="flex: 1; min-width: 0;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <span
                      class="legend-item-name"
                      :title="item.name"
                  >
                    {{ item.name }}
                  </span>
                  <span class="legend-item-percentage">
                    {{ item.percentage }}%
                  </span>
                </div>
                <div class="legend-item-count">
                  {{
                    /* translators: %s - number of licenses */
                    translate('%s licenses', item.value.toLocaleString())
                  }}
                </div>
              </div>
            </div>
          </div>

          <!-- Legend controls -->
          <div class="fct-custom-legend-controls">
            <button
                @click="() => {
                Object.keys(legendStates).forEach(key => legendStates[key] = true);
                nextTick(updateChart);
              }"
            >
              {{$t('Show All')}}
            </button>
            <button
                @click="() => {
                Object.keys(legendStates).forEach(key => legendStates[key] = false);
                nextTick(updateChart);
              }"
            >
             {{$t('Hide All')}}
            </button>
          </div>
        </div>
        <template #reference>
          <div class="fct-action-link-btn">
            <icon-button>
              <DynamicIcon name="More"/>
            </icon-button>
          </div>
        </template>
      </el-popover>
    </div>


    <div class="fct-chart-container" style="display: flex; align-items: flex-start;">
      <!-- Pie Chart -->
      <div class="fct-usage-statistics-chart" ref="chartRef" style="flex: 1; height: 350px;"></div>

    </div>
  </div>
</template>

<style scoped>
.fct-usage-statistics-chart {
  min-height: 350px;
}
</style>
