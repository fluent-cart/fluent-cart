<script setup>
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import {prepareLabel, axisDayLabel} from "@/Modules/Reports/Utils/decorator";
import {formatNumber} from "../Utils/formatNumber";
import {channelLabels} from "./channelLabels";
import ChannelRulesPreview from "./ChannelRulesPreview.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";

const props = defineProps({
  reportFilter: {type: Object, required: true},
});

const data = ref({coverage: {}, channels: [], chart: []});
const loading = ref(true);
const chartRef = ref(null);
let chart = null;
let latest = 0;

const fetchData = (filters) => {
  const request = ++latest;
  loading.value = true;
  Rest.get('reports/source-channels', {params: (filters || props.reportFilter.applicableFilters).params})
    .then(response => request === latest && (data.value = response))
    .catch(error => request === latest && Notify.error(error))
    .finally(() => request === latest && (loading.value = false));
};

const builtInLabels = computed(() => channelLabels());
const rulesOpen = ref(false);
const palette = Theme.colors.report;
const builtInColors = {
  email: palette.royal_blue, paid: palette.coral, social: palette.hot_pink, ai: palette.medium_slate_blue,
  search: palette.golden_rod, referral: palette.deep_sky_blue, campaign: palette.medium_turquoise,
  direct: palette.light_gray_cyan_blue,
};

// Custom channels carry their own name and take colors the built-in ones do not use
const customPalette = [palette.purple, palette.coral, palette.sea_green, palette.golden_rod, palette.deep_sky_blue, palette.hot_pink];
const labels = computed(() => {
  const map = {...builtInLabels.value};
  (data.value.channels || []).forEach(channel => channel.label && (map[channel.key] = channel.label));
  return map;
});
const colors = computed(() => {
  const map = {...builtInColors};
  (data.value.channels || []).filter(channel => channel.label).forEach((channel, index) => (map[channel.key] = customPalette[index % customPalette.length]));
  return map;
});
// A store sees only the channels it has
const channels = computed(() => (data.value.channels || []).filter(channel => channel.customers > 0));
const coverage = computed(() => data.value.coverage || {});
// The biggest known channel: "direct or unknown" says nothing about where customers come from
const topChannel = computed(() => {
  const known = channels.value.filter(channel => channel.key !== 'direct');
  return known.length ? labels.value[[...known].sort((a, b) => b.customers - a.customers)[0].key] : '—';
});
const money = (value) => props.reportFilter.formatAmount(value || 0, {cents: true});

