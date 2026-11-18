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
    <fct-skeleton animated>
            <fct-table :data="rows" :class="['w-full', { 'compact-table': canManage }]">
                <fct-table-column :label="translate('Rule')" :width="200">
                    <div class="table-cell">
                        <div class="flex flex-col items-start gap-1.5 w-full">
                            <fct-skeleton variant="p" class="w-4/5"/>
                            <fct-skeleton variant="p" class="w-1/2"/>
                        </div>
                    </div>
                </fct-table-column>

                <fct-table-column v-if="pricingRuleTable.isColumnVisible('type')" :label="translate('What it does')" :width="340">
                    <div class="table-cell">
                        <div class="flex items-center gap-1.5 w-full">
                            <fct-skeleton variant="button" class="w-[100px] h-6 rounded-md"/>
                            <fct-skeleton variant="text" class="w-8"/>
                            <fct-skeleton variant="button" class="w-[100px] h-6 rounded-md"/>
                            <fct-skeleton variant="text" class="w-[96px]"/>
                        </div>
                    </div>
                </fct-table-column>

                <fct-table-column v-if="pricingRuleTable.isColumnVisible('date')" :label="translate('Schedule')" :width="160">
                    <div class="table-cell">
                        <div class="flex flex-col items-start gap-1.5 w-full">
                            <fct-skeleton variant="p" class="w-4/5"/>
                            <fct-skeleton variant="p" class="w-3/5"/>
                        </div>
                    </div>
                </fct-table-column>

                <fct-table-column v-if="pricingRuleTable.isColumnVisible('status')" :label="translate('Status')" :width="80" align="center">
                    <div class="table-cell justify-center">
                        <fct-skeleton variant="button" class="w-10 h-5 rounded-full"/>
                    </div>
                </fct-table-column>

                <fct-table-column v-if="canManage" :label="translate('Actions')" :width="80" align="right">
                    <div class="table-cell justify-end">
                        <fct-skeleton variant="circle" class="w-6 h-6"/>
                    </div>
                </fct-table-column>

                <template #empty>
                </template>
            </fct-table>
    </fct-skeleton>
</template>
