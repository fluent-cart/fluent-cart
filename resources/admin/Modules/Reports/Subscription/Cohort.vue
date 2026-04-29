<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-cohort-report-page">
          <PageHeading>
            <template #title>
              <h1 class="page-title">
                {{ translate('Subscription Cohorts') }}

                <el-tooltip popper-class="fct-tooltip"
                            :content="translate('A cohort is considered churned either when it went on hold or when it officially ended (factoring in the end date).')"
                            placement="top">
                  <DynamicIcon class="text-gray-500 w-4 h-4 cursor-pointer" name="InformationFill" />
                </el-tooltip>
              </h1>
            </template>
          </PageHeading>

          <Card.Container class="overflow-hidden">
            <Card.Header>
              <div class="fct-cohort-settings">
                <div class="fct-cohort-settings-group">
                  <el-button-group>
                    <el-button :type="groupBy === 'year' ? 'primary' : ''" size="small" @click="groupBy = 'year'">
                      {{ translate('Year') }}
                    </el-button>
                    <el-button :type="groupBy === 'month' ? 'primary' : ''" size="small" @click="groupBy = 'month'">
                      {{ translate('Month') }}
                    </el-button>
                  </el-button-group>

                  <el-button-group>
                    <el-button :type="metric === 'subscribers' ? 'primary' : ''" size="small" @click="metric = 'subscribers'">
                      {{ translate('Subscriptions') }}
                    </el-button>
                    <el-button :type="metric === 'mrr' ? 'primary' : ''" size="small" @click="metric = 'mrr'">
                      {{ translate('MRR') }}
                    </el-button>
                  </el-button-group>

                  <el-select v-model="displayMode" size="small" class="w-[150px]">
                    <el-option value="percent" :label="translate('% of total')"></el-option>
                    <el-option value="percent_previous" :label="translate('% of previous')"></el-option>
                    <el-option value="value" :label="translate('Total Value')"></el-option>
                    <el-option value="percent_ended" :label="translate('% of total ended')"></el-option>
                  </el-select>
                </div>

                <div class="flex gap-2 items-center">
                  <el-button size="small" @click="generateSnapshots" :loading="loading">
                    {{ translate('Generate Snapshots') }}
                  </el-button>
                  <ExportReport
                      :data="exportData"
                      :filename="'subscription_cohorts.csv'"
                  />
                </div>
              </div>
            </Card.Header>
            <Card.Body class="px-0 pb-0">
              <div v-if="loading" class="px-4 pb-4">
                <el-skeleton animated :rows="10" />
              </div>

              <el-table
                  v-if="!loading && !isEmpty"
                  :data="tableData"
                  border
                  class="fct-cohort-el-table"
                  :cell-style="cellStyleHandler"
                  :row-class-name="rowClassName"
                  :span-method="spanMethod"
              >
                <el-table-column
                    prop="cohort_label"
                    :label="translate('Subscribed')"
                    fixed="left"
                    width="150"
                >
                  <template #default="scope">
                                <span :class="{ 'font-bold': scope.row.is_weighted_avg }">
                                    {{ scope.row.cohort_label }}
                                </span>
                  </template>
                </el-table-column>

                <el-table-column
                    prop="start_value"
                    :label="translate('Start')"
                    align="right"
                >
                  <template #default="scope">
                                <span :class="{ 'font-bold': scope.row.is_weighted_avg }">
                                    {{ scope.row.start_value }}
                                </span>
                  </template>
                </el-table-column>

                <el-table-column
                    v-for="i in maxPeriods"
                    :key="i"
                    :prop="'period_' + i"
                    :label="getPeriodLabel(i)"
                    width="120"
                    align="center"
                >
                  <template #default="scope">
                    <el-tooltip
                        v-if="scope.row['period_' + i + '_tooltip']"
                        placement="top"
                        popper-class="fct-tooltip"
                    >
                      <template #content>
                        <div class="text-sm" v-html="scope.row['period_' + i + '_tooltip']"></div>
                      </template>
                      <span :class="{ 'font-bold': scope.row.is_weighted_avg }">
                                        {{ scope.row['period_' + i] }}
                                    </span>
                    </el-tooltip>
                    <span v-else :class="{ 'font-bold': scope.row.is_weighted_avg }">
                                    {{ scope.row['period_' + i] || '' }}
                                </span>
                  </template>
                </el-table-column>
              </el-table>

              <Empty
                  v-if="!loading && isEmpty"
                  icon="Empty/ListView"
                  :has-dark="true"
                  :text="translate('Click Generate Snapshots above to view cohort data.')"
                  class="fct-report-empty-state"
              />
            </Card.Body>
          </Card.Container>
        </div>



        <!-- Cohort Table -->
