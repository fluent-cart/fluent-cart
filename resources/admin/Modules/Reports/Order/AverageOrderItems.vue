<template>
  <div class="fct-report-average-order-items-wrap">
    <Card.Container>
      <Card.Header
        border_bottom
        title_size="small"
      >
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{
              translate("Average Order Items ")
            }}</h5>
            <el-tooltip
              popper-class="fct-tooltip"
              :content="
                translate('Average Order Items grouped by date period.')
              "
              placement="top"
            >
              <DynamicIcon name="InformationFill" class="w-4 cursor-pointer" />
            </el-tooltip>
          </div>
        </template>
        <template #action v-if="!isEmpty">
          <div class="fct-btn-group sm">
            <IconButton
              tag="button"
              size="x-small"
              :title="$t('Zoom Chart')"
              @click="handleZoomChart"
              :class="zoomIsActive ? 'primary' : ''"
            >
              <DynamicIcon name="SearchAdd" />
            </IconButton>
            <Screenshot :targetRef="chartRef" size="x-small" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-0 pt-0">
        <el-skeleton v-if="dataLoader" animated :rows="4" class="px-5 pt-5"/>

        <template v-else>
          <div v-if="!isEmpty" class="fct-report-average-order-items-chart" ref="chartRef"></div>

          <Empty
              v-else
              icon="Empty/ListView"
              :has-dark="true"
              :text="translate('Currently there is no data!')"
              class="fct-report-empty-state"
          />

          <div v-if="!isEmpty" class="chart-action-wrap">
            <div class="chart-change-wrap">
              <div class="chart-change">
                <DynamicIcon name="ArrowUp" class="arrow-up" />
                <span class="text">{{ $t("Average Order Items") }}</span>
              </div>
              <div class="chart-change">
                <span class="text">{{ $t("Timeline") }}</span>
                <DynamicIcon name="ArrowRight" class="arrow-right" />
              </div>
            </div>
          </div>
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>

<script setup>
import {
  ref,
  onMounted,
  nextTick,
  computed,
  watch,
  onBeforeUnmount,
  onUnmounted,
} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import { monthNames } from "./../Utils/monthNames";
import {
  makeXAxisLabels,
  tooltipSuffix,
  prepareLabel,
  getEmphasisColor,
  getXAxisConfig,
} from '../Utils/decorator';
import orderReportModel from "@/Models/Reports/OrderReportModel";

// Define props
const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  chartRef: {},
  isEmpty: {
    type: Boolean,
    required: false,
  },
});

const chartRef = ref(null);
let chartInstance = null;
const chartType = ref(
  ChartTypeFilter.getChartType("order", "avg_order_items_chart") || "line"
);
const zoomIsActive = ref(false); // Track zoom state

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const primaryColor = computed(() =>
  isDarkTheme.value ? colors.purple_dark_mode : colors.purple
);

// Computed property for the series data
const seriesData = computed(() => [
  {
    name: translate("Average Order Items"),
    type: 'line',
    barMaxWidth: 30,
    data: Array.isArray(props.chartData)
      ? props.chartData.map((item) => item.average_order_items_count)
      : [],
    smooth: false,
    color: primaryColor.value,
    showSymbol: true,
    symbol: "circle",
    showSymbol: true,
    symbolSize: 8,
    itemStyle: {
      color: primaryColor.value,
      borderRadius: [4, 4, 0, 0]
    },
    yAxisIndex: 0,
    emphasis: {
      scale: 2,
      itemStyle: {
        color: getEmphasisColor(primaryColor.value),
      },
      lineStyle: {
        color: primaryColor.value,
      },
      areaStyle: {
        opacity: .3,
        color: primaryColor.value,
      },
    },
    animation: true,
    animationEasing: "cubicOut",
    animationDuration: 800,
    animationDelay: 0,
    barGap: '-100%',
    legendHoverLink: false,
  },
]);

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

