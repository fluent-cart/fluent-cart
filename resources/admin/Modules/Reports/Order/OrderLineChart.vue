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
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import orderReport from "@/Models/Reports/OrderReportModel";
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

const groupKeys = [
  { value: "default", label: translate("Default") },
  { value: "monthly", label: translate("Monthly") },
  { value: "yearly", label: translate("Yearly") },
];

const emit = defineEmits(["fetch-chart-data"]);

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
    {
      title: translate("Gross Sales"),
      key: "gross_sale",
      color: isDarkTheme.value ? colors.blue_dark_mode : colors.blue,
      yAxisIndex: 0,
      type: 'line',
    },
    {
      title: translate("Revenue"),
      key: "net_revenue",
      color: isDarkTheme.value ? colors.purple_dark_mode : colors.purple,
      yAxisIndex: 0,
      type: 'line',
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
    .filter(({ key }) => checkBoxFilter.data.order.includes(key))
    .map(({ title, key, color, yAxisIndex, type }) =>
      createSeries(
        title,
        type,
        yAxisIndex,
        props.chartData.map((item) => item[key]),
        color
      )
    )
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
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }

  nextTick(() => {
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
      axisPointer: {
        type: 'line',
        lineStyle: {
          type: 'solid',
          width: 2,
          color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_blue,
        }
      },
      formatter: (params) => {
        let tooltipContent = params[0].axisValue;
        const color = isDarkTheme.value ? "#ffffff" : "#565865";
      
        params.forEach(function (param, index) {
          const value = index === 0 
            ? param.value 
            : CurrencyFormatter.scaled(param.value);

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
          formatter: (value) => `${CurrencyFormatter.scaled(value)}`,
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
  [() => props.chartData, chartType],
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

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
});

onMounted(() => {
  nextTick(initChart);
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
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
            >
              <DynamicIcon
                class="text-gray-500 w-4 h-4 cursor-pointer"
                name="InformationFill"
              />
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