<!--         <Card.Container v-if="loading">-->
<!--            <Card.Body class="px-0 pb-0 hidden">-->
<!--                <el-skeleton animated :rows="10" class="p-4" />-->
<!--            </Card.Body>-->
<!--         </Card.Container>-->
        <!-- <Card.Container>
            <Card.Body class="px-0 pb-0"> -->
                <!-- <el-skeleton v-if="loading" animated :rows="10" class="p-4" /> -->

<!--                <template v-else-if="!isEmpty">-->
<!--                    <el-table -->
<!--                        :data="tableData" -->
<!--                        border -->
<!--                        class="fct-cohort-el-table hidden"-->
<!--                        :cell-style="cellStyleHandler"-->
<!--                        :row-class-name="rowClassName"-->
<!--                        :span-method="spanMethod"-->
<!--                    >-->
<!--                        <el-table-column -->
<!--                            prop="cohort_label" -->
<!--                            :label="translate('Subscribed')" -->
<!--                            fixed="left"-->
<!--                            width="150"-->
<!--                        >-->
<!--                            <template #default="scope">-->
<!--                                <span :class="{ 'font-bold': scope.row.is_weighted_avg }">-->
<!--                                    {{ scope.row.cohort_label }}-->
<!--                                </span>-->
<!--                            </template>-->
<!--                        </el-table-column>-->

<!--                        <el-table-column -->
<!--                            prop="start_value" -->
<!--                            :label="translate('Start')" -->
<!--                            align="right"-->
<!--                        >-->
<!--                            <template #default="scope">-->
<!--                                <span :class="{ 'font-bold': scope.row.is_weighted_avg }">-->
<!--                                    {{ scope.row.start_value }}-->
<!--                                </span>-->
<!--                            </template>-->
<!--                        </el-table-column>-->

<!--                        <el-table-column -->
<!--                            v-for="i in maxPeriods" -->
<!--                            :key="i"-->
<!--                            :prop="'period_' + i"-->
<!--                            :label="getPeriodLabel(i)"-->
<!--                            width="120"-->
<!--                            align="center"-->
<!--                        >-->
<!--                            <template #default="scope">-->
<!--                                <el-tooltip -->
<!--                                    v-if="scope.row['period_' + i + '_tooltip']" -->
<!--                                    placement="top" -->
<!--                                    popper-class="fct-tooltip"-->
<!--                                >-->
<!--                                    <template #content>-->
<!--                                        <div class="text-sm" v-html="scope.row['period_' + i + '_tooltip']"></div>-->
<!--                                    </template>-->
<!--                                    <span :class="{ 'font-bold': scope.row.is_weighted_avg }">-->
<!--                                        {{ scope.row['period_' + i] }}-->
<!--                                    </span>-->
<!--                                </el-tooltip>-->
<!--                                <span v-else :class="{ 'font-bold': scope.row.is_weighted_avg }">-->
<!--                                    {{ scope.row['period_' + i] || '' }}-->
<!--                                </span>-->
<!--                            </template>-->
<!--                        </el-table-column>-->

<!--                        <template #empty>-->
<!--                            <el-empty :text="translate('No data available')" />-->
<!--                        </template>-->
<!--                    </el-table>-->
<!--                </template>-->

<!--                <Empty v-else icon="Empty/ListView" :has-dark="true"-->
<!--                    :text="translate('No cohort data available for the selected period.')" -->
<!--                    class="fct-report-empty-state" />-->
            <!-- </Card.Body>
        </Card.Container> -->
    </UserCan>
</template>

<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import { ref, computed, onMounted, watch } from "vue";
import { ElMessage } from "element-plus";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import ExportReport from "../ExportReport.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import {handleError, handleSuccess, handleResponse, $confirm} from "@/Bits/common";

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
const cohortData = ref({
    cohorts: [],
    weighted_averages: [],
    group_by: 'year',
    metric: 'subscribers'
});
const loading = ref(true);
const groupBy = ref('year');
const metric = ref('subscribers');
const displayMode = ref('percent');
const maxPeriods = ref(12); // Will be updated from API response

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const isEmpty = computed(() => {
    return !cohortData.value.cohorts || cohortData.value.cohorts.length === 0;
});