// Computed property for chart options
const chartOptions = computed(() => {
  const labels = makeXAxisLabels(props.chartData);

  return {
    title: {
      text: "",
      left: "center",
    },
    tooltip: {
      trigger: "axis",
      backgroundColor: isDarkTheme.value ? "#253241" : "#ffffff",
      borderColor: isDarkTheme.value ? "#2C3C4E" : "#c0c4ca",
      borderWidth: 1,
      textStyle: {
        color: isDarkTheme.value ? "#ffffff" : "#565865",
      },
      axisPointer: {
        type: 'line',
        lineStyle: {
          type: 'solid',
          width: 2,
          color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_cyan_blue,
        }
      },
    },
    grid: {
      show: false,
      left: "10%",
      right: "10%",
      bottom: "15%",
    },
    xAxis: {
      type: "category",
      data: labels, // Use computed labels for x-axis
      axisLabel: {
        color: isDarkTheme.value ? "#FFFFFF" : "#696778", // Adjust color based on theme
        fontSize: 12,
        interval: xAxisConfig.value.interval,
      },
      axisLine: {
        lineStyle: {
          type: "dashed",
          color: isDarkTheme.value ? "#253241" : "#D6DAE1",
        },
      },
    },
    yAxis: {
      name: "Order Items",
      type: "value",
      axisLabel: {
        formatter: "{value}",
        color: isDarkTheme.value ? "#FFFFFF" : "#696778",
        fontSize: 12,
      },
      splitLine: {
        show: true,
        lineStyle: {
          color: isDarkTheme.value ? "#253241" : "#D6DAE1",
          type: "dashed",
        },
      },
      min: 0,
      splitNumber: 5,
    },
    dataZoom: zoomIsActive.value
      ? [
          {
            type: "slider",
            show: true,
            xAxisIndex: [0],
            start: 0,
            end: 100,
          },
          {
            type: "inside",
            xAxisIndex: [0],
            start: 0,
            end: 100,
          },
        ]
      : [],
    series: seriesData.value, // Use computed series data
  }
});

const dataLoader = computed(() => {
  return orderReportModel.data.isBusy.orderChartData;
});

const handleResize = () => {
  if (chartInstance) {
    chartInstance.resize();
  }
};

// Initialize the chart
const initChart = () => {
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }

  nextTick(() => {
    if (chartRef.value) {
      chartInstance = echarts.init(chartRef.value);
      chartInstance.setOption(chartOptions.value);
      window.addEventListener("resize", handleResize);
    } else if (chartInstance) {
      chartInstance.setOption(chartOptions.value);
    }
  });
};

const updateChart = () => {
  if (chartInstance) {
    chartInstance.setOption(chartOptions.value);
  }
}

// Toggle chart type
const toggleChartType = (type) => {
  if (chartType.value !== type) {
    chartType.value = type;
    ChartTypeFilter.onChange("order", "avg_order_items_chart", type);
    if (chartInstance) {
      chartInstance.setOption(chartOptions.value, true);
    }
  }
};

// Handle zoom toggle
const handleZoomChart = () => {
  zoomIsActive.value = !zoomIsActive.value;
  if (zoomIsActive.value) {
    chartInstance.setOption({
      dataZoom: [
        {
          type: "slider",
          show: true,
          xAxisIndex: [0],
          start: 0,
          end: 100,
        },
        {
          type: "inside",
          xAxisIndex: [0],
          start: 0,
          end: 100,
        },
      ],
    });
  } else {
    chartInstance.setOption(
      {
        dataZoom: [],
      },
      { replaceMerge: ["dataZoom"] }
    );
  }
};

// Watch for changes in chartData to update the chart
watch(
  () => props.chartData,
  () => {
    if (chartInstance) {
      chartInstance.setOption(chartOptions.value);
    }
  }
);

watch(props.isEmpty, (value) => {
  if (!value) initChart();
});

watch(dataLoader, (value) => {
  if (!value) initChart();
});

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  
  nextTick(() => {
    updateChart();
  });
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);

  nextTick(() => {
    initChart();
    window.addEventListener("resize", handleResize);
  });
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
  
  if (chartInstance) {
    window.removeEventListener("resize", handleResize);
    chartInstance.dispose();
    chartInstance = null;
  }
});
</script>
