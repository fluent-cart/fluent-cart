<script setup>
import * as echarts from 'echarts';
import {onMounted, onBeforeUnmount, ref, watch, computed} from 'vue';

const props = defineProps({
  option: { type: Object, required: true },
  height: { type: String, default: '400px' },
  width: { type: String, default: '100%' },
});

const chartRef = ref(null);
let chartInstance = null;

const initChart = () => {
  if (!chartRef.value) return;

  // Initialize ECharts
  chartInstance = echarts.init(chartRef.value);
  console.log(props.option)
  chartInstance.setOption(props.option);
};

watch(() => props.option, (newVal) => {
  if (chartInstance && newVal) {
    chartInstance.setOption(newVal, true);
  }
}, {deep: true});


const normalizeSize = (val, fallback) => {
  if (val === undefined || val === null || val === '') return fallback;

  // Convert numeric-like strings (e.g. "400") to numbers
  const num = Number(val);
  if (!isNaN(num)) {
    return `${num}px`;
  }

  // If it's not a number (e.g. "100%", "50vh"), return as-is
  return val;
};


// ✅ computed getters
const chartHeight = computed(() => normalizeSize(props.height, '400px'));
const chartWidth = computed(() => normalizeSize(props.width, '100%'));

onMounted(() => {
  initChart();
  window.addEventListener('resize', () => chartInstance?.resize());
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', () => chartInstance?.resize());
  chartInstance?.dispose();
});

</script>

<template>
  <div
      ref="chartRef"
      :style="{
      width: chartWidth,
      height: chartHeight
    }"
  ></div>
</template>
