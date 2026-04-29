<template>
  <ReportCard
      class="sales-sources"
      :card-options="{
        heading: translate('Sources'),
      }"
  >
    <template #action>
      <div class="flex items-center gap-2 justify-between" v-if="!isEmpty">
        <el-button-group class="left">
          <el-button size="small" @click="breakdown = 'revenue'" :type="breakdown === 'revenue' ? 'primary' : ''">
            {{ translate('Revenue') }}
          </el-button>
          <el-button size="small" @click="breakdown = 'orders'" :type="breakdown === 'orders' ? 'primary' : ''">
            {{ translate('Orders') }}
          </el-button>
        </el-button-group>

        <div class="fct-btn-group sm">
          <Screenshot :targetRef="chartRef" />
        </div>
      </div>
    </template>

    <el-skeleton v-if="dataLoader" animated :rows="7" />

    <template v-else>
      <div
          v-if="!isEmpty"
          class="fct-report-order-completion-time-chart"
          ref="chartRef"
      ></div>

      <Empty
          v-else
          icon="Empty/ListView"
          :has-dark="true"
          :text="translate('No relevant data to plot the chart')"
      />
    </template>
  </ReportCard>
</template>

<script setup>
import { ref, onMounted, nextTick, computed, watch, onUnmounted } from "vue";
import * as echarts from "echarts";
import ReportCard from "@/Modules/Reports/Components/ReportCard.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";
import Empty from "@/Bits/Components/Table/Empty.vue";
import defaultReport from "@/Models/Reports/DefaultReportModel";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

// Define props
const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
});

const colors = Theme.colors;
const chartRef = ref(null);
const isDarkTheme = ref(Theme.isDark());
const breakdown = ref('revenue');

// Track which legend items are selected/hidden
const legendStates = ref({});

// Generate colors for pie slices
const getColorForIndex = (index) => {
  const defaultColors = [
    "#F6B51E", 
    "#855EF8",
    "#47C2FF"
  ];
  const darkColors = [
    "#E6A819", 
    "#693EE0",
    "#35ADE9"
  ];

  const themeColors = isDarkTheme.value ? darkColors : defaultColors;

  return themeColors[index];
};

// Computed property for custom legend data
const customLegendData = computed(() => {
    if (typeof props.data.onetime_count === undefined) return [];

    // if all the keys values are zero, return empty array
    if (
        (props.data.onetime_count || 0) === 0 &&
        (props.data.renewal_count || 0) === 0 &&
        (props.data.subscription_count || 0) === 0
    ) {
        return [];
    }

    const relevantKeys = {
        onetime : translate('Onetime Payments'),
        renewal : translate('Renewals'),
        subscription : translate('Subscriptions'),
    };

    const totalOrders = props.data.order_count;

    const legendData = [];

    Object.keys(relevantKeys).forEach((key, index) => {

        legendData.push({
            name: relevantKeys[key],
            value: props.data[key + '_count'] || 0,
            count: props.data[key + '_count'] || 0,
            gross : props.data[key + '_gross'] || 0,
            net : props.data[key + '_net'] || 0,
            percentage: totalOrders ? ((props.data[key + '_count'] / totalOrders) * 100).toFixed(2) : 0,
            color: getColorForIndex(index),
            visible: legendStates.value[relevantKeys[key]] !== false
        });
    });

    return legendData;
});

const isEmpty = computed(() => {
  return !customLegendData.value.length;
});

const dataLoader = computed(() => {
  return defaultReport.data.isBusy.salesReport;
});

// Computed property for series data based on visibility
const visiblePieData = computed(() => {
  return customLegendData.value
      .filter(item => legendStates.value[item.name] !== false)
      .map((item, index) => ({
        value: breakdown.value === 'revenue' ? item.net : item.count,
        count: item.count,
        name: item.name,
        gross : item.gross,
        net : item.net,
        itemStyle: {
          color: getColorForIndex(customLegendData.value.findIndex(originalItem => originalItem.name === item.name))
        }
      }));
});

let chartInstance = null;

// Initialize the chart
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
  });
};

// Update the chart with new options
const updateChart = () => {
  if (!chartInstance) return;

  const option = {
    title: {
      text: props.chartTitle ?? "",
      left: "center",
    },
    // Disable default legend since we're using custom
    legend: {
      show: false
    },
    tooltip: {
      trigger: "item",
      formatterr: "{b}: {c} ({d}%)",
      formatter: function (params) {
        const color = isDarkTheme.value ? "#ffffff" : "#565865";

        return `
          <div style="font-weight: bold; margin-bottom: 4px;">
            ${params.marker} ${params.name} (${params.percent}%)
          </div>
          <div>
            <span style="color: ${color};">${translate('Orders')}</span>
            <span style="font-weight: bold; float: right; margin-left: 15px; color: ${color};">
              ${formatNumber(params.data.count)}
            </span>
          </div>
          <div>
            <span style="color: ${color};">${translate('Gross Sales')}</span>
            <span style="font-weight: bold; float: right; margin-left: 15px; color: ${color};">
              ${CurrencyFormatter.scaled(params.data.gross)}
            </span>
          </div>
          <div>
            <span style="color: ${color};">${translate('Revenue')}</span>
            <span style="font-weight: bold; float: right; margin-left: 15px; color: ${color};">
              ${CurrencyFormatter.scaled(params.data.net)}
            </span>
          </div>
        `;
      },
      borderRadius: 8,
      backgroundColor: isDarkTheme.value ? "#38485a" : '#ffffff',
      textStyle: { color: isDarkTheme.value ? colors.gray["25"] : colors.system["mid"] },
      borderColor: isDarkTheme.value ? "#4a5a6b" : "#c0c4ca",
      borderWidth: 1,
    },
    series: [
      {
        type: "pie",
        radius: '70%',
        data: visiblePieData.value,
        label: {
          show: true,
          color: isDarkTheme.value ? colors.gray["25"] : colors.system["dark"]
        },
        emphasis: {
          itemStyle: {
            shadowBlur: 10,
            shadowOffsetX: 0,
            shadowColor: 'rgba(0, 0, 0, 0.5)'
          }
        },
        itemStyle: {
          emphasis: {
            shadowBlur: 10,
            shadowOffsetX: 0,
            shadowColor: "rgba(0, 0, 0, 0.5)",
          },
        },
      },
    ],
  };

  chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

watch(
    [() => customLegendData.value],
    () => {
      nextTick(updateChart);
    },
    { deep: true }
);

watch(isEmpty, (value) => {
  if (!value) initChart();
});

watch(dataLoader, (value) => {
  if (!value) initChart();
});

watch(breakdown, () => {
  updateChart();
});

const onThemeChanged = (event) => {
  isDarkTheme.value = Theme.isDark();
  
  updateChart();
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", onThemeChanged);
  nextTick(initChart);
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", onThemeChanged);
});
</script>
