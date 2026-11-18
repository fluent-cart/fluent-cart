<script setup>
import {computed, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Asset from "@/utils/support/Asset";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Permission from "@/utils/permission/Permission";

const props = defineProps({
    bump: {
        type: Object,
        required: true
    }
});

const variant = computed(() => props.bump.product_variant || null);
// A deleted product or variation cannot add anything at checkout.
const missing = computed(() => !variant.value?.product);

// The product screen needs this permission, so without it the name is plain text, not a dead link.
const canOpenProduct = computed(() => Permission.resolve({permission: 'products/edit'}));
const productName = computed(() => variant.value?.product?.post_title || '');

// Same order as the storefront card: the bump's own image, the variation's, then the product's,
// and the placeholder when there is none. A file that fails to load shows the placeholder too.
const imageFailed = ref(false);
const imageUrl = computed(() => {
    if (missing.value) {
        return Asset.productPlaceholder();
    }

    const url = props.bump.config?.image?.url || variant.value?.thumbnail || variant.value?.product?.thumbnail;
    return url && !imageFailed.value ? url : Asset.productPlaceholder();
});

const variationText = computed(() => {
    const text = variant.value?.variation_title;
    return text && text !== productName.value ? text : '';
});

</script>

<template>
    <div class="fct-order-bump-offer">
        <div class="fct-order-bump-offer__tile">
            <img :src="imageUrl" alt="" @error="imageFailed = true">
        </div>
        <!-- %s is the "·" separator between the two phrases, so translators can swap it -->
        <LabelHint
            v-if="missing"
            class="fct-order-bump-offer__missing"
            :title="translate('Product deleted %s choose another', '·')"
            :content="translate('OrderBump_Product_Deleted_Help')"
        />
        <div v-else class="fct-order-bump-offer__body">
            <router-link
                v-if="canOpenProduct && productName"
                :to="{name: 'product_edit', params: {product_id: variant.post_id}}"
                class="fct-order-bump-offer__name"
            >{{ productName }}</router-link>
            <span v-else class="fct-order-bump-offer__product">{{ productName || translate('Product unavailable') }}</span>
            <span v-if="variationText" class="fct-order-bump-offer__variation">{{ variationText }}</span>
        </div>
    </div>
</template>
