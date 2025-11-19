<template>
  <Card.Container>
    <Card.Header :title="translate('Sales Growth')" border_bottom>
      <template #action>
        <div class="flex gap-1">
          <el-date-picker
              v-model="dateRange"
              range-separator="-"
              :start-placeholder="translate('Start From')"
              :end-placeholder="translate('End To')"
              type="daterange"
              :placeholder="translate('Start From')"
              @change="onDateChange"
              :clearable="false"
          />
        </div>
      </template>
    </Card.Header>
    <Card.Body>


      <div v-loading="loadingChartData">
        <div class="chart-wrap grid place-items-center">
          <Empty v-if="!showChart" icon="Empty/Chart" :text="$t('No Data Available')"/>
          <div ref="chartRef" v-if="showChart" class="echart"></div>
        </div>
      </div>

      <div class="chart-action-wrap" v-if="showChart && false">
        <div class="chart-legend-wrap">
          <div v-for="(value, index) of chartYears" class="chart-legend" @click="toggleChartDataset(index)"
               :key="index">
            <span class="marker" :style="{ '--marker-bg': colors[index] }"></span>
            <span class="text" :class="{ 'line-through': !value.showing }">Year {{ value.year }}</span>
          </div>
        </div>

        <div class="chart-change-wrap">
          <div class="chart-change">
            <svg width="9" height="11" viewBox="0 0 9 11" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M4.6087 10.5L4.6087 1.46395" stroke="currentColor" stroke-linecap="round"
                    stroke-linejoin="round"/>
              <path d="M1.00004 4.05762L4.55764 0.500019L8.11523 4.05762" stroke="currentColor" stroke-linecap="round"
                    stroke-linejoin="round"/>
            </svg>
            <span class="text">{{ $t('Sale') }}</span>
          </div>

          <div class="chart-change">
            <svg class="rotate-90" width="9" height="11" viewBox="0 0 9 11" fill="none"
                 xmlns="http://www.w3.org/2000/svg">
              <path d="M4.6087 10.5L4.6087 1.46395" stroke="currentColor" stroke-linecap="round"
                    stroke-linejoin="round"/>
              <path d="M1.00004 4.05762L4.55764 0.500019L8.11523 4.05762" stroke="currentColor" stroke-linecap="round"
                    stroke-linejoin="round"/>
            </svg>
            <span class="text">{{ $t('Timeline') }}</span>
          </div>
        </div>
      </div>
    </Card.Body>
  </Card.Container>
</template>

<script setup>
import {getCurrentInstance, nextTick, onMounted, onUnmounted, ref} from 'vue';
import * as echarts from 'echarts';
import dayjs from 'dayjs';
import palette from 'google-palette';
import * as Card from '@/Bits/Components/Card/Card.js';
import Empty from '@/Bits/Components/Table/Empty.vue';
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";

const selfRef = getCurrentInstance().ctx;
const dateRange = ref([
  dayjs().subtract(1, 'years').startOf('year'),
  dayjs(new Date())
]);

const showChart = ref(false);
const colors = ref();
const loadingChartData = ref(true);
const chartRef = ref(null);
const chartYears = ref([]);
const axisColors = {dark: '#2c3c4e', light: '#d6dae1'};
const axisLabelColors = {dark: '#98A2B3', light: '#696778'};
let chartInstance = null;

const initChart = () => {
  if (chartInstance != null) {
    return;
  }

  chartInstance = echarts.init(chartRef.value, null, {
    renderer: 'svg'
  });
  chartInstance.setOption({
    xAxis: {
      type: 'category',
      data: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
      axisLine: {lineStyle: {color: axisLabelColors[Theme.isDark()?'dark': 'light']}},
      axisLabel: {color: axisLabelColors[Theme.isDark()?'dark': 'light']},
    },
    yAxis: {
      type: 'value',
      splitLine: {lineStyle: {color: axisColors[Theme.isDark()?'dark': 'light']}},
      axisLabel: {color: axisLabelColors[Theme.isDark()?'dark': 'light']},
    },
    series: [],
    legend: {
      show: false
    },
  });
};

let salesData = {};
const updateChart = () => {
  let visibility = {};
  chartYears.value.forEach((value, index) => {
    visibility[value.year] = value.showing;
  })

  let series = Object.keys(salesData)
      .map((key, index) => ({
        name: key,
        type: 'line',
        data: mapChartValuesAsArray(salesData[key]),
        itemStyle: {
          color: colors.value[index],
          borderRadius: 5,
        },
        barMaxWidth: 40,
      }));

  console.log(series);

  //chartInstance.setOption({series});


};

const loadChartData = (startDate, endDate) => {
  loadingChartData.value = true;

  selfRef.$get('reports/sales-growth', {
    start_date: dayjs(startDate).format('YYYY-MM-DD HH:mm:ss'),
    end_date: dayjs(endDate).format('YYYY-MM-DD HH:mm:ss'),
  })
      .then((response) => {
        showChart.value = Object.keys(response.sales_data).length > 1 ;
        salesData = response.sales_data;
        generateColorPallets(Object.keys(salesData).length);
        chartYears.value = Object.keys(salesData).map((key) => ({year: key, showing: true}));


        setTimeout(()=>{
          loadingChartData.value = false;
        },0)
        nextTick(() => {

          if (showChart.value) {
            initChart();
            updateChart();
          }
        })

      });
};

const toggleChartDataset = (index) => {
  chartYears.value[index].showing = !chartYears.value[index].showing;
  chartInstance.dispatchAction({
    type: "legendToggleSelect",
    name: 2024
  });
};

const mapChartValuesAsArray = (data) => {
  const defaultData = {
    January: 0, February: 0, March: 0, April: 0, May: 0, June: 0,
    July: 0, August: 0, September: 0, October: 0, November: 0, December: 0,
  };
  return Object.values({...defaultData, ...data});
};

const generateColorPallets = (count) => {
  colors.value = palette('tol', count, 1).map((hex) => `#${hex}`);
};

const onDateChange = () => {
  const [start, end] = dateRange.value;
  if (start > end) {
    this.handleMessage('Oops, From date should be earlier than To date.', 'error', 'invalid-date');
  } else {
    loadChartData(start, end);
  }
};

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", onThemeChanged, false);
});
const onThemeChanged = (event)=>{
  const theme = event.detail.theme;
  chartInstance.setOption({
    xAxis: {
      axisLabel: {color: axisLabelColors[theme]},
    },
    yAxis: {
      splitLine: {lineStyle: {color: axisColors[theme]}},
      axisLabel: {color: axisLabelColors[theme]},
    },
  });
}

onMounted(() => {
  loadChartData(dateRange.value[0], dateRange.value[1]);
  window.addEventListener('onFluentCartThemeChange', onThemeChanged);
});



</script>

<style scoped>
.echart {
  width: 100%;
  height: 400px;
}
</style>
