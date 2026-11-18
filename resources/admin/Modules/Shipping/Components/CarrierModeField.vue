<template>
  <Field :label="field.label">
    <div>
      <!-- Only the header is the radio: inputs must not live inside role=radio, nor toggle it. -->
      <ul
          class="fct-radio-cards has-content"
          :class="{ 'is-row': below }"
          role="radiogroup"
          :aria-label="field.label"
          :aria-describedby="field.hint ? hintId : null"
      >
        <li
            v-for="(option, index) in options"
            :key="option.value"
            :class="{ active: isSelected(option) }"
            role="none"
        >
          <div
              class="fct-radio-cards-header"
              :ref="(el) => { radioEls[index] = el; }"
              role="radio"
              :tabindex="index === focusIndex ? 0 : -1"
              :aria-checked="isSelected(option)"
              :aria-labelledby="optionTitleId(option)"
              :aria-describedby="optionDescribedBy(option)"
              @click="select(option)"
              @keydown.enter.space.self.prevent="select(option)"
              @keydown.self="onRadioKeydown($event, index)"
          >
            <div class="fct-radio-cards-content">
              <span class="fct-radio-cards-title-row">
                <span :id="optionTitleId(option)" class="fct-radio-cards-title">{{ option.label }}</span>
                <!-- The saved mode, which can differ from the card picked but not yet saved. -->
                <span v-if="isActive(option)" :id="optionActiveId(option)" class="fct-radio-cards-active">
                  <DynamicIcon name="CheckCircleFill" class="fct-radio-cards-active-icon" aria-hidden="true"/>
                  {{ translate('Active') }}
                </span>
              </span>
              <span v-if="option.hint" :id="optionHintId(option)" class="fct-radio-cards-desc">{{ option.hint }}</span>
              <!-- Whether this mode has a token, so a switch is never a guess. -->
              <span v-if="option.tag" class="fct-carrier-tag" :class="'is-' + (option.tag.tone || 'mute')">{{ option.tag.text }}</span>
            </div>
            <div class="fct-radio-cards-dot-wrap">
              <span class="fct-radio-cards-dot"></span>
            </div>
          </div>
          <Animation v-if="!below" :visible="isSelected(option) && hasFields(option)" accordion>
            <div class="fct-radio-cards-body">
              <slot :option="option" />
            </div>
          </Animation>
          <!-- Row layout: each card holds its own token, so Live can get one without picking Live first. -->
          <div v-else-if="hasFields(option)" class="fct-carrier-mode-token">
            <slot :option="option" />
          </div>
        </li>
      </ul>
      <div
          v-if="field.hint"
          :id="hintId"
          class="w-full text-xs text-system-mid dark:text-gray-400 mt-1"
      >
        {{ field.hint }}
      </div>
    </div>
  </Field>
</template>

<script setup>
import { computed } from 'vue';
import Animation from '@/Bits/Components/Animation.vue';
import Field from '@/Bits/Components/Fct/Field.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import translate from '@/utils/translator/Translator';
import { radioKeyTarget } from './radioKeyTarget';

const props = defineProps({
  field: {
    type: Object,
    required: true
  },
  modelValue: {
    type: [String, Number],
    default: undefined
  },
  // The saved mode, in effect until the form is saved.
  activeValue: {
    type: [String, Number],
    default: undefined
  }
});

const emit = defineEmits(['update:modelValue']);

const options = computed(() => (Array.isArray(props.field.options) ? props.field.options : []));

// Stored values arrive as strings; compare loosely typed option values as strings too.
const isSelected = (option) => props.modelValue !== undefined
    && props.modelValue !== null
    && String(props.modelValue) === String(option.value);

const isActive = (option) => props.activeValue !== undefined
    && props.activeValue !== null
    && String(props.activeValue) === String(option.value);

const hasFields = (option) => Array.isArray(option.fields) && option.fields.length > 0;

const below = computed(() => props.field.layout === 'row');

const select = (option) => {
  if (!isSelected(option)) {
    emit('update:modelValue', option.value);
  }
};

// Roving tabindex: one tab stop, on the first radio when nothing is selected.
const focusIndex = computed(() => Math.max(0, options.value.findIndex(isSelected)));

const radioEls = [];

const onRadioKeydown = (event, index) => {
  const target = radioKeyTarget(event.key, index, options.value.length);
  if (target === null) {
    return;
  }

  event.preventDefault();
  select(options.value[target]);
  radioEls[target]?.focus();
};

const hintId = computed(() => 'fct-carrier-field-hint-' + props.field.key);

const optionTitleId = (option) => 'fct-carrier-mode-title-' + props.field.key + '-' + option.value;

const optionHintId = (option) => 'fct-carrier-mode-hint-' + props.field.key + '-' + option.value;

const optionActiveId = (option) => 'fct-carrier-mode-active-' + props.field.key + '-' + option.value;

const optionDescribedBy = (option) => {
  const ids = [];
  if (isActive(option)) {
    ids.push(optionActiveId(option));
  }
  if (option.hint) {
    ids.push(optionHintId(option));
  }
  return ids.length ? ids.join(' ') : null;
};
</script>
