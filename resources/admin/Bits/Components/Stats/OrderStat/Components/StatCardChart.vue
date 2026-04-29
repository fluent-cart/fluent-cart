<template>
  <Line ref="chart" class="w-full" :data="chartData" :options="chartOptions"/>
</template>

<script setup>
import {computed} from 'vue'

const props = defineProps({
  start: [Number, String],
  end: [Number, String],
  percentage: [Number, String],
})
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js'
import {Line} from 'vue-chartjs'
import {ref} from "vue";


ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
)

const upColor = '#22c55e'
const downColor = '#e55353'

const getArcColor = computed(() => {
  return props.percentage > 0 ? upColor : downColor
})

const getChartData = computed(() => {

  return [props.start, props.end];
  //return props.percentage > 0 ? [props.start,props.end]:[props.end,props.start]
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
   bezierCurve: true,
  scales: {
    x: {
      display: false,
    },
    y: {
      display: false,
    },
  },

  plugins: {
    legend: {
      display: false,
    }
  },

}

const chartData = ref({
  labels: ['January', 'February'],
  datasets: [
    {
      label: 'Data One',
      backgroundColor: (ctx) => {
        const canvas = ctx.chart.ctx;
        const gradient = canvas.createLinearGradient(0, -160, 0, 120);
        gradient.addColorStop(0, getArcColor.value);
        gradient.addColorStop(0.8, 'white');
        return gradient;
      },
      data: getChartData,
      fill: true,
      tension: 0.5,
      pointStyle: false,
      borderColor: getArcColor.value,
      color: getArcColor.value,
    },
  ]
})

</script>