<script setup>
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate, {_x} from "@/utils/translator/Translator";
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from "vue";
import * as echarts from "echarts";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import { formatNumber } from "../Utils/formatNumber";
import Empty from "@/Bits/Components/Table/Empty.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import Asset from "@/utils/support/Asset";
import Theme from "@/utils/Theme";
import { exportCSVWithColumns, csvSafe } from "@/utils/Export";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";

const props = defineProps({
    reportFilter: {
        type: Object,
        required: true,
    },
});

const UNKNOWN = "__unknown__";
const report = ref({rows: [], totals: {}, sources: {}, outsized: null, level: "country", movers: null, full: false});
// FluentCart Pro serves the full report: states and cities, lifetime value, movers
const isFull = computed(() => !!report.value.full);
// The store-wide cards; a drill-down keeps them, a filter change reloads them
const summary = ref({});
const previous = ref(null);
let needSummary = true;
const loading = ref(true);
const addressType = ref("billing");
const country = ref("");
const state = ref("");
const mapRef = ref(null);
let chart = null;
let mapReady = null;
let latestRequestId = 0;

const level = computed(() => (state.value ? "city" : (country.value ? "state" : "country")));

const regionName = (code) => {
    try {
        return new Intl.DisplayNames([document.documentElement.lang || "en"], {type: "region"}).of(code) || code;
    } catch (e) {
        return code;
    }
};

const label = (row) => {
    if (row.unknown) {
        return translate("Unknown");
    }

    return level.value === "country" ? regionName(row.key) : row.key;
};

const getReport = () => {
    const requestId = ++latestRequestId;
    loading.value = true;

    const call = Rest.get("reports/locations", {
        params: {
            ...props.reportFilter.applicableFilters.params,
            level: level.value,
            country: country.value,
            state: state.value,
            address_type: addressType.value,
            with_summary: needSummary ? 1 : 0,
        },
    });
    props.reportFilter.trackCompareRange(call);
    call.then((response) => {
            if (requestId !== latestRequestId) return;
            report.value = response;
            if (response.summary) {
                summary.value = response.summary;
                previous.value = response.previous;
                needSummary = false;
            }
        })
        .catch((errors) => {
            if (requestId !== latestRequestId) return;
            Notify.error(errors?.data?.message || translate("Failed to load the locations report"));
        })
        .finally(() => {
            if (requestId !== latestRequestId) return;
            loading.value = false;
        });
};

const drillDown = (row) => {
    if (!isFull.value) {
        return;
    }
    // An unknown country has no states; an unknown state still has cities
    if (level.value === "city" || (row.unknown && level.value === "country")) {
        return;
    }
    if (level.value === "country") {
        country.value = row.key;
    } else {
        state.value = row.key;
    }
    getReport();
};

const goTo = (target) => {
    if (target === "country") {
        country.value = "";
        state.value = "";
    } else {
        state.value = "";
    }
    getReport();
};

// A filter or address change alters the store-wide cards; a drill-down does not
const reload = () => {
    needSummary = true;
    getReport();
};

const money = (value) => props.reportFilter.formatAmount(value || 0);
const byNumber = (key) => (a, b) => Number(a[key]) - Number(b[key]);
/* translators: %1$s - left empty; the text follows a change in percentage points, e.g. "+1.2%" then " pts" */
const pointsSuffix = translate('%1$s pts', '');
const hasCompare = computed(() => report.value.rows.some(row => row.previous_net !== null && row.previous_net !== undefined));

const cards = computed(() => {
    const s = summary.value || {};
    const before = previous.value;
    // A share changes in points, a figure as a share of itself
    const change = (key, points) => {
        if (!before || before[key] === null || (!points && !before[key])) {
            return null;
        }
        return points
            ? Math.round(((s[key] || 0) - (before[key] || 0)) * 10) / 10
            : Math.round((s[key] - before[key]) / Math.abs(before[key]) * 1000) / 10;
    };
    const international = s.international_share !== null && s.international_share !== undefined;
    const list = [
        {key: "net_sales", title: translate("Net sales"), value: money(s.net_sales), change: change("net_sales"), previous: before ? before.net_sales : 0, money: true,
            info: translate("What customers paid in this period, less refunds and tax, across all locations.")},
    ];
    if (international) {
        list.push(
            {key: "international_share", title: translate("International sales"), value: (s.international_share || 0) + "%", change: change("international_share", true),
                previous: before ? before.international_share : 0, points: true,
                /* translators: %1$s - the store country */
                info: translate('LocationRpt_Net_Sales', regionName(s.store_country))},
            {key: "international_new_customers", title: translate("International new customers"), value: formatNumber(s.international_new_customers || 0),
                change: change("international_new_customers"), previous: before ? before.international_new_customers : 0,
                info: translate("Customers outside the store country whose first purchase was in this period.")}
        );
    }
    list.push(
        {key: "countries", title: translate("Countries with sales"), value: formatNumber(s.countries || 0), change: change("countries"),
            previous: before ? before.countries : 0, info: translate("Countries with at least one order in this period.")},
        {key: "top_country", title: translate("Top country"), value: s.top_country ? regionName(s.top_country) + " · " + (s.top_share || 0) + "%" : "—",
            change: null, info: translate("The country with the most net sales, and its share.")}
    );

    return list;
});