/**
 * Export data - flattens cohort data into CSV-friendly format
 */
const exportData = computed(() => {
    if (isEmpty.value) return [];
    
    const rows = [];
    
    cohortData.value.cohorts.forEach(cohort => {
        const row = {
            cohort: cohort.cohort,
            start_value: metric.value === 'mrr' ? cohort.start_mrr : cohort.start_count
        };
        
        // Add each period's data
        cohort.periods.forEach((period, index) => {
            const periodNum = index + 1;
            const periodLabel = groupBy.value === 'year' ? `year_${periodNum}` : `month_${periodNum}`;
            
            if (metric.value === 'mrr') {
                row[`${periodLabel}_retained`] = period.retained_mrr;
                row[`${periodLabel}_retention_rate`] = period.retention_rate_mrr;
            } else {
                row[`${periodLabel}_retained`] = period.retained_count;
                row[`${periodLabel}_retention_rate`] = period.retention_rate_count;
            }
        });
        
        rows.push(row);
    });
    
    // Add weighted average row
    if (cohortData.value.weighted_averages?.length > 0) {
        const avgRow = {
            cohort: 'Weighted Average',
            start_value: '-'
        };
        
        cohortData.value.weighted_averages.forEach((avg, index) => {
            const periodNum = index + 1;
            const periodLabel = groupBy.value === 'year' ? `year_${periodNum}` : `month_${periodNum}`;
            
            avgRow[`${periodLabel}_retained`] = '-';
            avgRow[`${periodLabel}_retention_rate`] = metric.value === 'mrr' 
                ? avg.weighted_avg_mrr 
                : avg.weighted_avg_count;
        });
        
        rows.push(avgRow);
    }
    
    return rows;
});

/**
 * Table data - transforms cohort data into flat rows for el-table
 */
const tableData = computed(() => {
    if (isEmpty.value) return [];
    
    const rows = [];
    
    // Add cohort rows
    cohortData.value.cohorts.forEach(cohort => {
        const row = {
            cohort: cohort.cohort,
            cohort_label: formatCohortLabel(cohort.cohort),
            start_value: formatStartValue(cohort),
            start_mrr: cohort.start_mrr,
            start_count: cohort.start_count,
            is_weighted_avg: false
        };
        
        // Add each period's data
        cohort.periods.forEach((period, index) => {
            const periodNum = index + 1;
            row[`period_${periodNum}`] = getDisplayValue(period);
            row[`period_${periodNum}_data`] = period;
            
            // Build tooltip content
            if (getDisplayValue(period)) {
                let tooltipContent = '';
                if (metric.value === 'mrr') {
                    tooltipContent = `<strong>${period.retention_rate_mrr}%</strong> (${formatCurrency(period.retained_mrr)}) of ${formatCurrency(cohort.start_mrr)} remains`;
                } else {
                    tooltipContent = `<strong>${period.retention_rate_count}%</strong> (${period.retained_count}) of ${cohort.start_count} remains`;
                }
                if (period.churned_mrr > 0 || period.churned_count > 0) {
                    const churnedValue = metric.value === 'mrr' ? formatCurrency(period.churned_mrr) : period.churned_count;
                    tooltipContent += `<div class="mt-1 text-gray-400">${churnedValue} churned in this period</div>`;
                }
                row[`period_${periodNum}_tooltip`] = tooltipContent;
            }
        });
        
        rows.push(row);
    });
    
    // Add weighted average row
    if (cohortData.value.weighted_averages?.length > 0) {
        const avgRow = {
            cohort: 'weighted_avg',
            cohort_label: translate('Weighted Avg. Retained'),
            start_value: '',
            is_weighted_avg: true
        };
        
        cohortData.value.weighted_averages.forEach((avg, index) => {
            const periodNum = index + 1;
            avgRow[`period_${periodNum}`] = getWeightedDisplayValue(avg);
            avgRow[`period_${periodNum}_data`] = avg;
            avgRow[`period_${periodNum}_is_weighted`] = true;
        });
        
        rows.push(avgRow);
    }
    
    return rows;
});

