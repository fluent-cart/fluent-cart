<script setup>
import Badge from "@/Bits/Components/Badge.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
    product: Object,
});
</script>

<template>
    <div class="fct-shared-variant-nav__summary">
        <div class="fct-shared-variant-nav__summary-product">
            <h3 class="fct-shared-variant-nav__summary-product-name">
                {{ product.post_title || translate('Untitled product') }}
            </h3>
        </div>
        
        <div class="fct-shared-variant-nav__summary-meta">
            <span class="fct-shared-variant-nav__status">
                <Badge :status="product.post_status || 'draft'"/>
            </span>
            <span class="fct-shared-variant-nav__variant-count">
                {{ product.variants.length }} {{ translate('variants') }}
            </span>
        </div>
    </div>
</template>
