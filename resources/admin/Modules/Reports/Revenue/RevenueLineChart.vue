<script setup>
import { ref, onMounted, nextTick, computed, watch, onUnmounted } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import checkBoxFilter from "@/Models/Reports/CheckBoxFilterModel";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Theme from "@/utils/Theme";
import ChartTab from "@/Bits/Components/ChartTab.vue";
import { formatNumber } from "../Utils/formatNumber";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import Empty from "@/Bits/Components/Table/Empty.vue";
import {
  makeXAxisLabels,
  tooltipSuffix,
  prepareLabel,
  getEmphasisColor,
  getXAxisConfig,
} from '../Utils/decorator';

// Define props
const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  compareData: {
    type: Array,
    required: true
  },
  reportFilter: {
    type: Object,
  },
  appliedGroupKey: {
    type: String
  },
  isEmpty: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: true,
  },
});

const chartRef = ref(null);
const chartType = ref(
  ChartTypeFilter.getChartType("revenue", "revenue_chart") || "line"
);
const zoomIsActive = ref(false);

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

// Method to create a series object
const createSeries = (name, type, yAxisIndex, data, color, hidden = false) => {
  return {
    name,
    type,
    barMaxWidth: 30,
    yAxisIndex,
    data,
    smooth: false,
    color,
    hidden,
    lineStyle: {
      width: 3,
    },
    areaStyle: {
      opacity: 0.3,
      color: color,
    },
    symbol: "circle",
    showSymbol: true,
    symbolSize: 8,
    itemStyle: {
      color: color,
      borderRadius: [4, 4, 0, 0]
    },
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
  };
};

const selectedGroupKey = ref(props.appliedGroupKey);

watch(() => props.appliedGroupKey, (value) => {
  selectedGroupKey.value = value
})

const groupKeys = [
  { label: translate("Default"), value: "default" },
  { label: translate("Monthly"), value: "monthly" },
  { label: translate("Yearly"), value: "yearly" },
];

const emit = defineEmits(["filter-data"]);

const filterChartData = () => {
  emit("filter-data", selectedGroupKey.value);
};

const hasComparison = computed(() => props.reportFilter.data.compareType !== 'no_comparison');

// Computed property for dynamic series based on selectedMetrics
const seriesData = computed(() => {
  let seriesSrc = [
    {
      name: translate("Current Revenue"),
      key: "net_revenue",
      type: chartType.value,
      color: isDarkTheme.value ? colors.dark_cyan_blue_36 : colors.light_gray_cyan_blue,
      data: props.chartData.map((item) => item["net_revenue"])
    },
  ];

  if (hasComparison.value) {
    seriesSrc.unshift({
      name: translate("Compare Revenue"),
      key: "net_revenue",
      type: chartType.value,
      color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
      data: props.compareData.map(item => {
        return {
          value: item.net_revenue,
          date: item.group
        }
      }),
      hidden: false,
    });
  }

  return seriesSrc.map((item) => createSeries(
    item.name,
    item.type,
    0,
    item.data,
    item.color,
    item.hidden
  ));
});

let chartInstance = null;

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

const tooltip = computed(() => {
  const tooltipAppendix = tooltipSuffix(props.reportFilter.data.dateRange);

  return translate('This report looks at the revenue you received ') + tooltipAppendix;
});

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

const updateChart = () => {
  if (!chartInstance) return;
  
  const labels = makeXAxisLabels(props.chartData);

  const option = {
    title: {
      text: "",
      left: "center",
    },
    legend: {
      show: true,
      itemGap: 20,
      itemWidth: 10,
      itemHeight: 10,
      textStyle: {
        color: isDarkTheme.value ? "#ffffff" : "#000000",
      },
      selected: Object.fromEntries(seriesData.value.map(item => [item.name, !item.hidden])),
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
          color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_blue,
        }
      },
      alwaysShowContent: false,
      valueFormatter: (value) => `${CurrencyFormatter.scaled(value)}`,
      formatter: (params) => {
        let tooltipContent = '';
        const color = isDarkTheme.value ? "#ffffff" : "#565865";
      
        params.forEach(function (param, index) {
          const isDataObject = typeof param.data === 'object' && param.data !== null;
          const customDate = isDataObject ? prepareLabel(param.data.date) : param.name;
          const value = CurrencyFormatter.scaled(param.value);
          const needMarginBottom = params.length > 1 && index === 0;

          if (needMarginBottom) {
            tooltipContent += `<div style="margin-bottom: 10px">`
          }

          tooltipContent += `<div>${customDate}</div>`;
          tooltipContent += `<div>
            ${param.marker}
            <span style="color: ${color}">${param.seriesName}</span>
            <span style="float: right; margin-left: 20px; color: ${color}">
              ${formatNumber(value)}
            </span>
          </div>`;

          if (needMarginBottom) {
            tooltipContent += `</div>`
          }
        });
        
        return tooltipContent;
      }
    },
    grid: {
      show: false,
    },
    xAxis: {
      type: "category",
      data: labels, // Use group for x-axis
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
        color: isDarkTheme.value ? "#ffffff" : "#000000",
        fontSize: 12,
        formatter: (value) => `${CurrencyFormatter.scaled(value)}`,
      },
      splitLine: {
        show: true,
        lineStyle: {
          color: isDarkTheme.value ? "#253241" : "#D6DAE1",
          type: "dashed",
        },
      },
    },
    series: seriesData.value, // Use the computed series data
  };
  chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

