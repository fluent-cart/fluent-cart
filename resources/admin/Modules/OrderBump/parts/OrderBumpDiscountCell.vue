<script setup>
import {computed} from "vue";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

const props = defineProps({
    bump: {
        type: Object,
        required: true
    }
});

const discount = computed(() => props.bump.config?.discount || {discount_type: 'percentage', discount_amount: 0});
const isPercentage = computed(() => discount.value.discount_type === 'percentage');
const amount = computed(() => Number(discount.value.discount_amount) || 0);

const typeLabel = computed(() => isPercentage.value ? translate('Percentage') : translate('Fixed Discount'));
const valueLabel = computed(() => {
    return isPercentage.value ? amount.value + '%' : CurrencyFormatter.formatFixed(amount.value * 100, true);
});
</script>

<template>
    <div class="fct-order-bump-discount">
        <div><span class="fct-order-bump-discount__label">{{ translate('Type:') }}</span> {{ typeLabel }}</div>
        <div><span class="fct-order-bump-discount__label">{{ translate('Value:') }}</span> {{ valueLabel }}</div>
    </div>
</template>
