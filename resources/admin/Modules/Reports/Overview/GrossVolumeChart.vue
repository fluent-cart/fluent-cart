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
  title: {
    type: String,
    default: "",
  },
  currentKey: {
    type: String,
    default: "current",
  },
  prevKey: {
    type: String,
    default: "prev",
  },
  labelKey: {
    type: String,
    default: "yoy_growth",
  },
  isDate: {
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
const chartType = ref("bar"); // Default to bar for current
const error = ref(null);

const colors = Theme.colors;
const isDarkTheme = ref(Theme.isDark());
const mapLabel = {
  'current': translate('Current'),
  'prev': translate('Previous'),
  'prev_year': translate('Previous'),
  'yoy_growth': translate('YoY Growth'),
};

// Transform data into an array format
const chartDataArray = computed(() => {
  if (!props.data || typeof props.data !== "object") {
    error.value = "Invalid or missing data prop";
    return [];
  }
  try {
    const dataArray = Object.keys(props.data).map((key) => {
      const item = props.data[key];
      return {
        label: key,
        current: item[props.currentKey] ? parseInt(item[props.currentKey]) : 0,
        prev: item[props.prevKey] ? parseInt(item[props.prevKey]) : 0,
        growth: item[props.labelKey] ? parseFloat(item[props.labelKey]) : 0,
      };
    });
    return dataArray;
  } catch (e) {
    error.value = `Error processing data: ${e.message}`;
    return [];
  }
});

// Computed property for labels
const labels = computed(() => {
  if (!chartDataArray.value.length) return [];
  return chartDataArray.value.map((item) => {

    if(props.isDate){
      try {
        const [year, month] = item.label.split("-");
        const date = new Date(year, month - 1);
        return date.toLocaleString("default", {month: "short", year: "numeric"});
      } catch {
        return item.label;
      }
    }else{
      return item.label;
    }

  });
});

// Computed property for series data
const seriesData = computed(() => {
  if (!chartDataArray.value.length) return [];
  return [
    {
      title: mapLabel[props.currentKey] || props.currentKey,
      dataKey: "current",
      type: "line",
      color: isDarkTheme.value ? colors.report.blue_dark_mode : colors.report.blue, // Purple for bars
    },
    {
      title: mapLabel[props.prevKey] || props.prevKey,
      dataKey: "prev",
      type: "bar",
      color: isDarkTheme.value ? colors.blue['500'] + '52' : colors.blue['300']
    },
  ].map(({title, dataKey, type, color}) =>
      createSeries(
          title,
          type,
          chartDataArray.value.map((item) => item[dataKey]),
          color
      )
  )

});

// Computed property for growth data (for tooltip only)
const growthData = computed(() => {
  if (!chartDataArray.value.length) return [];
  const growthValues = chartDataArray.value.map((item) => {
    return item.growth;
  });
  return growthValues;
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
        const growth = growthData.value[params[0].dataIndex];

        params.push({
          marker: '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:#FFA500; margin-right:5px;"></span>',
          seriesName: translate('YoY Growth'),
          value: isNaN(growth) ? 'N/A' : growth.toFixed(2) + '%'
        });

        const borderColor = isDarkTheme.value ? colors.report.dark_cyan_blue_16 : colors.report.light_gray_blue;

        params.forEach(function (param, index) {
          const isGrowth = index === params.length - 1;

          const value = isGrowth ? param.value : CurrencyFormatter.formatScaled(param.value);

          if (isGrowth) {
            tooltipContent += `<div style="border-top: 1px solid ${borderColor}; margin-top: 5px; padding-top: 5px;">`;
          } else {
            tooltipContent += `<div>`;
          }

          tooltipContent += `
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
        /* translators: %s - currency sign */
        name: translate("Volume (%s)", CurrencyFormatter.currencySign),
        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#696778",
          fontSize: 12,
          formatter: (value) => {
            return CurrencyFormatter.formatScaledChart(value);
          },
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
        name: "YoY Growth (%)",
        axisLabel: {
          color: isDarkTheme.value ? "#ffffff" : "#696778",
          fontSize: 12,
          formatter: "{value}%",
        },
        splitLine: {
          show: false,
        },
        min: Math.min(...growthData.value.filter(v => !isNaN(v)), 0) - 5, // Ensure valid min
        max: Math.max(...growthData.value.filter(v => !isNaN(v)), 0) + 5, // Ensure valid max
      },
    ],
    series: seriesData.value,
  };

  try {
    chartInstance.setOption(option, {notMerge: true, replaceMerge: ["series"]});
  } catch (e) {
    error.value = `Error updating chart: ${e.message}`;
    console.error(error.value);
  }
};

// Method to create a series object
const createSeries = (name, type, data, color, yAxisIndex = 0) => ({
  name,
  type,
  barMaxWidth: props.barMaxWidth,
  areaStyle: type === "line" ? null : undefined,
  data,
  smooth: type === "line",
  color,
  lineStyle: type === "line" ? {width: 2} : undefined,
  yAxisIndex,
  emphasis: {
    scale: 2,
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

// Watch for changes in data or chartType
watch([() => props.data, chartType], () => {
  nextTick(updateChart);
}, {deep: true});

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
  <div class="fct-gross-volume-chart-wrap">
    <Card.Container id="chartContainer" ref="cardRef">
      <Card.Header :title="translate(title)" title_size="small" border_bottom>
        <template #action>
<!--          <div class="fct-btn-group sm">-->
<!--            <ChartTab-->
<!--                :activeType="chartType"-->
<!--                :types="{ bar: 'BarChart', line: 'LineChart' }"-->
<!--                @change="toggleChartType"-->
<!--            />-->
<!--            <Screenshot :targetRef="chartRef"/>-->
<!--          </div>-->
        </template>
      </Card.Header>
      <Card.Body class="p-0">
        <div v-if="error" class="error-message" style="color: red; padding: 10px;">
          {{ error }}
        </div>
        <div
            v-if="!isEmpty"
            class="fct-chart-wrap fct-gross-volume-chart"
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
.fct-gross-volume-chart-wrap {
  width: 100%;
}

.fct-gross-volume-chart {
  width: 100%;
  height: 100%;
}

.fct-btn-group {
  display: flex;
  align-items: center;
  gap: 10px;
}
</style>
