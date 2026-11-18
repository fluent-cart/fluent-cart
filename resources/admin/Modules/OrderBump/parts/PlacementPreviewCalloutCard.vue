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
</script>

<template>
    <div class="fct-bump-callout-preview">
        <div class="fct-bump-callout-preview__body">
            <div class="fct-bump-callout-preview__image">
                <img v-if="bump.imageUrl" :src="bump.imageUrl" alt="">
            </div>
            <div class="fct-bump-callout-preview__details">
                <div class="fct-bump-callout-preview__product">{{ bump.productTitle }}</div>
                <div v-if="bump.variationTitle" class="fct-bump-callout-preview__variation">{{ bump.variationTitle }}</div>
                <div class="fct-bump-callout-preview__prices">
                    <span v-if="bump.originalPrice" class="fct-bump-callout-preview__original">{{ bump.originalPrice }}</span>
                    <span class="fct-bump-callout-preview__price">{{ bump.price }}</span>
                    <span v-if="bump.savings" class="fct-bump-callout-preview__savings">{{ translate('Save %s', bump.savings) }}</span>
                </div>
                <!-- eslint-disable-next-line vue/no-v-html -- the storefront prints this as HTML too, and it is the admin's own text -->
                <div v-if="bump.description" class="fct-bump-callout-preview__description" v-html="bump.description"></div>
            </div>
        </div>
        <div class="fct-bump-callout-preview__footer">
            <span class="fct-bump-callout-preview__box"></span>
            <span class="fct-bump-callout-preview__label">{{ ctaText }}</span>
        </div>
    </div>
</template>
