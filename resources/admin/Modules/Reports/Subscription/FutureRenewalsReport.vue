<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-refund-report-page">
          <PageHeading :title="translate('Subscription Future Revenue')"></PageHeading>
        </div>


        <Card.Container>
            <Card.Body class="p-3">
                <SummaryLoader v-if="loading" :loading="loading" :margin-bottom="0" :padding="0" />

                <div v-else>
                    {{ tooltip }}
                </div>
            </Card.Body>
        </Card.Container>

        <SummaryLoader v-if="loading" :loading="loading" :count="2" />

        <div v-else class="fct-report-order-summary summary-col-2">
            <div class="summary-item" v-for="(summary, summaryIndex) in summaryCards" :key="summaryIndex">
                <div class="summary-item-inner">
                    <div class="value">
                        <div class="inner-value">
                            <span v-html="summary.value"/>
                        </div>
                    </div>

                    <div class="title">
                        {{ summary.title }}
                    </div>
                </div>
            </div>
        </div>

        <div class="fct-revenue-line-chart-wrap">
            <Card.Container>
                <Card.Header title_size="small" border_bottom>
                    <template #title>
                        <div class="flex items-center gap-2">
                            <h4 class="fct-card-header-title is-small">
                                {{ translate("Upcoming Renewal Revenue") }}
                            </h4>
                        </div>
                    </template>
                    <template #action v-if="data.totalRenewals && !loading">
                        <!-- <div class="fct-chart-select">
                            <el-select class="el-select--x-small" v-model="selectedGroupKey" @change="filterChartData">
                                <el-option v-for="groupKey in groupKeys" :key="groupKey.value"
                                    :label="$t(groupKey.label)" :value="groupKey.value" />
                            </el-select>
                        </div> -->

                        <div class="fct-btn-group sm">
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
                            <div v-if="!isEmpty" class="fct-chart-wrap fct-revenue-line-chart mt-3" ref="chartRef"></div>
                            
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

                        <Empty
                            v-else
                            icon="Empty/ListView"
                            :has-dark="true"
                            :text="translate('Currently there is no data!')"
                            class="fct-report-empty-state"
                        />

                    </template>
                </Card.Body>
            </Card.Container>
        </div>
    </UserCan>
</template>

<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import { ref, onMounted, nextTick, computed, watch, onUnmounted } from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import { monthNames } from "@/Modules/Reports/Utils/monthNames";
import checkBoxFilter from "@/Models/Reports/CheckBoxFilterModel";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import Theme from "@/utils/Theme";
import Empty from "@/Bits/Components/Table/Empty.vue";
import { formatNumber } from "../Utils/formatNumber";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import SummaryLoader from "../Components/SummaryLoader.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import {
    makeXAxisLabels,
    tooltipSuffix,
    prepareLabel,
    getEmphasisColor,
    getXAxisConfig,
} from '../Utils/decorator';
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";

/**
 * ----------------------------------------------------------------------------
 * Props and Data
 * ----------------------------------------------------------------------------
 */

const props = defineProps({
    reportFilter: {
        type: Object,
        required: true,
    },
});

const reportFilter = props.reportFilter;
const zoomIsActive = ref(false);
const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());
const chartRef = ref(null);
let chartInstance = null;
const data = ref({
    totalProjected: 0,
    totalRenewals: 0,
    projections: [],
    period: {
        start: null,
        end: null
    },
    groupBy: 'monthly'
});
const loading = ref(true);

/**
 * ----------------------------------------------------------------------------
 * Methods
 * ----------------------------------------------------------------------------
 */
const fetchData = (filters, groupKey = 'monthly') => {
    loading.value = true;

    Rest.get('reports/future-renewals', {
        params: {
            ...filters.params,
            groupKey: groupKey
        }
    }).then(response => {
        data.value = response
    })
    .finally(() => loading.value = false)
}

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
        barGap: '-100%',
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
    
    const labels = makeXAxisLabels(data.value.projections);
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
                    const value = index === 0 ?
                        `${CurrencyFormatter.formatScaled(param.value)}`
                        : formatNumber(param.value);

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
                    formatter: (value) => `${CurrencyFormatter.formatScaledChart(value)}`,
                },
                splitLine: {
                    show: true,
                    lineStyle: {
                        color: isDarkTheme.value ? "#253241" : "#D6DAE1",
                        type: "dashed",
                    },
                },
            },
            {
                type: "value",
                axisLabel: {
                    color: isDarkTheme.value ? "#ffffff" : "#000000",
                    fontSize: 12,
                },
                splitLine: {
                    show: false,
                },
            },
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

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const tooltip = computed(() => {
    const tooltipAppendix = tooltipSuffix(data.value.period);

    return translate('This report is calculated by looking at active subscriptions and extrapolating their renewals over time, to estimate future renewal revenue ') + tooltipAppendix;
});

const seriesData = computed(() => {
    let seriesSrc = [
        {
            name: translate("Revenue"),
            key: "count",
            type: 'line',
            color: isDarkTheme.value ? colors.blue_dark_mode : colors.blue,
            data: data.value.projections.map((item) => item.projected_amount)
        },
        {
            name: translate("Count"),
            key: "count",
            type: 'bar',
            color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
            data: data.value.projections.map(item => item.renewals_count)
        }
    ];

    return seriesSrc.map((item, index) => createSeries(
        item.name,
        item.type,
        index,
        item.data,
        item.color
    ));
});

const xAxisConfig = computed(() => {
    return getXAxisConfig(data.value.projections.length);
});

const summaryCards = computed(() => [
    {
        title: translate("Renewal Revenue"),
        value: CurrencyFormatter.formatScaled(data.value.totalProjected),
        key: 'renewal_revenue',
    },
    {
        title: translate("Renewals"),
        value: formatNumber(data.value.totalRenewals),
        key: 'renewals_count',
        no_currency: true
    },
]);

const isEmpty = computed(() => {
    return !data.value.totalRenewals;
});

/**
 * ----------------------------------------------------------------------------
 * Watchers
 * ----------------------------------------------------------------------------
 */
watch(
    [() => data.value],
    () => {
        nextTick(updateChart);
    },
    { deep: true }
);

watch(isEmpty, (value) => {
    if (!value) initChart();
})

watch(loading, (value) => {
    if (!value) initChart();
})

/**
 * ----------------------------------------------------------------------------
 * Hooks
 * ----------------------------------------------------------------------------
 */
onMounted(() => {
    fetchData(reportFilter.applicableFilters);

    nextTick(() => {
        initChart();
    });

    window.addEventListener("onFluentCartThemeChange", handleThemeChange);
});

onUnmounted(() => {
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
});
</script>