const movers = computed(() => report.value.movers || {up: [], down: []});
const hasMovers = computed(() => (movers.value.up || []).length || (movers.value.down || []).length);
const moverLabel = (key) => label({key, unknown: false});
const signedMoney = (value) => (value > 0 ? "+" : "−") + money(Math.abs(value));

const columns = [
    {prop: "orders", label: translate("Orders"), count: true},
    {prop: "customers", label: translate("Customers"), count: true},
    {prop: "new_customers", label: translate("New Customers"), count: true},
    {prop: "returning_customers", label: translate("Returning Customers"), count: true},
    {prop: "net_sales", label: translate("Net"), money: true},
    {prop: "net_change", label: _x('Change', 'Report column: difference from the previous period'), change: true},
    {prop: "net_share", label: translate("Share of Net"), percent: true},
    {prop: "refunds", label: translate("Refunds"), money: true},
    {prop: "refund_rate", label: translate("Refund Rate"), percent: true},
    {prop: "average_net_order", label: translate("Average Order (Net)"), money: true},
    {prop: "average_ltv", label: translate("Lifetime Value"), money: true, rowOnly: true,
        info: translate("What the new customers won here have spent with you to date, each.")},
    {prop: "repeat_rate", label: translate("Bought Again"), percent: true, rowOnly: true,
        info: translate('LocationRpt_Share_New')},
];
const visibleColumns = computed(() => columns.filter(column => (!column.change || hasCompare.value) && (!column.rowOnly || isFull.value)));

const cell = (column, row) => {
    if (column.change) {
        if (row.net_change !== null && row.net_change !== undefined) {
            return (row.net_change > 0 ? "+" : "") + row.net_change + "%";
        }
        return row.previous_net === 0 ? translate("New") : "—";
    }
    if (column.money) {
        return money(row[column.prop]);
    }
    if (column.percent) {
        return (row[column.prop] || 0) + "%";
    }

    return formatNumber(row[column.prop] || 0);
};

const summaryRow = ({columns: tableColumns}) => tableColumns.map((column, index) => {
    if (index === 0) {
        return translate("Total");
    }
    const definition = columns.find(item => item.prop === column.property);
    if (!definition || definition.change || definition.rowOnly) {
        return "";
    }

    return definition.prop === "net_share" ? (report.value.totals.net_sales ? "100%" : "0%") : cell(definition, report.value.totals);
});

// How many orders had no address of their own; shown so owners know where the location came from
const sourceNote = computed(() => {
    const sources = report.value.sources || {};
    if (!sources.parent_order && !sources.customer_record) {
        return "";
    }

    /* translators: %1$s: orders located by their parent order, %2$s: orders located by the customer's country */
    return translate('LocationRpt_Orders_Without',
        formatNumber(sources.parent_order || 0), formatNumber(sources.customer_record || 0));
});

const outsizedNote = computed(() => {
    if (!report.value.outsized) {
        return "";
    }

    /* translators: %1$s: state or region code */
    return translate('LocationRpt_Holds_Country', report.value.outsized);
});

const loadMap = () => {
    if (!mapReady) {
        mapReady = fetch(Asset.getUrl("world.geo.json"))
            .then(response => response.json())
            .then((geo) => {
                geo.features.forEach((feature) => {
                    feature.properties.iso_a2 = feature.properties.iso_a2 || feature.properties.name;
                });
                echarts.registerMap("world", geo);
            })
            .catch((error) => {
                // Try again on the next render instead of keeping the failure
                mapReady = null;
                throw error;
            });
    }

    return mapReady;
};

