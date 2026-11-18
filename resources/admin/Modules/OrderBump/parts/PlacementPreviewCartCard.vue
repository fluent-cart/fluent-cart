<script setup>
import translate from "@/utils/translator/Translator";

defineProps({
    bump: {
        type: Object,
        required: true
    }
});
</script>

<template>
    <div class="fct-placement-preview-drawer__bump">
        <div class="fct_cart_bump_icon">
            <img v-if="bump.imageUrl" :src="bump.imageUrl" alt="">
        </div>
        <div class="fct_cart_bump_details">
            <span class="fct_cart_bump_badge">{{ bump.title }}</span>
            <div class="fct_cart_bump_product">{{ bump.productTitle }}</div>
            <div v-if="bump.variationTitle" class="fct_cart_bump_variation">{{ bump.variationTitle }}</div>
            <div class="fct_cart_bump_price">
                <span v-if="bump.originalPrice" class="fct_order_promotion_original_price">{{ bump.originalPrice }}</span>
                <span class="fct_order_promotion_discounted_price">{{ bump.price }}</span>
                <span v-if="bump.savings" class="fct_order_promotion_discount">{{ translate('Save %s', bump.savings) }}</span>
            </div>
        </div>
        <div class="fct_cart_bump_actions">
            <span class="fct_cart_bump_btn">{{ translate('Add') }}</span>
        </div>
    </div>
</template>
