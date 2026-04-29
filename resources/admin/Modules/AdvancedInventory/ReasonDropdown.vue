<template>
    <div class="fct-reason-dropdown-wrap">
        <label class="fct-reason-label">{{ translate('Reason') }}</label>
        <el-select
            v-model="selectedReason"
            :placeholder="translate('Select reason')"
            class="w-full"
            @change="handleChange"
        >
            <el-option :label="translate('Received Stock')" value="received" />
            <el-option :label="translate('Damage/Loss')" value="damage" />
            <el-option :label="translate('Return/Refund')" value="return" />
            <el-option :label="translate('Count Correction')" value="correction" />
            <el-option :label="translate('Inventory Transfer')" value="transfer" />
            <el-option :label="translate('Other')" value="other" />
        </el-select>

        <!-- Custom Reason Textarea for "Other" -->
        <Animation
            :visible="selectedReason === 'other'"
            accordion
            :duration="300"
        >
            <el-input
                v-model="customReasonText"
                type="textarea"
                :rows="3"
                :placeholder="translate('Please describe the reason')"
                class="fct-custom-reason-textarea"
                @input="handleChange"
            />
        </Animation>
    </div>
</template>

<script setup>
import { defineOptions, ref, watch } from 'vue';
import translate from '@/utils/translator/Translator';
import Animation from '@/Bits/Components/Animation.vue';

defineOptions({
    name: 'ReasonDropdown'
});

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['update:modelValue', 'update:customReason']);

const selectedReason = ref(props.modelValue);
const customReasonText = ref('');

watch(() => props.modelValue, (newVal) => {
    selectedReason.value = newVal;
});

const handleChange = () => {
    emit('update:modelValue', selectedReason.value);
    if (selectedReason.value === 'other') {
        emit('update:customReason', customReasonText.value);
    }
};

const updateSelected = (reason) => {
    selectedReason.value = reason;
    handleChange();
};

defineExpose({
    getValue: () => selectedReason.value,
    getCustomReason: () => customReasonText.value,
    setValue: (reason) => updateSelected(reason),
    setCustomReason: (text) => {
        customReasonText.value = text;
    }
});
</script>
