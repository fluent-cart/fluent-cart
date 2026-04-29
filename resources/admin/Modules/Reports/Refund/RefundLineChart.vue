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
import {
  makeXAxisLabels,
  tooltipSuffix,
  prepareLabel,
  getEmphasisColor,
  getXAxisConfig,
} from '../Utils/decorator';

const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  compareData: {
    type: Array,
    required: false,
  },
  reportFilter: {
    type: Object,
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

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());
const chartRef = ref(null);
const selectedGroupKey = ref("default");
const chartType = ref(
  ChartTypeFilter.getChartType("refund", "refund_chart") || "line"
);
const zoomIsActive = ref(false);

const currencySign = computed(() => {
  return props.reportFilter.currentCurrencySign;
});

const groupKeys = [
  { label: translate("Default"), value: "default" },
  { label: translate("Monthly"), value: "monthly" },
  { label: translate("Yearly"), value: "yearly" },
];

const emit = defineEmits(["fetch-chart-data"]);

const filterChartData = () => {
  emit("fetch-chart-data", selectedGroupKey.value);
};

const hasComparison = computed(() => props.reportFilter.data.compareType !== 'no_comparison');

const createSeries = (name, type, data, color, yAxisIndex, animation = true) => {
  return {
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
    animation: animation,
    animationEasing: "cubicOut",
    animationDuration: 800,
    animationDelay: 0,
    barGap: '-100%',
    legendHoverLink: false,
  };
};

const seriesData = computed(() => {
  let seriesSrc = 
  [
    {
      name: translate("Refunded Amount"),
      key: "refunded_amount",
      // type: chartType.value,
      type: 'line',
      color: isDarkTheme.value ? colors.blue_dark_mode : colors.blue,
      yAxisIndex: 0,
      data: props.chartData.map((item) => item.refunded_amount),
      animation: false,
    },
    {
      name: translate("Total Refunds"),
      key: "refund_count",
      // type: chartType.value,
      type: 'bar',
      color: isDarkTheme.value ? colors.dark_cyan_blue_36 : colors.light_gray_cyan_blue,
      yAxisIndex: 1,
      data: props.chartData.map((item) => item.refund_count),
      animation: false,
    }
  ];

  if (hasComparison.value) {
    seriesSrc.unshift(
      {
        name: translate("Previous Refunded Amount"),
        key: "refunded_amount_compare",
        // type: chartType.value,
        type: 'line',
        color: isDarkTheme.value ? colors.purple_dark_mode : colors.purple,
        yAxisIndex: 0,
        data: props.compareData.map((item) => item.refunded_amount),
        animation: false,
      },

      {
        name: translate("Previous Total Refunds"),
        key: "refund_count_compare",
        // type: chartType.value,
        type: 'bar',
        color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
        yAxisIndex: 1,
        data: props.compareData.map((item) => item.refund_count),
        animation: true,
      }
    );
  }
  
  return seriesSrc.map((item) => createSeries(
    item.name,
    item.type,
    item.data,
    item.color,
    item.yAxisIndex,
    item.animation,
  ));
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
        hasBarChart.value = params.selected["Total Refunds"] || params.selected["Previous Total Refunds"];
      });
    }
  });

};

const tooltip = computed(() => {
  const tooltipAppendix = tooltipSuffix(props.reportFilter.data.dateRange);

  return translate('This report looks at refunds made for successful orders created ') + tooltipAppendix;
});

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

const updateChart = () => {
  if (!chartInstance) return;

  const labels = makeXAxisLabels(props.chartData);

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
        let result = params[0].name;

        params.forEach((param) => {
          const value = [0, 2].includes(param.seriesIndex) ? `${CurrencyFormatter.scaled(param.value)}` : param.value;
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
    xAxis: {
      type: "category",
      data: labels,
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
    yAxis: [
      {
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
      {
        type: "value",
        position: "right",
        axisLabel: {
          color: isDarkTheme.value ? "#253241" : "#D6DAE1",
          fontSize: 12,
        },
        splitLine: {
          show: false,
        },
      },
    ],
    series: seriesData.value,
    animation: true,
    animationThreshold: 2000,
    animationDurationUpdate: 300,
    animationEasingUpdate: 'cubicInOut'
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
  ChartTypeFilter.onChange("refund", "refund_chart", type);
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
  () => checkBoxFilter.data.refund,
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
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  nextTick(initChart);
});
</script>

<template>
  <div class="fct-gross-sale-vs-net-revenue-wrap">
    <Card.Container id="chartContainer">
      <Card.Header title_size="small" border_bottom>
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{
                translate("Refunds Chart")
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
                  :label="$t(groupKey.label)"
                  :value="groupKey.value"
              />
            </el-select>
          </div>

          <div class="fct-btn-group sm">
            <!-- <ChartTab
              :activeType="chartType"
              :types="{
                bar: 'BarChart',
                line: 'LineChart',
              }"
              @change="toggleChartType"
            /> -->

            <IconButton
              tag="button"
              size="small"
              :title="$t('Zoom Chart')"
              @click="handleZoomChart"
              :class="zoomIsActive ? 'primary' : ''"
            >
              <DynamicIcon name="SearchAdd" />
            </IconButton>

            <Screenshot :targetRef="chartRef" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-0">
        <el-skeleton v-if="loading" animated :rows="7" class="p-4" />

        <template v-else>
          <template v-if="!isEmpty">
            <div v-if="!isEmpty" class="fct-chart-wrap fct-refund-line-chart" ref="chartRef"></div>
            
            <div class="chart-action-wrap">
              <div class="chart-change-wrap">
                <div class="chart-change">
                  <DynamicIcon name="ArrowUp" class="arrow-up" />
                  <span class="text">{{ $t("Data") }}</span>
                </div>
  
                <div class="chart-change">
                  <span class="text">{{ $t("Timeline") }}</span>
                  <DynamicIcon name="ArrowRight" class="arrow-right" />
                </div>
              </div>
            </div>
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
