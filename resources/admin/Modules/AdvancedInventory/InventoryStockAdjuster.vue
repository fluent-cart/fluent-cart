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
                    @update:custom-reason="customReasonText = $event"
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
                    :disabled="!selectedReason || isSavePending"
                    :loading="isSavePending"
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
import { computed, inject, ref, watch, nextTick } from 'vue';
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

// Pending state keyed by id (survives the table replacing variant objects
// on refetch); the object flag remains as a same-object fast path.
const pendingStockSaves = inject('fctPendingStockSaves', null);
const isSavePending = computed(() => {
    return Boolean(props.variant.stock_save_pending) || Boolean(pendingStockSaves?.has(props.variant.id));
});

const visible = ref(false);
const adjustedQuantity = ref(0);
const newStock = ref(props.variant.total_stock);
const selectedReason = ref('');
const customReasonText = ref('');
const adjustByInput = ref(null);
const reasonDropdownRef = ref(null);

// If the popover sat open while a save was in flight (Apply disabled), its
// inputs were seeded from the unconfirmed value. When confirmation lands,
// re-anchor: keep the admin's typed "Adjust by" delta and recompute the
// absolute New Stock from the now-confirmed total.
watch(isSavePending, (pending, wasPending) => {
    if (wasPending && !pending && visible.value) {
        handleAdjustChange();
    }
});

watch(visible, (newVal) => {
    if (newVal) {
        // Re-seed from the CURRENT stock every time the popover opens. The
        // confirmed value is written onto the variant asynchronously after
        // the previous save, so a mount-time seed goes stale — re-applying
        // it would silently revert the earlier adjustment.
        newStock.value = props.variant.total_stock;
        adjustedQuantity.value = 0;
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
    // A save for this variant is still in flight: both inputs were seeded
    // from a stock value the server hasn't confirmed yet, so an absolute
    // new_stock computed from it would silently drop the pending adjustment
    // (two quick +5s from 10 would send 15 twice instead of reaching 20).
    // The Apply button is disabled in this state; this guard covers keyboard
    // submission and races with the flag flipping mid-click.
    if (isSavePending.value) {
        return;
    }
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

