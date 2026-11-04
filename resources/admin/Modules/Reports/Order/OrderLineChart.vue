<script setup>
import {
  ref,
  shallowRef,
  onMounted,
  nextTick,
  computed,
  watch,
  onUnmounted,
} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import { monthNames } from "./../Utils/monthNames";
import checkBoxFilter from "@/Models/Reports/CheckBoxFilterModel";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Theme from "@/utils/Theme";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";
import {chartAxisPointer, chartTooltipAmount, chartTooltipPosition} from "@/utils/Utils";
import orderReport from "@/Models/Reports/OrderReportModel";
import Notify from "@/utils/Notify";
import {exportCSVWithColumns} from "@/utils/Export";
import {
  makeXAxisLabels,
  tooltipSuffix,
  prepareLabel,
  getEmphasisColor,
  getXAxisConfig,
  axisDayLabel,
} from '../Utils/decorator';
import useGroupKeyOptions from '../Utils/useGroupKeyOptions';

// Define props
const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  previousChartData: {
    type: Array,
    default: () => [],
  },
  reportFilter: {
    type: Object,
  },
  isEmpty: {
    type: Boolean,
    default: false,
  },
});

const chartRef = ref(null);
const selectedGroupKey = ref('default');
const chartType = ref(
  ChartTypeFilter.getChartType("order", "order_chart") || "line"
);
const zoomIsActive = ref(false);

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const { groupKeys } = useGroupKeyOptions(props.reportFilter, selectedGroupKey);

const emit = defineEmits(["fetch-chart-data"]);

// Each period's figures; gross (what customers paid, tax and shipping in) is here, not on the chart
const exportCsv = () => {
  const columns = [{header: 'period', accessor: 'group'},
    ...['order_count', 'net_sales', 'gross_sale', 'total_refund', 'refunded_orders', 'average_net', 'total_item_count']
      .map(key => ({header: key, accessor: key}))];
  exportCSVWithColumns(props.chartData, columns, 'orders-by-period.csv').catch(error => Notify.error(error.message));
};

const filterChartData = () => {
  emit("fetch-chart-data", selectedGroupKey.value);
};

const dataLoader = computed(() => {
  return orderReport.data.isBusy.orderChartData;
});

const seriesData = computed(() =>
  [
    {
      title: translate("Orders"),
      key: "order_count",
      color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
      yAxisIndex: 1,
      type: 'bar',
    },
    // Net sales as the Revenue report counts them; gross is in the export
    {
      title: translate("Net sales"),
      key: "net_sales",
      color: isDarkTheme.value ? colors.blue_dark_mode : colors.royal_blue,
      yAxisIndex: 0,
      type: 'line',
      always: true,
    },
    // {
    //   title: translate("Units Sold"),
    //   key: "total_item_count",
    //   color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_cyan_blue,
    //   yAxisIndex: 1,
    //   type: 'bar',
    // },
    // {
    //   title: translate("Avg. Order Net"),
    //   key: "average_net",
    //   color: colors.medium_slate_blue,
    //   yAxisIndex: 0,
    // },
    // {
    //   title: translate("Avg. Order Items"),
    //   key: "average_order_items_count",
    //   color: colors.golden_rod,
    //   yAxisIndex: 1,
    // },
    // {
    //   title: translate("Avg. Order Gross"),
    //   key: "average_gross",
    //   color: colors.sea_green,
    //   yAxisIndex: 1,
    // },
  ]
    .filter(({ key, always }) => always || checkBoxFilter.data.order.includes(key))
    .map(({ title, key, color, yAxisIndex, type }) =>
      createSeries(
        title,
        type,
        yAxisIndex,
        props.chartData.map((item) => item[key]),
        color
      )
    )
    // The compared period's net sales, point by point, as a dashed line
    .concat(props.previousChartData.length ? [{
      ...createSeries(translate("Compared net sales"), 'line', 0,
        props.chartData.map((item, index) => (props.previousChartData[index] || {}).net_sales ?? null), colors.light_gray_cyan_blue),
      lineStyle: {width: 2, type: 'dashed'},
      symbol: 'none',
    }] : [])
);

// Computed property for labels based on selected group key
const labels = computed(() => {
  return props.chartData.map((item) => {
    return prepareLabel(item.group);
  });
});

let chartInstance = null;
const hasBarChart = ref(true);

// Initialize the chart
const initChart = () => {
  nextTick(() => {
    // Dispose here: two calls in one tick would both init the same element
    if (chartInstance) {
      chartInstance.dispose();
      chartInstance = null;
    }

    if (chartRef.value) {
      chartInstance = echarts.init(chartRef.value);
      updateChart();

      chartInstance.on('legendselectchanged', (params) => {
        hasBarChart.value = params.selected["Orders"];
      });
    }
  });
};

const tooltip = computed(() => {
  const tooltipAppendix = tooltipSuffix(props.reportFilter.data.dateRange);

  return translate('This report looks at orders created ') + tooltipAppendix;
});

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