const handleZoomChart = () => {
  zoomIsActive.value = !zoomIsActive.value;

  if (zoomIsActive.value) {
    chartInstance.setOption({
      dataZoom: [
        {
          type: "slider",
          show: true,
          xAxisIndex: [0],
          start: 1,
          end: 100,
        },
        {
          type: "slider",
          show: true,
          yAxisIndex: [0],
          left: "95%",
          start: 1,
          end: 100,
        },
        {
          type: "inside",
          xAxisIndex: [0],
          start: 1,
          end: 100,
        },
        {
          type: "inside",
          yAxisIndex: [0],
          start: 1,
          end: 100,
        },
      ],
    });
  } else {
    updateChart();
  }
};

const toggleChartType = (type) => {
  ChartTypeFilter.onChange("revenue", "revenue_chart", type);
  if (chartType.value !== type) {
    zoomIsActive.value = false;
    chartType.value = type;
    updateChart();
  }
};

// Watch for changes in chartData or selectedMetrics to re-initialize the chart
watch(
  [() => props.chartData, selectedGroupKey],
  () => {
    nextTick(updateChart);
  },
  { deep: true }
);

watch(
  () => checkBoxFilter.data.revenue,
  () => {
    nextTick(() => {
      updateChart();
    });
  },

  { deep: true }
);

watch(() => props.isEmpty, (value) => {
  if (!value) initChart();
})

watch(() => props.loading, (value) => {
  if (!value) initChart();
})

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  nextTick(() => {
    updateChart();
  });
};

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
});

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  
  nextTick(() => {
    initChart();
  });
});
</script>

<template>
  <div class="fct-revenue-line-chart-wrap">
    <Card.Container id="chartContainer">
      <Card.Header title_size="small" border_bottom>
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{
                translate("Revenue Chart")
            }}</h5>
            <el-tooltip
              popper-class="fct-tooltip"
              :content="tooltip"
              placement="top"
            >
              <DynamicIcon
                class="text-gray-500 w-4 h-4 cursor-pointer"
                name="InformationFill"
              />
            </el-tooltip>
          </div>
        </template>
        <template #action v-if="!isEmpty && !loading">
          <div class="fct-chart-select">
            <el-select
                class="el-select--x-small"
                v-model="selectedGroupKey"
                @change="filterChartData"
            >
              <el-option
                v-for="groupKey in groupKeys"
                :key="groupKey.value"
                :label="translate(groupKey.label)"
                :value="groupKey.value"
              />
            </el-select>
          </div>

          <div class="fct-btn-group sm">
            <ChartTab
              :activeType="chartType"
              :types="{
                line: 'LineChart',
                bar: 'BarChart',
              }"
              @change="toggleChartType"
            />

            <IconButton
              tag="button"
              size="small"
              :title="translate('Zoom Chart')"
              @click="handleZoomChart"
              :class="zoomIsActive ? 'primary' : ''"
            >
              <DynamicIcon name="SearchAdd" />
            </IconButton>

            <Screenshot :targetRef="chartRef" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-0 pt-0">
        <el-skeleton v-if="loading" animated :rows="7" class="p-4" />
        
        <template v-else>
          <div v-if="!isEmpty" class="fct-chart-wrap fct-revenue-line-chart mt-3" ref="chartRef"></div>

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
                <span class="text">{{ translate("Data") }}</span>
              </div><!-- .chart-change -->

              <div class="chart-change">
                <span class="text">{{ translate("Timeline") }}</span>
                <DynamicIcon name="ArrowRight" class="arrow-right" />
              </div>
            </div>
          </div>
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>
