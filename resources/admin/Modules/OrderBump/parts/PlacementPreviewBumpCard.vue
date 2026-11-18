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
        <div class="fct_order_promotion">
            <div class="fct_order_promotion_body">
                <div class="fct_order_promotion_icon">
                    <img v-if="bump.imageUrl" :src="bump.imageUrl" alt="">
                </div>
                <div class="fct_bump_details">
                    <div class="fct_order_promotion_title">{{ bump.title }}</div>
                    <div class="fct_order_promotion_product_title">{{ bump.productTitle }}</div>
                    <div v-if="bump.variationTitle" class="fct_order_promotion_variation_title">
                        - {{ bump.variationTitle }}
                    </div>
                    <div class="fct_order_promotion_price">
                        <template v-if="hasDiscount">
                            <span class="fct_order_promotion_original_price">{{ bump.originalPrice }}</span>
                            <span class="fct_order_promotion_discounted_price">{{ bump.price }}</span>
                            <span v-if="bump.savings" class="fct_order_promotion_discount">({{ translate('Save %s', bump.savings) }})</span>
                        </template>
                        <span v-else class="fct_order_promotion_discounted_price">{{ bump.price }}</span>
                        <!-- eslint-disable-next-line vue/no-v-html -- the storefront prints this as HTML too, and it is the admin's own text -->
                        <div v-if="bump.description" class="fct_order_promotion_description" v-html="bump.description"></div>
                    </div>
                </div>
            </div>
            <div class="fct_order_promotion_footer">
                <div class="fct_order_promotion_toggle">
                    <span class="fct_order_promotion_checkbox"></span>
                    <span>{{ ctaText }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