const render = () => {
  if (!chartRef.value) {
    return;
  }
  if (!chart) {
    chart = echarts.init(chartRef.value);
  }
  const isDark = Theme.isDark();
  // The same text and grid colors as the other report charts
  const textColor = isDark ? '#ffffff' : '#000000';
  const lineColor = isDark ? '#253241' : '#D6DAE1';
  const points = data.value.chart || [];
  chart.setOption({
    legend: {show: true, textStyle: {color: textColor}},
    tooltip: {trigger: 'axis', valueFormatter: (value) => formatNumber(value || 0)},
    grid: {left: 16, right: 16, bottom: 8, containLabel: true},
    xAxis: {
      type: 'category',
      data: points.map(point => prepareLabel(String(point.group))),
      axisLabel: {color: textColor, formatter: axisDayLabel},
      axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: {type: 'value', minInterval: 1, axisLabel: {color: textColor}, splitLine: {lineStyle: {type: 'dashed', color: lineColor}}},
    series: channels.value.map(channel => ({
      name: labels.value[channel.key], type: 'bar', stack: 'channels', barMaxWidth: 30, color: colors.value[channel.key],
      data: points.map(point => point[channel.key] || 0),
    })),
  }, {notMerge: true});
};

const dispose = () => {
  if (chart) {
    chart.dispose();
    chart = null;
  }
};
const resize = () => chart && chart.resize();

watch(() => [loading.value, data.value], () => {
  dispose();
  if (!loading.value) {
    nextTick(render);
  }
});

onMounted(() => {
  props.reportFilter.addListener('source-channels', fetchData);
  fetchData();
  window.addEventListener('resize', resize);
  window.addEventListener('onFluentCartThemeChange', render);
});
onUnmounted(() => {
  props.reportFilter.removeListener('source-channels');
  latest++;
  dispose();
  window.removeEventListener('resize', resize);
  window.removeEventListener('onFluentCartThemeChange', render);
});
</script>

<template>
  <div class="fct-report-order-summary summary-col-3" v-if="!loading">
    <div class="summary-item">
      <div class="summary-item-inner">
        <div class="value"><div class="inner-value text-lg font-medium">{{ formatNumber(coverage.customers || 0) }}</div></div>
        <div class="title">{{ translate('New customers') }}</div>
      </div>
    </div>
    <div class="summary-item">
      <div class="summary-item-inner">
        <div class="value"><div class="inner-value text-lg font-medium">{{ (coverage.share || 0) + '%' }}</div></div>
        <!-- translators: %1$s - share of their first-order revenue -->
        <div class="title">{{ translate('With a known source (%1$s of their revenue)', (coverage.revenue_share || 0) + '%') }}</div>
      </div>
    </div>
    <div class="summary-item">
      <div class="summary-item-inner">
        <div class="value"><div class="inner-value text-lg font-medium">{{ topChannel }}</div></div>
        <div class="title">{{ translate('Top channel') }}</div>
      </div>
    </div>
  </div>

  <Card.Container>
    <Card.Header
        :title="translate('New Customers by Channel')"
        :text="translate('SourceRpt_New_Customer')"
        title_size="small"
        border_bottom
    >
      <template #action>
        <el-button size="small" @click="rulesOpen = true">{{ translate('Channel rules') }}</el-button>
      </template>
    </Card.Header>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="6"/>
      <el-empty v-else-if="!channels.length" :description="translate('No new customers in this date range')"/>
      <div v-else ref="chartRef" class="fct-chart-wrap" style="height: 300px"></div>
    </Card.Body>
  </Card.Container>

  <Card.Container class="overflow-hidden">
    <Card.Header
        :title="translate('Channels')"
        :text="translate('SourceRpt_Channel_New')"
        title_size="small"
        border_bottom
    />
    <Card.Body class="px-0 pb-0">
      <el-skeleton v-if="loading" animated :rows="5" class="px-4 pt-4 pb-4"/>
      <el-table v-else :data="channels" :default-sort="{prop: 'customers', order: 'descending'}">
        <el-table-column :label="translate('Channel')" min-width="170">
          <template #default="{row}">
            <span class="inline-block w-2.5 h-2.5 rounded-full me-2" :style="{backgroundColor: colors[row.key]}" aria-hidden="true"></span>{{ labels[row.key] }}
          </template>
        </el-table-column>
        <el-table-column prop="customers" :label="translate('New customers')" min-width="130" sortable>
          <template #default="{row}">{{ formatNumber(row.customers) }} <span class="text-xs text-gray-500">({{ row.share }}%)</span></template>
        </el-table-column>
        <el-table-column prop="first_net" :label="translate('First orders')" min-width="130" sortable>
          <template #default="{row}">{{ money(row.first_net) }}</template>
        </el-table-column>
        <el-table-column prop="average_ltv" :label="translate('Average lifetime value')" min-width="160" sortable>
          <template #default="{row}">{{ money(row.average_ltv) }}</template>
        </el-table-column>
        <el-table-column prop="repeat_rate" :label="translate('Bought again')" min-width="120" sortable>
          <template #default="{row}">{{ row.repeat_rate }}%</template>
        </el-table-column>
        <el-table-column prop="refund_rate" :label="translate('Refund rate')" min-width="110" sortable>
          <template #default="{row}">{{ row.refund_rate }}%</template>
        </el-table-column>
        <template #empty>
          <el-empty :description="translate('No new customers in this date range')"/>
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
  <ExtensionComponent name="reports.sources.channel_rules" v-model="rulesOpen" @saved="fetchData()">
    <ChannelRulesPreview v-model="rulesOpen"/>
  </ExtensionComponent>
</template>
