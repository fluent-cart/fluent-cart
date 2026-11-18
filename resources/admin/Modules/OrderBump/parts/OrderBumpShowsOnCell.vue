<script setup>
import {computed} from "vue";
import translate from "@/utils/translator/Translator";
import {placementOptions} from "../placementOptions";

const props = defineProps({
    bump: {
        type: Object,
        required: true
    }
});

const position = computed(() => props.bump.config?.position ?? '');

// '' is the Default placement, which sits under the order notes. A disabled one is not at the checkout.
const checkoutSpot = computed(() => {
    if (position.value === 'disabled') {
        return '';
    }

    const label = position.value
        ? placementOptions().find(option => option.value === position.value)?.label || ''
        : translate('Under order notes');

    // The first %s is the "·" separator and the second is the spot, so translators can swap the separator.
    return translate('Checkout %s %s', '·', label.toLowerCase());
});

const inCart = computed(() => props.bump.config?.show_in_cart === 'yes');
</script>

<template>
    <div class="fct-order-bump-shows-on">
        <span v-if="checkoutSpot" class="fct-order-bump-chip">{{ checkoutSpot }}</span>
        <span v-if="inCart" class="fct-order-bump-chip" :title="translate('Cart drawer and cart page')">{{ translate('Cart') }}</span>
        <span v-if="!checkoutSpot && !inCart" aria-hidden="true">—</span>
    </div>
</template>
