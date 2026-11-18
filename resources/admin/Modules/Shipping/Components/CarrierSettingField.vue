<template>
  <!-- A toggle carries its label beside the switch, so it takes one row instead of two. -->
  <Field :label="field.type === 'toggle' || field.label_note ? '' : field.label">
    <template v-if="field.label_note" #label>
      {{ field.label }} <small class="fct-carrier-label-note">· {{ field.label_note }}</small>
    </template>
    <!-- One root: an unlabelled Field renders only its first node, so the toggle keeps a form item's gap itself. -->
    <template #default="{id}">
      <div :class="{'mb-[18px]': field.type === 'toggle'}">
        <template v-if="field.type === 'password'">
          <fct-input
              :id="id"
              v-model="value"
              type="password"
              show-password
              autocomplete="new-password"
              :placeholder="savedPlaceholder"
              :aria-describedby="secretDescribedBy"
              class="w-full"
          />
          <!-- The value never comes back from the server, so say a stored one exists. -->
          <div
              v-if="showSavedNote"
              :id="savedNoteId"
              class="fct-carrier-secret-saved"
          >
            <DynamicIcon v-if="!field.saved_hint" name="CheckCircleFill" class="fct-carrier-secret-saved-icon" aria-hidden="true"/>
            <span>{{ savedNote }}</span>
          </div>
        </template>
        <template v-else-if="field.type === 'toggle'">
          <!--
            el-switch and el-select fall stray attrs through onto their root
            <div>, where aria-describedby is inert — unlike el-input, which
            forwards them to the inner <input>. So the hint is carried in an
            aria-label here instead, or a screen reader would never hear it.
          -->
          <div class="fct-carrier-toggle">
            <fct-switch
                :id="switchId"
                v-model="value"
                active-value="yes"
                inactive-value="no"
                :aria-label="controlLabel"
            />
            <label :for="switchId" class="fct-carrier-toggle__label">{{ field.label }}</label>
          </div>
        </template>
        <template v-else-if="field.type === 'select'">
          <!-- '' is a real choice (e.g. "All"), so only null/undefined count as empty. -->
          <fct-select
              :id="id"
              v-model="value"
              class="w-full"
              :options="field.options || []"
              :empty-values="[null, undefined]"
              :aria-label="controlLabel"
          />
        </template>
        <template v-else>
          <fct-input
              :id="id"
              v-model="value"
              :placeholder="field.placeholder ?? field.hint ?? ''"
              :aria-describedby="shownHint ? hintId : null"
              class="w-full"
              :class="{ 'fct-carrier-input-warn': emptyHint }"
          />
        </template>
        <div
            v-if="shownHint"
            :id="hintId"
            class="w-full text-xs text-system-mid dark:text-gray-400 mt-1"
            :class="{ 'fct-carrier-toggle__hint': field.type === 'toggle', ['fct-carrier-hint-' + (emptyHint && emptyHint.tone)]: !!emptyHint }"
        >
          {{ shownHint }}
        </div>
      </div>
    </template>
  </Field>
</template>

<script setup>
import { computed } from 'vue';
import translate from '@/utils/translator/Translator';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Field from '@/Bits/Components/Fct/Field.vue';

const props = defineProps({
  field: {
    type: Object,
    required: true
  },
  modelValue: {
    type: [String, Number, Boolean, Array, Object],
    default: undefined
  },
  // A stored secret, as CarrierManager::savedSecrets() describes it.
  saved: {
    type: Object,
    default: null
  }
});

const emit = defineEmits(['update:modelValue']);

const value = computed({
  get: () => props.modelValue,
  set: (newValue) => emit('update:modelValue', newValue)
});

// Keys are unique across a carrier's fields, nested ones included.
const hintId = computed(() => 'fct-carrier-field-hint-' + props.field.key);
const switchId = computed(() => 'fct-carrier-field-' + props.field.key);

// Label + hint in one accessible name, for controls that cannot take aria-describedby.
const controlLabel = computed(() => {
  if (!props.field.hint) {
    return props.field.label;
  }
  /* translators: %1$s: field label, %2$s: field hint */
  return translate('%1$s. %2$s', props.field.label, props.field.hint);
});

const savedEnding = computed(() => (props.saved && props.saved.ends_with) || '');

const savedPlaceholder = computed(() => {
  if (!props.saved) {
    return props.field.placeholder || '';
  }
  // A carrier may name its secret (e.g. "API key"); its strings arrive translated, %1$s = ending.
  if (savedEnding.value) {
    if (props.field.saved_placeholder_ending) {
      return props.field.saved_placeholder_ending.replace('%1$s', savedEnding.value);
    }
    /* translators: %1$s: last characters of the saved token */
    return translate('Leave blank to keep the saved token (ending %1$s)', savedEnding.value);
  }
  return props.field.saved_placeholder || translate('Leave blank to keep the saved token');
});

// An empty field the carrier flags (e.g. a phone a label needs) shows that warning instead of the hint.
const emptyHint = computed(() => (props.field.empty_hint && !String(value.value ?? '').trim() ? props.field.empty_hint : null));

const shownHint = computed(() => {
  if (emptyHint.value) {
    return emptyHint.value.text;
  }
  if (props.field.type === 'password') {
    if (showSavedNote.value && props.field.saved_hint) {
      return '';
    }
    if (value.value && props.field.typed_hint) {
      return props.field.typed_hint;
    }
  }
  return props.field.hint || '';
});

// Blank keeps the stored value, so the note stays until something is typed.
const showSavedNote = computed(() => !!props.saved && !value.value);

const savedNote = computed(() => {
  if (props.field.saved_hint) {
    return props.field.saved_hint;
  }
  if (savedEnding.value) {
    /* translators: %1$s: last characters of the saved token */
    return translate('Saved, ending in %1$s. Leave blank to keep it.', savedEnding.value);
  }
  return translate('Saved. Leave blank to keep it.');
});

const savedNoteId = computed(() => 'fct-carrier-field-saved-' + props.field.key);

const secretDescribedBy = computed(() => {
  const ids = [];
  if (showSavedNote.value) {
    ids.push(savedNoteId.value);
  }
  if (shownHint.value) {
    ids.push(hintId.value);
  }
  return ids.length ? ids.join(' ') : null;
});
</script>
