<script setup>
import {computed} from "vue";
import translate from "@/utils/translator/Translator";
import {placementOptions} from "../placementOptions";

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['update:modelValue']);

// Element Plus shows the placeholder for an empty model, so Default uses a stand-in value.
const DEFAULT_KEY = 'default';

const model = computed({
    get: () => props.modelValue || DEFAULT_KEY,
    set: (value) => emit('update:modelValue', value === DEFAULT_KEY ? '' : value)
});

const options = placementOptions().map((option) => ({...option, value: option.value || DEFAULT_KEY}));
</script>

<template>
    <fct-select
        v-model="model"
        :options="options"
        popper-class="fct-order-bump-placement-popper"
        :aria-label="translate('Checkout Placement')"
    />
</template>
