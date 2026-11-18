<script setup>
import translate from "@/utils/translator/Translator";
import {computed} from "vue";

const props = defineProps({
    bump: {
        type: Object,
        required: true
    }
});

const hasDiscount = computed(() => !!props.bump.originalPrice);
</script>

<template>
    <div class="fct-pv-toggle">
        <div class="fct-pv-toggle__icon">
            <img v-if="bump.imageUrl" :src="bump.imageUrl" alt="">
        </div>
        <div class="fct-pv-toggle__details">
            <div class="fct-pv-toggle__product">{{ bump.productTitle }}</div>
            <div v-if="bump.variationTitle" class="fct-pv-toggle__variation">{{ bump.variationTitle }}</div>
            <div class="fct-pv-toggle__prices">
                <span v-if="hasDiscount" class="fct-pv-toggle__original">{{ bump.originalPrice }}</span>
                <span class="fct-pv-toggle__price">{{ bump.price }}</span>
                <span v-if="hasDiscount && bump.savings" class="fct-pv-toggle__savings">{{ translate('Save %s', bump.savings) }}</span>
            </div>
        </div>
        <span class="fct-pv-toggle__switch" aria-hidden="true"></span>
    </div>
</template>
