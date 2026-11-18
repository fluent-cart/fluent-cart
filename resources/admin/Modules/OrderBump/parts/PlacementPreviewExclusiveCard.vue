<script setup>
import translate from "@/utils/translator/Translator";
import {computed} from "vue";

const props = defineProps({
    bump: {
        type: Object,
        required: true
    }
});

const ctaText = computed(() => props.bump.callToAction || translate('Grab it for %s', props.bump.price));
const hasDiscount = computed(() => !!props.bump.originalPrice);
</script>

<template>
    <div class="fct-placement-preview__bump">
        <div class="fct-pp-excl">
            <div class="fct-pp-excl__top">
                <div class="fct-pp-excl__icon">
                    <img v-if="bump.imageUrl" :src="bump.imageUrl" alt="">
                </div>
                <div class="fct-pp-excl__details">
                    <div v-if="bump.title" class="fct-pp-excl__badge">{{ bump.title }}</div>
                    <div class="fct-pp-excl__product">{{ bump.productTitle }}</div>
                    <div v-if="bump.variationTitle" class="fct-pp-excl__variation">{{ bump.variationTitle }}</div>
                    <div class="fct-pp-excl__prices">
                        <span v-if="hasDiscount" class="fct-pp-excl__original">{{ bump.originalPrice }}</span>
                        <span class="fct-pp-excl__price">{{ bump.price }}</span>
                        <span v-if="hasDiscount && bump.savings" class="fct-pp-excl__savings">{{ translate('Save') }} {{ bump.savings }}</span>
                    </div>
                </div>
            </div>
            <div class="fct-pp-excl__bottom">
                <div class="fct-pp-excl__toggle">
                    <span class="fct-pp-excl__checkbox"></span>
                    <span>{{ ctaText }}</span>
                </div>
                <div class="fct-pp-excl__total">
                    <span v-if="hasDiscount" class="fct-pp-excl__original">{{ bump.originalPrice }}</span>
                    <span class="fct-pp-excl__price">{{ bump.price }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