/**
 * ----------------------------------------------------------------------------
 * Methods
 * ----------------------------------------------------------------------------
 */

/**
 * Cell style handler for el-table - applies heatmap coloring
 */
const cellStyleHandler = ({ row, column, rowIndex, columnIndex }) => {
    // Skip first two columns (cohort label and start value)
    // if (columnIndex < 2) {
    //     if (row.is_weighted_avg) {
    //         return { backgroundColor: '#f3f4f6', fontWeight: 'bold' };
    //     }
    //     return {};
    // }
    
    const periodNum = columnIndex - 1; // Adjust for the two fixed columns
    const periodData = row[`period_${periodNum}_data`];
    
    if (!periodData) return {};
    
    if (row.is_weighted_avg) {
        return getWeightedCellStyle(periodData);
    }
    
    return getCellStyle(periodData);
};

/**
 * Row class name for el-table
 */
const rowClassName = ({ row, rowIndex }) => {
    if (row.is_weighted_avg) {
        return 'weighted-average-row';
    }
    return 'cohort-row';
};

/**
 * Span method for el-table - makes weighted avg row's first column span 2
 */
const spanMethod = ({ row, column, rowIndex, columnIndex }) => {
    if (row.is_weighted_avg) {
        if (columnIndex === 0) {
            // First column spans 2 columns
            return { rowspan: 1, colspan: 2 };
        } else if (columnIndex === 1) {
            // Second column is hidden (absorbed by first)
            return { rowspan: 0, colspan: 0 };
        }
    }
    return { rowspan: 1, colspan: 1 };
};

const fetchData = (filters = null) => {
    loading.value = true;
    
    const params = reportFilter.applicableFilters.params;

    Rest.get('reports/subscription-cohorts', {
        params: {
            ...params,
            groupBy: groupBy.value,
            metric: metric.value
        }
    }).then(response => {
        cohortData.value = response;
        // Update maxPeriods from server response
        if (response.max_periods) {
            maxPeriods.value = response.max_periods;
        }
    }).finally(() => loading.value = false);
};

const getPeriodLabel = (index) => {
    const labels = {
        'month': `Month ${index}`,
        'week': `Week ${index}`,
        'year': `Year ${index}`
    };
    return labels[groupBy.value] || `Period ${index}`;
};

/**
 * Get the last day (Sunday) of a given ISO week
 * @param {number} year - The year
 * @param {number} week - The ISO week number
 * @returns {Date} - The last day (Sunday) of that week
 */
const getLastDayOfWeek = (year, week) => {
    // ISO week 1 is the week containing the first Thursday of the year
    const jan4 = new Date(year, 0, 4); // Jan 4 is always in week 1
    const dayOfWeek = jan4.getDay() || 7; // Convert Sunday (0) to 7
    const mondayOfWeek1 = new Date(jan4);
    mondayOfWeek1.setDate(jan4.getDate() - dayOfWeek + 1);
    
    // Calculate the Monday of the target week
    const targetMonday = new Date(mondayOfWeek1);
    targetMonday.setDate(mondayOfWeek1.getDate() + (week - 1) * 7);
    
    // Get Sunday (last day of the week)
    const sunday = new Date(targetMonday);
    sunday.setDate(targetMonday.getDate() + 6);
    
    return sunday;
};

const formatCohortLabel = (cohort) => {
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    // Format based on groupBy
    if (groupBy.value === 'month') {
        const [year, month] = cohort.split('-');
        return `${monthNames[parseInt(month) - 1]} ${year}`;
    } else if (groupBy.value === 'week') {
        // Format: "2024-35" → "Aug 31, 2024" (last day of week 35)
        const [year, week] = cohort.split('-');
        const lastDay = getLastDayOfWeek(parseInt(year), parseInt(week));
        const monthName = monthNames[lastDay.getMonth()];
        const day = lastDay.getDate();
        return `${monthName} ${day}, ${lastDay.getFullYear()}`;
    }
    
    return cohort;
};

const formatStartValue = (cohort) => {
    if (metric.value === 'mrr') {
        return formatCurrency(cohort.start_mrr);
    }
    return cohort.start_count.toLocaleString();
};

const formatCurrency = (value) => {
    return CurrencyFormatter.scaled(value);
};

