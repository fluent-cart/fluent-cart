<script setup>
import {computed, ref} from "vue";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import Eye from "@/Bits/Components/Icons/Eye.vue";
import PlacementPreviewModal from "@/Modules/OrderBump/PlacementPreviewModal.vue";
import {applyDiscount} from "@/Modules/OrderBump/orderBumpPrice";

const props = defineProps({
    position: {
        type: String,
        default: ''
    },
    showInCart: {
        type: String,
        default: 'no'
    },
    orderBump: {
        type: Object,
        required: true
    },
    variant: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['update:position', 'update:showInCart']);

const isOpen = ref(false);

const apply = ({position, showInCart}) => {
    emit('update:position', position);
    emit('update:showInCart', showInCart);
};

// Reads the form as it is now, unsaved edits included.
const bump = computed(() => {
    const v = props.variant;

    // The variant is loaded with the page, so a product picked since then has no data yet.
    if (!v || String(v.id) !== String(props.orderBump.src_object_id)) {
        return null;
    }

    const price = Number(v.item_price) || 0;
    const {saving: off, offer} = applyDiscount(price, props.orderBump.config?.discount);
    const productTitle = v.product?.post_title || '';

    return {
        title: props.orderBump.title,
        productTitle,
        variationTitle: v.variation_title && v.variation_title !== productTitle ? v.variation_title : '',
        imageUrl: props.orderBump.config?.image?.url || v.thumbnail || v.product?.thumbnail || '',
        originalPrice: off > 0 ? CurrencyFormatter.formatNumber(price, true) : '',
        price: CurrencyFormatter.formatNumber(offer, true),
        savings: off > 0 ? CurrencyFormatter.formatNumber(off, true) : '',
        description: props.orderBump.description || ''
    };
});
</script>

<template>
    <slot :open="() => { isOpen = true; }">
        <button type="button" class="fct-order-bump-preview-btn" @click="isOpen = true">
            <Eye aria-hidden="true"/>
            <span>{{ translate('Preview') }}</span>
        </button>
    </slot>

    <PlacementPreviewModal
        v-model="isOpen"
        :position="position"
        :show-in-cart="showInCart"
        :bump="bump"
        @apply="apply"
    />
</template>
