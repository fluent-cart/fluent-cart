<script setup>
import {
  ref,
  onMounted,
  nextTick,
  computed,
  watch,
  onBeforeUnmount,
} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import orderReportModel from "@/Models/Reports/OrderReportModel";
import Empty from "@/Bits/Components/Table/Empty.vue";


const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  chartRef: {},
});

const chartRef = ref(null);
let chartInstance = null;

const days = [
  "Sunday",
  "Monday",
  "Tuesday",
  "Wednesday",
  "Thursday",
  "Friday",
  "Saturday",
];

const colors = Theme.colors
const isDarkTheme = ref(Theme.isDark());

const heatmapData = computed(() => {
  const data = [];
  props.chartData.forEach((item, hourIndex) => {
    days.forEach((day, dayIndex) => {
      data.push([hourIndex, dayIndex, item[day]]);
    });
  });
  return data;
});

const maxValue = computed(() => {
  return props.chartData.length
      ? Math.max(
          ...props.chartData.flatMap((item) => days.map((day) => item[day] || 0))
      )
      : 0;
});

const heatMapColorRange = computed(() => {
  return isDarkTheme.value
    ? ["#000000", colors.blue['500']]
    : ["#ffffff", colors.blue['600']];
});

const isEmpty = computed(() => {
  return Object.values(props.chartData).every(item => item.order_count === 0);
});

const dataLoader = computed(() => {
  return orderReportModel.data.isBusy.orderByDayAndHour;
});

const initChart = () => {
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }

  nextTick(() => {
    if (chartRef.value) {
      chartInstance = echarts.init(chartRef.value, null, { renderer: "svg" });

      const xData = props.chartData.map((item) => item.hour);
      const yData = days;

      const option = {
        tooltip: {
          position: "top",
          backgroundColor: isDarkTheme.value ? "#253241" : "#ffffff",
          borderColor: isDarkTheme.value ? "#2C3C4E" : "#c0c4ca",
          borderWidth: 1,
          textStyle: {
            color: isDarkTheme.value ? "#ffffff" : "#565865"
          },
          formatter: (param) => {
            const color = isDarkTheme.value ? "#ffffff" : "#000000";

            let content = `<div style="color: ${color};"><div>${ days[param.data[1]] }, ${param.name || '-'}</div>`;

            content += `<div style="color: ${color};">
            ${param.marker}
            <span style="color: ${color};">${param.seriesName}</span>
            <span style="float: right; margin-left: 20px; color: ${color};">
              ${param.data[2]}
            </span>
          </div>`;

            return content + `</div>`;
          },
        },
        grid: {
          height: "70%",
          top: "10%",
        },
        xAxis: {
          type: "category",
          data: xData,
          axisLabel: {
            color: isDarkTheme.value ? "#ffffff" : "#000000",
            fontSize: 12,
          },
          splitArea: {show: true},
        },
        yAxis: {
          type: "category",
          data: yData,
          axisLabel: {
            color: isDarkTheme.value ? "#ffffff" : "#000000",
            fontSize: 12,
          },
          splitArea: {show: true},
        },
        visualMap: {
          min: 0,
          max: maxValue.value,
          calculable: true,
          orient: "horizontal",
          left: "center",
          bottom: "0%",
          inRange: {
            color: heatMapColorRange.value,
          },
        },
        series: [
          {
            name: translate('Orders'),
            type: "heatmap",
            data: heatmapData.value,
            label: {show: true},
            itemStyle: {
              borderColor: "#e0e0e0",
              borderWidth: 1,
            },
            emphasis: {
              itemStyle: {
                shadowBlur: 10,
                shadowColor: "rgba(0, 0, 0, 0.5)",
              },
            },
          },
        ],
      };

      chartInstance.setOption(option);
    }
  });
};

watch([heatmapData, maxValue], () => {
  // if (chartInstance) {
  //   chartInstance.setOption({
  //     visualMap: {max: maxValue.value},
  //     series: [{data: heatmapData.value}],
  //   });
  // }
  nextTick(() => {
    initChart();
  });
});

watch(isEmpty, (value) => {
  if (!value) initChart();
});

watch(dataLoader, (value) => {
  if (!value) initChart();
});

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  
  nextTick(() => {
    initChart();
  });
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  
  nextTick(() => {
    initChart();
    window.addEventListener("resize", () => chartInstance?.resize());
  });
});

onBeforeUnmount(() => {
  if (chartInstance) {
    window.removeEventListener("resize", () => chartInstance?.resize());
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
    chartInstance.dispose();
    chartInstance = null;
  }
});
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header
        :title="translate('Orders by day and hour')"
        title_size="small"
        border_bottom
    />
    <Card.Body class="px-0 pb-0">
      <el-skeleton v-if="dataLoader" animated :rows="4" class="px-3 pb-5"/>

      <template v-else>
        <div v-if="!isEmpty" class="fct-report-average-order-items-chart" ref="chartRef"></div>
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
</template>
