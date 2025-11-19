<script setup>
import {computed, nextTick, onMounted, ref, watch} from "vue";
import * as echarts from 'echarts';
import * as Card from "@/Bits/Components/Card/Card.js";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import Empty from "@/Bits/Components/Table/Empty.vue";

const props = defineProps({
  data: {
    type: Object,
    required: true
  },
  title: {
    type: String,
    default: ""
  }
});
const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

// References
const SubscriptionCountTrendChartRef = ref(null);
const chartType = ref('line');
const chartData = ref(props.data);

// Theme colors
const themeColors = computed(() => ({
  textColor: isDarkTheme.value ? '#ffffff' : '#696778',
  borderColor: isDarkTheme.value ? '#2C3C4E' : '#c0c4ca',
  splitLineColor: isDarkTheme.value ? '#253241' : '#D6DAE1',
  bgColor: isDarkTheme.value ? '#2d3748' : '#ffffff',
}));

const isEmpty = computed(() => {
  return Object.values(chartData.value).every(value => value === 0);
});

// Computed labels for x-axis
const labels = computed(() => Object.keys(chartData.value));
const seriesData = computed(() => [
  {
    name: translate('Current'),
    type: chartType.value,
    data: Object.values(chartData.value),
    smooth: true,
    color: isDarkTheme.value ? colors.purple_dark_mode : colors.purple,
    lineStyle: { width: 2 },
    barMaxWidth: 30,
  },
]);


// Chart instance
let chartInstance = null;

// Initialize chart
const initChart = () => {
  if (SubscriptionCountTrendChartRef.value && !chartInstance) {
    chartInstance = echarts.init(SubscriptionCountTrendChartRef.value);
    updateChart();
  }
};

// Update chart options
const updateChart = () => {
  const option = {
    legend: {
      show: false,
    },
    tooltip: {
      trigger: 'axis',
      backgroundColor: themeColors.value.bgColor,
      borderColor: themeColors.value.borderColor,
      borderWidth: 1,
      textStyle: { color: themeColors.value.textColor },
      axisPointer: {
        type: 'line',
        lineStyle: {
          type: 'solid',
          width: 2,
          color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_blue,
        }
      },
      formatter: params => {
        let tooltipContent = params[0].name;
        const color = isDarkTheme.value ? "#ffffff" : "#565865";
      
        params.forEach(param => {
          const value = CurrencyFormatter.formatScaled(param.value);

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
      type: 'category',
      data: labels.value,
      axisLabel: {
        color: themeColors.value.textColor,
        fontSize: 12,
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
        formatter: value => CurrencyFormatter.formatScaled(value) // Convert back to cents for formatting
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
    grid: {
      left: '10px',
      right: '10px',
      top: '20px',
      bottom: '60px',
      containLabel: true  // This ensures labels fit within the grid
    },
  };

  chartInstance.setOption(option, { notMerge: true, replaceMerge: ['series'] });
};

// Watch for data or chart type changes
watch(
    [() => chartData.value, chartType, () => isDarkTheme.value],
    () => {
      nextTick(updateChart);
    },
    { deep: true }
);

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  
  nextTick(() => {
    updateChart();
  });
};

// Initialize chart on mount
onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange, false);

  nextTick(initChart);
})
</script>

<template>
  <Card.Container>
    <Card.Header v-if="title !== ''" :border_bottom="true">
      <template #title>
        <h3 class="fct-card-header-title is-small">{{ title }}</h3>
      </template>
    </Card.Header>
    <Card.Body>
      <div v-if="!isEmpty" class="fct-chart-wrap h-[350px]" ref="SubscriptionCountTrendChartRef"></div>

      <Empty
          v-else
          icon="Empty/ListView"
          :has-dark="true"
          :text="translate('Currently there is no data!')"
          class="fct-report-empty-state"
        />
    </Card.Body>
  </Card.Container>
</template>

<style scoped>

</style>
