<script setup>
import {ref, onMounted, nextTick, computed, watch, onUnmounted} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import Theme from "@/utils/Theme";
import ChartTab from "@/Bits/Components/ChartTab.vue";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import Empty from "@/Bits/Components/Table/Empty.vue";

// Define props
const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
  dataKey: {
    type: String,
    required: true,
  },
  labelSet: {
    type: String,
    default: "monthly",
  },

  dateRange: {
    type: Boolean,
    default: false,
  },
  barMaxWidth: {
    type: Number,
    default: 30,
  },
  
  isEmpty: {
    type: Boolean,
    default: false,
  },
});

const chartRef = ref(null);
const cardRef = ref(null);
const chartType = ref("bar");
const error = ref(null);

const colors = Theme.colors;
const isDarkTheme = ref(Theme.isDark());

const primaryColor = computed(() =>
  isDarkTheme.value ? colors.blue['500'] + '52' : colors.blue['300']
);

const mapLabel = {
  'current': translate('Current'),
  'prev': translate('Previous'),
  'yoy_growth': translate('YoY Growth'),
};

// Transform data into an array format
const chartDataArray = computed(() => {
  try {
    const data = Object.keys(props.data).map((key) => {
      return {
        label: key,
        value: props.data[key][props.dataKey] || 0,
      }
    });
    return data;
  } catch (e) {
    error.value = `Error processing data: ${e.message}`;
    return [];
  }
});

// Computed property for labels
const labels = computed(() => {
  if (!chartDataArray.value.length) return [];
  if (props.dateRange) {
    return chartDataArray.value.map((item) => {
      try {
        const [year, month] = item.label.split("-");
        const date = new Date(year, month - 1);
        return date.toLocaleString("default", {month: "short", year: "numeric"});
      } catch {
        return item.label; // Fallback to raw label if parsing fails
      }
    });
  }
  return chartDataArray.value.map((item) => item.label); // Quarterly labels like "Q3-2024"
});

// Computed property for series data
const seriesData = computed(() => {
  if (!chartDataArray.value.length) return [];
  const dt =  [
    {
      title: mapLabel[props.dataKey] || props.dataKey, // Fallback to key if translation fails
      data: chartDataArray.value.map((item) => item.value),
      color: primaryColor.value,
      yAxisIndex: 0,

    },
  ].map(({title, data, color, yAxisIndex}) =>
      createSeries(title, chartType.value, data, color, yAxisIndex)
  );
  return dt;
});

let chartInstance = null;

// Initialize the chart
const initChart = () => {
  if (chartRef.value && chartInstance === null) {
    try {
      chartInstance = echarts.init(chartRef.value);
      updateChart();
    } catch (e) {
      error.value = `Error initializing chart: ${e.message}`;
    }
  }
};

// Update the chart with new options
const updateChart = () => {
  if (!chartInstance) return;
  if (error.value) {
    console.error(error.value);
    return;
  }

  isDarkTheme.value = Theme.isDark();

  const option = {
    title: {
      text: "", // Removed chartTitle prop as it was unused
      left: "center",
    },
    legend: {
      show: false,
      top: "5%",
      icon: "circle",
      itemWidth: 8,
      itemHeight: 8,
      itemGap: 30,
      textStyle: {
        color: isDarkTheme.value ? "#ffffff" : "#696778",
      },
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
          color: isDarkTheme.value ? colors.report.dark_cyan_blue_16 : colors.report.light_gray_cyan_blue,
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
      type: "category",
      data: labels.value,
      axisLabel: {
        color: isDarkTheme.value ? "#ffffff" : "#696778",
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
        position: "left",

        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#696778",
          fontSize: 12,
          formatter: (value) => `${CurrencyFormatter.formatScaledChart(value)}`,
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
    grid: {
      left: '10px',
      right: '10px',
      top: '20px',
      bottom: '60px',
      containLabel: true  // This ensures labels fit within the grid
    },
  };


  try {
    chartInstance.setOption(option, {notMerge: true, replaceMerge: ["series"]});
  } catch (e) {
    error.value = `Error updating chart: ${e.message}`;
    console.error(error.value);
  }
};

// Method to create a series object
const createSeries = (name, type, data, color, yAxisIndex) => ({
  name,
  type,
  barMaxWidth: props.barMaxWidth,
  areaStyle: type === "line" ? {} : undefined,
  data,
  smooth: true,
  color,
  lineStyle: {
    width: 2,
  },
  yAxisIndex,
    emphasis: {
      itemStyle: {
        color: isDarkTheme.value ? colors.blue['800'] : colors.blue['400'],
      },
  },
});

const toggleChartType = (type) => {
  if (chartType.value !== type) {
    chartType.value = type;
    updateChart();
  }
};

// Watch for changes in data, key, labelSet, or chartType
watch(
    [() => props.data, () => props.dataKey, () => props.labelSet, chartType],
    () => {
      nextTick(updateChart);
    },
    {deep: true}
);

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", updateChart, false);
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
});

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", updateChart);
  nextTick(initChart);
});
</script>

<template>
  <div class="fct-overview-chart-wrap">
    <Card.Container id="chartContainer" ref="cardRef">
<!--      <Card.Header title=" " title_size="small" border_bottom>-->
<!--        <template #action>-->
<!--          <div class="fct-btn-group sm">-->
<!--            <ChartTab-->
<!--                :activeType="chartType"-->
<!--                :types="{ bar: 'BarChart', line: 'LineChart' }"-->
<!--                @change="toggleChartType"-->
<!--            />-->
<!--            <Screenshot :targetRef="chartRef"/>-->
<!--          </div>-->
<!--        </template>-->
<!--      </Card.Header>-->
      <Card.Body class="p-0">
        <div
            v-if="error"
            class="error-message"
            style="color: red; padding: 10px;"
        >
          {{ error }}
        </div>
        <div
            v-if="!isEmpty"
            class="fct-chart-wrap fct-overview-chart"
            ref="chartRef"
            style="height: 400px;"
        ></div>
        <Empty
          v-else
          icon="Empty/ListView"
          :has-dark="true"
          :text="translate('Currently there is no data!')"
          class="fct-report-empty-state"
        />
      </Card.Body>
    </Card.Container>
  </div>
</template>

<style scoped>
.fct-overview-chart-wrap {
  width: 100%;
}

.fct-overview-chart {
  width: 100%;
  height: 100%;
}

.fct-btn-group {
  display: flex;
  align-items: center;
  gap: 10px;
}

.fct-report-empty-state {
  height: 400px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
}
</style>
