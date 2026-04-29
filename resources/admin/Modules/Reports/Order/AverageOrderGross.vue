<template>
  <div class="fct-report-average-order-gross-wrap">
    <Card.Container id="chartContainer">
      <Card.Header border_bottom title_size="small">
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{
              translate("Average Order Gross ")
            }}</h5>
            <el-tooltip
              popper-class="fct-tooltip"
              :content="
                translate('Average Order Gross grouped by date period.')
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
          <div v-if="!isEmpty" class="fct-report-average-order-gross-chart" ref="chartRef"></div>

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
                <span class="text">{{ $t("Average Order Gross") }}</span>
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
import { ref, onMounted, nextTick, computed, watch, onUnmounted } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";
import { monthNames } from "./../Utils/monthNames";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
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
  isEmpty: {
    type: Boolean,
    required: false,
  },
});

const chartRef = ref(null);
let chartInstance = null;
const chartType = ref("line");
const zoomIsActive = ref(false); // Track zoom state

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const primaryColor = computed(() =>
  isDarkTheme.value ? colors.blue_dark_mode : colors.blue
);

const dataLoader = computed(() => {
  return orderReportModel.data.isBusy.orderChartData;
});

// Computed property for series data
const seriesData = computed(() => [
  {
    name: translate("Average Order Gross"),
    type: 'line',
    barMaxWidth: 30,
    data: props.chartData.map((item) => item.average_order_gross),
    smooth: false,
    color: primaryColor.value,
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

const handleResize = () => {
  if (chartInstance) {
    chartInstance.resize();
  }
};

const updateChart = () => {
  if (!chartInstance) return;
  
  const labels = makeXAxisLabels(props.chartData);
  const option = {
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
      valueFormatter: (value) => CurrencyFormatter.scaled(value),
    },
    grid: {
      show: false,
      left: "10%",
      right: "10%",
      bottom: "15%",
    },
    xAxis: {
      type: "category",
      data: labels, // Use computed labels for x-axis data
      axisLabel: {
        color: isDarkTheme.value ? "#ffffff" : "#000000",
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
      type: "value",
      axisLabel: {
        formatter: "{value}", // Format for Gross Sale (hundreds range)
        color: isDarkTheme.value ? "#ffffff" : "#000000",
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
  };

  chartInstance.setOption(option);
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
      updateChart();
    }
  });
};

// Toggle chart type
const toggleChartType = (type) => {
  if (chartType.value !== type) {
    chartType.value = type;
    updateChart();
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
    nextTick(() => updateChart());
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
  window.addEventListener("onFluentCartThemeChange", handleThemeChange, false);

  nextTick(() => initChart());
});

onUnmounted(() => {
  if (chartInstance) {
    chartInstance.dispose();
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
    window.removeEventListener("resize", handleResize);
  }
});
</script>
