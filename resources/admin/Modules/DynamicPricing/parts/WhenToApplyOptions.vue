<script setup>
import translate from "@/utils/translator/Translator";
import Animation from "@/Bits/Components/Animation.vue";

/*
 * The When to Apply choice as a radio list; the chosen option's hint slides
 * open under it.
 */
defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    disabled: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue']);

const options = [
    {
        value: 'every',
        label: translate('Every purchase'),
        hint: translate('Applies to all eligible purchases.')
    },
    {
        value: 'first',
        label: translate('First purchase only'),
        hint: translate('Only applies when a customer purchases for the first time.')
    },
    {
        value: 'renewals',
        label: translate('Store managed renewals'),
        hint: translate('Applies when a customer pays a renewal invoice issued by the store.')
    },
    {
        value: 'upgrades',
        label: translate('Upgrades & downgrades'),
        hint: translate('Applies when a customer moves a subscription to another plan.')
    },
];
</script>

<template>
    <el-radio-group
        :model-value="modelValue"
        class="fct-pricing-rule-when-options"
        :disabled="disabled"
        @update:model-value="(value) => emit('update:modelValue', value)"
    >
        <el-radio
            v-for="option in options"
            :key="option.value"
            :label="option.value"
        >
            <span class="fct-pricing-rule-when-title">{{ option.label }}</span>
            <Animation :visible="modelValue === option.value" accordion>
                <span class="fct-pricing-rule-when-hint">
                    {{ option.hint }}
                </span>
            </Animation>
        </el-radio>
    </el-radio-group>
</template>
