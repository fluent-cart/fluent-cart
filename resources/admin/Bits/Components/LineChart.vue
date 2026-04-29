<script setup>
import { ref, onMounted, onUnmounted, nextTick, computed, watch, defineExpose } from 'vue';
import * as echarts from 'echarts';
import Theme from "@/utils/Theme";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from '@/utils/translator/Translator';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {
  prepareLabel,
  getEmphasisColor,
  getXAxisConfig,
} from '@/Modules/Reports/Utils/decorator';

const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  chartTitle: {
    type: String,
    default: "",
  },
  formatValuePercentage: {
    type: Boolean,
    default: false,
  },
  amountFormat: {
    type: Boolean,
    default: false,
  },
  chartType: {
    type: String,
  },
  zoomIsActive: {
    type: Boolean,
    default: false
  },
  loading: {
    type: Boolean,
    default: false
  }
});

// References
const chartRef = ref(null);
const chartData = ref(props.chartData);

defineExpose({
  chartRef
});

// add watch on chartData
watch(() => props.chartData, (newVal) => {
  chartData.value = newVal;
}, { deep: true });

// Theme colors
const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const themeColors = computed(() => ({
  textColor: isDarkTheme.value ? '#ffffff' : '#000000',
  borderColor: isDarkTheme.value ? '#4a5a6b' : '#c0c4ca',
  splitLineColor: isDarkTheme.value ? '#253241' : '#D6DAE1',
  bgColor: isDarkTheme.value ? '#2d3748' : '#ffffff',
}));

const primaryColor = computed(() => isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray);

// Computed labels for x-axis
const labels = computed(() => chartData.value.map(item => prepareLabel(item.label)));

// Computed series data
const seriesData = computed(() => [
  {
    name: props.chartTitle,
    type: props.chartType,
    data: chartData.value.map(item => item.value),
    smooth: false,
    color: primaryColor.value,
    lineStyle: { 
      width: 3
    },
    barMaxWidth: 30,
    areaStyle: {
      opacity: 0.3,
      color: primaryColor.value,
    },
    symbol: "circle",
    showSymbol: true,
    symbolSize: 8,
    itemStyle: {
      color: primaryColor.value,
      borderRadius: [4, 4, 0, 0]
    },
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
    legendHoverLink: false,
  },
]);

// Chart instance
let chartInstance = null;

// Initialize chart
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

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

// Update chart options
const updateChart = () => {
  if (!chartInstance) return;

  const option = {
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
      trigger: 'axis',
      backgroundColor: isDarkTheme.value ? "#253241" : "#ffffff",
      borderColor: isDarkTheme.value ? "#2C3C4E" : "#c0c4ca",
      borderWidth: 1,
      textStyle: { color: isDarkTheme.value ? "#ffffff" : "#565865" },
      axisPointer: {
        type: 'line',
        lineStyle: {
          type: 'solid',
          width: 2,
          color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_blue,
        }
      },
      // formatter: (params) => {
      //   let result = `<div>${params[0].name}</div>`;
      //   params.forEach((param) => {
      //     result += `<div style="display: flex; align-items: center;">
      //       <span style="display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:5px;background-color:${
      //         param.color
      //     };"></span>
      //       ${param.seriesName}: ${props.amountFormat ? CurrencyFormatter.formatScaled(param.value) : param.value}${props.formatValuePercentage ? '%' : ''}
      //     </div>`;
      //   });
      //   return result;
      // },
    },
    xAxis: {
      type: 'category',
      data: labels.value,
      axisLabel: {
        color: themeColors.value.textColor,
        fontSize: 12,
        interval: xAxisConfig.value.interval,
      },
      axisLine: {
        lineStyle: {
          type: 'dashed',
          color: themeColors.value.splitLineColor,
        },
      },
    },
    yAxis: {
      type: 'value',
      axisLabel: {
        color: themeColors.value.textColor,
        fontSize: 12,
      },
      splitLine: {
        show: true,
        lineStyle: {
          color: themeColors.value.splitLineColor,
          type: 'dashed',
        },
      },
    },
    series: seriesData.value,
  };

  chartInstance.setOption(option, { notMerge: true, replaceMerge: ['series'] });
};

const handleZoomChart = (value) => {
  if (value) {
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


// Watch for data or chart type changes
watch([() => chartData.value, () => props.chartType, () => Theme.isDark(), () => props.loading], () => {
  initChart();
}, { deep: true });

watch(() => props.zoomIsActive, (value) => {
  handleZoomChart(value);
});

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  nextTick(() => {
    updateChart();
  });
};

// Initialize chart on mount
onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);

  nextTick(initChart);
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
});
</script>

<template>
    <el-skeleton v-if="loading" animated :rows="8" />

    <template v-else>
      <template v-if="chartData.length">
        <div v-if="chartData.length" class="fct-chart-wrap" ref="chartRef"></div>
        
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
</template>
