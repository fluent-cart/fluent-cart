<template>
    <el-skeleton animated>
        <template #template>
            <div class="fct-advanced-inventory-table">
                <div class="fct-inventory-toolbar-wrap">
                    <div class="fct-inventory-toolbar-actions ml-auto">
                        <el-skeleton-item variant="button" class="w-28 h-8 rounded" />
                    </div>
                </div>

                <div class="fct-advanced-inventory-table-wrap">
                    <table class="fct-advanced-inventory-table">
                        <colgroup>
                            <col width="50" />
                            <col />
                            <col v-if="inventoryTable?.isColumnVisible('sku')" width="150" />
                            <col width="160" />
                            <col v-if="inventoryTable?.isColumnVisible('available')" width="100" />
                            <col v-if="inventoryTable?.isColumnVisible('on_hand')" width="100" />
                            <col v-if="inventoryTable?.isColumnVisible('committed')" width="100" />
                            <col width="40" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th width="50">
                                    <el-skeleton-item variant="p" class="w-4 h-4" />
                                </th>
                                <th>
                                    <el-skeleton-item variant="p" class="w-20" />
                                </th>
                                <th v-if="inventoryTable?.isColumnVisible('sku')">
                                    <el-skeleton-item variant="p" class="w-10" />
                                </th>
                                <th>
                                    <el-skeleton-item variant="p" class="w-24" />
                                </th>
                                <th v-if="inventoryTable?.isColumnVisible('available')">
                                    <el-skeleton-item variant="p" class="w-20" />
                                </th>
                                <th v-if="inventoryTable?.isColumnVisible('on_hand')">
                                    <el-skeleton-item variant="p" class="w-16" />
                                </th>
                                <th v-if="inventoryTable?.isColumnVisible('committed')">
                                    <el-skeleton-item variant="p" class="w-20" />
                                </th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in rows" :key="i">
                                <td>
                                    <el-skeleton-item variant="p" class="w-4 h-4" />
                                </td>
                                <td>
                                    <div class="fct-product-info-card">
                                        <div class="fct-product-info-media">
                                            <el-skeleton-item variant="image" class="w-10 h-10 rounded flex-none" />
                                        </div>
                                        <div class="fct-product-info-details">
                                            <el-skeleton-item variant="p" class="max-w-[180px]" />
                                        </div>
                                    </div>
                                </td>
                                <td v-if="inventoryTable?.isColumnVisible('sku')">
                                    <el-skeleton-item variant="p" class="w-20" />
                                </td>
                                <td>
                                    <el-skeleton-item variant="p" class="w-16" />
                                </td>
                                <td v-if="inventoryTable?.isColumnVisible('available')">
                                    <el-skeleton-item variant="p" class="w-10" />
                                </td>
                                <td v-if="inventoryTable?.isColumnVisible('on_hand')">
                                    <el-skeleton-item variant="p" class="w-10" />
                                </td>
                                <td v-if="inventoryTable?.isColumnVisible('committed')">
                                    <el-skeleton-item variant="p" class="w-10" />
                                </td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </el-skeleton>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    nextPageCount: {
        type: Number,
        default: 10
    },
    inventoryTable: {
        type: Object,
        default: () => ({})
    }
});

const rows = computed(() => Array.from({ length: props.nextPageCount /2 }, (_, i) => i + 1));
</script>
