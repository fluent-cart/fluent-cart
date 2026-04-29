<template>
  <div class="fct-report-refund-weeks-between-an-order-wrap">
    <Card.Container>
      <Card.Header
          :title="translate('Weeks between an order\'s created date and refund date')"
          title_size="small"
          border_bottom
      >
        <template #action v-if="!isEmpty && !loading">
          <div class="fct-btn-group sm">
            <!-- <ChartTab
                :activeType="chartType"
                :types="{
                   bar: 'BarChart',
                  line: 'LineChart'
                }"
                @change="toggleChartType"
            /> -->

            <Screenshot :targetRef="chartRef" size="x-small"/>
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-0">
        <el-skeleton v-if="loading" animated :rows="7" class="p-4" />

        <template v-else>
          <template v-if="!isEmpty">
            <div v-if="!isEmpty" class="fct-chart-wrap fct-refund-weeks-between-an-order-chart" ref="chartRef"></div>
            
            <div class="chart-action-wrap">
              <div class="chart-change-wrap">
                <div class="chart-change">
                  <DynamicIcon name="ArrowUp" class="arrow-up"/>
                  <span class="text">{{ translate('Refund Count') }}</span>
                </div>
  
                <div class="chart-change">
                  <span class="text">{{ translate('Weeks') }}</span>
                  <DynamicIcon name="ArrowRight" class="arrow-right"/>
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

<script setup>
import {ref, onMounted, computed, nextTick, watch, onUnmounted} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import * as echarts from "echarts";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import Theme from "@/utils/Theme";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";

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
  chartTitle: {
    type: String,
    default: "",
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
const chartType = ref("bar");
let chartInstance = null;

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const primaryColor = computed(() => {
  return isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray
});

const seriesData = computed(() => {
  return [
    {
      name: translate("Refund Count"),
      type: chartType.value,
      data: props.chartData.map((item) => item.occurrence),
      smooth: true,
      color: primaryColor.value,
      showSymbol: false,
      itemStyle: {
        borderRadius: [4, 4, 0, 0]
      },
      emphasis: {
        itemStyle: {
          // color: 'inherit',
          color: getEmphasisColor(primaryColor.value),
        }
      },
      animation: true,
      animationEasing: "cubicOut",
      animationDuration: 800,
      animationDelay: 0,
      legendHoverLink: false,
    },
  ];
});

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

// Create computed property for chart options
const getChartOption = () => ({
  title: {
    text: props.chartTitle,
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
  },
  xAxis: {
    type: "category",
    data: props.chartData.map((item) => item.weekBetween),
    axisLabel: {
      color: isDarkTheme.value ? "#ffffff" : "#000000",
      fontSize: 12,
      interval: xAxisConfig.value.interval,
      formatter: (value) => value > 1 ? `${value} weeks` : `${value} week`,
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
      formatter: "{value}",
      color: isDarkTheme.value ? "#ffffff" : "#000000",
      fontSize: 12,
    },
    splitLine: {
      show: true,
      lineStyle: {
        color: isDarkTheme.value ? '#253241' : '#D6DAE1'
      }
    },
  },
  series: seriesData.value
});

// Initialize the chart
const initChart = () => {
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }

  nextTick(() => {
    if (chartRef.value) {
      chartInstance = echarts.init(chartRef.value);
      chartInstance.setOption(getChartOption());
      window.addEventListener("resize", () => {
        chartInstance.resize();
      });
    }
  });

};

// const toggleChartType = (type) => {
//   if (chartType.value !== type) {
//     chartType.value = type;
//     if (chartInstance) {
//       chartInstance.setOption(getChartOption(), true);
//     }
//   }
// };

// Watch for changes in chart data to update the chart
watch(
    () => props.chartData,
    () => {
      if (chartInstance) {
        chartInstance.setOption(getChartOption());
      }
    }
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

const updateChart = (event) => {
  if (!chartInstance) return;
  
  chartInstance.setOption(getChartOption(), true);
}

onMounted(() => {
  window.addEventListener('onFluentCartThemeChange', handleThemeChange);

  nextTick(() => {
    initChart();
  });
});

</script>


