<script setup>
import {computed, nextTick, onMounted, onUnmounted, ref, toRef, useId, watch} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import {prepareLabel} from "@/Modules/Reports/Utils/decorator";
import useGroupKeyOptions from "@/Modules/Reports/Utils/useGroupKeyOptions";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
  title: {
    type: String,
    default: "",
  },
  buckets: {
    type: Array,
    default: () => [],
  },
  series: {
    type: Array,
    default: () => [],
  },
  metric: {
    type: String,
    default: "orders",
  },
  formatValue: {
    type: Function,
    required: true,
  },
  groupKey: {
    type: String,
    default: "default",
  },
  ghostText: {
    type: String,
    default: "",
  },
  resting: {
    type: Boolean,
    default: false,
  },
  previewColors: {
    type: Array,
    default: () => [],
  },
  showActions: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(["update:groupKey", "openFilter"]);

const {groupKeys} = useGroupKeyOptions(props.reportFilter, toRef(props, "groupKey"));

// Mirrors ProductReportService::MAX_COMPARE_BUCKETS: a grouping the server would coarsen is not offered
const MAX_BUCKETS = 366;
const bucketCount = (key) => {
  const [start, end] = props.reportFilter.data.dateRange || [];
  if (!start || !end) return 0;
  const from = new Date(start);
  const to = new Date(end);
  // Calendar days from local date parts, so a DST change in the range does not shift the count
  const day = (date) => Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()) / (1000 * 60 * 60 * 24);
  if (key === "daily") return day(to) - day(from) + 1;
  if (key === "monthly") return (to.getFullYear() - from.getFullYear()) * 12 + to.getMonth() - from.getMonth() + 1;
  return to.getFullYear() - from.getFullYear() + 1;
};
const groupOptions = computed(() => groupKeys.value.map((option) => ({
  ...option,
  disabled: option.value !== "default" && bucketCount(option.value) > MAX_BUCKETS,
})));

const chartRef = ref(null);
const isDarkTheme = ref(Theme.isDark());
const isGhost = computed(() => !!props.ghostText);
const tableId = useId();
const hasTable = computed(() => !isGhost.value && props.series.length > 0 && props.buckets.length > 0);

const LEGEND_SIDE = 8;
const LEGEND_PADDING = 8;
const LEGEND_ICON = 12;
const LEGEND_LINE_HEIGHT = 16;
const LEGEND_GAP = 16;
const LEGEND_MIN_LINES = 2;
const LEGEND_FONT_SIZE = 12;
const LEGEND_FONT_FAMILY = "sans-serif";
const AXIS_GAP = 12;

// echarts does not move the grid for the legend, so the bottom inset comes from the wrap count the names need
// at this width. Names are measured with the renderer echarts uses and packed the way its horizontal legend
// packs items: icon, a 5px text gap, the name, and the item gap counted before the wrap check.
const legendSpace = (width) => {
  const maxWidth = width - 2 * LEGEND_SIDE - 2 * LEGEND_PADDING;
  const font = LEGEND_FONT_SIZE + "px " + LEGEND_FONT_FAMILY;
  let lines = 1;
  let x = 0;
  props.series.forEach((item, index) => {
    const gap = index < props.series.length - 1 ? LEGEND_GAP : 0;
    const move = LEGEND_ICON + 5 + echarts.format.getTextRect(item.name, font).width + gap;
    if (x > 0 && x + move > maxWidth) {
      lines += 1;
      x = move;
    } else {
      x += move;
    }
  });
  lines = Math.max(LEGEND_MIN_LINES, lines);
  return lines * LEGEND_LINE_HEIGHT + (lines - 1) * LEGEND_GAP + 2 * LEGEND_PADDING + AXIS_GAP;
};

let chartInstance = null;

const tooltipFormatter = (params) => {
  const color = isDarkTheme.value ? "#ffffff" : "#565865";
  const rows = params.map((param) => `<div style="display:flex;justify-content:space-between;gap:20px;color:${color}">
      <span>${param.marker}${echarts.format.encodeHTML(param.seriesName)}</span>
      <strong>${props.formatValue(param.value)}</strong>
    </div>`);
  const bucket = props.buckets[params[0]?.dataIndex] || "";

  return `<div style="font-weight:600;margin-bottom:4px;color:${color}">${prepareLabel(bucket)}</div>${rows.join("")}`;
};

