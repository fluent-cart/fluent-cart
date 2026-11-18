<template>
  <div :class="`fct-report-average-order-${variant}-wrap`">
    <!-- chartContainer: the header-actions gap and the mobile header stacking (chart.scss, responsive.scss) -->
    <Card.Container :id="variant === 'gross' ? 'chartContainer' : undefined">
      <Card.Header border_bottom title_size="small">
        <template #title>
          <div class="flex items-center gap-2">
            <h2 class="fct-card-header-title is-small">{{ config.title }}</h2>
            <fct-tooltip
              :content="config.help"
              placement="top"
              :trigger="['hover', 'focus']">
              <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')"><DynamicIcon name="InformationFill" class="text-gray-500 w-4 h-4" aria-hidden="true"/></button>
            </fct-tooltip>
          </div>
        </template>
        <template #action v-if="!isEmpty">
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
        <fct-skeleton v-if="dataLoader" animated :rows="4" class="px-5 pt-5"/>

        <template v-else>
          <div v-if="!isEmpty" :class="`fct-report-average-order-${variant}-chart`" ref="chartRef"></div>

          <Empty
              v-else
              icon="Empty/ListView"
              :has-dark="true"
              :text="translate('Currently there is no data!')"
              class="fct-report-empty-state"
          />

          <div v-if="!isEmpty" class="chart-action-wrap">
            <div class="chart-change-wrap">
              <div class="chart-change">
                <DynamicIcon name="ArrowUp" class="arrow-up" />
                <span class="text">{{ config.title }}</span>
              </div>
              <div class="chart-change">
                <span class="text">{{ $t("Timeline") }}</span>
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
import { ref, onMounted, nextTick, computed, watch, onUnmounted } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";
import {chartAxisPointer, chartTooltipAmount, chartTooltipPosition} from "@/utils/Utils";
import {
  makeXAxisLabels,
  getEmphasisColor,
  getXAxisConfig,
  axisDayLabel,
} from '../Utils/decorator';
import orderReportModel from "@/Models/Reports/OrderReportModel";

// One chart for the Orders page's two averages: net order value (money) and items per order (a count)
const props = defineProps({
  variant: {
    type: String,
    required: true,
    validator: (value) => ['gross', 'items'].includes(value),
  },
  chartData: {
    type: Array,
    required: true,
  },
  isEmpty: {
    type: Boolean,
    required: false,
  },
});

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

const config = computed(() => props.variant === 'gross'
  ? {
    title: translate('Average Order Value (Net)'),
    help: translate('OrderRpt_Net_Sales'),
    field: 'average_net',
    money: true,
    color: isDarkTheme.value ? colors.blue_dark_mode : colors.royal_blue,
    axisName: '',
  }
  : {
    title: translate('Average Order Items'),
    help: translate('Average Order Items grouped by date period.'),
    field: 'average_order_items_count',
    money: false,
    color: isDarkTheme.value ? colors.purple_dark_mode : colors.purple,
    axisName: 'Order Items',
  });

const chartRef = ref(null);
let chartInstance = null;
const zoomIsActive = ref(false);

const dataLoader = computed(() => {
  return orderReportModel.data.isBusy.orderChartData;
});

// Money values arrive as decimals; counts show as they are
const formatValue = (value) => config.value.money ? chartTooltipAmount(value * 100) : value;

const seriesData = computed(() => [
  {
    name: config.value.title,
    type: 'line',
    barMaxWidth: 30,
    data: Array.isArray(props.chartData) ? props.chartData.map((item) => item[config.value.field]) : [],
    smooth: false,
    color: config.value.color,
    symbol: "circle",
    showSymbol: true,
    symbolSize: 8,
    itemStyle: {
      color: config.value.color,
      borderRadius: [4, 4, 0, 0]
    },
    yAxisIndex: 0,
    emphasis: {
      scale: 2,
      itemStyle: {
        color: getEmphasisColor(config.value.color),
      },
      lineStyle: {
        color: config.value.color,
      },
      areaStyle: {
        opacity: .3,
        color: config.value.color,
      },
    },
    animation: true,
    animationEasing: "cubicOut",
    animationDuration: 800,
    animationDelay: 0,
    barGap: '-100%',
    legendHoverLink: false,
  },
]);

const xAxisConfig = computed(() => {
  return getXAxisConfig(props.chartData.length);
});

const handleResize = () => {
  if (chartInstance) {
    chartInstance.resize();
  }
};

const updateChart = () => {
  if (!chartInstance) return;

  const labels = makeXAxisLabels(props.chartData);
  const axisTextColor = isDarkTheme.value ? "#ffffff" : (config.value.money ? "#000000" : "#696778");
  const option = {
    title: {
      text: "",
      left: "center",
    },
    tooltip: {
      trigger: "axis",
      backgroundColor: isDarkTheme.value ? "#253241" : "#ffffff",
      borderColor: isDarkTheme.value ? "#2C3C4E" : "#D6DAE1",
      borderWidth: 1,
      textStyle: {
        color: isDarkTheme.value ? "#ffffff" : "#565865",
      },
      axisPointer: chartAxisPointer(isDarkTheme.value, colors.dark_cyan_blue_16, colors.light_gray_cyan_blue),
      confine: true,
      position: chartTooltipPosition,
      valueFormatter: formatValue,
    },
    grid: {
      show: false,
      left: "10%",
      right: "10%",
      bottom: "15%",
    },
    xAxis: {
      type: "category",
      data: labels,
      axisLabel: {
        formatter: axisDayLabel,
        color: axisTextColor,
        fontSize: 12,
        interval: xAxisConfig.value.interval,
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
      name: config.value.axisName,
      axisLabel: {
        formatter: formatValue,
        color: axisTextColor,
        fontSize: 12,
      },
      splitLine: {
        show: true,
        lineStyle: {
          color: isDarkTheme.value ? "#253241" : "#D6DAE1",
          type: "dashed",
        },
      },
      min: 0,
      splitNumber: 5,
    },
    dataZoom: zoomIsActive.value
      ? [
          {
            type: "slider",
            show: true,
            xAxisIndex: [0],
            start: 0,
            end: 100,
          },
          {
            type: "inside",
            xAxisIndex: [0],
            start: 0,
            end: 100,
          },
        ]
      : [],
    series: seriesData.value,
  };

  chartInstance.setOption(option);
};

const initChart = () => {
  nextTick(() => {
    // Dispose here: two calls in one tick would both init the same element
    if (chartInstance) {
      chartInstance.dispose();
      chartInstance = null;
    }

    if (chartRef.value) {
      chartInstance = echarts.init(chartRef.value);
      updateChart();
    }
  });
};

const handleZoomChart = () => {
  zoomIsActive.value = !zoomIsActive.value;
  if (zoomIsActive.value) {
    chartInstance.setOption({
      dataZoom: [
        {
          type: "slider",
          show: true,
          xAxisIndex: [0],
          start: 0,
          end: 100,
        },
        {
          type: "inside",
          xAxisIndex: [0],
          start: 0,
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

watch(
  () => props.chartData,
  () => {
    nextTick(() => updateChart());
  }
);

watch(() => props.isEmpty, (value) => {
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

// A currency switch reformats the money axis
const onCurrencyChanged = () => {
  updateChart();
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange, false);
  window.addEventListener("fluentCartCurrencyChange", onCurrencyChanged);
  window.addEventListener("resize", handleResize);

  nextTick(() => initChart());
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
  window.removeEventListener("fluentCartCurrencyChange", onCurrencyChanged, false);
  window.removeEventListener("resize", handleResize);
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
});
</script>
