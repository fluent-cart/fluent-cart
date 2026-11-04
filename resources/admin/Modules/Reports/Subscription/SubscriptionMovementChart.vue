<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import { formatNumber } from "../Utils/formatNumber";
import { chartAxisPointer, chartTooltipPosition } from "@/utils/Utils";
import { makeXAxisLabels, axisDayLabel, getXAxisConfig } from "../Utils/decorator";
import useGroupKeyOptions from "../Utils/useGroupKeyOptions";

// New subscriptions above zero, churned below, the net change as a line; by count or by MRR
const props = defineProps({
  rows: {type: Array, default: () => []},
  loading: {type: Boolean, default: false},
  reportFilter: {type: Object, required: true},
});
const emit = defineEmits(["group-change"]);

const colors = Theme.colors.report;
const chartRef = ref(null);
const metric = ref("count");
const selectedGroupKey = ref("default");
const { groupKeys } = useGroupKeyOptions(props.reportFilter, selectedGroupKey);
let chart = null;

const money = (value) => new DOMParser().parseFromString(props.reportFilter.formatAmount(value || 0), "text/html").documentElement.textContent;
const isEmpty = computed(() => !props.rows.some(row => Number(row.new) || Number(row.churned) || Number(row.expansion_mrr) || Number(row.contraction_mrr)));

const series = computed(() => {
  const mrr = metric.value === "mrr";
  const added = props.rows.map(row => Number(mrr ? row.new_mrr : row.new) || 0);
  const lost = props.rows.map(row => -(Number(mrr ? row.churned_mrr : row.churned) || 0));
  // MRR only: plan upgrades and downgrades (no subscription is new or lost by a plan change)
  const upgrades = props.rows.map(row => (mrr ? Number(row.expansion_mrr) || 0 : 0));
  const downgrades = props.rows.map(row => (mrr ? -(Number(row.contraction_mrr) || 0) : 0));
  const list = [
    {name: translate("New"), type: "bar", stack: "movement", color: colors.medium_turquoise, data: added},
    {name: translate("Churned"), type: "bar", stack: "movement", color: colors.coral, data: lost},
  ];
  if (upgrades.some(Boolean) || downgrades.some(Boolean)) {
    list.push(
      {name: translate("Upgrades"), type: "bar", stack: "movement", color: colors.sea_green, data: upgrades},
      {name: translate("Downgrades"), type: "bar", stack: "movement", color: colors.golden_rod, data: downgrades},
    );
  }
  list.push({name: translate("Net"), type: "line", color: Theme.isDark() ? colors.blue_dark_mode : colors.royal_blue, symbolSize: 6,
    data: added.map((value, index) => Math.round((value + lost[index] + upgrades[index] + downgrades[index]) * 100) / 100)});

  return list;
});

const render = () => {
  if (!chartRef.value || props.loading || isEmpty.value) {
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
  const format = (value) => (metric.value === "mrr" ? money(value) : formatNumber(value));
  chart.setOption({
    legend: {show: true, itemGap: 20, itemWidth: 10, itemHeight: 10, textStyle: {color: textColor}},
    grid: {left: 16, right: 16, bottom: 8, top: 48, containLabel: true},
    tooltip: {
      trigger: "axis", confine: true, position: chartTooltipPosition,
      axisPointer: chartAxisPointer(dark, colors.dark_cyan_blue_16, colors.light_gray_blue),
      // Churned and downgrades draw below zero; the tooltip reads every bar as a positive amount
      formatter: (params) => params[0].name + params.map(param => `<div>${param.marker} ${param.seriesName}
        <span style="float: right; margin-left: 20px">${format(param.seriesType === "bar" ? Math.abs(param.value) : param.value)}</span></div>`).join(""),
    },
    xAxis: {type: "category", data: makeXAxisLabels(props.rows),
      axisLabel: {formatter: axisDayLabel, color: textColor, interval: getXAxisConfig(props.rows.length).interval}},
    yAxis: {type: "value", axisLabel: {color: textColor, formatter: (value) => format(value)},
      splitLine: {lineStyle: {type: "dashed", color: dark ? "#253241" : "#D6DAE1"}}},
    series: series.value,
  }, {notMerge: true});
};

const onGroup = (value) => {
  emit("group-change", value);
};

watch(() => [props.rows, props.loading, metric.value], () => nextTick(render), {deep: true});
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
          <h5 class="fct-card-header-title is-small">{{ translate("Subscription movement") }}</h5>
          <el-tooltip popper-class="fct-tooltip" placement="top"
                      :content="translate('SubRpt_New_Subscriptions')">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div>
      </template>
      <template #action>
        <div class="flex items-center gap-3">
          <el-radio-group v-model="metric" size="small">
            <el-radio-button value="count">{{ translate("Subscriptions") }}</el-radio-button>
            <el-radio-button value="mrr">{{ translate("MRR") }}</el-radio-button>
          </el-radio-group>
          <el-select v-model="selectedGroupKey" size="small" class="w-[140px]" :aria-label="translate('Group by')" @change="onGroup">
            <el-option v-for="option in groupKeys" :key="option.value" :value="option.value" :label="option.label" />
          </el-select>
        </div>
      </template>
    </Card.Header>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="6" />
      <div v-else-if="isEmpty" class="py-10 text-center text-sm text-system-light dark:text-gray-400">
        {{ translate("No new or churned subscriptions in this period") }}
      </div>
      <div v-else ref="chartRef" class="w-full h-[360px]"></div>
    </Card.Body>
  </Card.Container>
</template>