const buildOption = () => {
  const dark = isDarkTheme.value;
  const ghost = isGhost.value;
  const text = dark ? "#ffffff" : "#000000";
  const grid = dark ? "#253241" : "#D6DAE1";
  const fade = ghost ? 0.5 : 1;
  const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  return {
    animation: !reduceMotion,
    legend: {
      show: !ghost,
      // Mouse-only toggling would be a control keyboard users cannot reach; the Totals rows carry the highlight
      selectedMode: false,
      type: "plain",
      bottom: 0,
      left: LEGEND_SIDE,
      right: LEGEND_SIDE,
      padding: LEGEND_PADDING,
      icon: "roundRect",
      itemWidth: LEGEND_ICON,
      itemHeight: LEGEND_ICON,
      itemGap: LEGEND_GAP,
      textStyle: {color: text, fontSize: LEGEND_FONT_SIZE, fontFamily: LEGEND_FONT_FAMILY, lineHeight: LEGEND_LINE_HEIGHT},
    },
    // The right inset leaves room for the last x label, centred on the plot edge
    grid: {top: 20, left: 8, right: 40, bottom: ghost ? 4 : legendSpace(chartInstance.getWidth()), containLabel: true},
    tooltip: {
      show: !ghost,
      trigger: "axis",
      backgroundColor: dark ? "#253241" : "#ffffff",
      borderColor: dark ? "#2C3C4E" : "#c0c4ca",
      borderWidth: 1,
      textStyle: {color: dark ? "#ffffff" : "#565865"},
      axisPointer: {
        type: "line",
        lineStyle: {type: "solid", width: 2, color: dark ? Theme.colors.report.dark_cyan_blue_16 : Theme.colors.report.light_gray_blue},
      },
      formatter: tooltipFormatter,
    },
    xAxis: {
      type: "category",
      boundaryGap: false,
      data: props.buckets.map((bucket) => prepareLabel(bucket)),
      axisTick: {show: false},
      axisLabel: {color: text, opacity: fade, fontSize: 12, hideOverlap: true},
      axisLine: {lineStyle: {type: "dashed", color: grid, opacity: fade}},
    },
    yAxis: {
      type: "value",
      min: 0,
      max: ghost ? 100 : undefined,
      minInterval: props.metric === "revenue" ? 0 : 1,
      axisLabel: {show: !ghost, color: text, fontSize: 12, formatter: (value) => props.formatValue(value, true)},
      splitLine: {show: true, lineStyle: {color: grid, type: "dashed", opacity: fade}},
    },
    series: ghost ? [] : props.series.map((item) => ({
      id: item.key,
      name: item.name,
      type: "line",
      data: item.data,
      color: item.color,
      symbol: "circle",
      symbolSize: 6,
      showSymbol: item.data.length <= 31,
      lineStyle: {width: 2, color: item.color},
      itemStyle: {color: item.color},
      emphasis: {focus: "series"},
    })),
  };
};

const disposeChart = () => {
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
};

const renderChart = () => {
  if (!chartRef.value) {
    disposeChart();
    return;
  }
  if (chartInstance && chartInstance.getDom() !== chartRef.value) {
    disposeChart();
  }
  if (!chartInstance) {
    chartInstance = echarts.init(chartRef.value);
  }
  chartInstance.setOption(buildOption(), {notMerge: true});
};

watch(
    [() => props.buckets, () => props.series, () => props.metric, () => props.ghostText, () => props.resting, () => props.loading],
    () => nextTick(renderChart),
    {deep: true}
);

const highlightSeries = (key) => {
  if (chartInstance) {
    chartInstance.dispatchAction({type: "highlight", seriesId: key});
  }
};

const downplaySeries = (key) => {
  if (chartInstance) {
    chartInstance.dispatchAction({type: "downplay", seriesId: key});
  }
};

defineExpose({highlightSeries, downplaySeries});

const handleResize = () => {
  if (chartInstance) {
    chartInstance.resize();
    if (!isGhost.value) {
      chartInstance.setOption({grid: {bottom: legendSpace(chartInstance.getWidth())}});
    }
  }
};

