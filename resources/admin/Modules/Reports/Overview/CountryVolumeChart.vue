<template>
  <div class="fct-country-volume-chart-wrap">
    <Card.Container id="chartContainer" ref="cardRef">
      <Card.Header :title="title" title_size="small" border_bottom>
        <template #action>
          <div class="fct-btn-group sm">
            <Screenshot :targetRef="chartRef"/>
          </div>
        </template>
      </Card.Header>
      <Card.Body class="p-0">
        <div v-if="!isEmpty" class="fct-chart-wrap fct-country-volume-chart" ref="chartRef" style="height: 400px;"></div>

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

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import * as echarts from 'echarts';
import * as Card from "@/Bits/Components/Card/Card.js";
import Screenshot from "@/Bits/Components/Icons/Screenshot.vue";
import Theme from '@/utils/Theme';
import dayjs from 'dayjs';
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import translate from "@/utils/translator/Translator";
import toCountryName from "@/Modules/Reports/Utils/toCountryName";
import {chartAxisPointer, chartTooltipAmount, chartTooltipPosition} from "@/utils/Utils";
import Empty from "@/Bits/Components/Table/Empty.vue";

// Props
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

// Chart references
const chartRef = ref(null);
const cardRef = ref(null);
let chartInstance = null;

// Chart type state
const chartType = ref('bar');

// Error state
const error = ref('');

const isDarkTheme = ref(Theme.isDark());

// Define colors for countries, ordered by overall volume rank.
// The steps are kept far enough apart that two stacked segments never read as
// the same color.
const countryColors = computed(() => {
  return isDarkTheme.value
    ? ['#8FC0F5', '#4E90E8', '#2E6BC4', '#1E4C90', '#143562']
    : ['#9CC9FA', '#3E9DF6', '#1573D5', '#0B57A3', '#083C73'];
});

// White is unreadable on the lightest steps of the palette, so pick the label
// color from the perceived brightness of the segment it sits on.
const readableLabelColor = (hexColor) => {
  const value = hexColor.replace('#', '');
  const red = parseInt(value.substring(0, 2), 16);
  const green = parseInt(value.substring(2, 4), 16);
  const blue = parseInt(value.substring(4, 6), 16);
  const brightness = (red * 299 + green * 587 + blue * 114) / 1000;

  return brightness > 150 ? '#0B3A66' : '#ffffff';
};

const isEmpty = computed(() => {
  const data = Array.isArray(props.data.by_countries) ? props.data.by_countries : Object.values(props.data.by_countries);

  return !data.length || !data.every(item => !!item);
});

const colors = Theme.colors;

// Computed chart data
const chartDataArray = computed(() => {
  const byMonth = props.data.by_month || {};
  const months = Object.keys(byMonth).sort(); // Sort months: "2024-06" to "2025-05"
  return months.map(month => {
    const countries = byMonth[month];
    const total = Object.values(countries).reduce((sum, value) => sum + value, 0);
    // Sort countries by value (ascending) for stacking (smallest on top)
    const sortedCountries = Object.entries(countries)
        .sort((a, b) => a[1] - b[1]) // Ascending order
        .reduce((obj, [key, value]) => ({ ...obj, [key]: value }), {});
    return {
      label: month, // e.g., "2024-06"
      countries: sortedCountries,
      total
    };
  });
});

// Labels for the x-axis
const labels = computed(() => {
  if (!chartDataArray.value.length) return [];
  return chartDataArray.value.map(item => {
    try {
      const [year, month] = item.label.split("-");
      const date = dayjs(new Date(year, month - 1));
      return date.format('MMM YYYY'); // e.g., "Jun 2024"
    } catch {
      return item.label;
    }
  });
});

// One color per country for the whole period, assigned by overall volume rank.
// Ranking per month (the previous behaviour) let two countries end up with the
// same color, which made their segments indistinguishable.
const countryColorMap = computed(() => {
  const totals = {};

  Object.values(props.data.by_month || {}).forEach(monthData => {
    Object.entries(monthData).forEach(([country, value]) => {
      totals[country] = (totals[country] || 0) + value;
    });
  });

  // The API already ranks the top countries for the whole range; prefer it.
  Object.entries(props.data.by_countries || {}).forEach(([country, value]) => {
    totals[country] = value;
  });

  const palette = countryColors.value;
  const fallback = palette[palette.length - 1];

  return Object.keys(totals)
    .sort((a, b) => totals[b] - totals[a])
    .reduce((map, country, rank) => {
      map[country] = palette[rank] || fallback;
      return map;
    }, {});
});

// Tallest stack in the chart — used to decide whether a segment has the height
// to hold its country label.
const maxStackTotal = computed(() => {
  return chartDataArray.value.reduce((max, item) => Math.max(max, item.total), 0);
});

// A segment thinner than this share of the tallest bar cannot fit a label
// without spilling over the segment below it.
const MIN_LABEL_SHARE = 0.05;

