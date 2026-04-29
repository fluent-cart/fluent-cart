<template>
    <div class="fct-advanced-inventory-table">
        <!-- Bulk Tools -->
        <div class="fct-inventory-toolbar-wrap">
            <div v-if="selectedItems.length > 0" class="fct-inventory-toolbar-selected">
                {{ selectedItems.length }} {{ translate('selected') }}
            </div>

            <div class="fct-inventory-toolbar-actions ml-auto">
                <el-button v-if="selectedItems.length > 0" type="primary" size="small" @click="openBulkAdjustModal">
                    {{ translate('Update Stocks') }}
                </el-button>

                <el-button class="fct-expand-row-button" size="small" @click="toggleAllRows">
                    <DynamicIcon :name="allRowsExpanded ? 'Expand' : 'Collapse'" />

                    {{ allRowsExpanded ? translate('Collapse All') : translate('Expand All') }}
                </el-button>
            </div>
        </div>

        <!-- Table -->
        <div v-if="data.length > 0" class="fct-advanced-inventory-table-wrap">
           
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
                            <el-checkbox v-model="selectAll" :indeterminate="isIndeterminate" @change="toggleSelectAll" />
                        </th>
                        <th>{{ translate('Products') }}</th>
                        <th v-if="inventoryTable?.isColumnVisible('sku')">
                            {{ translate('SKU') }}
                        </th>
                        <th>
                            {{ translate('Total Stock') }}
                        </th>
                        <th v-if="inventoryTable?.isColumnVisible('available')">
                            {{ translate('Available') }}
                        </th>
                        <th v-if="inventoryTable?.isColumnVisible('on_hand')">
                            {{ translate('On hold') }}
                        </th>
                        <th v-if="inventoryTable?.isColumnVisible('committed')">
                            {{ translate('Delivered') }}
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in data" :key="getRowKey(row)">
                        <InventoryTableRow
                            v-if="!row.variants || row.variants.length === 1"
                            :row="row"
                            :inventory-table="inventoryTable"
                            :is-row-selected="isRowSelected"
                            :toggle-row-selection="toggleRowSelection"
                            :navigate-to-product="navigateToProduct"
                            :get-placeholder-image="getPlaceholderImage"
                            @stock-save="emit('stock-save', $event)"
                            @open-history="openAdjustmentHistory"
                        />

                        <!-- Parent Row with Variants -->
                        <template v-if="row.variants && row.variants.length > 1">
                            <InventoryParentTableRow
                                :row="row"
                                :inventory-table="inventoryTable"
                                :navigate-to-product="navigateToProduct"
                                :get-placeholder-image="getPlaceholderImage"
                                :is-row-selected="isRowSelected"
                                :toggle-row-selection="toggleRowSelection"
                                :expanded="allRowsExpanded"
                                @stock-save="emit('stock-save', $event)"
                                @open-history="openAdjustmentHistory"
                            />
                        </template>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Empty State -->
        <Empty v-else icon="Empty/ListView" :text="translate('No inventory data')" />

        <!-- Bulk Adjust Modal Component -->
        <InventoryBulkAdjustModal
            ref="bulkAdjustModalRef"
            :selected-items="selectedItems"
            @bulk-update-success="handleBulkUpdateSuccess"
        />

        <!-- Adjustment History Drawer -->
        <InventoryAdjustmentHistory ref="historyRef" />
    </div>
</template>

<script setup>
import { defineOptions, watch, ref } from 'vue';
import { useRouter } from 'vue-router';
import translate from '@/utils/translator/Translator';
import Empty from "@/Bits/Components/Table/Empty.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import InventoryTableRow from './InventoryTableRow.vue';
import InventoryParentTableRow from './InventoryParentTableRow.vue';
import InventoryAdjustmentHistory from './InventoryAdjustmentHistory.vue';
import InventoryBulkAdjustModal from './InventoryBulkAdjustModal.vue';

defineOptions({
    name: 'InventoryTable'
});

const router = useRouter();

const props = defineProps({
    data: {
        type: Array,
        default: () => []
    },
    inventoryTable: Object
});

const emit = defineEmits(['stock-save', 'bulk-update-success', 'selection-changed']);

const selectedItems = ref([]);
const selectAll = ref(false);
const isIndeterminate = ref(false);
const historyRef = ref(null);
const bulkAdjustModalRef = ref(null);
const allRowsExpanded = ref(true);

// Initialize variant properties when data changes
// watch(() => props.data, (newData) => {
//     if (newData && newData.length > 0) {
//         newData.forEach(row => {
//             if (!row.new_stock) {
//                 row.new_stock = row.total_stock;
//             }
//             if (row.adjusted_quantity === undefined) {
//                 row.adjusted_quantity = 0;
//             }
//             // Also initialize for child variants
//             if (row.variants && row.variants.length > 0) {
//                 row.variants.forEach(child => {
//                     if (!child.new_stock) {
//                         child.new_stock = child.total_stock;
//                     }
//                     if (child.adjusted_quantity === undefined) {
//                         child.adjusted_quantity = 0;
//                     }
//                 });
//             }
//         });
//     }
// }, { deep: true, immediate: true });

// Emit when selected items change
watch(selectedItems, (newItems) => {
    emit('selection-changed', {
        selectedItems: newItems,
        count: newItems.length
    });
}, { deep: true });

const getRowKey = (row) => {
    // Parent rows use id (product id), variant rows use id (variant id) and post_id
    return row.post_id ? `variant-${row.id}` : `product-${row.id}`;
};


const navigateToProduct = (id) => {
  router.push({ name: 'product_edit', params: { product_id: id } });
};


const openBulkAdjustModal = () => {
    if (bulkAdjustModalRef.value) {
        bulkAdjustModalRef.value.openModal();
    }
};

const handleBulkUpdateSuccess = () => {
    selectedItems.value = [];
    updateSelectAll();
    emit('bulk-update-success');
};

const getAllItems = () => {
    return props.data.flatMap(row => {
        if (row.variants && row.variants.length > 0) {
            return row.variants;
        }
        return [row];
    });
};

const toggleSelectAll = (value) => {
    if (value) {
        // Select all items
        selectedItems.value = getAllItems();
    } else {
        // Deselect all items
        selectedItems.value = [];
    }
    updateSelectAll();
};

const toggleRowSelection = (row) => {
    const index = selectedItems.value.findIndex(item => item.id === row.id);
    if (index > -1) {
        selectedItems.value.splice(index, 1);
    } else {
        selectedItems.value.push(row);
    }
    updateSelectAll();
};

const isRowSelected = (row) => {
    return selectedItems.value.some(item => item.id === row.id);
};

const updateSelectAll = () => {
    const allItems = getAllItems();
    const selectedCount = selectedItems.value.length;

    if (selectedCount === 0) {
        selectAll.value = false;
        isIndeterminate.value = false;
    } else if (selectedCount === allItems.length) {
        selectAll.value = true;
        isIndeterminate.value = false;
    } else {
        selectAll.value = false;
        isIndeterminate.value = true;
    }
};


// =============================
// ADJUSTMENT HISTORY
// =============================
const openAdjustmentHistory = (variant) => {
    if (historyRef.value) {
        historyRef.value.openHistory({
            id: variant.id,
            name: variant.variation_title || variant.post_title
        });
    }
};

const toggleAllRows = () => {
    allRowsExpanded.value = !allRowsExpanded.value;
};

</script>