const getDisplayValue = (period) => {
    if (displayMode.value === 'percent') {
        const rate = metric.value === 'mrr' ? period.retention_rate_mrr : period.retention_rate_count;
        if (rate === null || rate === undefined) return '';
        return `${rate}%`;
    } else if (displayMode.value === 'percent_previous') {
        const rate = metric.value === 'mrr' ? period.retention_rate_previous_mrr : period.retention_rate_previous_count;
        if (rate === null || rate === undefined) return '';
        return `${rate}%`;
    } else if (displayMode.value === 'value') {
        const value = metric.value === 'mrr' ? period.retained_mrr : period.retained_count;
        if (value === null || value === undefined) return '';
        if (metric.value === 'mrr') {
            return formatCurrency(value);
        }
        return value.toLocaleString();
    } else if (displayMode.value === 'percent_ended') {
        const rate = metric.value === 'mrr' ? period.churn_rate_total_mrr : period.churn_rate_total_count;
        if (rate === null || rate === undefined) return '';
        return `${rate}%`;
    }
    
    return '';
};

const getWeightedDisplayValue = (avg) => {
    if (displayMode.value === 'percent') {
        const rate = metric.value === 'mrr' ? avg.weighted_avg_mrr : avg.weighted_avg_count;
        return `${rate}%`;
    } else if (displayMode.value === 'percent_previous') {
        const rate = metric.value === 'mrr' ? avg.weighted_avg_prev_mrr : avg.weighted_avg_prev_count;
        return `${rate}%`;
    } else if (displayMode.value === 'value') {
        // For value mode, we still show retention % in the weighted average row
        const rate = metric.value === 'mrr' ? avg.weighted_avg_mrr : avg.weighted_avg_count;
        return `${rate}%`;
    } else if (displayMode.value === 'percent_ended') {
        const rate = metric.value === 'mrr' ? avg.weighted_avg_churn_mrr : avg.weighted_avg_churn_count;
        return `${rate}%`;
    }
    return '-';
};

const getCellClass = (period) => {
    let rate;
    
    if (displayMode.value === 'percent_ended') {
        // For churn rate, lower is better (green), higher is worse (red/low retention color)
        // But we want to visualize churn, so high churn = dark color? 
        // Usually cohort charts visualize retention. If showing churn, maybe inverse?
        // Let's keep the "heat map" logic: higher value = darker color.
        rate = metric.value === 'mrr' ? period.churn_rate_total_mrr : period.churn_rate_total_count;
    } else if (displayMode.value === 'percent_previous') {
        rate = metric.value === 'mrr' ? period.retention_rate_previous_mrr : period.retention_rate_previous_count;
    } else if (displayMode.value === 'value') {
        // For value, we can't easily use fixed percentages. 
        // We'll use the retention rate as a proxy for the heat map intensity
        rate = metric.value === 'mrr' ? period.retention_rate_mrr : period.retention_rate_count;
    } else {
        rate = metric.value === 'mrr' ? period.retention_rate_mrr : period.retention_rate_count;
    }
    
    if (rate === 0) return 'retention-0';
    if (rate >= 90) return 'retention-high';
    if (rate >= 70) return 'retention-medium';
    return 'retention-low';
};

const getCellStyle = (period) => {
    let rate;
    
    if (displayMode.value === 'percent_ended') {
        rate = metric.value === 'mrr' ? period.churn_rate_total_mrr : period.churn_rate_total_count;
    } else if (displayMode.value === 'percent_previous') {
        rate = metric.value === 'mrr' ? period.retention_rate_previous_mrr : period.retention_rate_previous_count;
    } else if (displayMode.value === 'value') {
        // Use retention rate for opacity
        rate = metric.value === 'mrr' ? period.retention_rate_mrr : period.retention_rate_count;
    } else {
        rate = metric.value === 'mrr' ? period.retention_rate_mrr : period.retention_rate_count;
    }
    
    // Handle null/undefined (future periods) or 0 (no retention)
    if (rate === null || rate === undefined || rate === 0) return {};
    
    // Calculate opacity in 5% steps
    const step = Math.floor(rate / 5) * 5;
    const opacity = step / 100;
    
    // Base color: #335CFF (RGB: 51, 92, 255) - Information Base Blue
    return {
        backgroundColor: `rgba(51, 92, 255, ${opacity})`,
        color: opacity > 0.5 ? '#ffffff' : '#1f2937'
    };
};

