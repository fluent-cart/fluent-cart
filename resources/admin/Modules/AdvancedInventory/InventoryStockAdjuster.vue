<template>
    <el-popover :visible="visible" popper-class="fct-stock-dropdown fct-inventory-stock-dropdown" trigger="click" placement="bottom-end">
        <div class="fct-adjust-by-wrap">
            <!-- Adjust By -->
            <div class="fct-adjust-by-row">
                <div class="fct-adjust-by-col">
                    <span class="title">
                        {{ translate('Adjust by') }}
                    </span>
                    <el-input
                        ref="adjustByInput"
                        size="small"
                        :placeholder="translate('Quantity')"
                        type="number"
                        v-model.number="adjustedQuantity"
                        @input="handleAdjustChange"
                    />
                </div>
                <div class="fct-adjust-by-col">
                    <span class="title">
                        {{ translate('New Stock') }}
                    </span>
                    <el-input
                        size="small"
                        :placeholder="translate('New Stock')"
                        type="number"
                        v-model.number="newStock"
                        @input="handleNewStockChange"
                    />
                </div>
            </div>

            <!-- Stock Info -->
            <Animation :visible="Boolean(adjustedQuantity)" accordion>
                <div class="fct-stock-info">
                    &#40;Original quantity:
                    <strong>{{ variant.total_stock }}</strong>&#41;
                </div>
            </Animation>

            <!-- Reason Dropdown -->
            <div class="fct-adjust-reason-wrap">
                <ReasonDropdown
                    ref="reasonDropdownRef"
                    v-model="selectedReason"
                    @update:customReason="customReasonText = $event"
                />
            </div>

            <!-- Action Buttons -->
            <div class="fct-adjust-by-action">
                <el-button
                    size="small"
                    type="info"
                    soft
                    @click="handleCancel"
                >
                    {{ translate('Cancel') }}
                </el-button>
                <el-button
                    size="small"
                    type="primary"
                    @click="handleApply"
                    :disabled="!selectedReason"
                >
                    {{ translate('Apply') }}
                </el-button>
            </div>
        </div>

        <template #reference>
            <div
                class="fct-stock-adjuster-trigger"
                @click="visible = !visible"
            >
                <DynamicIcon name="Configuration"/>
            </div>
        </template>
    </el-popover>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';
import translate from '@/utils/translator/Translator';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Animation from "@/Bits/Components/Animation.vue";
import ReasonDropdown from './ReasonDropdown.vue';

defineOptions({
    name: 'InventoryStockAdjuster'
});

const props = defineProps({
    variant: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['save']);

const visible = ref(false);
const adjustedQuantity = ref(0);
const newStock = ref(props.variant.total_stock);
const selectedReason = ref('');
const customReasonText = ref('');
const adjustByInput = ref(null);
const reasonDropdownRef = ref(null);

watch(visible, (newVal) => {
    if (newVal) {
        nextTick(() => {
            adjustByInput.value?.focus();
            adjustByInput.value?.select?.();
        });
    }
});

const handleAdjustChange = () => {
    const adjusted = adjustedQuantity.value || 0;
    const totalStock = parseInt(props.variant.total_stock) || 0;
    let calculated = totalStock + parseInt(adjusted);
    newStock.value = (calculated < 0) ? 0 : calculated;
};

const handleNewStockChange = () => {
    const newVal = newStock.value || 0;
    const totalStock = parseInt(props.variant.total_stock) || 0;
    adjustedQuantity.value = parseInt(newVal) - totalStock;
};

const handleCancel = () => {
    adjustedQuantity.value = 0;
    newStock.value = props.variant.total_stock;
    selectedReason.value = '';
    customReasonText.value = '';
    visible.value = false;
};

const handleApply = () => {
    emit('save', {
        variant: props.variant,
        newStock: newStock.value,
        adjustedQuantity: adjustedQuantity.value,
        reason: selectedReason.value,
        customReason: selectedReason.value === 'other' ? customReasonText.value : ''
    });
    visible.value = false;
    handleCancel();
};
</script>

