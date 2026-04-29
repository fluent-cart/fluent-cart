<template>
    <div class="fct-revenue-line-chart-wrap">
        <Card.Container>
            <Card.Header title_size="small" border_bottom>
                <template #title>
                    <div class="flex items-center gap-2">
                        <h4 class="fct-card-header-title is-small">
                            {{ translate("Retention Rate") }}
                        </h4>
                    </div>
                </template>
                <template #action v-if="!isEmpty && !loading">
                    <div class="fct-btn-group sm">
                        <ChartTab :activeType="chartType" :types="{
                            line: 'LineChart',
                            bar: 'BarChart',
                        }" @change="toggleChartType" />
                        <IconButton tag="button" size="small" :title="$t('Zoom Chart')" @click="handleZoomChart"
                            :class="zoomIsActive ? 'primary' : ''">
                            <DynamicIcon name="SearchAdd" />
                        </IconButton>

                        <Screenshot :targetRef="chartRef" />
                    </div>
                </template>
            </Card.Header>
            <Card.Body class="px-0 pt-0">
                <el-skeleton v-if="loading" animated :rows="7" class="px-4 pt-4" />

                <template v-else>
                    <template v-if="!isEmpty">
                        <div v-if="!isEmpty" class="fct-chart-wrap fct-revenue-line-chart mt-3" ref="chartRef">
                        </div>

                        <div class="chart-action-wrap">
                            <div class="chart-change-wrap">
                                <div class="chart-change">
                                    <DynamicIcon name="ArrowUp" class="arrow-up" />
                                    <span class="text">{{ translate("Data") }}</span>
                                </div>

                                <div class="chart-change">
                                    <span class="text">{{ translate("Timeline") }}</span>
                                    <DynamicIcon name="ArrowRight" class="arrow-right" />
                                </div>
                            </div>
                        </div>
                    </template>

                    <Empty v-else icon="Empty/ListView" :has-dark="true"
                        :text="translate('Currently there is no data!')" class="fct-report-empty-state" />

                </template>
            </Card.Body>
        </Card.Container>
    </div>
</template>

<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import { ref, onMounted, nextTick, computed, watch, onUnmounted } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import ChartTab from "@/Bits/Components/ChartTab.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Theme from "@/utils/Theme";
import Empty from "@/Bits/Components/Table/Empty.vue";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import translate from "@/utils/translator/Translator";
import {
    makeXAxisLabels,
    getEmphasisColor,
    getXAxisConfig,
} from '@/Modules/Reports/Utils/decorator';

/**
 * ----------------------------------------------------------------------------
 * Props and Data
 * ----------------------------------------------------------------------------
 */
const props = defineProps({
    loading: {
        type: Boolean,
        default: true,
    },
    data: {
        type: Object,
        required: true,
    }
});

const zoomIsActive = ref(false);
const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());
const chartRef = ref(null);
let chartInstance = null;
const chartType = ref(
  ChartTypeFilter.getChartType("subscription", "retention_chart") || "line"
);

/**
 * ----------------------------------------------------------------------------
 * Methods
 * ----------------------------------------------------------------------------
 */
const createSeries = (name, type, yAxisIndex, data, color) => {
    return {
        name,
        type,
        barMaxWidth: 30,
        yAxisIndex,
        data,
        smooth: false,
        color,
        lineStyle: {
            width: 3,
        },
        symbol: "circle",
        showSymbol: true,
        symbolSize: 8,
        itemStyle: {
            color: color,
            borderRadius: [4, 4, 0, 0]
        },
        emphasis: {
            scale: 2,
            itemStyle: {
                color: getEmphasisColor(color),
            },
            lineStyle: {
                color: color,
            },
        },
        animation: true,
        animationEasing: "cubicOut",
        animationDuration: 800,
        animationDelay: 0,
        // barGap: '-100%',
        legendHoverLink: false,
    };
};

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

