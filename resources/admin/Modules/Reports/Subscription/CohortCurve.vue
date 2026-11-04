<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { chartAxisPointer, chartTooltipPosition } from "@/utils/Utils";

// The average cohort over time: the share of customers (or MRR) left after each month or year
const props = defineProps({
  averages: {type: Array, default: () => []},
  metric: {type: String, default: "subscribers"},
  groupBy: {type: String, default: "month"},
  loading: {type: Boolean, default: false},
});

const colors = Theme.colors.report;
const chartRef = ref(null);
let chart = null;

const label = (offset) => {
  if (props.groupBy === "year") {
    /* translators: %1$s - number of years since the cohort first paid */
    return translate("Year %1$s", offset);
  }
  /* translators: %1$s - number of months since the cohort first paid */
  return translate("Month %1$s", offset);
};
const points = computed(() => props.averages.map((avg) => ({
  offset: avg.offset,
  value: Number(props.metric === "mrr" ? avg.weighted_avg_mrr : avg.weighted_avg_count),
})));

const render = () => {
  if (!chartRef.value || props.loading || !points.value.length) {
    return;
  }
  // The chart element is re-created after each load; a chart bound to the old one would stay blank
  if (chart && chart.getDom() !== chartRef.value) {
    chart.dispose();
    chart = null;
  }
  if (!chart) {
    chart = echarts.init(chartRef.value);
  }
  const dark = Theme.isDark();
  const textColor = dark ? "#ffffff" : "#565865";
  const values = points.value.map((point) => point.value);
  chart.setOption({
    grid: {left: 16, right: 24, bottom: 8, top: 24, containLabel: true},
    tooltip: {
      trigger: "axis", confine: true, position: chartTooltipPosition,
      axisPointer: chartAxisPointer(dark, colors.dark_cyan_blue_16, colors.light_gray_blue),
      formatter: (params) => `${params[0].name}<div>${params[0].marker} ${formatNumber(params[0].value)}%</div>`,
    },
    xAxis: {type: "category", data: points.value.map((point) => label(point.offset)), axisLabel: {color: textColor}},
    // Net MRR retention can pass 100% when customers upgrade
    yAxis: {type: "value", min: 0, max: Math.max(100, Math.ceil(Math.max(...values) / 10) * 10),
      axisLabel: {color: textColor, formatter: "{value}%"}, splitLine: {lineStyle: {type: "dashed", color: dark ? "#253241" : "#D6DAE1"}}},
    series: [{type: "line", data: values, smooth: false, symbolSize: 6, areaStyle: {opacity: 0.08},
      color: dark ? colors.blue_dark_mode : colors.royal_blue}],
  }, {notMerge: true});
};

watch(() => [points.value, props.loading], () => nextTick(render), {deep: true});
const resize = () => chart && chart.resize();
onMounted(() => {
  nextTick(render);
  window.addEventListener("resize", resize);
});
onUnmounted(() => {
  window.removeEventListener("resize", resize);
  if (chart) {
    chart.dispose();
    chart = null;
  }
});
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header title_size="small" border_bottom>
      <template #title>
        <div class="flex items-center gap-2">
          <h4 class="fct-card-header-title is-small">{{ translate("Retention curve") }}</h4>
          <el-tooltip popper-class="fct-tooltip" placement="top"
                      :content="metric === 'mrr'
                        ? translate('SubRpt_Average_Cohort_Share')
                        : translate('SubRpt_Average_Cohort')">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div>
      </template>
    </Card.Header>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="5" />
      <div v-else-if="!points.length" class="py-10 text-center text-sm text-system-light dark:text-gray-400">
        {{ translate("No cohort has reached its first period yet") }}
      </div>
      <div v-else ref="chartRef" class="w-full h-[300px]"></div>
    </Card.Body>
  </Card.Container>
</template>
