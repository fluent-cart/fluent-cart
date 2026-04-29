<template>
  <div class="fct-report-order-value-distribution-wrap">
    <Card.Container id="chartContainer">
      <Card.Header title_size="small" border_bottom>
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{
              translate("Order value distribution")
            }}</h5>
            <el-tooltip
              popper-class="fct-tooltip"
              :content="
                translate(
                  'Orders grouped by based on their order value in different ranges.'
                )
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
        <el-skeleton v-if="dataLoader" animated :rows="4" class="p-3"/>

        <template v-else>
          <div
              class="fct-report-order-value-distribution-chart"
              v-if="!isEmpty"
              ref="chartRef"
          ></div>

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
                <span class="text">{{ $t("Order Count") }}</span>
              </div>
              <div class="chart-change">
                <span class="text">{{ $t("Order Value Ranges") }}</span>
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
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";
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
    type: Object,
    required: true,
  },
  chartRef: {},
});

const chartRef = ref(null);
let chartInstance = null;
const chartType = ref(
  ChartTypeFilter.getChartType("order", "order_value_distribution_chart") ||
    "bar"
);
const zoomIsActive = ref(false); // Track zoom state

// Computed property for x-axis labels
const labels = computed(() => Object.keys(props.chartData));

const colors = Theme.colors;
const isDarkTheme = ref(Theme.isDark());

const primaryColor = computed(() =>
  isDarkTheme.value ? colors.blue['500'] + '52' : colors.blue['300'] 
);

const isEmpty = computed(() => {
  return !Object.values(props.chartData).every(value => value && value !== 0);
});

const dataLoader = computed(() => {
  return orderReportModel.data.isBusy.orderValueDistribution;
});

// Computed property for series data
const seriesData = computed(() => [
  {
    name: translate("Orders"),
    type: 'bar',
    barMaxWidth: 30,
    data: Object.values(props.chartData),
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
        color: isDarkTheme.value ? colors.blue['800'] : colors.blue['400'],
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

// Computed property for chart options
const chartOptions = computed(() => ({
  title: {
    text: props.chartTitle,
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
        color: isDarkTheme.value ? colors.report.dark_cyan_blue_16 : colors.report.light_gray_cyan_blue,
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
    data: labels.value, // Use computed labels for x-axis data
    axisLabel: {
      color: isDarkTheme.value ? "#ffffff" : "#000000",
      fontSize: 12,
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
    name: translate("Orders"),
    axisLabel: {
      formatter: "{value}",
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
}));

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
      window.addEventListener("resize", () => {
        chartInstance.resize();
      });
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
    ChartTypeFilter.onChange("order", "order_value_distribution_chart", type);
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

watch(isEmpty, (value) => {
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

  nextTick(() => {
    initChart();
  });
});

onUnmounted(() => {
  if (chartInstance) {
    chartInstance.dispose();
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
    window.removeEventListener("resize", () => {
      chartInstance.resize();
    });
  }
});
</script>
