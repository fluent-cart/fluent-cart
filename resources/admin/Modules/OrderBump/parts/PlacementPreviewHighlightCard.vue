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
    <div class="fct-pp-highlight">
        <div class="fct-pp-highlight__bar">
            <span class="fct-pp-highlight__toggle">
                <span class="fct-pp-highlight__checkbox"></span>
                <span>{{ ctaText }}</span>
            </span>
            <span class="fct-pp-highlight__prices">
                <span v-if="hasDiscount" class="fct-pp-highlight__was">{{ bump.originalPrice }}</span>
                <span>{{ bump.price }}</span>
            </span>
        </div>
        <div class="fct-pp-highlight__body">
            <div class="fct-pp-highlight__image">
                <img v-if="bump.imageUrl" :src="bump.imageUrl" alt="">
            </div>
            <div class="fct-pp-highlight__details">
                <div v-if="bump.title" class="fct-pp-highlight__badge">{{ bump.title }}</div>
                <div class="fct-pp-highlight__name">{{ bump.productTitle }}</div>
                <div v-if="bump.variationTitle" class="fct-pp-highlight__variation">{{ bump.variationTitle }}</div>
                <div class="fct-pp-highlight__row">
                    <span v-if="hasDiscount" class="fct-pp-highlight__was fct-pp-highlight__was--body">{{ bump.originalPrice }}</span>
                    <span class="fct-pp-highlight__price">{{ bump.price }}</span>
                    <!-- translators: %1$s: the amount saved, e.g. $2.50 -->
                    <span v-if="bump.savings" class="fct-pp-highlight__savings">{{ translate('Save %s', bump.savings) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
