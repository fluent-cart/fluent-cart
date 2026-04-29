<script setup>
import {ref, onMounted, nextTick, computed, watch, onUnmounted} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import orderReportModel from "@/Models/Reports/OrderReportModel.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Theme from "@/utils/Theme";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import {
  makeXAxisLabels,
  tooltipSuffix,
  prepareLabel,
  getEmphasisColor,
  getXAxisConfig,
} from '../Utils/decorator';

// Define props
const props = defineProps({
  reportFilter: {
    type: Object,
  },
  chartRef: {},
  chartData: {
    type: Array,
    required: true,
  },
});

const chartRef = ref(null);
let chartInstance = null;

const chartType = ref(
  ChartTypeFilter.getChartType("order", "gross_sale_by_day_chart") || "line"
);

const zoomIsActive = ref(false); // Track zoom state

// Computed property to generate x-axis labels
const labels = computed(() => {
  const dayLabels = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const sortedData = [...props.chartData].sort((a, b) => a.day - b.day);
  return sortedData.map((item) => dayLabels[item.day - 1]);
});

const isEmpty = computed(() => {
  return Object.values(props.chartData).every(item => item.order_count === 0);
});

const dataLoader = computed(() => {
  return orderReportModel.data.isBusy.orderByDayAndHour;
});

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

// Computed property for series data
const seriesData = computed(() => {
  return [
    createSeries(
        "Gross Sale",
        'line',
        0,
        props.chartData.map((item) => item.gross_sale),
        isDarkTheme.value ? colors.blue_dark_mode : colors.blue,
    ),
    createSeries(
        "Orders",
        'bar',
        1,
        props.chartData.map((item) => item.order_count),
        isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
    ),
  ];
});

// Function to create a series object
const createSeries = (name, type, yAxisIndex, data, color) => ({
  name,
  type,
  barMaxWidth: 30,
  data,
  smooth: false,
  color,
  lineStyle: {
    width: 3,
  },
  symbol: "circle",
  showSymbol: true,
  symbolSize: 8,
  itemStyle: {
    color: color,
    borderRadius: [4, 4, 0, 0]
  },
  yAxisIndex,
  emphasis: {
    scale: 2,
    itemStyle: {
      color: getEmphasisColor(color),
    },
    lineStyle: {
      color: color,
    },
    areaStyle: {
      opacity: .3,
      color: color,
    },
  },
  animation: true,
  animationEasing: "cubicOut",
  animationDuration: 800,
  animationDelay: 0,
  barGap: '-100%',
  legendHoverLink: false,
});

// Update chart options
const updateChart = () => {
  if (!chartInstance) return;
  // chartData.value = orderReportModel.data.grossSaleByDay;
  const option = {
    title: {text: props.chartTitle, left: "center"},
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
          color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_blue,
        }
      },
      formatter: (params) => {
        let result = params[0].name;

        params.forEach((param) => {
          const value = param.seriesName === "Gross Sale" ? `${CurrencyFormatter.scaled(param.value)}` : param.value;
          const color = isDarkTheme.value ? "#ffffff" : "#565865";

          result += `<div>
            ${param.marker}
            <span style="color: ${color};">${param.seriesName}</span>
            <span style="float: right; margin-left: 20px; color: ${color};">
              ${value}
            </span>
          </div>`;
        });
        return result;
      },
    },
    grid: {left: "10%", right: "10%", bottom: "15%"},
    xAxis: {
      type: "category",
      data: labels.value,
      axisLabel: {
        color: isDarkTheme.value ? "#FFFFFF" : "#696778",
        fontSize: 12,
      },
      axisLine: {
        lineStyle: {
          type: "dashed",
          color: isDarkTheme.value ? "#253241" : "#D6DAE1",
        },
      },
    },
    yAxis: [
      {
        type: "value",
        name: "Gross Sale",
        axisLabel: {
          color: isDarkTheme.value ? "#FFFFFF" : "#696778",
          fontSize: 12,
          formatter: (value) => `${CurrencyFormatter.scaled(value)}`,
        },
        min: 0,
        max: "dataMax",
        splitLine: {
          show: true,
          lineStyle: {
            color: isDarkTheme.value ? "#253241" : "#D6DAE1",
            type: "dashed",
          },
        },
      },
      {
        type: "value",
        name: "Orders",
        position: "right",
        axisLabel: {
          color: isDarkTheme.value ? "#FFFFFF" : "#696778",
          fontSize: 12,
        },
        min: 0,
        max: "dataMax",
        splitLine: {
          show: false,
          lineStyle: {
            color: isDarkTheme.value ? "#253241" : "#D6DAE1",
            type: "dashed",
          },
        },
      },
    ],
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
    series: seriesData.value,
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
  ChartTypeFilter.onChange("order", "gross_sale_by_day_chart", type);
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
        {replaceMerge: ["dataZoom"]}
    );
  }
};

// Watch for changes in chartData and update the chart
watch(
    () => [orderReportModel.data.grossSaleByDay],
    () => {
      nextTick(() => {
        updateChart();
      });
    },
    {deep: true}
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
  nextTick(() => {
    initChart();
    props.reportFilter.addListener("spend-by-day", initChart);
    window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  });
});

onUnmounted(() => {
  if (chartInstance) {
    chartInstance.dispose();
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
    props.reportFilter.removeListener("spend-by-day", initChart, false);
    window.removeEventListener("resize", () => {
      chartInstance.resize();
    });
  }
});
</script>

<template>
  <div class="fct-report-spend-by-day-wrap">
    <Card.Container id="chartContainer">
      <Card.Header title_size="small" border_bottom>
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{
                translate("Spend by day")
              }}</h5>
            <el-tooltip
                popper-class="fct-tooltip"
                :content="translate('Gross sales grouped by the days of the week.')"
                placement="top"
            >
              <DynamicIcon name="InformationFill" class="w-4 cursor-pointer"/>
            </el-tooltip>
          </div>
        </template>
        <template #action v-if="!isEmpty">
          <div class="fct-btn-group sm">
            <!-- <ChartTab
                :activeType="chartType"
                :types="{
                line: 'LineChart',
                bar: 'BarChart',
              }"
                @change="toggleChartType"
            /> -->

            <IconButton
                tag="button"
                size="x-small"
                :title="$t('Zoom Chart')"
                @click="handleZoomChart"
                :class="zoomIsActive ? 'primary' : ''"
            >
              <DynamicIcon name="SearchAdd"/>
            </IconButton>
            <Screenshot :targetRef="chartRef" size="x-small"/>
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-0 pt-0">
        <el-skeleton v-if="dataLoader" animated :rows="4" class="p-3"/>

        <template v-else>
          <div
              class="fct-report-spend-by-day-chart"
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
                <DynamicIcon name="ArrowUp" class="arrow-up"/>
                <span class="text">{{ $t("Gross Sale") }}</span>
              </div>
              <div class="chart-change">
                <span class="text">{{ $t("Days of the week") }}</span>
                <DynamicIcon name="ArrowRight" class="arrow-right"/>
              </div>
            </div>
          </div>
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>
