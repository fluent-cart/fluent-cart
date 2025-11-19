<template>
  <div style="height: 70px" ref="chartRef"></div>
</template>

<script setup>
import { ref, onMounted, nextTick, watch, computed } from "vue";
import * as echarts from "echarts";
import { monthNames } from "@/Modules/Reports/Utils/monthNames";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

// Define props
const props = defineProps({
  xAxis: {
    type: Array,
    default: () => [],
    required: true,
  },
  yAxis: {
    type: Array,
    default: () => [],
    required: true,
  },
  lineColor: {
    type: String,
    default: "#42b983",
  },
  backgroundGradient: {
    type: Array,
    required: true,
  },
  chartTitle: {
    type: String,
    default: "",
  },
  hasCurrency: {
    type: Boolean,
    default: false,
  },
});

const chartRef = ref(null);

// Method to create a series object
const createSeries = (name, type, data, color) => {
  return {
    name,
    type,
    data,
    smooth: true,
    color,
    showSymbol: true,
    areaStyle: {
      color: new echarts.graphic.LinearGradient(
        0,
        0,
        0,
        1,
        props.backgroundGradient.map((gradient) => ({
          offset: gradient.offset,
          color: gradient.color,
        }))
      ),
    },
  };
};

const seriesData = () => {
  const series = [];
  series.push(createSeries("", "line", props.yAxis, props.lineColor));
  // if(props.yAxis.length===1){
  //   series.push(createSeries("", "bar", props.yAxis, props.lineColor));
  // }
  // else {
  //   series.push(createSeries("", "line", props.yAxis, props.lineColor));
  // }

  return series;
};

const prepareLabel = (item) => {
  const parts = item.split("-");
  if (parts.length === 3) {
    const [year, month, day] = parts;
    return `${monthNames[parseInt(month) - 1]} ${day}, ${year}`;
  } else if (parts.length === 2) {
    const [year, month] = parts;
    return `${monthNames[parseInt(month) - 1]} ${year}`;
  } else if (parts.length === 1) {
    const [year] = parts;
    return `${year}`;
  }
}

const xAxisLabels = computed(() => {
  return props.xAxis.map((item) => prepareLabel(item));
});

let chartInstance = null;

// Initialize the chart
const initChart = () => {
  if (chartRef.value && chartInstance == null) {
    chartInstance = echarts.init(chartRef.value);
    const option = {
      title: {
        show: false,
      },
      tooltip: {
        trigger: "axis",
        valueFormatter: (value) => props.hasCurrency ? `${CurrencyFormatter.scaled(value)}` : value,
      },
      grid: {
        show: false,
        left: "10%",
        right: "10%",
        bottom: "80%",
      },
      xAxis: {
        type: "category",
        data: xAxisLabels.value,
        axisLabel: {
          show: false,
        },
        axisLine: {
          show: false, // Hide the x-axis line
        },
        axisTick: {
          show: false, // Hide the x-axis ticks
        },
      },
      yAxis: {
        type: "value",
        axisLabel: {
          show: false,
        },
        splitLine: {
          show: false,
        },
        axisLine: {
          show: false, // Hide the x-axis line
        },
        axisTick: {
          show: false, // Hide the x-axis ticks
        },
      },
      series: seriesData(),
    };

    chartInstance.setOption(option);
    window.addEventListener("resize", () => {
      chartInstance.resize();
    });
  }
};

const updateYAxisRange = () => {
  // If only one point exists, set the min/max manually
  const isSinglePoint = props.yAxis.length === 1;

  return {
    min: isSinglePoint ? props.yAxis[0] - 10 : "dataMin",
    max: isSinglePoint ? props.yAxis[0] + 10 : "dataMax",
  };
};

// Update the chart on data changes
const updateChart = () => {
  if (chartInstance) {
    chartInstance.setOption({
      xAxis: {
        data: props.xAxis,
      },
      series: seriesData(),
    });
  }
};

// Watch for changes in xAxis or yAxis to update the chart
watch(
  () => [props.xAxis, props.yAxis],
  () => {
    nextTick(() => {
      updateChart();
    });
  }
);

onMounted(() => {
  nextTick(() => {
    initChart();
  });
});
</script>

<style scoped>
.fct-gross-sale-vs-net-revenue-wrap {
  width: 100%;
  height: 150px;
}
</style>
