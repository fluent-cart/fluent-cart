<template>
    <el-dialog v-model="modalVisible" :title="translate('Update Stocks')">
        <div class="fct-bulk-modal-content">
            <!-- Mode Selection with Radio Buttons -->
            <div class="fct-inventory-mode-selection">
                <el-radio-group v-model="bulkMode" class="fct-mode-radio-group">
                    <el-radio value="add">
                        {{ translate('Increase Stock') }}
                    </el-radio>
                    <el-radio value="set">
                        {{ translate('Set Exact Stock') }}
                    </el-radio>
                </el-radio-group>
            </div>

            <!-- Value Input -->
            <div class="fct-inventory-input-section">
                <LabelHint
                    :title="bulkMode === 'add' ? translate('Increase Stock by') : translate('Exact new stock')"
                    :content="bulkMode === 'add' ? translate('Increase stock by value (e.g. 10 + 5 = 15)') : translate('Set stock to exact value (e.g. 10 → 20)')"
                />

                <el-input
                    v-model.number="bulkStock"
                    type="number"
                    :placeholder="bulkMode === 'add' ? translate('Enter amount to add') : translate('Enter exact stock value')"
                />

                <div v-if="bulkMode === 'set'" class="fct-inventory-mode-hint">
                    <DynamicIcon name="Warning" />
                    {{ translate('Replaces current inventory') }}
                </div>
            </div>

            <!-- Selected Items -->
            <div class="fct-inventory-selected-items">
                <div class="fct-inventory-selected-items-count">
                    {{ selectedItems.length }} {{ translate('variants will be changed:') }}
                </div>

                <div v-if="selectedItems?.length" class="fct-inventory-selected-items-preview">
                    <div class="fct-inventory-selected-item-header">
                        <span class="variant-title">
                            {{ translate('Variant') }}
                        </span>
                        <span class="total-stock">
                            {{ translate('Stock Change') }}
                        </span>
                    </div>

                    <ul class="fct-inventory-selected-item-list">
                        <li v-for="item in selectedItems" :key="item.id">
                            <div class="fct-inventory-selected-item-info">
                                <span class="fct-inventory-selected-item-title">
                                    {{ item.variation_title }}
                                </span>
                                <span class="fct-inventory-selected-item-price" v-html="item.formatted_total"></span>
                            </div>

                            <div class="fct-inventory-selected-item-stock-action">
                                <span class="total-stock">
                                    {{ item.total_stock }}
                                </span>

                                <template v-if="shouldShowBulkStockPreview(item)">
                                    <span class="stock-arrow">
                                        <DynamicIcon name="ArrowRight" />
                                    </span>

                                    <span class="bulk-stock-value">
                                        {{ calculateNewStock(item) }}
                                    </span>
                                </template>
                            </div>
                        </li>
                    </ul>
                </div>

                
            </div>

            <!-- Adjustment Reason -->
            <div class="fct-inventory-adjustment-reason">
                <ReasonDropdown
                    v-model="bulkAdjustmentReason"
                    @update:customReason="customBulkReasonText = $event"
                />
            </div>

            <!-- Footer -->
            <div class="dialog-footer">
                <el-button @click="handleCancel">
                    {{ translate('Cancel') }}
                </el-button>
                <el-button type="primary" @click="handleSave" :disabled="!bulkAdjustmentReason">
                    {{ translate('Update Stocks') }}
                </el-button>
            </div>
        </div>
    </el-dialog>
</template>

<script setup>
import { defineOptions, ref } from 'vue';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import ReasonDropdown from './ReasonDropdown.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import LabelHint from '@/Bits/Components/LabelHint.vue';

defineOptions({
    name: 'InventoryBulkAdjustModal'
});

const props = defineProps({
    selectedItems: {
        type: Array,
        required: true
    }
});

const emit = defineEmits(['bulk-update-success', 'close']);

const modalVisible = ref(false);
const bulkMode = ref('add');
const bulkStock = ref(null);
const bulkAdjustmentReason = ref('');
const customBulkReasonText = ref('');

const calculateNewStock = (item) => {
    if (bulkMode.value === 'add') {
        return item.total_stock + bulkStock.value;
    }
    return bulkStock.value;
};

const shouldShowBulkStockPreview = (item) => {
    return (bulkStock.value !== null && bulkStock.value !== '') && calculateNewStock(item) !== item.total_stock;
};

const handleCancel = () => {
    resetModal();
    modalVisible.value = false;
};

const handleSave = () => {
    const payload = {
        mode: bulkMode.value,
        value: bulkStock.value,
        reason: bulkAdjustmentReason.value,
        customReason: bulkAdjustmentReason.value === 'other' ? customBulkReasonText.value : '',
        items: props.selectedItems.map(item => ({
            id: item.id,
            post_id: item.post_id
        }))
    };

    Rest.post('inventory/bulk-update', payload)
        .then(() => {
            Notify.success(translate('Stock updated successfully'));
            resetModal();
            modalVisible.value = false;
            emit('bulk-update-success');
        })
        .catch(error => {
            if (error.status_code === 422) {
                Notify.validationErrors(error);
            } else {
                Notify.error(error.data?.message || translate('Failed to update stock'));
            }
        });
};

const resetModal = () => {
    bulkStock.value = null;
    bulkMode.value = 'add';
    bulkAdjustmentReason.value = '';
    customBulkReasonText.value = '';
};

const openModal = () => {
    resetModal();
    modalVisible.value = true;
};

defineExpose({
    openModal
});
</script>