const handleThemeChange = () => {
  isDarkTheme.value = Theme.isDark();
  nextTick(renderChart);
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", handleThemeChange);
  window.addEventListener("resize", handleResize);
  nextTick(renderChart);
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", handleThemeChange);
  window.removeEventListener("resize", handleResize);
  disposeChart();
});
</script>

<template>
  <Card.Container class="fct-product-compare-chart">
    <Card.Header :title="title" title_size="small" border_bottom>
      <template v-if="showActions || resting" #action>
        <span v-if="resting" class="fct-product-compare-chart__tag">{{ translate('Example') }}</span>
        <template v-if="showActions">
          <slot name="actions"/>
          <div class="fct-chart-select">
            <el-select
                class="el-select--x-small"
                :model-value="groupKey"
                :aria-label="translate('Grouping')"
                @update:model-value="(value) => emit('update:groupKey', value)"
            >
              <el-option
                  v-for="option in groupOptions"
                  :key="option.value"
                  :label="option.label"
                  :value="option.value"
                  :disabled="option.disabled"
              />
            </el-select>
          </div>
        </template>
      </template>
    </Card.Header>

    <Card.Body class="px-0">
      <el-skeleton v-if="loading" animated :rows="8" class="p-5"/>

      <div v-else-if="resting" class="fct-product-compare-chart__preview">
        <svg
            class="fct-product-compare-chart__preview-art"
            viewBox="0 0 1000 340"
            preserveAspectRatio="none"
            aria-hidden="true"
            focusable="false"
        >
          <g class="fct-product-compare-chart__preview-grid">
            <line x1="0" y1="60" x2="1000" y2="60"/>
            <line x1="0" y1="130" x2="1000" y2="130"/>
            <line x1="0" y1="200" x2="1000" y2="200"/>
            <line x1="0" y1="270" x2="1000" y2="270"/>
          </g>
          <path d="M40 250 C180 230,260 180,380 190 S600 120,720 110 S900 60,960 70" :stroke="previewColors[0]" opacity=".28"/>
          <path d="M40 280 C180 270,260 240,380 250 S600 200,720 215 S900 170,960 160" :stroke="previewColors[1]" opacity=".28"/>
          <path d="M40 200 C180 215,260 170,380 150 S600 190,720 160 S900 220,960 230" :stroke="previewColors[2]" opacity=".22" stroke-dasharray="6 6"/>
        </svg>
        <div class="fct-product-compare-chart__preview-body">
          <p class="fct-product-compare-chart__preview-title">{{ translate('Pick 2 or more products to compare') }}</p>
          <p class="fct-product-compare-chart__preview-text">
            {{ translate('Each product gets its own line here, plus a totals table underneath.') }}
          </p>
          <div class="fct-product-compare-chart__chips" aria-hidden="true">
            <span class="fct-product-compare-chart__chip">
              <span class="fct-product-compare-chart__dot" :style="{background: previewColors[0]}"></span>{{ translate('Product A') }}
            </span>
            <span class="fct-product-compare-chart__chip">
              <span class="fct-product-compare-chart__dot" :style="{background: previewColors[1]}"></span>{{ translate('Product B') }}
            </span>
            <span class="fct-product-compare-chart__chip is-more">
              <span class="fct-product-compare-chart__dot" :style="{background: previewColors[2]}"></span>{{ translate('+ one more') }}
            </span>
          </div>
          <!-- FilterDropdown closes on any document click, so this one must not reach it -->
          <el-button class="fct-product-compare-chart__open" @click.stop="emit('openFilter')">
            {{ translate('Open the Product filter') }}
          </el-button>
        </div>
      </div>

      <div v-else class="fct-product-compare-chart__wrap">
        <div
            ref="chartRef"
            class="fct-chart-wrap"
            role="img"
            :aria-label="ghostText || title"
            :aria-describedby="hasTable ? tableId : undefined"
        ></div>
        <p v-if="ghostText" class="fct-product-compare-chart__ghost">{{ ghostText }}</p>
        <table v-if="hasTable" :id="tableId" class="sr-only">
          <caption>{{ title }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ translate('Period') }}</th>
              <th v-for="item in series" :key="item.key" scope="col">{{ item.name }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(bucket, index) in buckets" :key="bucket">
              <th scope="row">{{ prepareLabel(bucket) }}</th>
              <td v-for="item in series" :key="item.key">{{ formatValue(item.data[index]) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card.Body>
  </Card.Container>
</template>
