<template>
  <div class="fct-report-item-count-distribution-wrap">
    <Card.Container id="chartContainer">
      <Card.Header title_size="small" border_bottom>
        <template #title>
          <div class="flex items-center gap-2">
            <h5 class="fct-card-header-title is-small">{{
              translate("Item count distribution")
            }}</h5>
            <el-tooltip
              popper-class="fct-tooltip"
              :content="
                translate(
                  'Grouping the orders by the number of items in each order.'
                )
              "
              placement="top"
            >
              <DynamicIcon name="InformationFill" class="w-4 cursor-pointer" />
            </el-tooltip>
          </div>
        </template>

        <template #action v-if="chartData.length">
          <div class="fct-btn-group sm">
            <IconButton
              tag="button"
              size="x-small"
              :title="$t('Zoom Chart')"
              @click="handleZoomChart"
              :class="zoomIsActive ? 'primary' : ''"
            >
              <DynamicIcon name="SearchAdd" />
            </IconButton>

            <Screenshot :targetRef="chartRef" size="x-small" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="px-0 pt-0">
        <el-skeleton v-if="dataLoader" animated :rows="4" class="px-5 pb-5"/>

        <template v-else>
          <div
              v-if="!isEmpty"
              class="fct-report-item-count-distribution-chart"
              ref="chartRef"
          ></div>

          <Empty
              v-else
              icon="Empty/ListView"
              :has-dark="true"
              :text="translate('Currently there is no data!')"
              class="fct-report-empty-state"
          />

          <div  v-if="!isEmpty" class="chart-action-wrap">
            <div class="chart-change-wrap">
              <div class="chart-change">
                <DynamicIcon name="ArrowUp" class="arrow-up" />
                <span class="text">{{ $t("Order Count") }}</span>
              </div>
              <div class="chart-change">
                <span class="text">{{ $t("Item Count") }}</span>
                <DynamicIcon name="ArrowRight" class="arrow-right" />
              </div>
            </div>
          </div>
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick, watch, computed } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
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
import orderReportModel from "@/Models/Reports/OrderReportModel";

// Define props
const props = defineProps({
  chartData: {
    type: Array,
    required: true,
  },
  chartRef: {},
});

const chartRef = ref(null);
const chartType = ref(
  props.chartData.length <= 1 ? "bar" : 
  ChartTypeFilter.getChartType("order", "item_count_distribtion_chart") || "bar"
);
const zoomIsActive = ref(false);

// Computed property for chart labels
const chartLabels = computed(() => {
  const dayLabels = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  const sortedData = [...props.chartData].sort((a, b) => a.day - b.day);
  return sortedData.map((item) => dayLabels[item.day - 1]);
});

const colors = Theme.colors;
const isDarkTheme = ref(Theme.isDark());
let chartInstance = null;

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
    chartInstance.setOption(
      {
        dataZoom: [],
      },
      { replaceMerge: ["dataZoom"] }
    );
  }
};
const handleResize = () => {
  if (chartInstance) {
    chartInstance.resize();
  }
};

const primaryColor = computed(() =>
  isDarkTheme.value ? colors.blue['500'] + '52' : colors.blue['300']
);

// Computed property for series data
const seriesData = computed(() => {
  return [
    {
      name: translate("Orders"),
      type: 'bar',
      barMaxWidth: 30,
      data: props.chartData.map((item) => item.order_count),
      smooth: false,
      color: primaryColor.value,
      symbol: "circle",
      showSymbol: true,
      symbolSize: 8,
      itemStyle: {
        color: primaryColor.value,
        borderRadius: [4, 4, 0, 0]
      },
      yAxisIndex: 0,
      emphasis: {
        scale: 2,
        itemStyle: {
          color: isDarkTheme.value ? colors.blue['800'] : colors.blue['400'],
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
      barGap: '-100%',
      legendHoverLink: false,
    },
  ];
});

// Computed property for chart options
const chartOptions = computed(() => ({
  title: {
    text: props.chartTitle,
    left: "center",
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
  },
  grid: {
    show: false,
    left: "10%",
    right: "10%",
    bottom: "15%",
  },
  xAxis: {
    type: "category",
    data: props.chartData.map((item) => item.item_count),
    axisLabel: {
      color: isDarkTheme.value ? "#FFFFFF" : "#696778",
      fontSize: 12,
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
    name: translate("Orders"),
    axisLabel: {
      formatter: "{value}",
      color: isDarkTheme.value ? "#FFFFFF" : "#696778",
      fontSize: 12,
    },
    min: 0,
    splitLine: {
      show: true,
      lineStyle: {
        color: isDarkTheme.value ? "#253241" : "#D6DAE1",
        type: "dashed",
      },
    },
  },
  dataZoom: [],
  series: seriesData.value,
}));

const isEmpty = computed(() => {
  return Object.values(props.chartData).every(item => item.order_count === 0);
});

const dataLoader = computed(() => {
  return orderReportModel.data.isBusy.orderByDayAndHour;
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
      chartInstance.setOption(chartOptions.value);
      window.addEventListener("resize", handleResize);
    }
  });
};

const updateChart = () => {
  if (chartInstance) {
    chartInstance.setOption(chartOptions.value);
  }
}

const toggleChartType = (type) => {
  if (chartType.value !== type) {
    zoomIsActive.value = false;
    chartType.value = type;
    ChartTypeFilter.onChange("order", "item_count_distribution_chart", type);
    if (chartInstance) {
      chartInstance.setOption(chartOptions.value, true);
    }
  }
};

// Watch for changes in chart data to update the chart
watch(
  () => props.chartData,
  () => {
    if (chartInstance) {
      chartInstance.setOption(chartOptions.value);
    } else {
      nextTick(() => {
        initChart();
      });
    }
  }
);

watch(isEmpty, (value) => {
  if (!value) initChart();
});

watch(dataLoader, (value) => {
  if (!value) initChart();
});

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  
  nextTick(() => {
    updateChart();
  });
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange, false);

  nextTick(() => {
    initChart();
  });
});

onUnmounted(() => {
  if (chartInstance) {
    chartInstance.dispose();
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
    window.removeEventListener("resize", handleResize);
  }
});
</script>