const renderMap = () => {
    if (level.value !== "country" || !mapRef.value) {
        return;
    }
    loadMap().then(() => {
        if (!mapRef.value) {
            return;
        }
        if (!chart) {
            chart = echarts.init(mapRef.value);
            chart.on("click", (params) => {
                const row = report.value.rows.find(item => item.key === params.name);
                if (row) {
                    drillDown(row);
                }
            });
        }
        const data = report.value.rows.filter(row => !row.unknown).map(row => ({name: row.key, value: row.net_sales, label: regionName(row.key)}));
        const max = Math.max(1, ...data.map(item => item.value));
        chart.setOption({
            tooltip: {trigger: "item", formatter: (params) => (params.data ? params.data.label + ": " + money(params.data.value) : params.name)},
            visualMap: {min: 0, max, calculable: true, left: "left", bottom: "4%", inRange: {color: [Theme.colors.blue["100"], Theme.colors.blue["500"]]}},
            series: [{type: "map", map: "world", nameProperty: "iso_a2", roam: true, zoom: 1.2, data}],
        }, {notMerge: true});
    }).catch(() => {
        // No map without its shapes; the table still shows every country
    });
};

const disposeMap = () => {
    if (chart) {
        chart.dispose();
        chart = null;
    }
};

const exportCsv = () => {
    const csvColumns = [{header: level.value, accessor: "key", formatter: (value, row) => csvSafe(row.unknown === undefined ? value : label(row))}];
    ["orders", "customers", "new_customers", "returning_customers", "gross_sales", "refunds", "net_sales", "previous_net", "net_change",
        "net_share", "average_net_order", "refund_rate", ...(isFull.value ? ["average_ltv", "repeat_rate"] : [])]
        .forEach(key => csvColumns.push({header: key, accessor: key}));
    exportCSVWithColumns([...report.value.rows, {...report.value.totals, key: translate("Total")}], csvColumns, "locations-" + level.value + ".csv")
        .catch(error => Notify.error(error.message));
};

watch([loading, level], () => {
    disposeMap();
    if (!loading.value) {
        nextTick(renderMap);
    }
});

const resizeMap = () => chart && chart.resize();

onMounted(() => {
    getReport();
    props.reportFilter.addListener("locations-report", reload);
    window.addEventListener("resize", resizeMap);
});

onUnmounted(() => {
    props.reportFilter.removeListener("locations-report");
    latestRequestId++;
    disposeMap();
    window.removeEventListener("resize", resizeMap);
});
</script>

