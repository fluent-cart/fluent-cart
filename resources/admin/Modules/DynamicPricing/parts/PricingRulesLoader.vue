<script setup>
import translate from "@/utils/translator/Translator";
import Permission from "@/utils/permission/Permission";

const props = defineProps({
    nextPageCount: {
        type: Number,
        default: 0
    },
    pricingRuleTable: {
        type: Object,
        default: () => ({})
    }
});

const canManage = Permission.has('dynamic_pricing/manage');

const rows = Array.from({length: props.nextPageCount}, (_, i) => i + 1);
</script>

<template>
    <!-- Mirrors PricingRulesTable: same columns, order, widths and cell shapes. -->
    <el-skeleton animated>
        <template #template>
            <el-table :data="rows" :class="['w-full', { 'compact-table': canManage }]">
                <el-table-column :label="translate('Rule')" :width="200">
                    <div class="table-cell">
                        <div class="flex flex-col items-start gap-1.5 w-full">
                            <el-skeleton-item variant="p" class="w-4/5"/>
                            <el-skeleton-item variant="p" class="w-1/2"/>
                        </div>
                    </div>
                </el-table-column>

                <el-table-column v-if="pricingRuleTable.isColumnVisible('type')" :label="translate('What it does')" :width="340">
                    <div class="table-cell">
                        <div class="flex items-center gap-1.5 w-full">
                            <el-skeleton-item variant="button" class="w-[100px] h-6 rounded-md"/>
                            <el-skeleton-item variant="text" class="w-8"/>
                            <el-skeleton-item variant="button" class="w-[100px] h-6 rounded-md"/>
                            <el-skeleton-item variant="text" class="w-[96px]"/>
                        </div>
                    </div>
                </el-table-column>

                <el-table-column v-if="pricingRuleTable.isColumnVisible('date')" :label="translate('Schedule')" :width="160">
                    <div class="table-cell">
                        <div class="flex flex-col items-start gap-1.5 w-full">
                            <el-skeleton-item variant="p" class="w-4/5"/>
                            <el-skeleton-item variant="p" class="w-3/5"/>
                        </div>
                    </div>
                </el-table-column>

                <el-table-column v-if="pricingRuleTable.isColumnVisible('status')" :label="translate('Status')" :width="80" align="center">
                    <div class="table-cell justify-center">
                        <el-skeleton-item variant="button" class="w-10 h-5 rounded-full"/>
                    </div>
                </el-table-column>

                <el-table-column v-if="canManage" :label="translate('Actions')" :width="80" align="right">
                    <div class="table-cell justify-end">
                        <el-skeleton-item variant="circle" class="w-6 h-6"/>
                    </div>
                </el-table-column>

                <template #empty>
                </template>
            </el-table>
        </template>
    </el-skeleton>
</template>
