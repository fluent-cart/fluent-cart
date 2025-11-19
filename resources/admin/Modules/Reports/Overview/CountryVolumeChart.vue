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
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import * as echarts from 'echarts';
import * as Card from "@/Bits/Components/Card/Card.js";
import Screenshot from "@/Bits/Components/Icons/Screenshot.vue";
import Theme from '@/utils/Theme';
import dayjs from 'dayjs';
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import translate from "@/utils/translator/Translator";
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

// Define colors for countries
const countryColors = computed(() => {
  return [
    isDarkTheme.value ? '#032647' : '#3e9df6',
    isDarkTheme.value ? '#04315b' : '#1589f4',
    isDarkTheme.value ? '#053c70' : '#0a73d5',
    isDarkTheme.value ? '#064784' : '#0968c1',
    isDarkTheme.value ? '#075298' : '#085dad',
  ];
});

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

const seriesData = computed(() => {
  const byMonth = props.data.by_month || {};
  const months = Object.keys(byMonth).sort();
  const allCountries = new Set();
  
  // Collect all unique countries
  Object.values(byMonth).forEach(monthData => {
    Object.keys(monthData).forEach(country => allCountries.add(country));
  });
  
  return Array.from(allCountries).reverse().map(country => {
    let color;

    const data = chartDataArray.value.map((item, monthIndex) => {
      const monthData = byMonth[months[monthIndex]];
      
      // Get countries sorted by volume for this month (ascending)
      const sortedCountries = Object.entries(monthData)
        .sort((a, b) => b[1] - a[1])
        .map(([country]) => country);
      
      const countryRank = sortedCountries.indexOf(country);
      const value = item.countries[country] || 0;
      
      // Assign color based on rank (0-4 for top 5, or default color)
      const colorIndex = countryRank < 5 ? countryRank : 4;
      
      if (!color && colorIndex >= 0) {
        color = countryColors.value[colorIndex] || countryColors.value[4];
      }
      
      return {
        value: value / 100,
        label: {
          show: value > 0,
          position: 'inside',
          formatter: country,
          color: isDarkTheme.value ? '#fff' : '#fff',
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
      axisPointer: {
        type: 'line',
        lineStyle: {
          type: 'solid',
          width: 2,
          color: isDarkTheme.value ? colors.report.dark_cyan_blue_16 : colors.report.light_gray_cyan_blue,
        }
      },
      borderWidth: 1,
      formatter: params => {
        let tooltipContent = params[0].axisValue;
        const color = isDarkTheme.value ? "#ffffff" : "#565865";
        const total = chartDataArray.value[params[0].dataIndex].total;

        params = params.sort((a, b) => b.value - a.value).filter(param => param.value > 0);

        params.push({
          marker: '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:#FFA500; margin-right:5px;"></span>',
          seriesName: translate('Total'),
          value: total / 100
        });

        const borderColor = isDarkTheme.value ? colors.report.dark_cyan_blue_16 : colors.report.light_gray_blue;

        params.forEach(function (param, index) {
          const isTotal = index === params.length - 1;

          if (!isTotal && param.value === 0) return;

          const value = CurrencyFormatter.scaled(param.value);

          if (isTotal) {
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
onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);

  nextTick(initChart);
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
