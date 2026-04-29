<script setup>
import {ref, shallowRef, onMounted, nextTick, computed, watch, onUnmounted} from "vue";
import licenseReport from "@/Models/Reports/LicenseReportModel";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import { monthNames } from "../Modules/Reports/Utils/monthNames";
import dateShortcuts from "../Modules/Reports/Utils/dateShortCuts";
import dayjs from "dayjs";
import Theme from "@/utils/Theme";
import ChartTab from  "@/Bits/Components/ChartTab.vue";

// Define props
const props = defineProps({
  reportFilter: {
    type: Object,
  },
});

const chartRef = ref(null);
const selectedGroupKey = ref("daily");
const chartType = ref("line");
const dateRange = ref([dayjs().startOf("month"), dayjs()]);

const groupKeys = [
  { value: "daily", label: "Daily" },
  { value: "monthly", label: "Monthly" },
  { value: "yearly", label: "Yearly" },
];

const seriesData = computed(() =>
  [
    {
      title: "Total License",
      key: "total_license",
      color: "#2ecc71",
    },
  ].map(({ title, key, color }, index) =>
    createSeries(
      title,
      chartType.value,
      index % 2, // Alternate yAxisIndex for demonstration
      licenseReport.data.lineChartData.map((item) => item.license_count), // Map `data` array to extract the `license` values
      color
    )
  )
);

// Computed property for labels based on selected group key
const labels = computed(() =>
  licenseReport.data.lineChartData.map((item) => {
    if (selectedGroupKey.value === "daily") {
      const [year, month, day] = item.date.split("-");
      return `${monthNames[parseInt(month) - 1]} ${day}, ${year}`;
    } else if (selectedGroupKey.value === "monthly") {
      return `${monthNames[parseInt(item.month) - 1]} ${item.year}`;
    } else if (selectedGroupKey.value === "yearly") {
      return `${item.year}`;
    }
  })
);

let chartInstance = null;

// Initialize the chart
const initChart = () => {
  if (chartRef.value && chartInstance === null) {
    chartInstance = echarts.init(chartRef.value);
    updateChart();
  }
};

// Update the chart with new options whenever seriesData or labels change
const updateChart = () => {
  const option = {
    title: {
      text: props.chartTitle ?? "",
      left: "center",
    },
    legend: {
      show: false,
    },
    tooltip: {
      trigger: "axis",
      backgroundColor: Theme.isDark() ? "#38485a" : "#ffffff",
      borderColor: Theme.isDark() ? "#4a5a6b" : "#c0c4ca",
      borderWidth: 1,
      textStyle: {
        color: Theme.isDark() ? "#ffffff" : "#565865"
      },
    },
    xAxis: {
      type: "category",
      data: labels.value,
      axisLabel: {
        color: Theme.isDark() ? "#ffffff" : "#565865",
        fontSize: 12,
      },
    },
    yAxis: [
      {
        type: "value",
        axisLabel: {
          color: Theme.isDark() ? "#ffffff" : "#565865",
          fontSize: 12,
        },
        splitLine: {
          show: true,
          lineStyle: {
            color: Theme.isDark() ? '#38485a' : '#D6DAE1'
          }
        },
      },
      {
        type: "value",
        position: "right",
        axisLabel: {
          color: Theme.isDark() ? "#ffffff" : "#565865",
          fontSize: 12,
        },
        splitLine: {
          show: false,
        },
      },
    ],
    dataZoom: [],
    series: seriesData.value,
  };

  chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

const getData = () => {
  const params = {
    startDate: dateRange.value[0].toISOString(),
    endDate: dateRange.value[1].toISOString(),
  };
  licenseReport.getLicenseLineChart({
    params: {
      ...params,
      groupKey: selectedGroupKey.value,
    },
  });
};

// Method to create a series object
const createSeries = (name, type, yAxisIndex, data, color) => ({
  name,
  type,
  yAxisIndex,
  data,
  smooth: true,
  color,
  lineStyle: {
    width: 3,
  },
});

const toggleLegend = (title) => {
  chartInstance.dispatchAction({
    type: "legendToggleSelect",
    name: title,
  });
};

const handleDateChange = () => {
  getData();
};

const groupBy = () => {
  getData();
};

const toggleChartType = (type) => {
  if (chartType.value !== type) {
    chartType.value = type;
    updateChart();
  }
};

// Watch for changes in chartData, chartType, or selectedGroupKey to re-initialize the chart
watch(
  [() => licenseReport.chartData, chartType],
  () => {
    nextTick(updateChart);
  },
  { deep: true }
);

watch(
  () => licenseReport.data.lineChartData,
  () => {
    nextTick(() => {
      updateChart();
    });
  },

  { deep: true }
);

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", updateChart, false);
});

onMounted(() => {
  window.addEventListener('onFluentCartThemeChange', updateChart);
  getData();
  nextTick(initChart);
});
</script>

<template>
  <div class="fct-license-statistics-wrap">
    <Card.Container id="chartContainer">
      <Card.Header
        :title="$t('License Chart')"
        title_size="small"
        border_bottom
      >
        <template #action>
          <div class="fct-btn-group sm">

            <ChartTab
                :activeType="chartType"
                :types="{
                  line: 'LineChart',
                  bar: 'BarChart',
                }"
                @change="toggleChartType"
            />
            
            <el-select
                class="w-[100px] el-select--x-small"
              v-model="selectedGroupKey"
              @change="groupBy"
            >
              <el-option
                v-for="groupKey in groupKeys"
                :key="groupKey.value"
                :label="$t(groupKey.label)"
                :value="groupKey.value"
              />
            </el-select>

            <el-date-picker
                class="w-[220px] el-range-editor--x-small hide-close-icon"
                v-model="dateRange"
                type="daterange"
                range-separator="-"
                :start-placeholder="$t('Start date')"
                :end-placeholder="$t('End date')"
                unlink-panels
                :clearable="false"
                :shortcuts="dateShortcuts"
                @change="handleDateChange"
            />
          </div>
        </template>
      </Card.Header>
      <Card.Body>
        <div class="fct-license-statistics-chart" ref="chartRef"></div>
        <div class="chart-action-wrap">
          <div class="chart-change-wrap">
            <div class="chart-change">
              <svg
                width="9"
                height="11"
                viewBox="0 0 9 11"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path
                  d="M4.6087 10.5L4.6087 1.46395"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M1.00004 4.05762L4.55764 0.500019L8.11523 4.05762"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              <span class="text">{{ $t("Data") }}</span>
            </div>
            <!-- .chart-change -->

            <div class="chart-change">
              <span class="text">{{ $t("Timeline") }}</span>
              <svg
                class="rotate-90"
                width="9"
                height="11"
                viewBox="0 0 9 11"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path
                  d="M4.6087 10.5L4.6087 1.46395"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M1.00004 4.05762L4.55764 0.500019L8.11523 4.05762"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </div>
            <!-- .chart-change -->
          </div>
          <!-- .chart-change-wrap -->
        </div><!-- .chart-action-wrap -->
      </Card.Body>
    </Card.Container>
  </div>
</template>
