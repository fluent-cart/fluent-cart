<script setup>
import {ref, onMounted, nextTick, computed, watch, onUnmounted} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import palette from "google-palette";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Theme from "@/utils/Theme";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";
import ChartTab from "@/Bits/Components/ChartTab.vue";
import translate from "@/utils/translator/Translator";
import { monthNames } from "@/Modules/Reports/Utils/monthNames";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import Empty from "@/Bits/Components/Table/Empty.vue";
import {
  makeXAxisLabels,
  tooltipSuffix,
  prepareLabel,
  getEmphasisColor,
  getXAxisConfig,
} from '@/Modules/Reports/Utils/decorator';


// Define props
const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  reportFilter: {
    type: Object,
  },
  loading: {
    type: Boolean,
    default: true,
  },
});

const chartRef = ref(null);
const cardRef = ref(null);
const selectedGroupKey = ref("daily");
const chartType = ref(
  ChartTypeFilter.getChartType("order", "sales_growth_chart") || "line"
);

const emit = defineEmits(["fetch-chart-data"]);

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const isEmpty = computed(() => props.chartData.every(item => item.orders === 0));

const primaryColor = computed(() =>
  isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
  // isDarkTheme.value ? colors.blue_dark_mode : colors.blue
);

const secondaryColor = computed(() =>
  isDarkTheme.value ? colors.dark_cyan_blue_36 : colors.light_gray_cyan_blue,
  // isDarkTheme.value ? colors.purple_dark_mode : colors.purple,
);

const seriesData = computed(() =>
  [
    {
      title: translate("Orders"),
      key: "orders",
      color: primaryColor.value,
      yAxisIndex: 1
    },
    {
      title: translate("Revenue"),
      key: "net_revenue",
      color: secondaryColor.value,
      yAxisIndex: 0
    },
    
  ].map(({ title, key, color, yAxisIndex}) =>{
        return createSeries(
            title,
            chartType.value,
            props.chartData.map((item) => item[key]),
            color,
            yAxisIndex
        );
      }

  )
);

const months = monthNames.map((month) => month.slice(0, 3));

// Computed property for labels based on selected group key
const labels = computed(() =>  
  makeXAxisLabels(props.chartData)
);

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

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

// Update the chart with new options whenever seriesData or labels change
const updateChart = () => {
  if (!chartInstance) return;

  isDarkTheme.value = Theme.isDark();
  
  const option = {
    title: {
      text: props.chartTitle ?? "",
      left: "center",
    },
    legend: {
      show: true,
      top: "5%",
      icon: "circle",
      itemWidth: 8,
      itemHeight: 8,
      itemGap: 30,
      textStyle: {
      color: isDarkTheme.value ? "#ffffff" : "#000000",
      },
    },
    tooltip: {
      trigger: "axis",
      backgroundColor: isDarkTheme.value ? "#253241" : "#ffffff",
      borderColor: isDarkTheme.value ? "#2C3C4E" : "#c0c4ca",
      borderWidth: 1,
      textStyle: {
        color: isDarkTheme.value ? "#ffffff" : "#565865"
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
          const value = param.seriesIndex === 1 ? `${CurrencyFormatter.scaled(param.value)}` : param.value;
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
      data: labels.value,
      axisLabel: {
        color: isDarkTheme.value ? "#ffffff" : "#696778",
        fontSize: 12,
        interval: xAxisConfig.value.interval,
      },
      axisLine: {
        lineStyle: {
          type: 'dashed',
          color: isDarkTheme.value ? '#253241' : '#D6DAE1',
        }
      }
    },
    yAxis: [
      {
        name: translate("Revenue"),
        type: "value",
        position: "left",
        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#696778",
          fontSize: 12,
          formatter: (value) => `${CurrencyFormatter.scaledChart(value)}`,
        },
        splitLine: {
          show: true,
          lineStyle: {
            color: isDarkTheme.value ? '#253241' : '#D6DAE1',
            type: 'dashed',
          },
        },
      },
      {
        name: translate("Orders"),
        type: "value",
        position: "right",
        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#696778",
          fontSize: 12,
        },
        splitLine: {
          show: false,
          lineStyle: {
            color: isDarkTheme.value ? '#253241' : '#D6DAE1',
            type: 'dashed',
          },
        },
      },
    ],

    // dataZoom: [
    //   {
    //     type: "inside",
    //     xAxisIndex: 0,
    //     start: 0,
    //     end: 100,
    //   },
    // ],
    series: seriesData.value,
  };

  chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

// Method to create a series object
const createSeries = (name, type, data, color, yAxisIndex) => ({
  name,
  type,
  barMaxWidth: 30,
  data,
  smooth: false,
  color,
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

const toggleChartType = (type) => {
  if (chartType.value !== type) {
    chartType.value = type;
    ChartTypeFilter.onChange("order", "sales_growth_chart", type);
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

watch(isEmpty, (value) => {
  if (!value) initChart();
});

watch(() => props.loading, (value) => {
  if (!value) initChart();
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", updateChart, false);
});

onMounted(() => {
  window.addEventListener('onFluentCartThemeChange', updateChart);
  nextTick(initChart);
});
</script>

<template>
  <div class="fct-gross-sale-vs-net-revenue-wrap">
    <Card.Container id="chartContainer" ref="cardRef">
      <Card.Header
        :title="$t('Sales Growth')"
        border_bottom
      >
        <template #action v-if="!isEmpty && !loading">
          <div class="fct-btn-group sm">
            <!--            <el-select-->
            <!--              size="small"-->
            <!--              v-model="selectedGroupKey"-->
            <!--              @change="filterChartData"-->
            <!--            >-->
            <!--              <el-option-->
            <!--                v-for="groupKey in groupKeys"-->
            <!--                :key="groupKey.value"-->
            <!--                :label="groupKey.label"-->
            <!--                :value="groupKey.value"-->
            <!--              />-->
            <!--            </el-select>-->

            <ChartTab
                :activeType="chartType"
                :types="{
                  bar: 'BarChart',
                  line: 'LineChart',
                }"
                @change="toggleChartType"
            />

            <Screenshot :targetRef="chartRef" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="p-0" >
        <el-skeleton v-if="loading" animated :rows="5" class="p-4" />

        <template v-else>
          <div
            v-if="!isEmpty"
            class="fct-chart-wrap fct-gross-sale-vs-net-revenue-chart"
            ref="chartRef"
          ></div>

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
