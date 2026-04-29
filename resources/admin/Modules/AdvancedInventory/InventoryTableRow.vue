<template>
    <tr class="fct-inventory-row">
        <td width="50">
            <div class="flex items-center gap-1">
                <el-checkbox
                    :model-value="isRowSelected(row.variants[0])"
                    @change="toggleRowSelection(row.variants[0])"
                />
            </div>
        </td>

        <td>
            <div
                class="fct-inventory-product-info cursor-pointer"
                @click="navigateToProduct(row.ID)"
            >
                <img
                    v-if="row.thumbnail !== null"
                    :src="row.thumbnail"
                    :alt="row.post_title"
                    class="inventory-thumbnail"
                />

                <img v-else :src="getPlaceholderImage()" :alt="row.post_title" />

                <div class="fct-inventory-product-content">
                    <span class="fct-inventory-product-title">
                        {{ row.post_title }}
                    </span>
                </div>
            </div>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('sku')">
            <span v-if="row.variants[0].sku">
                {{ row.variants[0].sku }}
            </span>
            <span v-else class="text-system-mid dark:text-gray-300">
                {{ translate('No SKU') }}
            </span>
        </td>

        <td>
            <el-input
                v-model="row.variants[0].total_stock"
                class="el-input--x-small input-with-total-stock fct-input-group"
                readonly
                size="small"
            >
                <template #append>
                    <InventoryStockAdjuster
                        :variant="row.variants[0]"
                        @save="$emit('stock-save', $event)"
                    />
                </template>
            </el-input>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('available')">
            <span>{{ row.variants[0].available }}</span>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('on_hand')">
            <span>{{ row.variants[0].on_hold }}</span>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('committed')">
            <span>{{ row.variants[0].committed }}</span>
        </td>

        <td>
            <IconButton
                tag="button"
                size="small"
                @click="$emit('open-history', row.variants[0])"
            >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 2.5C14.1422 2.5 17.5 5.85775 17.5 10C17.5 14.1422 14.1422 17.5 10 17.5C5.85775 17.5 2.5 14.1422 2.5 10H4C4 13.3135 6.6865 16 10 16C13.3135 16 16 13.3135 16 10C16 6.6865 13.3135 4 10 4C7.9375 4 6.118 5.04025 5.03875 6.625H7V8.125H2.5V3.625H4V5.5C5.368 3.6775 7.54675 2.5 10 2.5ZM10.75 6.25V9.68875L13.1823 12.121L12.121 13.1823L9.25 10.3098V6.25H10.75Z" fill="currentColor"/>
                </svg>
            </IconButton>
        </td>
    </tr>
</template>

<script setup>
import { defineOptions } from 'vue';
import translate from '@/utils/translator/Translator';
import InventoryStockAdjuster from './InventoryStockAdjuster.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';

defineOptions({
    name: 'InventoryTableRow'
});

const props = defineProps({
    row: {
        type: Object,
        required: true
    },
    inventoryTable: Object,
    isRowSelected: {
        type: Function,
        required: true
    },
    toggleRowSelection: {
        type: Function,
        required: true
    },
    navigateToProduct: {
        type: Function,
        required: true
    },
    getPlaceholderImage: {
        type: Function,
        required: true
    }
});

defineEmits(['stock-save', 'open-history']);
</script>