<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-revenue-report-page">
            <PageHeading><template #title><h2 class="page-title">{{ translate('Locations') }}</h2></template></PageHeading>

            <SummaryLoader v-if="loading && !summary.orders && summary.orders !== 0" :loading="true" :count="5"/>
            <div v-else class="fct-report-order-summary" :class="'summary-col-' + cards.length">
                <div class="summary-item" v-for="item in cards" :key="item.key">
                    <div class="summary-item-inner">
                        <div class="value">
                            <div class="inner-value">
                                <ReportSummary :amount="item.value" :title="item.title"/>
                            </div>
                            <Fluctuation
                                v-if="item.change !== null"
                                :fluctuation="item.change"
                                :amount="item.previous"
                                :has-currency="!!item.money"
                                :is-percentage="!!item.points"
                                :fluctuation-suffix="item.points ? pointsSuffix : ''"
                            />
                        </div>
                        <div class="title">
                            {{ item.title }}
                            <fct-tooltip :content="item.info" placement="top" :trigger="['hover', 'focus']">
                                <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
                                    <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true"/>
                                </button>
                            </fct-tooltip>
                        </div>
                    </div>
                </div>
            </div>

            <Card.Container v-if="!loading && hasMovers">
                <Card.Header :title="translate('Biggest Movers')" :text="translate('The largest changes in net sales against the compared period, at this level.')" title_size="small" border_bottom/>
                <Card.Body>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div v-for="side in [{key: 'up', title: translate('Growing')}, {key: 'down', title: translate('Declining')}]" :key="side.key">
                            <h3 class="text-sm font-medium m-0 mb-2 text-system-dark dark:text-gray-200">{{ side.title }}</h3>
                            <p v-if="!(movers[side.key] || []).length" class="text-sm text-gray-500 m-0">{{ translate('None') }}</p>
                            <div v-for="move in movers[side.key]" :key="move.key" class="flex justify-between text-sm py-1.5 [border-bottom-width:1px] border-gray-100 dark:border-dark-400">
                                <span class="text-system-mid dark:text-gray-300">{{ moverLabel(move.key) }}</span>
                                <span :class="move.change > 0 ? 'text-green-600' : 'text-red-600'">{{ signedMoney(move.change) }}</span>
                            </div>
                        </div>
                    </div>
                </Card.Body>
            </Card.Container>

            <ProReportPreview
                v-if="!loading && !isFull"
                :title="translate('Go deeper than countries')"
                :text="translate('LocationRpt_Open_Country')"
                :bullets="[translate('States and cities'), translate('Lifetime value and repeat rate by place'), translate('Biggest movers against the compared period')]"
                placement="feature_lock_report_locations"
            >
                <ProReportSample
                    :title="translate('Biggest Movers')"
                    :columns="[translate('Place'), _x('Change', 'Report column: difference from the previous period')]"
                    :rows="[[translate('Place A'), '+1,000.00'], [translate('Place B'), '+500.00'], [translate('Place C'), '−300.00']]"
                />
            </ProReportPreview>

            <div class="fct-revenue-comparison fct-table-wrapper">
                <div class="fct-card">
                    <div class="fct-card-header">
                        <div class="fct-card-header-top">
                            <div class="fct-card-header-left flex-1">
                                <el-breadcrumb separator="/">
                                    <el-breadcrumb-item>
                                        <a href="#" @click.prevent="goTo('country')">{{ translate('All countries') }}</a>
                                    </el-breadcrumb-item>
                                    <el-breadcrumb-item v-if="country">
                                        <a v-if="state" href="#" @click.prevent="goTo('state')">{{ regionName(country) }}</a>
                                        <span v-else>{{ regionName(country) }}</span>
                                    </el-breadcrumb-item>
                                    <el-breadcrumb-item v-if="state">
                                        {{ state === UNKNOWN ? translate('Unknown') : state }}
                                    </el-breadcrumb-item>
                                </el-breadcrumb>
                            </div>

                            <div class="fct-card-header-actions gap-2">
                                <fct-select v-model="addressType" style="width: 190px" :aria-label="translate('Address')" @change="reload"
                                    :options="[{label: translate('Billing'), value: 'billing'}, {label: translate('Shipping'), value: 'shipping'}]">
                                    <template #prefix>{{ translate('Address') }}</template>
                                </fct-select>
                                <div class="fct-btn-group sm">
                                    <fct-tooltip :content="translate('Export CSV')" placement="top">
                                        <IconButton tag="button" :disabled="loading || !report.rows.length" :aria-label="translate('Export CSV')" @click="exportCsv">
                                            <DynamicIcon name="Download" />
                                        </IconButton>
                                    </fct-tooltip>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="fct-card-body">
                        <fct-skeleton class="px-5 pb-5" v-if="loading" :rows="6" animated></fct-skeleton>

                        <template v-else>
                            <div v-if="level === 'country' && report.rows.length" ref="mapRef" class="px-4 pt-4" style="height: 380px"></div>

                            <el-alert v-if="outsizedNote" class="mx-4 mt-4" style="width: auto" type="warning" :closable="false" show-icon :title="outsizedNote" />

                            <fct-table :data="report.rows" class="w-full" show-summary :summary-method="summaryRow"
                                :row-class-name="({row}) => (!isFull || level === 'city' || (row.unknown && level === 'country') ? '' : 'cursor-pointer')" @row-click="drillDown">
                                <fct-table-column :label="level === 'country' ? translate('Country') : (level === 'state' ? translate('State') : translate('City'))" min-width="180" fixed>
                                    <template #default="scope">
                                        <span class="font-medium">{{ label(scope.row) }}</span>
                                    </template>
                                </fct-table-column>

                                <fct-table-column v-for="column in visibleColumns" :key="column.prop" :prop="column.prop"
                                    :min-width="column.info ? 160 : 130" sortable :sort-method="byNumber(column.prop)">
                                    <template #header>
                                        {{ column.label }}
                                        <fct-tooltip v-if="column.info" :content="column.info" placement="top">
                                            <DynamicIcon class="text-gray-500 w-4 h-4 cursor-help inline-block align-middle" name="InformationFill" />
                                        </fct-tooltip>
                                    </template>
                                    <template #default="scope">{{ cell(column, scope.row) }}</template>
                                </fct-table-column>

                                <template #empty>
                                    <Empty icon="Empty/Order" has-dark :text="translate('No orders in this period')" />
                                </template>
                            </fct-table>

                            <p class="px-4 py-3 text-xs text-gray-500 m-0">
                                {{ translate('LocationRpt_Order_Placed') }}
                                <template v-if="isFull">{{ translate('Click a country or state to see where in it.') }}</template>
                                <template v-if="sourceNote"><br />{{ sourceNote }}</template>
                            </p>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </UserCan>
</template>
