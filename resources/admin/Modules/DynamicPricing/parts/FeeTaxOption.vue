<script setup>
import translate from "@/utils/translator/Translator";

// Stored as config.fee_taxable; a rule saved without it is taxed.
defineProps({
    modelValue: {
        type: String,
        default: 'yes'
    }
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="fct-pricing-rule-fee-tax">
        <el-switch
            :model-value="modelValue !== 'no'"
            :active-text="translate('Charge tax on this fee')"
            @update:model-value="(value) => emit('update:modelValue', value ? 'yes' : 'no')"
        />
        <div class="form-note">
            <p>{{ translate('Tax is worked out on the fee at the same rate as the cart items.') }}</p>
        </div>
    </div>
</template>
