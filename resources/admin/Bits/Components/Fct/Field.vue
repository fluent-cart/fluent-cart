<template>
  <div v-if="label || $slots.label || help || validationErrors" class="fct-form-field" :class="$attrs.class">
    <label v-if="label || $slots.label" :id="labelId" class="fct-form-field__label" :for="inputId">
      <slot name="label">
        <LabelHint :title="label" :content="tooltip || null" :required="required"/>
      </slot>
    </label>
    <slot :id="inputId" :labelId="label || $slots.label ? labelId : undefined"/>
    <div v-if="help" class="form-note"><p>{{ help }}</p></div>
    <ValidationError v-if="validationErrors && fieldKey" :validation-errors="validationErrors" :field-key="fieldKey"/>
  </div>
  <Bare v-else :id="props.id || parentId"/>
</template>

<script setup>
import {computed, inject, provide, useId, useSlots} from 'vue';
import LabelHint from '@/Bits/Components/LabelHint.vue';
import ValidationError from '@/Bits/Components/Inputs/ValidationError.vue';
import {fieldProps} from './fieldProps';

// Wraps one fct-* input; renders nothing extra when no field prop is set.
defineOptions({name: 'FctField', inheritAttrs: false});

const props = defineProps(fieldProps);

// Without a wrapper the control itself is the root, so $el and <Transition> get an element, not a fragment.
const slots = useSlots();
const Bare = (bareProps) => slots.default({id: bareProps.id})[0];

// Like el-form-item: a labelled Field hands its id to the first fct-* control inside it that has no label or id
// of its own, so the label points at that control. Later controls keep their own ids.
const FIELD_ID = 'fctFieldId';
const parentField = inject(FIELD_ID, null);
const parentId = !props.id && !props.label && parentField ? parentField.claim() : undefined;
let claimed = false;
provide(FIELD_ID, {
  claim: () => {
    if (claimed) {
      return undefined;
    }
    claimed = true;
    return inputId.value;
  },
});

const generatedId = useId();
const inputId = computed(() => props.id || parentId || 'fct-' + generatedId);
const labelId = computed(() => inputId.value + '-label');
</script>
