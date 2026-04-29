<script setup>
import {
  ref,
  onMounted,
  nextTick,
  computed,
  watch,
  onUnmounted,
  getCurrentInstance,
} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import checkBoxFilter from "@/Models/Reports/CheckBoxFilterModel";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Theme from "@/utils/Theme";
import ChartTab from "@/Bits/Components/ChartTab.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import {
  getEmphasisColor,
} from '../Utils/decorator';
import translate from "@/utils/translator/Translator";

const props = defineProps({
  chartData: {
    type: Object,
    required: true,
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
    default: true
  }
});

const chartRef = ref(null);
const chartType = ref(
  ChartTypeFilter.getChartType("subscription", "retention_chart") || "line"
);
const zoomIsActive = ref(false);

const customDayInput = ref(null);

const products = ref([]);

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const createSeries = (name, type, data, color) => {
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
    legendHoverLink: false,
  };
};

const chartLabels = computed(() => {
  const keys = Object.keys(props.chartData);
  return keys.map((key) => {
    if (key.startsWith("day_")) {
      /* translators: %s: number of days */
      return translate('%s Days', key.replace("day_", ""));
    } else if (key === "more_than_year") {
      return translate("More than Year");
    }
    return key;
  });
});

const chartValues = computed(() => {
  const keys = Object.keys(props.chartData);
  return keys.map((key) => props.chartData[key] || 0);
});

const seriesData = computed(() => [
  createSeries(
    translate("Customers"),
    chartType.value, 
    chartValues.value, 
    isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray
  ),
]);

let chartInstance = null;

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
  })
};

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
    },
    xAxis: {
      type: "category",
      data: chartLabels.value,
      axisLabel: {
        color: isDarkTheme.value ? "#ffffff" : "#565865",
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
        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#565865",
          fontSize: 12,
        },
        splitLine: {
          show: true,
          lineStyle: {
            color: isDarkTheme.value ? "#253241" : "#D6DAE1",
            type: "dashed",
          },
        },
      },
    ],
    series: seriesData.value,
  };
  chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

const toggleChartType = (type) => {
  ChartTypeFilter.onChange("subscription", "retention_chart", type);

  if (chartType.value !== type) {
    zoomIsActive.value = false;
    chartType.value = type;
    updateChart();
  }
};

const emit = defineEmits(["fetch-custom-chart-data"]);

const handleCustomFilters = () => {
  emit("fetch-custom-chart-data", customDayInput.value);
};

watch(
  () => props.chartData,
  () => {
    nextTick(updateChart);
  },
  { deep: true }
);

watch(
  () => checkBoxFilter.data.refund,
  () => {
    nextTick(updateChart);
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
  nextTick(initChart);
});
</script>

<template>
  <div class="fct-gross-sale-vs-net-revenue-wrap">
    <Card.Container id="chartContainer">
      <Card.Header
        :title="translate('Retention Chart')"
        title_size="small"
        border_bottom
      >
        <template #action v-if="!isEmpty && !loading">
          <div class="fct-chart-select">
            <el-input
                type="text"
                v-model="customDayInput"
                :placeholder="translate('Enter custom days')"
                class="el-input--x-small"
                @keyup.enter="handleCustomFilters"
            />
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

            <Screenshot :targetRef="chartRef" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-0">
        <el-skeleton v-if="loading" animated :rows="7" class="p-4" />

        <template v-else>
          <template v-if="!isEmpty">
            <div class="fct-chart-wrap fct-refund-line-chart" ref="chartRef"></div>
            
            <div class="chart-action-wrap">
              <div class="chart-change-wrap">
                <div class="chart-change">
                  <DynamicIcon name="ArrowUp" class="arrow-up" />
                  <span class="text">{{ translate("Data") }}</span>
                </div>
  
                <div class="chart-change">
                  <span class="text">{{ translate("Timeline") }}</span>
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