// Update the chart with new options whenever seriesData or labels change
const updateChart = () => {
  if (!chartInstance) return;

  const option = {
    title: {
      text: props.chartTitle ?? "",
      left: "center",
    },
    legend: {
      show: true,
      itemGap: 20,
      itemWidth: 10,
      itemHeight: 10,
      textStyle: {
        color: isDarkTheme.value ? "#ffffff" : "#000000",
      }
    },
    tooltip: {
      trigger: "axis",
      backgroundColor: isDarkTheme.value ? "#253241" : "#ffffff",
      borderColor: isDarkTheme.value ? "#2C3C4E" : "#c0c4ca",
      borderWidth: 1,
      textStyle: {
        color: isDarkTheme.value ? "#ffffff" : "#565865",
      },
      axisPointer: chartAxisPointer(isDarkTheme.value, colors.dark_cyan_blue_16, colors.light_gray_blue),
      confine: true,
      position: chartTooltipPosition,
      formatter: (params) => {
        let tooltipContent = params[0].axisValue;
        const color = isDarkTheme.value ? "#ffffff" : "#565865";
      
        params.forEach(function (param, index) {
          // Series 0 is the order count, the rest are amounts.
          const value = index === 0
            ? param.value
            : chartTooltipAmount(param.value * 100);

          tooltipContent += `<div>
            ${param.marker} 
            <span style="color: ${color};">${param.seriesName}</span>
            <span style="float: right; margin-left: 20px; color: ${color};">
              ${value}
            </span>
          </div>`;
        });
        
        return tooltipContent;
      }
    },
    xAxis: {
      type: "category",
      data: labels.value,
      axisLabel: {
        formatter: axisDayLabel,
        color: isDarkTheme.value ? "#ffffff" : "#696778",
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
    yAxis: [
      {
        name: translate('Sales'),
        type: "value",
        position: "left",
        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#696778",
          fontSize: 12,
          formatter: (value) => `${props.reportFilter.formatAmount(value)}`,
        },
        splitLine: {
          show: true,
          lineStyle: {
            color: isDarkTheme.value ? "#253241" : "#D6DAE1",
            type: "dashed",
          },
        },
        minInterval: 500,
      },
      {
        name: translate('Orders'),
        type: "value",
        position: "right",
        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#696778",
          fontSize: 12,
        },
        splitLine: {
          show: false,
          lineStyle: {
            color: isDarkTheme.value ? "#253241" : "#D6DAE1",
            type: "dashed",
          },
        },
        minInterval: 20,
      },
    ],
    dataZoom: [],
    series: seriesData.value,
  };

  chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

// Method to create a series object
const createSeries = (name, type, yAxisIndex, data, color) => ({
  name,
  type,
  yAxisIndex,
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
  ChartTypeFilter.onChange("order", "order_chart", type);
  if (chartType.value !== type) {
    zoomIsActive.value = false;
    chartType.value = type;
    updateChart();
  }
};

// Watch for changes in chartData, chartType, or selectedGroupKey to re-initialize the chart
watch(
  [() => props.chartData, () => props.previousChartData, chartType],
  () => {
    nextTick(updateChart);
  },
  { deep: true }
);

watch(
  () => checkBoxFilter.data.order,
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

watch(dataLoader, (value) => {
  if (!value) initChart();
})

watch(hasBarChart, () => {
  if (!chartInstance) return;

  const option = chartInstance.getOption();
  
  const updatedSeries = option.series.map(series => {
    if (series.type === 'line') {
      return {
        ...series,
        areaStyle: hasBarChart.value ? undefined : { opacity: 0.3 }
      };
    }
    return series;
  });

  nextTick(() => {
    chartInstance.setOption({
      series: updatedSeries
    });
  });
});

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  nextTick(() => {
    updateChart();
  });
};

const handleCurrencyChange = () => {
  nextTick(() => {
    updateChart();
  });
};

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
  window.removeEventListener("fluentCartCurrencyChange", handleCurrencyChange, false);
});

onMounted(() => {
  nextTick(initChart);
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  window.addEventListener("fluentCartCurrencyChange", handleCurrencyChange);
});
</script>

<template>
  <div class="fct-gross-sale-vs-net-revenue-wrap">
    <Card.Container id="chartContainer">
      <Card.Header title_size="small" border_bottom>
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{ translate("Order Chart") }}</h5>
            <el-tooltip
              popper-class="fct-tooltip"
              :content="tooltip"
              placement="top"
            
              :trigger="['hover', 'focus']">
              <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')"><DynamicIcon name="InformationFill" class="text-gray-500 w-4 h-4" aria-hidden="true"/></button>
            </el-tooltip>
          </div>
        </template>

        <template #action v-if="!isEmpty">
          <div class="fct-chart-select">
            <el-select
                class="el-select--x-small"
                v-model="selectedGroupKey"
                @change="filterChartData"
            >
              <el-option
                  v-for="groupKey in groupKeys"
                  :key="groupKey.value"
                  :label="groupKey.label"
                  :value="groupKey.value"
              />
            </el-select>
          </div>

          <div class="fct-btn-group sm">
            <IconButton tag="button" size="small" :title="translate('Export CSV')" :aria-label="translate('Export CSV')"
                        :disabled="!chartData.length" @click="exportCsv">
              <DynamicIcon name="Download"/>
            </IconButton>
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
      <Card.Body>
        <el-skeleton v-if="dataLoader" animated :rows="4" />

        <template v-else>
          <div v-if="!isEmpty" class="fct-chart-wrap fct-order-line-chart" ref="chartRef"></div>
          
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
              </div>
              <!-- .chart-change -->

              <div class="chart-change">
                <span class="text">{{ translate("Timeline") }}</span>
                <DynamicIcon name="ArrowRight" class="arrow-right" />
              </div>
              <!-- .chart-change -->
            </div>
            <!-- .chart-change-wrap -->
          </div>
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>