const getWeightedCellClass = (avg) => {
    let rate;
    
    if (displayMode.value === 'percent_ended') {
        rate = metric.value === 'mrr' ? avg.weighted_avg_churn_mrr : avg.weighted_avg_churn_count;
    } else if (displayMode.value === 'percent_previous') {
        rate = metric.value === 'mrr' ? avg.weighted_avg_prev_mrr : avg.weighted_avg_prev_count;
    } else {
        rate = metric.value === 'mrr' ? avg.weighted_avg_mrr : avg.weighted_avg_count;
    }
    
    if (rate >= 90) return 'retention-high';
    if (rate >= 70) return 'retention-medium';
    return 'retention-low';
};

const getWeightedCellStyle = (avg) => {
    let rate;
    
    if (displayMode.value === 'percent_ended') {
        rate = metric.value === 'mrr' ? avg.weighted_avg_churn_mrr : avg.weighted_avg_churn_count;
    } else if (displayMode.value === 'percent_previous') {
        rate = metric.value === 'mrr' ? avg.weighted_avg_prev_mrr : avg.weighted_avg_prev_count;
    } else {
        rate = metric.value === 'mrr' ? avg.weighted_avg_mrr : avg.weighted_avg_count;
    }
    
    // Calculate opacity in 5% steps
    const step = Math.floor(rate / 5) * 5;
    const opacity = step / 100;
    
    return {
        backgroundColor: `rgba(51, 92, 255, ${opacity})`,
        color: opacity > 0.5 ? '#ffffff' : '#1f2937'
    };
};

/**
 * Generate retention snapshots
 */
const generateSnapshots = async () => {
    $confirm(
        translate('This will regenerate all retention snapshots. This may take 30-60 seconds.'),
        translate('Please confirm'),
        {
            confirmButtonText: translate('Continue'),
            cancelButtonText: translate('Cancel'),
            type: 'warning'
        }
    )
        .then(async () => {
            loading.value = true;
            try {
                const response = await Rest.post('reports/retention-snapshots/generate');
                
                if (response.success && response.mode === 'background') {
                    // Background job queued - poll for status
                    handleResponse(translate('Snapshot generation started in background!'));
                    pollJobStatus(response.job_id);
                } else if (response.success && response.mode === 'synchronous') {
                    // Completed synchronously
                    handleSuccess(translate('Snapshots generated successfully!'));
                    loading.value = false;
                    await fetchData();
                } else {
                    handleError(response.message || translate('Failed to generate snapshots!'));
                    loading.value = false;
                }
            } catch (error) {
                handleError(translate('Error generating snapshots'));
                console.error(error);
                loading.value = false;
            }
        })
        .catch(() => {
            // User cancelled
        });
};

/**
 * Poll job status until completion
 */
const pollJobStatus = async (jobId) => {
    const maxAttempts = 10; // Poll for up to 100 seconds
    let attempts = 0;

    const checkStatus = async () => {
        try {
            const response = await Rest.get('reports/retention-snapshots/status', {
                params: { job_id: jobId }
            });

            if (response.success && response.status === 'completed') {
                handleSuccess(translate('Snapshots generated successfully!'));
                loading.value = false;
                await fetchData();
                return;
            }

            if (response.success && response.status === 'failed') {
                handleError(response.message || translate('Snapshot generation failed!'));
                loading.value = false;
                return;
            }

            // Still running - poll again
            attempts++;
            if (attempts < maxAttempts) {
                setTimeout(checkStatus, 10000); // Check every 10 seconds
            } else {
                handleResponse(translate('Job is still running. Please refresh the page in a moment!'));
                loading.value = false;
            }
        } catch (error) {
            handleError(translate('Error checking job status!'));
            console.error(error);
            loading.value = false;
        }
    };

    checkStatus();
};

/**
 * ----------------------------------------------------------------------------
 * Watchers
 * ----------------------------------------------------------------------------
 */
watch([groupBy], () => {
    fetchData();
});

/**
 * ----------------------------------------------------------------------------
 * Hooks
 * ----------------------------------------------------------------------------
 */
onMounted(() => {
    fetchData();
    reportFilter.addListener("cohort-report", fetchData);
});
</script>

<style scoped>
/* El-table cohort styling */
.fct-cohort-el-table {
    font-size: 13px;
}
</style>
