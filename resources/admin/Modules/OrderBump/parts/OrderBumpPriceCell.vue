<script setup>
import {computed} from "vue";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import {applyDiscount} from "@/Modules/OrderBump/orderBumpPrice";

const props = defineProps({
    bump: {
        type: Object,
        required: true
    }
});

const variant = computed(() => props.bump.product_variant || null);
// Same rule as the flagged row: no product, no price to show.
const missing = computed(() => !variant.value?.product);

const pricing = computed(() => applyDiscount(variant.value.item_price, props.bump.config?.discount));
const regularPrice = computed(() => CurrencyFormatter.formatFixed(variant.value.item_price, true));
const offerPrice = computed(() => CurrencyFormatter.formatFixed(pricing.value.offer, true));
// Striking the regular price out is only honest when the offer really is cheaper.
const hasSaving = computed(() => pricing.value.saving > 0);
</script>

<template>
    <span v-if="missing" aria-hidden="true">—</span>
    <div v-else class="fct-order-bump-price">
        <div><span class="fct-order-bump-price__label">{{ translate('Regular Price:') }}</span> <span class="fct-order-bump-price__regular" :class="{'fct-order-bump-price__regular--struck': hasSaving}">{{ regularPrice }}</span></div>
        <div><span class="fct-order-bump-price__label">{{ translate('Offer Price:') }}</span> {{ offerPrice }}</div>
    </div>
</template>
