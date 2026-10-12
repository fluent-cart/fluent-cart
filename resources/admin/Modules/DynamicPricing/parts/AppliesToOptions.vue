<script>
import translate from "@/utils/translator/Translator";

// Exported as well: the edit page's summary bar shows the chosen option's
// label and icon.
export const appliesToOptions = [
    {
        value: 'line_item',
        label: translate('All line items'),
        icon: 'ShoppingBag',
        // Conditions switch the rule on for the whole cart; they never pick lines.
        hint: translate('Applied to every line item in the cart when the conditions match.')
    },
    {
        value: 'checkout',
        label: translate('Checkout'),
        icon: 'Cart',
        hint: translate('Discount or fee is applied once to the overall checkout total.')
    },
    {
        value: 'shipping',
        label: translate('Shipping'),
        icon: 'Truck',
        hint: translate('Discount or fee is applied to the shipping cost.')
    },
];
</script>

<script setup>
import {computed, nextTick, ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

/*
 * The Applies To choice as icon tiles, with the chosen one's hint below. A
 * radio group: one tile is in the tab order, and the arrow keys move the
 * choice between the tiles that can be chosen.
 */
const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    isDisabled: {
        type: Function,
        default: () => false
    }
});

const emit = defineEmits(['update:modelValue']);

const tiles = ref([]);

const hint = computed(() => {
    const selected = appliesToOptions.find((option) => option.value === props.modelValue);

    return selected ? selected.hint : translate('Where should this rule affect the price?');
});

const enabledValues = () => appliesToOptions
    .filter((option) => !props.isDisabled(option.value))
    .map((option) => option.value);

// The tile Tab lands on: the chosen one, or the first that can be chosen.
const tabStop = computed(() => {
    const enabled = enabledValues();

    return enabled.includes(props.modelValue) ? props.modelValue : enabled[0];
});

const select = (value) => {
    emit('update:modelValue', value);
};

const move = (step) => {
    const enabled = enabledValues();

    if (!enabled.length) {
        return;
    }

    const next = enabled[(enabled.indexOf(tabStop.value) + step + enabled.length) % enabled.length];

    select(next);

    nextTick(() => {
        tiles.value[appliesToOptions.findIndex((option) => option.value === next)]?.focus();
    });
};
</script>

<template>
    <div
        class="fct-pricing-rule-applies-to-options"
        role="radiogroup"
        :aria-label="translate('Applies To')"
    >
        <button
            v-for="(option, index) in appliesToOptions"
            :key="option.value"
            :ref="(el) => { tiles[index] = el; }"
            type="button"
            role="radio"
            class="fct-pricing-rule-applies-to-option"
            :class="{ 'is-selected': modelValue === option.value }"
            :aria-checked="modelValue === option.value"
            :tabindex="tabStop === option.value ? 0 : -1"
            :disabled="isDisabled(option.value)"
            @click="select(option.value)"
            @keydown.left.prevent="move(-1)"
            @keydown.up.prevent="move(-1)"
            @keydown.right.prevent="move(1)"
            @keydown.down.prevent="move(1)"
        >
            <DynamicIcon :name="option.icon"/>
            <span>{{ option.label }}</span>
        </button>
    </div>

    <div class="form-note mt-3">
        <p>{{ hint }}</p>
    </div>
</template>