const updateChart = () => {
    if (!chartInstance) return;

    const labels = makeXAxisLabels(retentionData.value);

    const option = {
        title: {
            text: "",
            left: "center",
        },
        legend: {
            show: true,
            itemGap: 20,
            itemWidth: 10,
            itemHeight: 10,
            textStyle: {
                color: isDarkTheme.value ? "#ffffff" : "#000000",
            }
        },
        tooltip: {
            trigger: "axis",
            backgroundColor: isDarkTheme.value ? "#253241" : "#ffffff",
            borderColor: isDarkTheme.value ? "#2C3C4E" : "#c0c4ca",
            borderWidth: 1,
            textStyle: {
                color: isDarkTheme.value ? "#ffffff" : "#565865",
            },
            axisPointer: {
                type: 'line',
                lineStyle: {
                    type: 'solid',
                    width: 2,
                    color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray_blue,
                }
            },
            formatter: (params) => {
                let result = params[0].name;

                params.forEach((param, index) => {
                    const value = formatNumber(param.value) + '%';

                    const color = isDarkTheme.value ? "#ffffff" : "#565865";

                    result += `<div>
                        ${param.marker}
                        <span style="color: ${color};">${param.seriesName}</span>
                        <span style="float: right; margin-left: 20px; color: ${color};">
                        ${value}
                        </span>
                    </div>`;
                });
                return result;
            },
        },
        grid: {
            show: false,
        },
        xAxis: {
            type: "category",
            data: labels, // Use group for x-axis
            axisLabel: {
                color: isDarkTheme.value ? "#ffffff" : "#000000",
                fontSize: 12,
                interval: xAxisConfig.value.interval,
            },
            axisLine: {
                lineStyle: {
                    type: "dashed",
                    color: isDarkTheme.value ? "#253241" : "#D6DAE1",
                },
            },
        },
        yAxis: [
            {
                type: "value",
                axisLabel: {
                    color: isDarkTheme.value ? "#ffffff" : "#000000",
                    fontSize: 12,
                    formatter: (value) => `${value}%`,
                },
                splitLine: {
                    show: true,
                    lineStyle: {
                        color: isDarkTheme.value ? "#253241" : "#D6DAE1",
                        type: "dashed",
                    },
                },
                splitNumber: 2
            }
        ],
        series: seriesData.value, // Use the computed series data
    };
    chartInstance.setOption(option, { notMerge: true, replaceMerge: ["series"] });
};

const handleZoomChart = () => {
    zoomIsActive.value = !zoomIsActive.value;

    if (zoomIsActive.value) {
        chartInstance.setOption({
            dataZoom: [
                {
                    type: "slider",
                    show: true,
                    xAxisIndex: [0],
                    start: 1,
                    end: 100,
                },
                {
                    type: "slider",
                    show: true,
                    yAxisIndex: [0],
                    left: "95%",
                    start: 1,
                    end: 100,
                },
                {
                    type: "inside",
                    xAxisIndex: [0],
                    start: 1,
                    end: 100,
                },
                {
                    type: "inside",
                    yAxisIndex: [0],
                    start: 1,
                    end: 100,
                },
            ],
        });
    } else {
        updateChart();
    }
};

const handleThemeChange = () => {
    isDarkTheme.value = Theme.isDark();

    nextTick(() => {
        updateChart();
    });
};

const toggleChartType = (type) => {
  ChartTypeFilter.onChange("subscription", "retention_chart", type);
  if (chartType.value !== type) {
    zoomIsActive.value = false;
    chartType.value = type;
    updateChart();
  }
};

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const retentionData = computed(() => {
  return props.data?.retention_data ?? [];
});

const seriesData = computed(() => {
    let seriesSrc = [
        {
            name: translate("Subscription Retention"),
            key: "count",
            type: chartType.value,
            color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
            data: retentionData.value.map((item) => item.retention_rate)
        },
        {
            name: translate("MRR Retention"),
            key: "count",
            type: chartType.value,
            color: isDarkTheme.value ? colors.dark_cyan_blue_36 : colors.light_gray_cyan_blue,
            data: retentionData.value.map(item => item.retention_rate_money)
        }
    ];

    return seriesSrc.map((item, index) => createSeries(
        item.name,
        item.type,
        0,
        item.data,
        item.color
    ));
});

const xAxisConfig = computed(() => {
    return getXAxisConfig(retentionData.value.length);
});

const isEmpty = computed(() => {
    return !retentionData.value || !retentionData.value.length;
});

/**
 * ----------------------------------------------------------------------------
 * Watchers
 * ----------------------------------------------------------------------------
 */
watch(
    [() => props.data],
    () => {
        nextTick(initChart);
    },
    { deep: true }
);

watch(isEmpty, (value) => {
    if (!value) initChart();
})

watch(props.loading, (value) => {
    if (!value) initChart();
})

/**
 * ----------------------------------------------------------------------------
 * Hooks
 * ----------------------------------------------------------------------------
 */
onMounted(() => {
    nextTick(() => {
        initChart();
    });

    window.addEventListener("onFluentCartThemeChange", handleThemeChange);
});

onUnmounted(() => {
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
});
</script>