const seriesData = computed(() => {
  const byMonth = props.data.by_month || {};
  const allCountries = new Set();
  
  // Collect all unique countries
  Object.values(byMonth).forEach(monthData => {
    Object.keys(monthData).forEach(country => allCountries.add(country));
  });
  
  const palette = countryColors.value;
  const maxTotal = maxStackTotal.value;

  return Array.from(allCountries).reverse().map(country => {
    const color = countryColorMap.value[country] || palette[palette.length - 1];
    const labelColor = readableLabelColor(color);

    const data = chartDataArray.value.map(item => {
      const value = item.countries[country] || 0;
      const fitsLabel = maxTotal > 0 && (value / maxTotal) >= MIN_LABEL_SHARE;

      return {
        value: value / 100,
        label: {
          show: value > 0 && fitsLabel,
          position: 'inside',
          formatter: country,
          color: labelColor,
          fontSize: 10,
          fontWeight: 'bold',
        },
      };
    });
    
    return {
      name: country,
      type: chartType.value,
      stack: 'total',
      data,
      itemStyle: {
        color: color
      },
      lineStyle: {
        color: color
      },
    };
  });
});

// Initialize chart
const initChart = () => {
  if (!chartRef.value) return;
  chartInstance = echarts.init(chartRef.value);
  const option = {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'axis',
      backgroundColor: isDarkTheme.value ? "#253241" : '#ffffff',
      textStyle: { color: isDarkTheme.value ? colors.gray["25"] : colors.system["mid"] },
      borderColor: isDarkTheme.value ? "#2C3C4E" : "#c0c4ca",
      axisPointer: chartAxisPointer(isDarkTheme.value, colors.report.dark_cyan_blue_16, colors.report.light_gray_cyan_blue, chartType.value),
      borderWidth: 1,
      confine: true,
      position: chartTooltipPosition,
      formatter: params => {
        const color = isDarkTheme.value ? "#ffffff" : "#565865";
        const borderColor = isDarkTheme.value ? colors.report.dark_cyan_blue_16 : colors.report.light_gray_blue;
        const total = chartDataArray.value[params[0].dataIndex].total;

        let tooltipContent = params[0].axisValue;

        params
          .filter(param => param.value > 0)
          .sort((a, b) => b.value - a.value)
          .forEach(param => {
            const value = chartTooltipAmount(param.value * 100);

            tooltipContent += `
          <div>
            ${param.marker}
            <span style="color: ${color};">${toCountryName(param.seriesName)}</span>
            <span style="float: right; margin-left: 20px; color: ${color};">
              ${value}
            </span>
          </div>`;
          });

        tooltipContent += `
          <div style="border-top: 1px solid ${borderColor}; margin-top: 5px; padding-top: 5px;">
            <span style="color: ${color}; font-weight: 600;">${translate('Total')}</span>
            <span style="float: right; margin-left: 20px; color: ${color}; font-weight: 600;">
              ${chartTooltipAmount(total)}
            </span>
          </div>`;

        return tooltipContent;
      }
    },
    xAxis: {
      type: 'category',
      data: labels.value,
      axisLabel: {
        color: isDarkTheme.value ? '#ffffff' : '#696778',
        fontSize: 12
      },
      axisLine: {
        lineStyle: {
          color: isDarkTheme.value ? '#253241' : '#D6DAE1'
        }
      }
    },
    yAxis: {
      type: 'value',
      position: 'left',
      /* translators: %s - currency sign */
      name: translate('Volume (%s)', CurrencyFormatter.currencySign),
      axisLabel: {
        color: isDarkTheme.value ? '#ffffff' : '#696778',
        fontSize: 12,
        formatter: value => CurrencyFormatter.formatScaled(value * 100) // Convert back to cents for formatting
      },
      splitLine: {
        show: true,
        lineStyle: {
          color: isDarkTheme.value ? '#253241' : '#D6DAE1',
          type: 'dashed'
        }
      }
    },
    series: seriesData.value
  };
  chartInstance.setOption(option);
};

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  
  nextTick(() => {
    initChart();
  });
};

// Lifecycle hooks
const handleCurrencyChange = () => {
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
  initChart();
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  window.addEventListener("fluentCartCurrencyChange", handleCurrencyChange);

  nextTick(initChart);
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
  window.removeEventListener("fluentCartCurrencyChange", handleCurrencyChange, false);
});

watch(() => isDarkTheme.value, () => {
  if (chartInstance) {
    chartInstance.dispose();
    initChart();
  }
});

watch(() => props.data, () => {
  if (chartInstance) {
    initChart();
  }
});
</script>

<style scoped>
.fct-country-volume-chart-wrap {
  width: 100%;
}

.fct-chart-wrap {
  width: 100%;
}

.summary-text {
  padding: 10px;
  font-size: 14px;
  color: #666;
}
</style>
