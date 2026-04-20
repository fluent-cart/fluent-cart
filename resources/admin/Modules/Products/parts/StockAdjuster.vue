<script setup>
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {ref, watch} from 'vue';

const props = defineProps({
    variant: { type: Object, default: null },
    fieldKey: { type: String, default: '' },
    productEditModel: { type: Object, default: null },
});

const emit = defineEmits(['save']);

const visible = ref(false);
const adjustedQuantity = ref(0);
const newStock = ref(parseInt(props.variant?.total_stock) || 0);

watch(() => props.variant?.id, () => {
    adjustedQuantity.value = 0;
    newStock.value = parseInt(props.variant?.total_stock) || 0;
    visible.value = false;
});

const toggleVisible = () => {
    if (!visible.value) {
        const total = parseInt(props.variant && props.variant.total_stock) || 0;
        adjustedQuantity.value = 0;
        newStock.value = total;
    }
    visible.value = !visible.value;
};

const onAdjustChange = (value) => {
    adjustedQuantity.value = value === '' ? '' : value;
    const delta = parseInt(value) || 0;
    const total = parseInt(props.variant && props.variant.total_stock) || 0;
    newStock.value = total + delta;
};

const onNewStockChange = (value) => {
    newStock.value = value === '' ? '' : value;
    const ns = parseInt(value) || 0;
    const total = parseInt(props.variant && props.variant.total_stock) || 0;
    adjustedQuantity.value = ns - total;
};

const applyStock = () => {
    if (props.variant) {
        props.variant.new_stock = parseInt(newStock.value) || 0;
        props.variant.adjusted_quantity = parseInt(adjustedQuantity.value) || 0;
    }
    visible.value = false;
    emit('save');
    // Reset for next open
    adjustedQuantity.value = 0;
    newStock.value = parseInt(props.variant?.total_stock) || 0;
};
</script>

<template>
    <el-popover v-model:visible="visible" popper-class="fct-stock-dropdown" placement="bottom-end">
        <div class="fct-adjust-by-wrap">
            <div class="fct-adjust-by-row">
                <div class="fct-adjust-by-col">
                    <span class="title">
                        {{ translate('Adjust by') }}
                    </span>
                    <el-input
                        size="small"
                        :placeholder="translate('Quantity')"
                        type="number"
                        :model-value="adjustedQuantity"
                        @update:model-value="onAdjustChange"
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
                        :min="0"
                        :model-value="newStock"
                        @update:model-value="onNewStockChange"
                    />
                </div>
            </div>
            <div class="fct-adjust-by-action">
                <el-button
                    size="small"
                    type="info"
                    soft
                    @click="visible = false"
                >
                    {{ translate('Cancel') }}
                </el-button>
                <el-button
                    size="small"
                    type="primary"
                    @click="applyStock"
                    :disabled="newStock === '' || newStock === null || newStock === undefined"
                >
                    {{ translate('Apply') }}
                </el-button>
            </div>
        </div>

        <template #reference>
            <button type="button" @click="toggleVisible" :aria-label="translate('Adjust stock')" class="fct-stock-adjuster-trigger">
                <DynamicIcon name="Configuration"/>
            </button>
        </template>
    </el-popover>
</template>
