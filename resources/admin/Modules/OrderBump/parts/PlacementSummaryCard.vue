<script setup>
import {computed} from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {placementOptions} from "../placementOptions";

const props = defineProps({
    position: {
        type: String,
        default: ''
    },
    showInCart: {
        type: String,
        default: 'no'
    }
});

// The checkout as two columns: a slot is where an offer can go, an empty entry is a bar of the page.
const sketch = [
    ['above_customer', '', '', 'below_customer', 'above_payment', '', 'below_payment'],
    ['above_summary', '', '', 'below_summary', 'default']
];

// '' is the Default placement, which sits under the order notes.
const placementLabel = computed(() => {
    if (!props.position) {
        return translate('Under order notes');
    }

    return placementOptions().find(option => option.value === props.position)?.label || '';
});
</script>

<template>
    <button type="button" class="fct-order-bump-placement-card">
        <span class="fct-order-bump-placement-thumb" :data-position="position || 'default'" aria-hidden="true">
            <span v-for="(column, index) in sketch" :key="index" class="fct-order-bump-placement-thumb__col">
                <span
                    v-for="(part, partIndex) in column"
                    :key="partIndex"
                    :class="part ? 'fct-order-bump-placement-thumb__slot' : 'fct-order-bump-placement-thumb__bar'"
                    :data-slot="part || null"
                />
            </span>
        </span>
        <span class="fct-order-bump-placement-card__text">
            <span class="fct-order-bump-placement-card__line">
                {{ translate('Checkout:') }}
                <strong>{{ placementLabel }}</strong>
            </span>
            <span class="fct-order-bump-placement-card__line">
                {{ translate('Cart drawer and Cart page:') }}
                <strong>{{ showInCart === 'yes' ? translate('On') : translate('Off') }}</strong>
            </span>
        </span>
        <DynamicIcon name="ChevronRight" class="fct-order-bump-placement-card__chevron" aria-hidden="true"/>
    </button>
</template>
